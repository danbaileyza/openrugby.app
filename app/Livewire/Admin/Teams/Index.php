<?php

namespace App\Livewire\Admin\Teams;

use App\Models\Team;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public string $type = 'all';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingType(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = Team::query()->orderBy('name');

        if ($this->type !== 'all') {
            $query->where('type', $this->type);
        }

        if ($this->search !== '') {
            $query->where(function ($q) {
                $q->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('short_name', 'like', '%'.$this->search.'%');
            });
        }

        return view('livewire.admin.teams.index', [
            'teams' => $query->paginate(25),
        ])->layout('layouts.app', ['title' => 'Teams']);
    }
}
