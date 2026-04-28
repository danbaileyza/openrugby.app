<?php

namespace App\Livewire;

use App\Models\Competition;
use App\Models\Player;
use App\Models\PlayerSeasonStat;
use App\Models\RugbyMatch;
use App\Models\Season;
use App\Models\Standing;
use App\Models\Team;
use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        $recentMatches = RugbyMatch::with(['matchTeams.team', 'season.competition'])
            ->where('status', 'ft')
            ->whereHas('matchTeams', fn ($q) => $q->whereNotNull('team_id'))
            ->orderByDesc('kickoff')
            ->limit(5)
            ->get();

        // Show matches flagged live AND matches whose kickoff is in the last ~3 hours
        // and haven't been marked FT yet (covers "just started, sync hasn't run" state).
        $liveMatches = RugbyMatch::with(['matchTeams.team', 'season.competition'])
            ->where(function ($q) {
                $q->where('status', 'live')
                  ->orWhere(function ($q2) {
                      $q2->whereIn('status', ['scheduled', 'ns'])
                         ->whereBetween('kickoff', [now()->subHours(3), now()]);
                  });
            })
            ->whereHas('matchTeams', fn ($q) => $q->whereNotNull('team_id'))
            ->orderBy('kickoff')
            ->get();

        $upcomingMatches = RugbyMatch::with(['matchTeams.team', 'season.competition'])
            ->where('status', '!=', 'ft')
            ->where('status', '!=', 'live')
            ->where('kickoff', '>=', now())
            ->whereHas('matchTeams', fn ($q) => $q->whereNotNull('team_id'))
            ->orderBy('kickoff')
            ->limit(5)
            ->get();

        // Featured competitions (those with data, ordered by most complete).
        // Preview the season whose data-coverage score is highest — same rule
        // as the Competitions list page — so the % shown on each tile matches
        // what the user will see when they click through. (The old code preferred
        // the current season, which is often in-progress / empty and shows 0%.)
        $featuredCompetitions = Competition::whereHas('seasons.matches')
            ->with(['seasons' => fn ($q) => $q->orderByDesc('completeness_score')->orderByDesc('label')->limit(1)])
            ->withCount(['seasons as match_count' => fn ($q) => $q->join('matches', 'matches.season_id', '=', 'seasons.id')])
            ->orderByDesc('match_count')
            ->limit(8)
            ->get()
            ->filter(fn ($c) => $c->seasons->isNotEmpty());

        // Pick a featured competition for the right-column standings + scorers widgets.
        // Require persisted standings so the widget does not disappear behind an empty
        // current season. Prefer current URC standings, then other current standings,
        // then the latest season with any standings rows.
        $featuredSeason = Season::with('competition')
            ->whereHas('competition', fn ($q) => $q->where('has_standings', true))
            ->whereHas('standings')
            ->when(
                Competition::where('code', 'urc')->exists(),
                fn ($q) => $q->orderByRaw("CASE WHEN (SELECT code FROM competitions WHERE competitions.id = seasons.competition_id) = 'urc' THEN 0 ELSE 1 END")
            )
            ->orderByDesc('is_current')
            ->orderByDesc('start_date')
            ->first();

        $standings = collect();
        $topScorers = collect();
        if ($featuredSeason) {
            $standings = Standing::where('season_id', $featuredSeason->id)
                ->with('team')
                ->orderBy('position')
                ->limit(8)
                ->get();

            $topScorers = PlayerSeasonStat::where('season_id', $featuredSeason->id)
                ->where('stat_key', 'total_points')
                ->with(['player', 'player.contracts.team'])
                ->orderByDesc('stat_value')
                ->limit(5)
                ->get();
        }

        return view('livewire.dashboard', [
            'stats' => [
                'competitions' => Competition::whereHas('seasons.matches')->count(),
                'teams' => Team::count(),
                'players' => Player::where('is_active', true)->count(),
                'matches' => RugbyMatch::count(),
                'completed_matches' => RugbyMatch::where('status', 'ft')->count(),
            ],
            'recentMatches' => $recentMatches,
            'upcomingMatches' => $upcomingMatches,
            'liveMatches' => $liveMatches,
            'featuredCompetitions' => $featuredCompetitions,
            'featuredSeason' => $featuredSeason,
            'standings' => $standings,
            'topScorers' => $topScorers,
        ])->layout('layouts.app', ['title' => 'Dashboard', 'fullBleed' => true]);
    }
}
