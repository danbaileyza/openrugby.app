<?php

namespace App\Livewire\Admin\Seasons;

use App\Models\Competition;
use Livewire\Component;

class Index extends Component
{
    public Competition $competition;

    public function mount(string $competition): void
    {
        $this->competition = Competition::findOrFail($competition);
    }

    public function render()
    {
        $seasons = $this->competition->seasons()
            ->withCount(['matches', 'teams'])
            ->orderByDesc('start_date')
            ->get();

        return view('livewire.admin.seasons.index', [
            'seasons' => $seasons,
        ])->layout('layouts.app', ['title' => $this->competition->name.' — Seasons']);
    }
}
