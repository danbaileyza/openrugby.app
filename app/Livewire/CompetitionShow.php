<?php

namespace App\Livewire;

use App\Models\Competition;
use App\Models\RugbyMatch;
use Livewire\Attributes\Url;
use Livewire\Component;

class CompetitionShow extends Component
{
    public Competition $competition;

    #[Url(as: 'season', except: '')]
    public string $selectedSeason = '';

    #[Url(as: 'round', except: '')]
    public string $selectedRound = '';

    #[Url(as: 'tab', except: 'standings')]
    public string $activeTab = 'standings';

    public function mount(Competition $competition)
    {
        $this->competition = $competition;

        if (! $competition->has_standings) {
            $this->activeTab = 'matches';
        }
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function updatedSelectedSeason()
    {
        $this->selectedRound = '';
        $this->activeTab = $this->competition->has_standings ? 'standings' : 'matches';
    }

    public function render()
    {
        $this->competition->load([
            'seasons' => fn ($q) => $q->orderByDesc('label'),
        ]);

        // Pick the selected season, or default to current/latest
        $season = null;
        if ($this->selectedSeason) {
            $season = $this->competition->seasons->firstWhere('id', $this->selectedSeason);
        }
        if (! $season) {
            $season = $this->competition->seasons->firstWhere('is_current', true)
                ?? $this->competition->seasons->first();
        }

        $standings = collect();
        $matches = collect();
        $teams = collect();
        $rounds = collect();
        $totalMatches = 0;

        if ($season) {
            $standings = $season->standings()
                ->with('team')
                ->orderBy('pool')
                ->orderBy('position')
                ->get();

            // Get available rounds for the filter (includes knockout stages)
            $rounds = RugbyMatch::where('season_id', $season->id)
                ->whereNotNull('round')
                ->distinct()
                ->pluck('round')
                ->sort()
                ->values();

            $stages = RugbyMatch::where('season_id', $season->id)
                ->whereNotNull('stage')
                ->where('stage', '!=', 'pool')
                ->distinct()
                ->pluck('stage')
                ->sort()
                ->values();

            // Total match count (unfiltered)
            $totalMatches = RugbyMatch::where('season_id', $season->id)->count();

            // Build matches query with optional round filter
            $matchQuery = RugbyMatch::where('season_id', $season->id)
                ->with(['matchTeams.team', 'venue']);

            if ($this->selectedRound !== '') {
                $matchQuery->where('round', (int) $this->selectedRound);
            }

            $matches = $matchQuery->orderByDesc('kickoff')->get();

            // Teams in this season (from match_teams)
            $teamIds = \App\Models\MatchTeam::whereHas('match', fn ($q) => $q->where('season_id', $season->id))
                ->distinct()
                ->pluck('team_id');
            $teams = \App\Models\Team::whereIn('id', $teamIds)->orderBy('name')->get();

            // Referees who officiated in this season
            $matchIds = RugbyMatch::where('season_id', $season->id)->pluck('id');
            $referees = \App\Models\Referee::whereHas('matchOfficials', fn ($q) => $q->whereIn('match_id', $matchIds))
                ->withCount(['matchOfficials' => fn ($q) => $q->whereIn('match_id', $matchIds)])
                ->orderBy('last_name')
                ->get();
        }

        // Lions Tour: series summary for this season
        $seriesSummary = null;
        if ($season && $this->competition->code === 'lions_tour') {
            $seriesSummary = $this->buildSeriesSummary($season);
        }

        // All-time competition stats: titles per team (based on 'final' stage matches)
        $titlesByTeam = $this->buildTitlesByTeam();
        $appearancesByTeam = $this->buildAppearancesByTeam();

        return view('livewire.competition-show', [
            'currentSeason' => $season,
            'standings' => $standings,
            'matches' => $matches,
            'teams' => $teams,
            'rounds' => $rounds,
            'stages' => $stages ?? collect(),
            'titlesByTeam' => $titlesByTeam,
            'appearancesByTeam' => $appearancesByTeam,
            'totalMatches' => $totalMatches,
            'referees' => $referees ?? collect(),
            'seriesSummary' => $seriesSummary,
        ])->layout('layouts.app', ['title' => $this->competition->name, 'fullBleed' => true]);
    }

    /**
     * Count titles per team.
     *  - Competitions with a 'final' stage: winner of the final match.
     *  - League-table competitions (no playoff, e.g. Six Nations): top of standings
     *    is the winner. Uses position=1 from the standings table.
     */
    private function buildTitlesByTeam(): \Illuminate\Support\Collection
    {
        $seasonIds = $this->competition->seasons()->pluck('id');

        // Competitions that decide the title by league table (no playoffs)
        $tableOnlyCodes = ['six_nations', 'rugby_championship', 'autumn_internationals'];
        $useTable = in_array($this->competition->code, $tableOnlyCodes, true);

        // Lions Tour uses a 3-test series (best of 3)
        if ($this->competition->code === 'lions_tour') {
            return $this->buildLionsTourWinners();
        }

        $titles = collect();

        if ($useTable) {
            // Top of standings wins
            $winners = \App\Models\Standing::whereIn('season_id', $seasonIds)
                ->where('position', 1)
                ->with(['team', 'season'])
                ->get();

            foreach ($winners as $standing) {
                if (! $standing->team) continue;
                $teamId = $standing->team->id;
                if (! $titles->has($teamId)) {
                    $titles[$teamId] = [
                        'team' => $standing->team,
                        'count' => 0,
                        'seasons' => [],
                    ];
                }
                $entry = $titles[$teamId];
                $entry['count']++;
                $entry['seasons'][] = $standing->season->label;
                $titles[$teamId] = $entry;
            }
        } else {
            // Knockout-decided: winner of the 'final' stage match
            $finals = RugbyMatch::whereIn('season_id', $seasonIds)
                ->where('stage', 'final')
                ->with(['matchTeams.team', 'season'])
                ->get();

            foreach ($finals as $final) {
                $teams = $final->matchTeams;
                if ($teams->count() !== 2) continue;

                $winner = null;
                foreach ($teams as $mt) {
                    $opponent = $teams->firstWhere('team_id', '!=', $mt->team_id);
                    if ($mt->score !== null && $opponent?->score !== null && $mt->score > $opponent->score) {
                        $winner = $mt->team;
                        break;
                    }
                }

                if (! $winner) continue;

                if (! $titles->has($winner->id)) {
                    $titles[$winner->id] = [
                        'team' => $winner,
                        'count' => 0,
                        'seasons' => [],
                    ];
                }
                $entry = $titles[$winner->id];
                $entry['count']++;
                $entry['seasons'][] = $final->season->label;
                $titles[$winner->id] = $entry;
            }
        }

        return $titles->sortByDesc('count');
    }

    /**
     * Build a per-season series summary (tests won per team) for Lions Tour.
     */
    private function buildSeriesSummary(\App\Models\Season $season): ?array
    {
        $tests = RugbyMatch::where('season_id', $season->id)
            ->whereIn('stage', ['first_test', 'second_test', 'third_test'])
            ->with('matchTeams.team')
            ->get();

        if ($tests->isEmpty()) return null;

        $wins = [];
        $teams = [];
        foreach ($tests as $t) {
            $mts = $t->matchTeams;
            if ($mts->count() !== 2) continue;
            foreach ($mts as $mt) {
                $teams[$mt->team_id] = $mt->team;
                $wins[$mt->team_id] = $wins[$mt->team_id] ?? 0;
            }
            $winner = null;
            foreach ($mts as $mt) {
                $opp = $mts->firstWhere('team_id', '!=', $mt->team_id);
                if ($mt->score !== null && $opp?->score !== null && $mt->score > $opp->score) {
                    $winner = $mt->team_id;
                    break;
                }
            }
            if ($winner) $wins[$winner]++;
        }

        arsort($wins);
        $rows = [];
        foreach ($wins as $teamId => $count) {
            $rows[] = ['team' => $teams[$teamId], 'wins' => $count];
        }

        $seriesWinner = null;
        if (count($rows) === 2 && $rows[0]['wins'] !== $rows[1]['wins'] && $rows[0]['wins'] >= 2) {
            $seriesWinner = $rows[0]['team'];
        }

        return [
            'rows' => $rows,
            'tests_played' => $tests->count(),
            'winner' => $seriesWinner,
        ];
    }

    /**
     * Lions Tour series winner — best-of-3 tests tagged first_test/second_test/third_test.
     */
    private function buildLionsTourWinners(): \Illuminate\Support\Collection
    {
        $seasonIds = $this->competition->seasons()->pluck('id');
        $titles = collect();

        foreach ($this->competition->seasons as $season) {
            $tests = RugbyMatch::where('season_id', $season->id)
                ->whereIn('stage', ['first_test', 'second_test', 'third_test'])
                ->with('matchTeams.team')
                ->get();

            if ($tests->count() !== 3) continue;

            // Tally wins by team
            $wins = [];
            foreach ($tests as $t) {
                $mts = $t->matchTeams;
                if ($mts->count() !== 2) continue;
                $winner = null;
                foreach ($mts as $mt) {
                    $opp = $mts->firstWhere('team_id', '!=', $mt->team_id);
                    if ($mt->score !== null && $opp?->score !== null && $mt->score > $opp->score) {
                        $winner = $mt->team;
                        break;
                    }
                }
                if ($winner) {
                    $wins[$winner->id] = ($wins[$winner->id] ?? 0) + 1;
                }
            }

            if (empty($wins)) continue;

            arsort($wins);
            $topCount = reset($wins);
            $topIds = array_keys(array_filter($wins, fn ($v) => $v === $topCount));

            if (count($topIds) !== 1 || $topCount < 2) continue; // Drawn or inconclusive series

            $winnerTeam = \App\Models\Team::find($topIds[0]);
            if (! $winnerTeam) continue;

            if (! $titles->has($winnerTeam->id)) {
                $titles[$winnerTeam->id] = ['team' => $winnerTeam, 'count' => 0, 'seasons' => []];
            }
            $entry = $titles[$winnerTeam->id];
            $entry['count']++;
            $entry['seasons'][] = $season->label;
            $titles[$winnerTeam->id] = $entry;
        }

        return $titles->sortByDesc('count');
    }

    /**
     * Total appearance count per team across all seasons in this competition.
     */
    private function buildAppearancesByTeam(): \Illuminate\Support\Collection
    {
        $seasonIds = $this->competition->seasons()->pluck('id');
        $rows = \App\Models\MatchTeam::join('matches', 'matches.id', '=', 'match_teams.match_id')
            ->whereIn('matches.season_id', $seasonIds)
            ->whereNotNull('match_teams.team_id')
            ->join('teams', 'teams.id', '=', 'match_teams.team_id')
            ->selectRaw('teams.id, teams.name, COUNT(*) as matches_played, COUNT(DISTINCT matches.season_id) as seasons_in')
            ->groupBy('teams.id', 'teams.name')
            ->orderByDesc('matches_played')
            ->get();

        return $rows;
    }
}
