<?php

namespace App\Livewire\Admin\Competitions;

use App\Models\Competition;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $level = 'all';

    public string $search = '';

    public function updatingLevel()
    {
        $this->resetPage();
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = Competition::query()
            ->withCount('seasons')
            ->orderBy('level')
            ->orderBy('name');

        if ($this->level !== 'all') {
            $query->where('level', $this->level);
        }

        if ($this->search !== '') {
            $query->where(function ($q) {
                $q->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('code', 'like', '%'.$this->search.'%')
                    ->orWhere('grade', 'like', '%'.$this->search.'%');
            });
        }

        return view('livewire.admin.competitions.index', [
            'competitions' => $query->paginate(25),
        ])->layout('layouts.app', ['title' => 'Competitions']);
    }
}
