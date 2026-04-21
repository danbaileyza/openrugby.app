<?php

namespace App\Livewire;

use App\Models\MatchLineup;
use App\Models\PlayerContract;
use App\Models\RugbyMatch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class MatchLineupEntry extends Component
{
    public RugbyMatch $match;

    public string $side = 'home';

    /** @var array<string, array{included:bool, jersey:?int, role:string, captain:bool, position:?string}> */
    public array $entries = [];

    public function mount(string $side = 'home'): void
    {
        $this->side = in_array($side, ['home', 'away'], true) ? $side : 'home';
        $this->ensureAuthorizedForSide();
        $this->loadEntries();
    }

    protected function ensureAuthorizedForSide(): void
    {
        $user = auth()->user();
        $teamSide = $this->match->matchTeams()->where('side', $this->side)->first();

        if (! $user || ! $teamSide || ! $user->canCaptureForTeam($teamSide->team)) {
            throw new AuthorizationException('Not authorised to edit this lineup.');
        }
    }

    public function switchSide(string $side): void
    {
        if (! in_array($side, ['home', 'away'], true)) {
            return;
        }
        $this->side = $side;
        $this->ensureAuthorizedForSide();
        $this->loadEntries();
    }

    private function loadEntries(): void
    {
        $teamSide = $this->match->matchTeams()->where('side', $this->side)->first();
        if (! $teamSide) {
            $this->entries = [];

            return;
        }

        $squad = PlayerContract::where('team_id', $teamSide->team_id)
            ->where('is_current', true)
            ->with('player')
            ->get()
            ->filter(fn ($c) => $c->player);

        $existing = MatchLineup::where('match_id', $this->match->id)
            ->where('team_id', $teamSide->team_id)
            ->get()
            ->keyBy('player_id');

        $this->entries = [];
        foreach ($squad as $contract) {
            $pid = $contract->player_id;
            $line = $existing->get($pid);
            $this->entries[$pid] = [
                'included' => (bool) $line,
                'jersey' => $line?->jersey_number,
                'role' => $line?->role ?? 'starter',
                'captain' => (bool) ($line?->captain ?? false),
                'position' => $line?->position,
                'first_name' => $contract->player->first_name,
                'last_name' => $contract->player->last_name,
                'default_position' => $contract->player->position,
            ];
        }

        // Sort: included first (by jersey), then rest by last name
        uasort($this->entries, function ($a, $b) {
            if ($a['included'] !== $b['included']) {
                return $a['included'] ? -1 : 1;
            }
            if ($a['included']) {
                return ($a['jersey'] ?? 99) <=> ($b['jersey'] ?? 99);
            }

            return strcmp($a['last_name'], $b['last_name']);
        });
    }

    public function save(): void
    {
        $this->ensureAuthorizedForSide();

        $teamSide = $this->match->matchTeams()->where('side', $this->side)->first();
        if (! $teamSide) {
            return;
        }

        // Validate: every included player has a jersey number (1-99)
        $errors = [];
        $captains = 0;
        foreach ($this->entries as $pid => $entry) {
            if (! $entry['included']) {
                continue;
            }
            $jersey = (int) ($entry['jersey'] ?? 0);
            if ($jersey < 1 || $jersey > 99) {
                $errors["entries.{$pid}.jersey"] = 'Jersey number required (1-99).';
            }
            if (! empty($entry['captain'])) {
                $captains++;
            }
        }
        if ($captains > 1) {
            $errors['entries.captain'] = 'Only one captain allowed.';
        }
        if (! empty($errors)) {
            foreach ($errors as $key => $msg) {
                $this->addError($key, $msg);
            }

            return;
        }

        DB::transaction(function () use ($teamSide) {
            MatchLineup::where('match_id', $this->match->id)
                ->where('team_id', $teamSide->team_id)
                ->delete();

            foreach ($this->entries as $pid => $entry) {
                if (! $entry['included']) {
                    continue;
                }

                MatchLineup::create([
                    'match_id' => $this->match->id,
                    'team_id' => $teamSide->team_id,
                    'player_id' => $pid,
                    'jersey_number' => (int) $entry['jersey'],
                    'role' => $entry['role'] ?: 'starter',
                    'position' => $entry['position'] ?: null,
                    'captain' => (bool) ($entry['captain'] ?? false),
                ]);
            }
        });

        session()->flash('message', 'Lineup saved.');
        $this->loadEntries();
    }

    public function render()
    {
        $home = $this->match->matchTeams->firstWhere('side', 'home');
        $away = $this->match->matchTeams->firstWhere('side', 'away');
        $activeTeam = $this->side === 'home' ? $home : $away;
        $canEditOther = auth()->user()?->isAdmin() || ($this->side === 'home'
            ? auth()->user()?->canCaptureForTeam($away?->team)
            : auth()->user()?->canCaptureForTeam($home?->team));

        return view('livewire.match-lineup-entry', [
            'home' => $home,
            'away' => $away,
            'activeTeam' => $activeTeam,
            'canEditOther' => $canEditOther,
        ])->layout('layouts.app', ['title' => 'Lineup — '.$this->match->matchTeams->firstWhere('side', $this->side)?->team->name]);
    }
}
