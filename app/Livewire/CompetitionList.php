<?php

namespace App\Livewire;

use App\Models\Competition;
use App\Models\Season;
use Livewire\Component;
use Livewire\WithPagination;

class CompetitionList extends Component
{
    use WithPagination;

    public string $format = '';

    public string $search = '';

    public string $country = '';

    public string $quality = 'good'; // good (>=50%), all, verified

    public string $level = 'all';    // all, professional, club, school

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingFormat()
    {
        $this->resetPage();
    }

    public function updatingCountry()
    {
        $this->resetPage();
    }

    public function updatingQuality()
    {
        $this->resetPage();
    }

    public function updatingLevel()
    {
        $this->resetPage();
    }

    public function setLevel(string $level): void
    {
        $this->level = $level;
        $this->resetPage();
    }

    public function render()
    {
        $query = Competition::withCount('seasons')
            ->with(['seasons' => fn ($q) => $q->orderByDesc('label')->limit(1)]);

        if ($this->search) {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('country', 'like', "%{$search}%");
            });
        }
        if ($this->format) {
            $query->where('format', $this->format);
        }
        if ($this->country) {
            $query->where('country', $this->country);
        }
        if ($this->level !== 'all') {
            $query->where('level', $this->level);
        }

        // Quality filter — based on best season's completeness score
        // (Use MAX score across seasons rather than latest, since latest may be in-progress)
        if ($this->quality === 'good') {
            $query->whereHas('seasons', fn ($q) => $q->where('completeness_score', '>=', 50));
        } elseif ($this->quality === 'verified') {
            $query->whereHas('seasons', fn ($q) => $q->where('is_verified', true));
        }

        // Replace the default "latest season" preview with "best-scored season"
        // so the displayed progress bar aligns with why it passed the filter
        $query->with(['seasons' => fn ($q) => $q->orderByDesc('completeness_score')->orderByDesc('label')->limit(1)]);

        $levelCounts = [
            'all' => Competition::count(),
            'professional' => Competition::where('level', 'professional')->count(),
            'club' => Competition::where('level', 'club')->count(),
            'school' => Competition::where('level', 'school')->count(),
        ];

        return view('livewire.competition-list', [
            'competitions' => $query->orderBy('name')->paginate(24),
            'countries' => Competition::select('country')->distinct()->orderBy('country')->pluck('country')->filter(),
            'hiddenCount' => Competition::count() - Competition::whereHas('seasons', fn ($q) => $q->where('completeness_score', '>=', 50))->count(),
            'levelCounts' => $levelCounts,
        ])->layout('layouts.app', ['title' => 'Competitions', 'fullBleed' => true]);
    }
}
