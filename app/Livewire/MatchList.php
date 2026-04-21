<?php

namespace App\Livewire;

use App\Models\Competition;
use App\Models\RugbyMatch;
use Livewire\Component;
use Livewire\WithPagination;

class MatchList extends Component
{
    use WithPagination;

    public string $status = '';
    public string $competition = '';

    public function render()
    {
        $query = RugbyMatch::with(['matchTeams.team', 'season.competition', 'venue']);

        if ($this->status) {
            $query->where('status', $this->status);
        }
        if ($this->competition) {
            $query->whereHas('season.competition', fn ($q) => $q->where('id', $this->competition));
        }

        // Live → upcoming (soonest first) → past (most recent first).
        $now = now();
        $query
            ->orderByRaw("CASE WHEN status = 'live' THEN 0 WHEN kickoff >= ? THEN 1 ELSE 2 END", [$now])
            ->orderByRaw('CASE WHEN kickoff >= ? THEN kickoff END ASC', [$now])
            ->orderByDesc('kickoff');

        return view('livewire.match-list', [
            'matches' => $query->paginate(20),
            'competitions' => Competition::orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => 'Matches']);
    }
}
