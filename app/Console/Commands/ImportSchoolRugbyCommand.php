<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\MatchTeam;
use App\Models\RugbyMatch;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ImportSchoolRugbyCommand extends Command
{
    protected $signature = 'rugby:import-school-rugby
                            {--path= : JSON file produced by schoolrugby_scraper.py}
                            {--season= : Force a single season label (default: derive from match date)}
                            {--fixtures : Include unplayed fixtures (status=scheduled)}';

    protected $description = 'Import SA schoolboy rugby results from schoolrugby.co.za scraper JSON';

    public function handle(): int
    {
        $path = $this->option('path');
        if (! $path || ! file_exists($path)) {
            $this->error('File required: --path=/path/to/schoolrugby_*.json');
            return self::FAILURE;
        }

        $data = json_decode(file_get_contents($path), true);
        $competition = Competition::firstOrCreate(
            ['code' => 'sa_schools'],
            [
                'name' => 'SA Schools Rugby',
                'format' => 'union',
                'country' => 'South-Africa',
                'has_standings' => false,
                'external_source' => 'schoolrugby.co.za',
            ]
        );
        $forcedSeasonLabel = $this->option('season');
        $seasonCache = [];

        $stats = ['matches_created' => 0, 'matches_existing' => 0, 'teams_created' => 0, 'seasons' => 0];

        $includeFixtures = (bool) $this->option('fixtures');

        foreach ($data['matches'] as $m) {
            $hasScore = isset($m['home_score'], $m['away_score']) && $m['home_score'] !== null && $m['away_score'] !== null;
            if (! $hasScore && ! $includeFixtures) {
                continue;
            }

            $home = $this->resolveSchool($m['home_team'], $m['home_school_id'] ?? null, $stats);
            $away = $this->resolveSchool($m['away_team'], $m['away_school_id'] ?? null, $stats);
            if (! $home || ! $away) continue;

            $kickoff = Carbon::parse($m['kickoff'] ?? $m['date']);
            $seasonLabel = $forcedSeasonLabel ?: (string) $kickoff->year;
            if (! isset($seasonCache[$seasonLabel])) {
                $seasonCache[$seasonLabel] = $competition->seasons()->firstOrCreate(
                    ['label' => $seasonLabel],
                    [
                        'is_current' => false,
                        'start_date' => "{$seasonLabel}-01-01",
                        'end_date' => "{$seasonLabel}-12-31",
                        'external_source' => 'schoolrugby.co.za',
                    ]
                );
                $stats['seasons']++;
            }
            $season = $seasonCache[$seasonLabel];

            $existing = RugbyMatch::where('season_id', $season->id)
                ->whereHas('matchTeams', fn ($q) => $q->where('team_id', $home->id))
                ->whereHas('matchTeams', fn ($q) => $q->where('team_id', $away->id))
                ->whereBetween('kickoff', [$kickoff->copy()->subDay(), $kickoff->copy()->addDay()])
                ->first();

            if ($existing) {
                $stats['matches_existing']++;
                if ($hasScore) {
                    // Update scores in case they changed (promote fixture → result, or fix revised score)
                    $this->updateScores($existing, $home->id, $away->id, $m['home_score'], $m['away_score']);
                    if ($existing->status !== 'ft') {
                        $existing->update(['status' => 'ft']);
                    }
                }
                continue;
            }

            $status = $hasScore ? 'ft' : 'scheduled';
            $match = RugbyMatch::create([
                'season_id' => $season->id,
                'kickoff' => $kickoff,
                'status' => $status,
                'external_source' => 'schoolrugby.co.za',
            ]);
            $isDraw = $hasScore && $m['home_score'] === $m['away_score'];
            MatchTeam::create([
                'match_id' => $match->id,
                'team_id' => $home->id,
                'side' => 'home',
                'score' => $hasScore ? $m['home_score'] : null,
                'is_winner' => $hasScore && ! $isDraw && $m['home_score'] > $m['away_score'],
            ]);
            MatchTeam::create([
                'match_id' => $match->id,
                'team_id' => $away->id,
                'side' => 'away',
                'score' => $hasScore ? $m['away_score'] : null,
                'is_winner' => $hasScore && ! $isDraw && $m['away_score'] > $m['home_score'],
            ]);
            $stats['matches_created']++;
        }

        $this->table(['Metric', 'Count'], [
            ['Matches created',  $stats['matches_created']],
            ['Matches existing', $stats['matches_existing']],
            ['Schools created',  $stats['teams_created']],
            ['Seasons touched',  count($seasonCache)],
        ]);
        return self::SUCCESS;
    }

    private function resolveSchool(string $name, ?int $externalId, array &$stats): ?Team
    {
        $name = trim($name);
        if ($name === '') return null;

        if ($externalId) {
            $team = Team::where('external_source', 'schoolrugby.co.za')
                ->where('external_id', (string) $externalId)->first();
            if ($team) return $team;
        }

        // Exact name match.
        $team = Team::where('name', $name)->where('type', 'school')->first();
        if ($team) {
            if ($externalId && ! $team->external_id) {
                $team->update(['external_id' => (string) $externalId]);
            }
            return $team;
        }

        // Fuzzy match — schoolboyrugby.co.za uses short names ("Brackenfell")
        // while schoolrugby.co.za uses full names ("Brackenfell High School"),
        // both for the same team. Try substring both directions and accept
        // only when exactly one school matches (avoids ambiguous merges).
        $candidates = Team::where('type', 'school')
            ->where(function ($q) use ($name) {
                $q->where('name', 'like', "%{$name}%")
                    ->orWhereRaw('? LIKE CONCAT("%", name, "%")', [$name]);
            })
            ->limit(2)
            ->get();
        if ($candidates->count() === 1) {
            $team = $candidates->first();
            if ($externalId && ! $team->external_id) {
                $team->update(['external_id' => (string) $externalId]);
            }
            return $team;
        }

        $team = Team::create([
            'name' => $name,
            'country' => 'South-Africa',
            'type' => 'school',
            'external_source' => 'schoolrugby.co.za',
            'external_id' => $externalId ? (string) $externalId : null,
        ]);
        $stats['teams_created']++;
        return $team;
    }

    private function updateScores(RugbyMatch $match, string $homeId, string $awayId, int $hs, int $as): void
    {
        $isDraw = $hs === $as;
        MatchTeam::where('match_id', $match->id)->where('team_id', $homeId)->update([
            'score' => $hs,
            'is_winner' => ! $isDraw && $hs > $as,
        ]);
        MatchTeam::where('match_id', $match->id)->where('team_id', $awayId)->update([
            'score' => $as,
            'is_winner' => ! $isDraw && $as > $hs,
        ]);
    }
}
