<div
    class="max-w-2xl mx-auto"
    @if(($match->status === 'live') && $match->live_started_at)
    x-data="{
        start: new Date('{{ $match->live_started_at->toIso8601String() }}').getTime(),
        now: Date.now(),
        autoSync: true,
        lastSyncedMinute: {{ $this->liveClockMinute() }},
        get currentMinute() { return Math.max(0, Math.floor((this.now - this.start) / 60000)); },
        get mmss() {
            const e = Math.max(0, this.now - this.start);
            const m = Math.floor(e / 60000);
            const s = Math.floor((e % 60000) / 1000);
            return m + ':' + String(s).padStart(2, '0');
        },
        tick() {
            this.now = Date.now();
            if (this.autoSync) {
                const m = this.currentMinute;
                if (m !== this.lastSyncedMinute) {
                    this.lastSyncedMinute = m;
                    $wire.set('minute', m, false);
                }
            }
        },
        syncNow() {
            this.autoSync = true;
            this.lastSyncedMinute = this.currentMinute;
            $wire.set('minute', this.currentMinute, false);
        },
        pauseSync() { this.autoSync = false; },
    }"
    x-init="tick(); setInterval(() => tick(), 1000)"
    @endif
>
    {{-- Breadcrumb --}}
    <div class="mb-4 text-sm text-gray-400">
        <a href="{{ route('matches.show', $match) }}" class="hover:text-white transition">&larr; Match detail</a>
    </div>

    @if(session()->has('message'))
        <div class="mb-4 rounded-md bg-emerald-900/40 border border-emerald-800 px-4 py-2 text-sm text-emerald-300">
            {{ session('message') }}
        </div>
    @endif

    @php
        $statusLabel = match($match->status) {
            'scheduled' => 'Not started',
            'live' => 'LIVE',
            'ft' => 'Full Time',
            'postponed' => 'Postponed',
            'cancelled' => 'Cancelled',
            'abandoned' => 'Abandoned',
            default => ucfirst($match->status),
        };
        $isLive = $match->status === 'live';
        $isScheduled = $match->status === 'scheduled';
    @endphp

    {{-- Scoreboard --}}
    <div class="rounded-xl bg-gray-900 border border-gray-800 p-4 mb-4" @if($isLive) wire:poll.30s @endif>
        <div class="flex items-center justify-center gap-4">
            <div class="flex-1 text-right">
                <div class="text-xs text-emerald-400 uppercase tracking-wider mb-1">Home</div>
                <div class="text-sm font-semibold text-white truncate">{{ $home?->team->name ?? 'TBD' }}</div>
            </div>
            <div class="rounded-lg bg-gray-800 px-5 py-3 text-center">
                <div class="text-3xl font-mono font-bold text-emerald-400">
                    {{ $home?->score ?? 0 }} <span class="text-gray-600">-</span> {{ $away?->score ?? 0 }}
                </div>
                <div class="text-[10px] uppercase tracking-wider mt-1 font-semibold flex items-center justify-center gap-1.5">
                    @if($isLive && $match->live_started_at)
                        <span class="inline-block w-1.5 h-1.5 rounded-full bg-red-500 animate-pulse"></span>
                        <span class="text-red-400">{{ $statusLabel }}</span>
                        <span class="text-gray-400 font-mono tabular-nums" x-text="'· ' + mmss">· {{ $this->liveClockMinute() }}:00</span>
                    @else
                        <span class="text-gray-500">{{ $statusLabel }}</span>
                    @endif
                </div>
            </div>
            <div class="flex-1 text-left">
                <div class="text-xs text-blue-400 uppercase tracking-wider mb-1">Away</div>
                <div class="text-sm font-semibold text-white truncate">{{ $away?->team->name ?? 'TBD' }}</div>
            </div>
        </div>
    </div>

    {{-- Status control --}}
    @if($isScheduled)
        <button type="button" wire:click="startMatch" class="w-full rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-base font-semibold py-4 mb-4 transition flex items-center justify-center gap-2">
            <span class="inline-block w-2 h-2 rounded-full bg-white"></span> Start Match
        </button>
    @elseif($match->status === 'ft')
        <div class="rounded-xl bg-gray-900 border border-gray-800 px-4 py-3 mb-4 text-center text-sm text-gray-400">
            Match ended &middot; scores are final.
        </div>
    @endif

    {{-- Team selector --}}
    <div class="grid grid-cols-2 gap-3 mb-4">
        <button
            type="button"
            wire:click="setSide('home')"
            @class([
                'rounded-xl px-4 py-3 text-sm font-semibold transition border-2',
                'bg-emerald-600 border-emerald-500 text-white' => $side === 'home',
                'bg-gray-900 border-gray-800 text-gray-400 hover:bg-gray-800' => $side !== 'home',
            ])
        >
            {{ $home?->team->short_name ?? $home?->team->name ?? 'Home' }}
        </button>
        <button
            type="button"
            wire:click="setSide('away')"
            @class([
                'rounded-xl px-4 py-3 text-sm font-semibold transition border-2',
                'bg-blue-600 border-blue-500 text-white' => $side === 'away',
                'bg-gray-900 border-gray-800 text-gray-400 hover:bg-gray-800' => $side !== 'away',
            ])
        >
            {{ $away?->team->short_name ?? $away?->team->name ?? 'Away' }}
        </button>
    </div>

    {{-- Minute + player picker --}}
    <div class="rounded-xl bg-gray-900 border border-gray-800 p-4 mb-4 space-y-3">
        <div class="flex items-center gap-2">
            <label class="text-xs text-gray-500 uppercase tracking-wider w-16">Minute</label>
            <button type="button" wire:click="adjustMinute(-1)" @if($isLive)x-on:click="pauseSync()"@endif class="rounded-md bg-gray-800 hover:bg-gray-700 w-10 h-10 text-lg text-gray-300 transition">−</button>
            <input type="number" wire:model.live="minute" @if($isLive)x-on:input="pauseSync()"@endif min="0" max="120" class="flex-1 rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-center text-2xl font-mono font-bold text-white focus:border-emerald-500 focus:outline-none">
            <button type="button" wire:click="adjustMinute(1)" @if($isLive)x-on:click="pauseSync()"@endif class="rounded-md bg-gray-800 hover:bg-gray-700 w-10 h-10 text-lg text-gray-300 transition">+</button>
            @if($isLive)
                <button
                    type="button"
                    x-on:click="syncNow()"
                    title="Sync to live clock"
                    :class="autoSync ? 'bg-gray-800' : 'bg-amber-700/40 border border-amber-600'"
                    class="rounded-md hover:bg-gray-700 w-10 h-10 text-sm text-gray-300 transition"
                >↻</button>
            @endif
        </div>
        @if($isLive)
            <div x-show="!autoSync" style="display:none" class="text-xs text-amber-400 -mt-2">
                Manual minute override — tap ↻ to resume live clock.
            </div>
        @endif

        <div>
            <label class="block text-xs text-gray-500 uppercase tracking-wider mb-1">
                Player <span class="text-gray-600 normal-case">(optional)</span>
                @if($lineupSource === 'contracts')
                    <span class="text-[10px] text-amber-400 normal-case ml-1">· no lineup, showing contracts</span>
                @endif
            </label>
            @if($lineupEntries->isEmpty())
                <div class="rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-gray-500 italic">
                    No roster for {{ $activeTeam?->team->name ?? 'this team' }}. Events will save without a scorer.
                </div>
            @else
                <select wire:model.live="playerId" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                    <option value="">— Unknown scorer —</option>
                    @foreach($lineupEntries as $entry)
                        <option value="{{ $entry->player->id }}">
                            @if($entry->jersey_number)#{{ $entry->jersey_number }} @endif
                            {{ $entry->player->first_name }} {{ $entry->player->last_name }}
                            @if($entry->role === 'replacement') (R)@endif
                        </option>
                    @endforeach
                </select>
            @endif
        </div>
    </div>

    {{-- Event buttons --}}
    <div class="mb-4">
        <div class="text-xs text-gray-500 uppercase tracking-wider mb-2">
            @if($isLive)
                Tap to capture
            @else
                Start the match to capture events
            @endif
        </div>
        <div @class([
            'grid grid-cols-2 md:grid-cols-4 gap-2',
            'opacity-40 pointer-events-none' => ! $isLive,
        ])>
            <button type="button" wire:click="captureEvent('try')" class="rounded-xl bg-emerald-700/30 hover:bg-emerald-700/50 border border-emerald-700 px-3 py-4 text-left transition">
                <div class="text-2xl mb-1">🏉</div>
                <div class="text-sm font-semibold text-white">Try</div>
                <div class="text-[10px] text-emerald-300">+5 pts</div>
            </button>
            <button type="button" wire:click="captureEvent('conversion')" class="rounded-xl bg-blue-700/30 hover:bg-blue-700/50 border border-blue-700 px-3 py-4 text-left transition">
                <div class="text-2xl mb-1">🥅</div>
                <div class="text-sm font-semibold text-white">Conversion</div>
                <div class="text-[10px] text-blue-300">+2 pts</div>
            </button>
            <button type="button" wire:click="captureEvent('penalty_goal')" class="rounded-xl bg-blue-700/30 hover:bg-blue-700/50 border border-blue-700 px-3 py-4 text-left transition">
                <div class="text-2xl mb-1">🎯</div>
                <div class="text-sm font-semibold text-white">Pen Kick</div>
                <div class="text-[10px] text-blue-300">+3 pts</div>
            </button>
            <button type="button" wire:click="captureEvent('drop_goal')" class="rounded-xl bg-purple-700/30 hover:bg-purple-700/50 border border-purple-700 px-3 py-4 text-left transition">
                <div class="text-2xl mb-1">🎯</div>
                <div class="text-sm font-semibold text-white">Drop Goal</div>
                <div class="text-[10px] text-purple-300">+3 pts</div>
            </button>
            <button type="button" wire:click="captureEvent('conversion_miss')" class="rounded-xl bg-gray-800 hover:bg-gray-700 border border-gray-700 px-3 py-4 text-left transition">
                <div class="text-2xl mb-1">✕</div>
                <div class="text-sm font-semibold text-gray-300">Conv Missed</div>
            </button>
            <button type="button" wire:click="captureEvent('penalty_miss')" class="rounded-xl bg-gray-800 hover:bg-gray-700 border border-gray-700 px-3 py-4 text-left transition">
                <div class="text-2xl mb-1">✕</div>
                <div class="text-sm font-semibold text-gray-300">Pen Missed</div>
            </button>
            <button type="button" wire:click="captureEvent('penalty_conceded')" class="rounded-xl bg-amber-700/20 hover:bg-amber-700/40 border border-amber-700/60 px-3 py-4 text-left transition">
                <div class="text-2xl mb-1">🚩</div>
                <div class="text-sm font-semibold text-white">Penalty</div>
                <div class="text-[10px] text-amber-300">conceded</div>
            </button>
            <button type="button" wire:click="captureEvent('yellow_card')" class="rounded-xl bg-yellow-600/30 hover:bg-yellow-600/50 border border-yellow-600 px-3 py-4 text-left transition">
                <div class="text-2xl mb-1">🟨</div>
                <div class="text-sm font-semibold text-white">Yellow</div>
            </button>
            <button type="button" wire:click="captureEvent('red_card')" class="rounded-xl bg-red-700/30 hover:bg-red-700/50 border border-red-700 px-3 py-4 text-left transition">
                <div class="text-2xl mb-1">🟥</div>
                <div class="text-sm font-semibold text-white">Red</div>
            </button>
            <button type="button" wire:click="toggleSubPanel" class="rounded-xl bg-indigo-700/30 hover:bg-indigo-700/50 border border-indigo-700 px-3 py-4 text-left transition">
                <div class="text-2xl mb-1">🔄</div>
                <div class="text-sm font-semibold text-white">Sub</div>
            </button>
        </div>
    </div>

    {{-- Substitution panel --}}
    @if($showSubPanel && $isLive)
        <div class="rounded-xl bg-gray-900 border border-indigo-700/50 p-4 mb-4">
            <div class="flex items-center justify-between mb-3">
                <div class="text-xs text-indigo-400 uppercase tracking-wider font-semibold">Substitution · {{ $activeTeam?->team->name ?? '' }}</div>
                <button type="button" wire:click="toggleSubPanel" class="text-gray-500 hover:text-gray-300 text-sm">✕</button>
            </div>
            @if($starters->isEmpty() || $reserves->isEmpty())
                <div class="text-sm text-amber-400">
                    Lineup missing starters or reserves for this team. Enter the lineup first (starters with role ≠ replacement, bench with role = replacement).
                </div>
            @else
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs text-gray-500 uppercase tracking-wider mb-1">Off (leaving pitch)</label>
                        <select wire:model="subOffPlayerId" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-indigo-500 focus:outline-none">
                            <option value="">— Select player —</option>
                            @foreach($starters as $entry)
                                <option value="{{ $entry->player->id }}">
                                    @if($entry->jersey_number)#{{ $entry->jersey_number }} @endif
                                    {{ $entry->player->first_name }} {{ $entry->player->last_name }}
                                </option>
                            @endforeach
                        </select>
                        @error('subOffPlayerId') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 uppercase tracking-wider mb-1">On (entering pitch)</label>
                        <select wire:model="subOnPlayerId" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-indigo-500 focus:outline-none">
                            <option value="">— Select replacement —</option>
                            @foreach($reserves as $entry)
                                <option value="{{ $entry->player->id }}">
                                    @if($entry->jersey_number)#{{ $entry->jersey_number }} @endif
                                    {{ $entry->player->first_name }} {{ $entry->player->last_name }}
                                </option>
                            @endforeach
                        </select>
                        @error('subOnPlayerId') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex gap-2 pt-1">
                        <button type="button" wire:click="captureSubstitution" class="flex-1 rounded-md bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold py-2 transition">
                            Confirm Substitution · {{ $minute }}'
                        </button>
                        <button type="button" wire:click="toggleSubPanel" class="rounded-md bg-gray-800 hover:bg-gray-700 px-4 text-sm text-gray-400 transition">Cancel</button>
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- Recent events --}}
    <div class="rounded-xl bg-gray-900 border border-gray-800 overflow-hidden mb-4">
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-800">
            <h3 class="text-xs uppercase tracking-wider text-gray-500 font-semibold">Recent events</h3>
            @if($recentEvents->isNotEmpty())
                <button type="button" wire:click="undoLast" wire:confirm="Undo the most recent event?" class="text-xs text-amber-400 hover:text-amber-300 transition">Undo last</button>
            @endif
        </div>
        @if($recentEvents->isEmpty())
            <div class="px-4 py-6 text-sm text-gray-500 text-center">No events captured yet.</div>
        @else
            <div class="divide-y divide-gray-800">
                @foreach($recentEvents as $event)
                    @php
                        $isHome = $event->team_id === $home?->team_id;
                        $icon = match($event->type) {
                            'try' => '🏉', 'conversion', 'penalty_goal', 'drop_goal' => '🥅',
                            'conversion_miss', 'penalty_miss' => '✕',
                            'penalty_conceded' => '🚩',
                            'yellow_card' => '🟨', 'red_card' => '🟥',
                            'substitution_on' => '↑', 'substitution_off' => '↓',
                            default => '•',
                        };
                        $isEditing = $editingEventId === $event->id;
                        $teamLineup = $isHome
                            ? $home?->team?->matchLineupForEdit ?? null
                            : $away?->team?->matchLineupForEdit ?? null;
                    @endphp
                    <div class="px-4 py-2">
                        <div class="flex items-center gap-3">
                            <span class="w-10 text-right font-mono text-sm text-gray-400">{{ $event->minute }}'</span>
                            <span>{{ $icon }}</span>
                            <button type="button" wire:click="startEdit('{{ $event->id }}')" class="flex-1 min-w-0 text-left hover:text-emerald-400 transition">
                                <div class="text-sm text-white truncate">
                                    @if($event->player)
                                        {{ $event->player->first_name }} {{ $event->player->last_name }}
                                    @else
                                        <span class="text-gray-500">Unknown</span>
                                    @endif
                                    <span class="text-xs text-gray-500 ml-1 capitalize">{{ str($event->type)->replace('_', ' ') }}</span>
                                </div>
                            </button>
                            <span @class([
                                'text-[10px] uppercase tracking-wider font-semibold',
                                'text-emerald-400' => $isHome,
                                'text-blue-400' => ! $isHome,
                            ])>{{ $isHome ? 'Home' : 'Away' }}</span>
                            <button type="button" wire:click="deleteEvent('{{ $event->id }}')" wire:confirm="Delete this event?" class="text-gray-600 hover:text-red-400 transition text-xs px-1">✕</button>
                        </div>
                        @if($isEditing)
                            @php
                                $editTeamLineup = $this->match->lineups->where('team_id', $event->team_id)->filter(fn ($l) => $l->player)->sortBy(fn ($l) => ($l->role === 'replacement' ? 1 : 0).'-'.str_pad((string) ($l->jersey_number ?? 99), 3, '0', STR_PAD_LEFT));
                            @endphp
                            <div class="mt-2 ml-16 p-3 bg-gray-800/70 rounded-lg border border-gray-700 space-y-2">
                                <div class="grid grid-cols-[80px_1fr] items-center gap-2">
                                    <label class="text-xs text-gray-500 uppercase">Minute</label>
                                    <input type="number" wire:model="editMinute" min="0" max="120" class="rounded-md bg-gray-900 border border-gray-700 px-2 py-1 text-sm text-white text-center font-mono focus:border-emerald-500 focus:outline-none">
                                </div>
                                <div class="grid grid-cols-[80px_1fr] items-center gap-2">
                                    <label class="text-xs text-gray-500 uppercase">Type</label>
                                    <select wire:model="editType" class="rounded-md bg-gray-900 border border-gray-700 px-2 py-1 text-sm text-white focus:border-emerald-500 focus:outline-none">
                                        <option value="try">Try</option>
                                        <option value="conversion">Conversion</option>
                                        <option value="conversion_miss">Conversion Missed</option>
                                        <option value="penalty_goal">Penalty Goal</option>
                                        <option value="penalty_miss">Penalty Missed</option>
                                        <option value="penalty_conceded">Penalty Conceded</option>
                                        <option value="drop_goal">Drop Goal</option>
                                        <option value="yellow_card">Yellow Card</option>
                                        <option value="red_card">Red Card</option>
                                        <option value="substitution_on">Sub On</option>
                                        <option value="substitution_off">Sub Off</option>
                                    </select>
                                </div>
                                <div class="grid grid-cols-[80px_1fr] items-center gap-2">
                                    <label class="text-xs text-gray-500 uppercase">Player</label>
                                    <select wire:model="editPlayerId" class="rounded-md bg-gray-900 border border-gray-700 px-2 py-1 text-sm text-white focus:border-emerald-500 focus:outline-none">
                                        <option value="">— Unknown —</option>
                                        @foreach($editTeamLineup as $entry)
                                            <option value="{{ $entry->player->id }}">
                                                @if($entry->jersey_number)#{{ $entry->jersey_number }} @endif
                                                {{ $entry->player->first_name }} {{ $entry->player->last_name }}
                                                @if($entry->role === 'replacement') (R)@endif
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="flex gap-2 pt-1">
                                    <button type="button" wire:click="saveEdit" class="flex-1 rounded-md bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold py-1.5 transition">Save</button>
                                    <button type="button" wire:click="cancelEdit" class="rounded-md bg-gray-700 hover:bg-gray-600 text-gray-200 text-xs px-3 transition">Cancel</button>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- End match --}}
    @if($isLive)
        <button type="button" wire:click="endMatch" wire:confirm="End the match? Scores will be final." class="w-full rounded-xl bg-red-700 hover:bg-red-600 text-white text-base font-semibold py-3 transition flex items-center justify-center gap-2">
            <span>⏱</span> End Match
        </button>
    @endif
</div>
