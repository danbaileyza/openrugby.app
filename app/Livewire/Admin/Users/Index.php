<?php

namespace App\Livewire\Admin\Users;

use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public string $role = 'all';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingRole(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = User::query()->withCount('teams')->orderBy('name');

        if ($this->role !== 'all') {
            $query->where('role', $this->role);
        }

        if ($this->search !== '') {
            $query->where(function ($q) {
                $q->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('email', 'like', '%'.$this->search.'%');
            });
        }

        return view('livewire.admin.users.index', [
            'users' => $query->paginate(25),
        ])->layout('layouts.app', ['title' => 'Users']);
    }
}
