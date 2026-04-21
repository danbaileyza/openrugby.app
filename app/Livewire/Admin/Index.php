<?php

namespace App\Livewire\Admin;

use App\Models\Competition;
use App\Models\RugbyMatch;
use App\Models\Team;
use App\Models\User;
use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        return view('livewire.admin.index', [
            'stats' => [
                'competitions' => Competition::count(),
                'schoolCompetitions' => Competition::where('level', Competition::LEVEL_SCHOOL)->count(),
                'clubCompetitions' => Competition::where('level', Competition::LEVEL_CLUB)->count(),
                'teams' => Team::count(),
                'matches' => RugbyMatch::count(),
                'users' => User::count(),
            ],
        ])->layout('layouts.app', ['title' => 'Admin']);
    }
}
