<?php

namespace App\Livewire;

use App\Models\Player;
use Livewire\Component;
use Livewire\WithPagination;

class PlayerList extends Component
{
    use WithPagination;

    public string $search = '';

    public string $position = '';

    public string $nationality = '';

    public string $sort = 'last_name';

    public string $dir = 'asc';

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingPosition()
    {
        $this->resetPage();
    }

    public function updatingNationality()
    {
        $this->resetPage();
    }

    public function sortBy(string $col): void
    {
        if ($this->sort === $col) {
            $this->dir = $this->dir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $col;
            $this->dir = $col === 'last_name' ? 'asc' : 'desc';
        }
        $this->resetPage();
    }

    public function render()
    {
        $query = Player::where('is_active', true)
            ->with(['contracts' => fn ($q) => $q->where('is_current', true)->with('team')]);

        if ($this->search) {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where('last_name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%");
            });
        }
        if ($this->position) {
            $query->where('position_group', $this->position);
        }
        if ($this->nationality) {
            $query->where('nationality', $this->nationality);
        }

        $allowedSorts = ['last_name', 'position', 'nationality', 'dob', 'weight_kg', 'height_cm'];
        $sort = in_array($this->sort, $allowedSorts) ? $this->sort : 'last_name';
        $query->orderBy($sort, $this->dir === 'desc' ? 'desc' : 'asc');

        return view('livewire.player-list', [
            'players' => $query->paginate(30),
            'nationalities' => Player::where('is_active', true)
                ->select('nationality')->distinct()
                ->whereNotNull('nationality')
                ->where('nationality', '!=', '')
                ->orderBy('nationality')
                ->pluck('nationality')
                ->filter(fn ($n) => strlen(trim($n)) > 1),
            'totalActive' => Player::where('is_active', true)->count(),
        ])->layout('layouts.app', ['title' => 'Players', 'fullBleed' => true]);
    }
}
