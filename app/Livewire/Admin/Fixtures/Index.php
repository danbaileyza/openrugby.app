<?php

namespace App\Livewire\Admin\Fixtures;

use App\Models\Competition;
use App\Models\RugbyMatch;
use App\Models\Season;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $competition_id = 'all';

    public string $season_id = 'all';

    public string $status = 'all';

    public function updatingCompetitionId(): void
    {
        $this->resetPage();
        $this->season_id = 'all';
    }

    public function updatingSeasonId(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = RugbyMatch::query()
            ->with([
                'season.competition',
                'matchTeams.team',
            ])
            ->orderByDesc('kickoff');

        if ($this->season_id !== 'all') {
            $query->where('season_id', $this->season_id);
        } elseif ($this->competition_id !== 'all') {
            $query->whereHas('season', fn ($q) => $q->where('competition_id', $this->competition_id));
        }

        if ($this->status !== 'all') {
            $query->where('status', $this->status);
        }

        $competitions = Competition::orderBy('level')->orderBy('name')->get();
        $seasonsForFilter = $this->competition_id !== 'all'
            ? Season::where('competition_id', $this->competition_id)->orderByDesc('start_date')->get()
            : collect();

        return view('livewire.admin.fixtures.index', [
            'matches' => $query->paginate(25),
            'competitions' => $competitions,
            'seasonsForFilter' => $seasonsForFilter,
        ])->layout('layouts.app', ['title' => 'Fixtures']);
    }
}
