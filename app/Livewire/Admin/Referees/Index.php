<?php

namespace App\Livewire\Admin\Referees;

use App\Models\Referee;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = Referee::query()->withCount('matchOfficials')->orderBy('last_name')->orderBy('first_name');

        if ($this->search !== '') {
            $query->where(function ($q) {
                $q->where('first_name', 'like', '%'.$this->search.'%')
                    ->orWhere('last_name', 'like', '%'.$this->search.'%')
                    ->orWhere('nationality', 'like', '%'.$this->search.'%');
            });
        }

        return view('livewire.admin.referees.index', [
            'referees' => $query->paginate(25),
        ])->layout('layouts.app', ['title' => 'Referees']);
    }
}
