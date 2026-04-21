<?php

namespace App\Services\Rugby;

use App\Models\MatchEvent;
use App\Models\MatchTeam;
use App\Models\RugbyMatch;
use App\Models\Season;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StandingsComputer
{
    private Collection $matches;

    /** @var array<string, int> Batch-loaded try counts keyed by "{match_id}_{team_id}" */
    private array $tryEventCounts = [];

    /** @var array<string, string|null> Pool assignments keyed by team_id */
    private array $teamPools = [];

    public function __construct(
        private Season $season,
        private array $pointsConfig,
        private array|false $bonusConfig,
        private array $countableStatuses,
    ) {}

    public static function forSeason(Season $season): self
    {
        $season->loadMissing('competition');
        $code = $season->competition->code;

        $config = config('rugby.standings');
        $points = $config['points'];
        $bonus = $config['bonus'];
        $statuses = $config['countable_statuses'];

        // Apply per-competition overrides
        $overrides = $config['competition_overrides'][$code] ?? [];
        if (array_key_exists('bonus', $overrides)) {
            if ($overrides['bonus'] === false) {
                $bonus = false;
            } else {
                $bonus = array_merge($bonus, $overrides['bonus']);
            }
        }
        if (isset($overrides['points'])) {
            $points = array_merge($points, $overrides['points']);
        }

        return new self($season, $points, $bonus, $statuses);
    }

    public function compute(): Collection
    {
        $this->loadMatches();
        $this->loadTeamPools();
        $this->batchLoadTryEvents();

        $standings = [];

        foreach ($this->matches as $match) {
            $teams = $match->matchTeams;
            if ($teams->count() !== 2) {
                continue;
            }

            $teamA = $teams->first();
            $teamB = $teams->last();

            if ($teamA->score === null || $teamB->score === null) {
                continue;
            }

            $this->accumulateTeam($standings, $match, $teamA, $teamB);
            $this->accumulateTeam($standings, $match, $teamB, $teamA);
        }

        return $this->rankTeams(collect($standings));
    }

    public function audit(): StandingsAuditReport
    {
        $report = new StandingsAuditReport();

        $this->loadMatches();
        $this->loadTeamPools();
        $this->batchLoadTryEvents();

        $report->totalMatches = $this->matches->count();

        // Pre-load team names
        $teamNames = [];
        foreach ($this->matches as $match) {
            foreach ($match->matchTeams as $mt) {
                if ($mt->team) {
                    $teamNames[$mt->team_id] = $mt->team->name;
                }
            }
        }

        foreach ($this->matches as $match) {
            $teams = $match->matchTeams;
            if ($teams->count() !== 2) {
                continue;
            }

            $teamA = $teams->first();
            $teamB = $teams->last();
            $nameA = $teamNames[$teamA->team_id] ?? 'Unknown';
            $nameB = $teamNames[$teamB->team_id] ?? 'Unknown';

            // Check missing scores
            if ($teamA->score === null || $teamB->score === null) {
                $report->addWarning('MISSING_SCORE', "{$nameA} vs {$nameB} — score is null");
                continue;
            }

            $complete = true;

            // Check is_winner consistency
            $this->auditWinnerField($report, $match, $teamA, $teamB, $nameA, $nameB);
            $this->auditWinnerField($report, $match, $teamB, $teamA, $nameB, $nameA);

            // Check try count consistency
            foreach ([$teamA, $teamB] as $mt) {
                $name = $teamNames[$mt->team_id] ?? 'Unknown';
                if ($mt->tries !== null) {
                    $eventTries = $this->getTriesByEvents($match->id, $mt->team_id);
                    if ($eventTries > 0 && $mt->tries !== $eventTries) {
                        $report->addWarning('TRY_MISMATCH', "{$name} — match_teams.tries={$mt->tries}, match_events count={$eventTries}");
                    }
                }
                if ($mt->tries === null) {
                    $complete = false;
                }
            }

            if ($complete) {
                $report->matchesWithCompleteData++;
            }

            // Per-team breakdowns
            $this->addTeamBreakdown($report, $match, $teamA, $teamB, $teamNames);
            $this->addTeamBreakdown($report, $match, $teamB, $teamA, $teamNames);
        }

        // Run cross-checks on computed standings
        $computed = $this->compute();
        $allWdlMatch = true;
        $allPdMatch = true;
        $failedTeams = [];

        foreach ($computed as $row) {
            $name = $teamNames[$row['team_id']] ?? $row['team_id'];

            if ($row['won'] + $row['drawn'] + $row['lost'] !== $row['played']) {
                $allWdlMatch = false;
                $failedTeams[] = "{$name}: W({$row['won']})+D({$row['drawn']})+L({$row['lost']}) != P({$row['played']})";
            }
            if ($row['points_for'] - $row['points_against'] !== $row['point_differential']) {
                $allPdMatch = false;
                $failedTeams[] = "{$name}: PF({$row['points_for']})-PA({$row['points_against']}) != PD({$row['point_differential']})";
            }
        }

        $report->addCrossCheck(
            'All teams: W + D + L = P',
            $allWdlMatch,
            $allWdlMatch ? '' : implode('; ', $failedTeams)
        );
        $report->addCrossCheck(
            'All teams: PF - PA = PD',
            $allPdMatch,
            $allPdMatch ? '' : implode('; ', $failedTeams)
        );

        return $report;
    }

    private function loadMatches(): void
    {
        if (isset($this->matches)) {
            return;
        }

        $query = RugbyMatch::where('season_id', $this->season->id)
            ->whereIn('status', $this->countableStatuses)
            ->with(['matchTeams.team']);

        // Only include pool-stage matches in standings (exclude knockouts)
        $query->where(function ($q) {
            $q->where('stage', 'pool')
              ->orWhereNull('stage');
        });

        $this->matches = $query->get();
    }

    private function loadTeamPools(): void
    {
        if (! empty($this->teamPools)) {
            return;
        }

        $pivotRows = $this->season->teams()->get();
        foreach ($pivotRows as $team) {
            $this->teamPools[$team->id] = $team->pivot->pool;
        }
    }

    private function batchLoadTryEvents(): void
    {
        if (! empty($this->tryEventCounts)) {
            return;
        }

        $matchIds = $this->matches->pluck('id');
        if ($matchIds->isEmpty()) {
            return;
        }

        $counts = MatchEvent::whereIn('match_id', $matchIds)
            ->where('type', 'try')
            ->selectRaw('match_id, team_id, COUNT(*) as try_count')
            ->groupBy('match_id', 'team_id')
            ->get();

        foreach ($counts as $row) {
            $key = "{$row->match_id}_{$row->team_id}";
            $this->tryEventCounts[$key] = (int) $row->try_count;
        }
    }

    private function resolveTriesFor(MatchTeam $mt): int
    {
        if ($mt->tries !== null) {
            return $mt->tries;
        }

        return $this->getTriesByEvents($mt->match_id, $mt->team_id);
    }

    private function getTriesByEvents(string $matchId, string $teamId): int
    {
        return $this->tryEventCounts["{$matchId}_{$teamId}"] ?? 0;
    }

    private function determineResult(MatchTeam $team, MatchTeam $opponent): string
    {
        if ($team->score > $opponent->score) {
            return 'win';
        }
        if ($team->score < $opponent->score) {
            return 'loss';
        }

        return 'draw';
    }

    private function computeBonusPoints(MatchTeam $team, MatchTeam $opponent): array
    {
        if ($this->bonusConfig === false) {
            return ['points' => 0, 'reasons' => []];
        }

        $points = 0;
        $reasons = [];

        // Try bonus
        $tries = $this->resolveTriesFor($team);
        if ($tries >= $this->bonusConfig['try_threshold']) {
            $points++;
            $reasons[] = 'try';
        }

        // Losing bonus
        $result = $this->determineResult($team, $opponent);
        if ($result === 'loss') {
            $margin = $opponent->score - $team->score;
            if ($margin <= $this->bonusConfig['losing_margin_threshold']) {
                $points++;
                $reasons[] = 'losing';
            }
        }

        return ['points' => $points, 'reasons' => $reasons];
    }

    private function accumulateTeam(array &$standings, RugbyMatch $match, MatchTeam $team, MatchTeam $opponent): void
    {
        $teamId = $team->team_id;
        $pool = $this->teamPools[$teamId] ?? null;

        if (! isset($standings[$teamId])) {
            $standings[$teamId] = [
                'season_id' => $this->season->id,
                'team_id' => $teamId,
                'pool' => $pool,
                'position' => 0,
                'played' => 0,
                'won' => 0,
                'drawn' => 0,
                'lost' => 0,
                'points_for' => 0,
                'points_against' => 0,
                'tries_for' => 0,
                'tries_against' => 0,
                'bonus_points' => 0,
                'total_points' => 0,
                'point_differential' => 0,
            ];
        }

        $row = &$standings[$teamId];
        $result = $this->determineResult($team, $opponent);
        $bonus = $this->computeBonusPoints($team, $opponent);

        $row['played']++;
        $row[$result === 'win' ? 'won' : ($result === 'draw' ? 'drawn' : 'lost')]++;
        $row['points_for'] += $team->score;
        $row['points_against'] += $opponent->score;
        $row['tries_for'] += $this->resolveTriesFor($team);
        $row['tries_against'] += $this->resolveTriesFor($opponent);
        $row['bonus_points'] += $bonus['points'];

        $row['total_points'] = ($row['won'] * $this->pointsConfig['win'])
            + ($row['drawn'] * $this->pointsConfig['draw'])
            + ($row['lost'] * $this->pointsConfig['loss'])
            + $row['bonus_points'];

        $row['point_differential'] = $row['points_for'] - $row['points_against'];
    }

    private function rankTeams(Collection $standings): Collection
    {
        // Group by pool, rank within each pool
        $grouped = $standings->groupBy('pool');
        $ranked = collect();

        foreach ($grouped as $pool => $poolTeams) {
            $sorted = $poolTeams->sortBy([
                ['total_points', 'desc'],
                ['point_differential', 'desc'],
                ['tries_for', 'desc'],
                ['points_for', 'desc'],
            ])->values();

            foreach ($sorted as $i => $row) {
                $row['position'] = $i + 1;
                $ranked->push($row);
            }
        }

        return $ranked;
    }

    private function auditWinnerField(
        StandingsAuditReport $report,
        RugbyMatch $match,
        MatchTeam $team,
        MatchTeam $opponent,
        string $teamName,
        string $opponentName,
    ): void {
        if ($team->is_winner === null) {
            return;
        }

        $result = $this->determineResult($team, $opponent);

        if ($result === 'win' && ! $team->is_winner) {
            $report->addWarning('WINNER_MISMATCH', "{$teamName} ({$team->score}) beat {$opponentName} ({$opponent->score}) but is_winner=false");
        } elseif ($result === 'loss' && $team->is_winner) {
            $report->addWarning('WINNER_MISMATCH', "{$teamName} ({$team->score}) lost to {$opponentName} ({$opponent->score}) but is_winner=true");
        } elseif ($result === 'draw' && $team->is_winner) {
            $report->addWarning('WINNER_MISMATCH', "{$teamName} drew {$opponentName} ({$team->score}-{$opponent->score}) but is_winner=true");
        }
    }

    private function addTeamBreakdown(
        StandingsAuditReport $report,
        RugbyMatch $match,
        MatchTeam $team,
        MatchTeam $opponent,
        array $teamNames,
    ): void {
        $result = $this->determineResult($team, $opponent);
        $bonus = $this->computeBonusPoints($team, $opponent);

        $report->addTeamMatch($team->team_id, $teamNames[$team->team_id] ?? 'Unknown', [
            'round' => $match->round,
            'opponent' => $teamNames[$opponent->team_id] ?? 'Unknown',
            'is_home' => $team->side === 'home',
            'result' => $result,
            'score' => $team->score,
            'opponent_score' => $opponent->score,
            'tries' => $this->resolveTriesFor($team),
            'bonus_points' => $bonus['points'],
            'bonus_reason' => implode('+', $bonus['reasons']),
        ]);
    }
}
