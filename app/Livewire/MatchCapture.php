<?php

namespace App\Livewire;

use App\Models\MatchEvent;
use App\Models\RugbyMatch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class MatchCapture extends Component
{
    public RugbyMatch $match;

    public string $side = 'home';

    public int $minute = 0;

    public ?string $playerId = null;

    public bool $showSubPanel = false;

    public ?string $subOffPlayerId = null;

    public ?string $subOnPlayerId = null;

    public ?string $editingEventId = null;

    public int $editMinute = 0;

    public string $editType = 'try';

    public ?string $editPlayerId = null;

    /**
     * Scoring event types + their point values. Null = no points (cards etc.).
     */
    public const EVENT_POINTS = [
        'try' => 5,
        'conversion' => 2,
        'penalty_goal' => 3,
        'drop_goal' => 3,
        'conversion_miss' => 0,
        'penalty_miss' => 0,
        'penalty_conceded' => 0,
        'yellow_card' => 0,
        'red_card' => 0,
    ];

    public function mount(): void
    {
        $this->ensureAuthorized();
        $this->match->load(['matchTeams.team', 'events.player', 'events.team', 'lineups.player']);
        $this->minute = $this->liveClockMinute();
    }

    protected function ensureAuthorized(): void
    {
        $user = auth()->user();
        if (! $user || ! $user->canCaptureForMatch($this->match)) {
            throw new AuthorizationException('Not authorised to capture for this match.');
        }
    }

    public function liveClockMinute(): int
    {
        if ($this->match->status !== 'live' || ! $this->match->live_started_at) {
            return $this->minute;
        }

        $elapsed = (int) abs(now()->diffInMinutes($this->match->live_started_at, false));

        return max(0, min(120, $elapsed));
    }

    public function syncToLiveClock(): void
    {
        if ($this->match->live_started_at) {
            $elapsed = (int) abs(now()->diffInMinutes($this->match->live_started_at, false));
            $this->minute = max(0, min(120, $elapsed));
        }
    }

    public function startMatch(): void
    {
        $this->ensureAuthorized();

        if ($this->match->status !== 'scheduled') {
            return;
        }

        $this->match->update([
            'status' => 'live',
            'live_started_at' => now(),
            'score_source' => auth()->user()->isAdmin() ? RugbyMatch::SOURCE_ADMIN : RugbyMatch::SOURCE_TEAM_USER,
            'captured_by_user_id' => auth()->id(),
            'captured_at' => now(),
        ]);
        $this->match->refresh();
        $this->minute = 0;
    }

    public function endMatch(): void
    {
        $this->ensureAuthorized();

        $this->recomputeScores();
        $this->match->update(['status' => 'ft']);
        $this->match->refresh();

        session()->flash('message', 'Match ended.');
    }

    public function setSide(string $side): void
    {
        if (in_array($side, ['home', 'away'], true)) {
            $this->side = $side;
            $this->playerId = null;
        }
    }

    public function adjustMinute(int $delta): void
    {
        $this->minute = max(0, min(120, $this->minute + $delta));
    }

    public function captureEvent(string $type): void
    {
        if (! array_key_exists($type, self::EVENT_POINTS)) {
            return;
        }
        if ($this->match->status !== 'live') {
            return;
        }

        $this->ensureAuthorized();

        $teamSide = $this->match->matchTeams->firstWhere('side', $this->side);
        if (! $teamSide) {
            return;
        }

        DB::transaction(function () use ($type, $teamSide) {
            MatchEvent::create([
                'match_id' => $this->match->id,
                'team_id' => $teamSide->team_id,
                'player_id' => $this->playerId ?: null,
                'minute' => $this->minute,
                'type' => $type,
            ]);

            $this->match->update([
                'score_source' => auth()->user()->isAdmin() ? RugbyMatch::SOURCE_ADMIN : RugbyMatch::SOURCE_TEAM_USER,
                'captured_by_user_id' => auth()->id(),
                'captured_at' => now(),
            ]);

            $this->recomputeScores();
        });

        $this->match->refresh();
        $this->match->load(['matchTeams.team', 'events.player', 'events.team', 'lineups.player']);
        $this->playerId = null;

        $this->dispatch('event-captured');
    }

    public function undoLast(): void
    {
        $this->ensureAuthorized();

        $last = $this->match->events()->latest('created_at')->first();
        if ($last) {
            $last->delete();
            $this->recomputeScores();
            $this->match->refresh();
            $this->match->load(['matchTeams.team', 'events.player', 'events.team', 'lineups.player']);
        }
    }

    public function toggleSubPanel(): void
    {
        $this->showSubPanel = ! $this->showSubPanel;
        $this->subOffPlayerId = null;
        $this->subOnPlayerId = null;
    }

    public function captureSubstitution(): void
    {
        if ($this->match->status !== 'live') {
            return;
        }
        $this->ensureAuthorized();

        $this->validate([
            'subOffPlayerId' => 'required|uuid|different:subOnPlayerId',
            'subOnPlayerId' => 'required|uuid',
        ], [
            'subOffPlayerId.required' => 'Pick the player coming off.',
            'subOnPlayerId.required' => 'Pick the player coming on.',
            'subOffPlayerId.different' => 'Off and on players must differ.',
        ]);

        $teamSide = $this->match->matchTeams->firstWhere('side', $this->side);
        if (! $teamSide) {
            return;
        }

        DB::transaction(function () use ($teamSide) {
            MatchEvent::create([
                'match_id' => $this->match->id,
                'team_id' => $teamSide->team_id,
                'player_id' => $this->subOffPlayerId,
                'minute' => $this->minute,
                'type' => 'substitution_off',
                'meta' => ['replaced_by_player_id' => $this->subOnPlayerId],
            ]);
            MatchEvent::create([
                'match_id' => $this->match->id,
                'team_id' => $teamSide->team_id,
                'player_id' => $this->subOnPlayerId,
                'minute' => $this->minute,
                'type' => 'substitution_on',
                'meta' => ['replaces_player_id' => $this->subOffPlayerId],
            ]);

            $this->match->update([
                'score_source' => auth()->user()->isAdmin() ? RugbyMatch::SOURCE_ADMIN : RugbyMatch::SOURCE_TEAM_USER,
                'captured_by_user_id' => auth()->id(),
                'captured_at' => now(),
            ]);
        });

        $this->match->refresh();
        $this->match->load(['matchTeams.team', 'events.player', 'events.team', 'lineups.player']);

        $this->showSubPanel = false;
        $this->subOffPlayerId = null;
        $this->subOnPlayerId = null;
    }

    public function startEdit(string $eventId): void
    {
        $this->ensureAuthorized();

        $event = $this->match->events()->where('id', $eventId)->first();
        if (! $event) {
            return;
        }

        $this->editingEventId = $event->id;
        $this->editMinute = (int) $event->minute;
        $this->editType = $event->type;
        $this->editPlayerId = $event->player_id;
        $this->showSubPanel = false;
    }

    public function cancelEdit(): void
    {
        $this->editingEventId = null;
        $this->editMinute = 0;
        $this->editType = 'try';
        $this->editPlayerId = null;
    }

    public function saveEdit(): void
    {
        if (! $this->editingEventId) {
            return;
        }
        $this->ensureAuthorized();

        $event = $this->match->events()->where('id', $this->editingEventId)->first();
        if (! $event) {
            $this->cancelEdit();

            return;
        }

        DB::transaction(function () use ($event) {
            $event->update([
                'minute' => max(0, min(120, $this->editMinute)),
                'type' => $this->editType,
                'player_id' => $this->editPlayerId ?: null,
            ]);

            $this->recomputeScores();
        });

        $this->match->refresh();
        $this->match->load(['matchTeams.team', 'events.player', 'events.team', 'lineups.player']);
        $this->cancelEdit();
    }

    public function deleteEvent(string $eventId): void
    {
        $this->ensureAuthorized();

        $event = $this->match->events()->where('id', $eventId)->first();
        if ($event) {
            $event->delete();
            $this->recomputeScores();
            $this->match->refresh();
            $this->match->load(['matchTeams.team', 'events.player', 'events.team', 'lineups.player']);
        }
    }

    private function recomputeScores(): void
    {
        $events = MatchEvent::where('match_id', $this->match->id)->get();

        foreach ($this->match->matchTeams as $mt) {
            $teamEvents = $events->where('team_id', $mt->team_id);

            $tries = $teamEvents->where('type', 'try')->count();
            $conversions = $teamEvents->where('type', 'conversion')->count();
            $penalties = $teamEvents->where('type', 'penalty_goal')->count();
            $drops = $teamEvents->where('type', 'drop_goal')->count();

            $score = ($tries * self::EVENT_POINTS['try'])
                + ($conversions * self::EVENT_POINTS['conversion'])
                + ($penalties * self::EVENT_POINTS['penalty_goal'])
                + ($drops * self::EVENT_POINTS['drop_goal']);

            $mt->update([
                'score' => $score,
                'tries' => $tries,
                'conversions' => $conversions,
                'penalties_kicked' => $penalties,
                'drop_goals' => $drops,
            ]);
        }

        // Determine winner
        $home = $this->match->matchTeams->firstWhere('side', 'home');
        $away = $this->match->matchTeams->firstWhere('side', 'away');
        if ($home && $away && $home->score !== null && $away->score !== null) {
            $home->refresh();
            $away->refresh();
            if ($home->score > $away->score) {
                $home->update(['is_winner' => true]);
                $away->update(['is_winner' => false]);
            } elseif ($away->score > $home->score) {
                $home->update(['is_winner' => false]);
                $away->update(['is_winner' => true]);
            } else {
                $home->update(['is_winner' => null]);
                $away->update(['is_winner' => null]);
            }
        }
    }

    public function render()
    {
        $home = $this->match->matchTeams->firstWhere('side', 'home');
        $away = $this->match->matchTeams->firstWhere('side', 'away');
        $activeTeam = $this->side === 'home' ? $home : $away;

        $lineupEntries = collect();
        $starters = collect();
        $reserves = collect();
        $lineupSource = 'none';

        if ($activeTeam) {
            $lineupEntries = $this->match->lineups
                ->where('team_id', $activeTeam->team_id)
                ->filter(fn ($l) => $l->player)
                ->sortBy(fn ($l) => ($l->role === 'replacement' ? 1 : 0).'-'.str_pad((string) ($l->jersey_number ?? 99), 3, '0', STR_PAD_LEFT))
                ->values();

            if ($lineupEntries->isNotEmpty()) {
                $lineupSource = 'lineup';
                $starters = $lineupEntries->where('role', '!=', 'replacement')->values();
                $reserves = $lineupEntries->where('role', 'replacement')->values();
            } else {
                // Fallback: current contracts, wrapped to look like lineup entries
                $lineupEntries = $activeTeam->team->playerContracts()
                    ->where('is_current', true)
                    ->with('player')
                    ->get()
                    ->filter(fn ($c) => $c->player)
                    ->map(fn ($c) => (object) [
                        'player' => $c->player,
                        'jersey_number' => null,
                        'role' => 'unknown',
                    ])
                    ->sortBy(fn ($e) => $e->player->last_name)
                    ->values();
                if ($lineupEntries->isNotEmpty()) {
                    $lineupSource = 'contracts';
                }
            }
        }

        $recentEvents = $this->match->events->sortByDesc('created_at')->take(15);

        return view('livewire.match-capture', [
            'home' => $home,
            'away' => $away,
            'activeTeam' => $activeTeam,
            'lineupEntries' => $lineupEntries,
            'starters' => $starters,
            'reserves' => $reserves,
            'lineupSource' => $lineupSource,
            'recentEvents' => $recentEvents,
        ])->layout('layouts.app', ['title' => 'Capture Scores']);
    }
}
