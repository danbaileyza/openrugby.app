<?php

namespace App\Livewire;

use App\Models\Referee;
use Livewire\Component;
use Livewire\WithPagination;

class RefereeList extends Component
{
    use WithPagination;

    public string $search = '';

    public string $nationality = '';

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingNationality()
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = Referee::withCount('matchOfficials');

        if ($this->search) {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where('last_name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%");
            });
        }

        if ($this->nationality) {
            $query->where('nationality', $this->nationality);
        }

        return view('livewire.referee-list', [
            'referees' => $query->orderByDesc('match_officials_count')->orderBy('last_name')->paginate(30),
            'totalReferees' => Referee::count(),
            'nationalities' => Referee::whereNotNull('nationality')
                ->distinct()
                ->orderBy('nationality')
                ->pluck('nationality')
                ->filter(),
        ])->layout('layouts.app', ['title' => 'Referees', 'fullBleed' => true]);
    }
}
