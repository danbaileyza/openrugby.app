<?php

namespace App\Livewire;

use App\Models\Team;
use Livewire\Component;
use Livewire\WithPagination;

class TeamList extends Component
{
    use WithPagination;

    public string $search = '';

    public string $country = '';

    public string $type = 'all'; // all | club | national | franchise | provincial | invitational

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingCountry()
    {
        $this->resetPage();
    }

    public function updatingType()
    {
        $this->resetPage();
    }

    public function setType(string $type): void
    {
        $this->type = $type;
        $this->resetPage();
    }

    public function render()
    {
        $query = Team::query();

        if ($this->search) {
            $query->where('name', 'like', "%{$this->search}%");
        }
        if ($this->country) {
            $query->where('country', $this->country);
        }
        if ($this->type !== 'all') {
            $query->where('type', $this->type);
        }

        $typeCounts = [
            'all' => Team::count(),
            'club' => Team::where('type', 'club')->count(),
            'national' => Team::where('type', 'national')->count(),
            'franchise' => Team::where('type', 'franchise')->count(),
            'provincial' => Team::where('type', 'provincial')->count(),
            'invitational' => Team::where('type', 'invitational')->count(),
        ];

        return view('livewire.team-list', [
            'teams' => $query->orderBy('name')->paginate(24),
            'countries' => Team::select('country')->distinct()->orderBy('country')->pluck('country')->filter(),
            'typeCounts' => $typeCounts,
        ])->layout('layouts.app', ['title' => 'Teams', 'fullBleed' => true]);
    }
}
