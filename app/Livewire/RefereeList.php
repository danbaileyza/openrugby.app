<?php

namespace App\Livewire;

use App\Models\Referee;
use Livewire\Component;
use Livewire\WithPagination;

class RefereeList extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatingSearch()
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

        return view('livewire.referee-list', [
            'referees' => $query->orderBy('last_name')->paginate(30),
        ])->layout('layouts.app', ['title' => 'Referees']);
    }
}
