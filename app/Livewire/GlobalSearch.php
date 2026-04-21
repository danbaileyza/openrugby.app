<?php

namespace App\Livewire;

use App\Models\Competition;
use App\Models\Player;
use App\Models\Team;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Top-nav search box. Debounced full-text-style lookup across the
 * three entities users navigate to most often: Teams, Competitions,
 * Players. Results rendered as a grouped dropdown; the wire:model
 * is bound to the query string only when the input is actually
 * interacted with (we avoid URL-polluting empty searches).
 */
class GlobalSearch extends Component
{
    #[Url(except: '', history: false)]
    public string $q = '';

    public bool $showResults = false;

    /** Results used by the view — kept as arrays so they serialize cheaply. */
    public array $teams = [];

    public array $competitions = [];

    public array $players = [];

    public int $totalResults = 0;

    public function updatedQ(): void
    {
        $this->search();
    }

    public function focus(): void
    {
        $this->showResults = true;
        if ($this->q !== '') {
            $this->search();
        }
    }

    public function clear(): void
    {
        $this->q = '';
        $this->showResults = false;
        $this->teams = [];
        $this->competitions = [];
        $this->players = [];
        $this->totalResults = 0;
    }

    protected function search(): void
    {
        $term = trim($this->q);
        if (mb_strlen($term) < 2) {
            $this->teams = [];
            $this->competitions = [];
            $this->players = [];
            $this->totalResults = 0;
            $this->showResults = $term !== '';

            return;
        }

        $like = '%'.$term.'%';

        $this->teams = Team::where('name', 'like', $like)
            ->orWhere('short_name', 'like', $like)
            ->orderByRaw("CASE WHEN name LIKE ? THEN 0 ELSE 1 END", [$term.'%'])
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name', 'slug', 'country', 'type', 'logo_url', 'primary_color'])
            ->map(fn ($t) => [
                'name' => $t->name,
                'slug' => $t->slug ?? $t->id,
                'meta' => $t->country ?: ucfirst($t->type ?? ''),
                'color' => $t->primary_color,
                'logo' => $t->logo_url,
            ])
            ->toArray();

        $this->competitions = Competition::where('name', 'like', $like)
            ->orWhere('code', 'like', $like)
            ->orderByRaw("CASE WHEN name LIKE ? THEN 0 ELSE 1 END", [$term.'%'])
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name', 'slug', 'country', 'format', 'level'])
            ->map(fn ($c) => [
                'name' => $c->name,
                'slug' => $c->slug ?? $c->id,
                'meta' => $c->country ?: 'International',
                'level' => $c->level,
            ])
            ->toArray();

        $this->players = Player::where(function ($q) use ($like) {
            $q->where('last_name', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]);
        })
            ->where('is_active', true)
            ->with(['contracts' => fn ($q) => $q->where('is_current', true)->with('team')])
            ->orderByRaw("CASE WHEN last_name LIKE ? THEN 0 ELSE 1 END", [$term.'%'])
            ->orderBy('last_name')
            ->limit(6)
            ->get(['id', 'first_name', 'last_name', 'slug', 'position', 'nationality'])
            ->map(fn ($p) => [
                'name' => $p->first_name.' '.$p->last_name,
                'slug' => $p->slug ?? $p->id,
                'team' => optional(optional($p->contracts->first())->team)->name,
                'pos' => str_replace('_', ' ', $p->position ?? ''),
                'nationality' => $p->nationality,
            ])
            ->toArray();

        $this->totalResults = count($this->teams) + count($this->competitions) + count($this->players);
        $this->showResults = true;
    }

    public function render()
    {
        return view('livewire.global-search');
    }
}
