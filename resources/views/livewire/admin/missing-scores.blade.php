<div>
    {{-- ═══ Page head ═══ --}}
    <section class="page-head">
        <div class="crumb">← <a href="{{ route('admin.index') }}">back to admin</a></div>
        <h1>Missing <span class="yellow">Scores</span>.</h1>
        <p class="sub">Matches scheduled in the last {{ $days }} days that don't have scores yet. Mostly source-lag — fill in known results to keep the data complete.</p>

        <div class="search-row">
            <select wire:model.live="competition">
                <option value="">All competitions</option>
                @foreach($competitions as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="days">
                <option value="7">Last 7 days</option>
                <option value="14">Last 14 days</option>
                <option value="30">Last 30 days</option>
                <option value="90">Last 90 days</option>
            </select>
        </div>
    </section>

    {{-- ═══ Body ═══ --}}
    <div class="page-body">
        <div class="missing-list">
            @forelse($matches as $match)
                @php
                    $home = $match->matchTeams->firstWhere('side', 'home');
                    $away = $match->matchTeams->firstWhere('side', 'away');
                @endphp
                <div class="missing-row" wire:key="m-{{ $match->id }}">
                    <div class="m-comp">
                        {{ $match->season->competition->name }}@if($match->round) · R{{ $match->round }}@endif
                    </div>
                    <div class="m-date">{{ strtoupper($match->kickoff->format('D j M Y')) }}</div>
                    <div class="m-teams">
                        <span class="m-team m-home">{{ $home?->team?->name ?? 'TBD' }}</span>
                        <input type="number" min="0" max="200"
                            wire:model="homeScores.{{ $match->id }}"
                            placeholder="—"
                            class="m-score-input"
                            wire:keydown.enter="save('{{ $match->id }}')">
                        <span class="m-dash">vs</span>
                        <input type="number" min="0" max="200"
                            wire:model="awayScores.{{ $match->id }}"
                            placeholder="—"
                            class="m-score-input"
                            wire:keydown.enter="save('{{ $match->id }}')">
                        <span class="m-team m-away">{{ $away?->team?->name ?? 'TBD' }}</span>
                    </div>
                    <div class="m-actions">
                        <button type="button" class="m-save" wire:click="save('{{ $match->id }}')">Save</button>
                        <a href="{{ route('matches.show', $match) }}" class="m-view" wire:navigate>View</a>
                    </div>
                </div>
            @empty
                <div style="padding: 40px 24px; text-align: center; color: var(--color-muted); background: var(--color-bg-2); font-family: var(--font-mono); font-size: 12px; letter-spacing: .08em;">
                    Nothing missing in this window — every scheduled match has scores.
                </div>
            @endforelse
        </div>

        @if($matches->hasPages())
            <div style="margin-top: 18px;">{{ $matches->links() }}</div>
        @endif
    </div>
</div>
