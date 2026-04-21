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

        return view('livewire.match-list', [
            'matches' => $query->orderByDesc('kickoff')->paginate(20),
            'competitions' => Competition::orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => 'Matches']);
    }
}
