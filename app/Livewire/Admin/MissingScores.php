<?php

namespace App\Livewire\Admin;

use App\Models\MatchTeam;
use App\Models\RugbyMatch;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin queue for matches that should have scores but don't yet.
 * Sources sometimes lag (especially school feeds for late-Sunday games),
 * so this gives an admin a one-screen punch list with inline scoring.
 */
class MissingScores extends Component
{
    use WithPagination;

    #[Url(except: '14', history: false)]
    public int $days = 14;

    #[Url(except: '', history: false)]
    public string $competition = '';

    /** Inline scores keyed by match id — wire:model binds these. */
    public array $homeScores = [];

    public array $awayScores = [];

    public function updatedDays(): void
    {
        $this->resetPage();
    }

    public function updatedCompetition(): void
    {
        $this->resetPage();
    }

    public function save(string $matchId): void
    {
        $home = $this->homeScores[$matchId] ?? null;
        $away = $this->awayScores[$matchId] ?? null;

        if ($home === null || $away === null || $home === '' || $away === '') {
            return;
        }

        $home = (int) $home;
        $away = (int) $away;

        $match = RugbyMatch::with('matchTeams')->find($matchId);
        if (! $match) {
            return;
        }

        $homeMt = $match->matchTeams->firstWhere('side', 'home');
        $awayMt = $match->matchTeams->firstWhere('side', 'away');
        if (! $homeMt || ! $awayMt) {
            return;
        }

        $isDraw = $home === $away;
        MatchTeam::where('id', $homeMt->id)->update([
            'score' => $home,
            'is_winner' => ! $isDraw && $home > $away,
        ]);
        MatchTeam::where('id', $awayMt->id)->update([
            'score' => $away,
            'is_winner' => ! $isDraw && $away > $home,
        ]);
        $match->update(['status' => 'ft']);

        unset($this->homeScores[$matchId], $this->awayScores[$matchId]);
        $this->dispatch('score-saved', matchId: $matchId);
    }

    public function render()
    {
        $cutoff = now()->subDays($this->days);

        $query = RugbyMatch::with(['matchTeams.team', 'season.competition'])
            ->where('status', 'scheduled')
            ->where('kickoff', '>=', $cutoff)
            ->where('kickoff', '<=', now());

        if ($this->competition !== '') {
            $query->whereHas('season.competition', fn ($q) => $q->where('id', $this->competition));
        }

        return view('livewire.admin.missing-scores', [
            'matches' => $query->orderByDesc('kickoff')->paginate(25),
            'competitions' => \App\Models\Competition::orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => 'Missing scores']);
    }
}
