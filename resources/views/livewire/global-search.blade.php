<div
    class="gs"
    x-data="{
        open: @entangle('showResults').live,
        focused: false,
        expanded: false,
        toggle() {
            this.expanded = !this.expanded;
            if (this.expanded) {
                this.$nextTick(() => this.$refs.input?.focus());
            } else {
                this.close();
            }
        },
        close() {
            this.open = false;
            this.focused = false;
            this.expanded = false;
        },
    }"
    x-bind:class="{ 'gs-active': expanded || focused || open }"
    x-on:keydown.escape.window="close()"
    x-on:click.outside="close()"
>
    {{-- Mobile-only icon trigger; hidden ≥900px via CSS --}}
    <button
        type="button"
        class="gs-trigger"
        x-on:click="toggle()"
        x-bind:aria-expanded="(expanded || focused || open).toString()"
        aria-label="Open search"
    >
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
    </button>

    <label class="gs-field" x-bind:class="{ 'is-focus': focused || open || expanded }">
        <svg class="gs-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <input
            x-ref="input"
            type="search"
            wire:model.live.debounce.200ms="q"
            placeholder="Search teams, players, competitions…"
            aria-label="Global search"
            x-on:focus="focused = true; $wire.focus()"
            x-on:blur="focused = false"
            autocomplete="off"
        >
        @if($q !== '')
            <button type="button" class="gs-clear" wire:click="clear" x-on:click="close()" aria-label="Clear search">×</button>
        @else
            <button type="button" class="gs-clear gs-close-mobile" x-on:click="close()" aria-label="Close search">×</button>
        @endif
    </label>

    @if($showResults && $q !== '')
        <div class="gs-dropdown" x-cloak x-show="open" x-transition.opacity.duration.120ms>
            @if(mb_strlen(trim($q)) < 2)
                <div class="gs-empty">Keep typing — at least 2 characters.</div>
            @elseif($totalResults === 0)
                <div class="gs-empty">No matches for "<b>{{ $q }}</b>".</div>
            @else
                @if(! empty($teams))
                    <div class="gs-group">
                        <div class="gs-group-head">Teams</div>
                        @foreach($teams as $t)
                            <a href="{{ route('teams.show', $t['slug']) }}" class="gs-item" wire:navigate x-on:click="close()">
                                <span class="gs-badge" @if($t['color']) style="background: {{ $t['color'] }}" @endif>
                                    @if($t['logo'])
                                        <img src="{{ $t['logo'] }}" alt="" style="width: 16px; height: 16px; object-fit: contain;">
                                    @else
                                        {{ strtoupper(mb_substr($t['name'], 0, 1)) }}
                                    @endif
                                </span>
                                <span class="gs-item-body">
                                    <span class="gs-item-name">{{ $t['name'] }}</span>
                                    @if($t['meta'])<span class="gs-item-meta">{{ $t['meta'] }}</span>@endif
                                </span>
                            </a>
                        @endforeach
                    </div>
                @endif

                @if(! empty($competitions))
                    <div class="gs-group">
                        <div class="gs-group-head">Competitions</div>
                        @foreach($competitions as $c)
                            <a href="{{ route('competitions.show', $c['slug']) }}" class="gs-item" wire:navigate x-on:click="close()">
                                <span class="gs-badge gs-badge-comp">🏆</span>
                                <span class="gs-item-body">
                                    <span class="gs-item-name">{{ $c['name'] }}</span>
                                    <span class="gs-item-meta">{{ $c['meta'] }}@if($c['level']) · {{ ucfirst($c['level']) }}@endif</span>
                                </span>
                            </a>
                        @endforeach
                    </div>
                @endif

                @if(! empty($players))
                    <div class="gs-group">
                        <div class="gs-group-head">Players</div>
                        @foreach($players as $p)
                            <a href="{{ route('players.show', $p['slug']) }}" class="gs-item" wire:navigate x-on:click="close()">
                                <span class="gs-badge gs-badge-player">{{ strtoupper(mb_substr($p['name'], 0, 1)) }}</span>
                                <span class="gs-item-body">
                                    <span class="gs-item-name">{{ $p['name'] }}</span>
                                    <span class="gs-item-meta">
                                        {{ $p['team'] ?? 'Free agent' }}@if($p['pos']) · {{ ucfirst($p['pos']) }}@endif
                                    </span>
                                </span>
                            </a>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>
    @endif
</div>
