<?php

namespace App\Livewire;

use App\Models\Player;
use App\Models\MatchTeam;
use App\Models\Team;
use Livewire\Component;

class TeamShow extends Component
{
    public Team $team;

    public function mount(Team $team)
    {
        $this->team = $team;
        $this->team->load(['parentTeam', 'subTeams']);
    }

    public function render()
    {
        // Get players with current contracts at this team
        $players = Player::whereHas('contracts', fn ($q) => $q
            ->where('team_id', $this->team->id)
            ->where('is_current', true)
        )->orderBy('position_group')->orderBy('last_name')->get();

        // Get recent matches via match_teams
        $recentMatchIds = MatchTeam::where('team_id', $this->team->id)
            ->pluck('match_id');

        $recentMatches = \App\Models\RugbyMatch::whereIn('id', $recentMatchIds)
            ->with(['season.competition', 'matchTeams.team', 'venue'])
            ->where('status', 'ft')
            ->orderByDesc('kickoff')
            ->limit(20)
            ->get();

        // Win/loss record
        $teamMatchTeams = MatchTeam::where('team_id', $this->team->id)->get();
        $record = [
            'played' => $teamMatchTeams->count(),
            'won' => $teamMatchTeams->where('is_winner', true)->count(),
            'lost' => $teamMatchTeams->where('is_winner', false)->whereNotNull('is_winner')->count(),
            'drawn' => $teamMatchTeams->whereNull('is_winner')->whereNotNull('score')->count(),
        ];

        // Competitions this team plays in
        $competitions = $this->team->seasons()
            ->with('competition')
            ->get()
            ->pluck('competition')
            ->unique('id')
            ->sortBy('name');

        $standings = $this->team->standings()
            ->with('season.competition')
            ->join('seasons', 'seasons.id', '=', 'standings.season_id')
            ->orderByDesc('seasons.label')
            ->limit(10)
            ->select('standings.*')
            ->get();

        return view('livewire.team-show', [
            'players' => $players,
            'recentMatches' => $recentMatches,
            'standings' => $standings,
            'record' => $record,
            'competitions' => $competitions,
        ])->layout('layouts.app', ['title' => $this->team->name, 'fullBleed' => true]);
    }
}
