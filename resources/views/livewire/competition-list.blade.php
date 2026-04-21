<div>
    {{-- ═══ Page head ═══ --}}
    <section class="page-head">
        <div class="crumb">← <a href="{{ route('dashboard') }}">back to dashboard</a></div>
        <h1>Compe<span class="yellow">titions</span></h1>
        <p class="sub">Every domestic league, cup and international series we track — {{ number_format($levelCounts['all']) }} competitions across {{ $countries->count() }} nations. Click any card for full standings, fixtures and team rosters.</p>

        <div class="search-row">
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search competitions...">
            <select wire:model.live="format">
                <option value="">All formats</option>
                <option value="union">Union</option>
                <option value="league">League</option>
                <option value="sevens">Sevens</option>
            </select>
            <select wire:model.live="country">
                <option value="">All countries</option>
                @foreach($countries as $c)
                    <option value="{{ $c }}">{{ $c }}</option>
                @endforeach
            </select>
            <select wire:model.live="quality">
                <option value="good">Good data (≥50%)</option>
                <option value="all">Any data</option>
                <option value="verified">Verified only</option>
            </select>
            @if($quality === 'good' && $hiddenCount > 0)
                <span class="hidden-note">{{ number_format($hiddenCount) }} incomplete hidden</span>
            @endif
        </div>
    </section>

    {{-- ═══ Body ═══ --}}
    <div class="page-body">
        {{-- Level chips --}}
        <div class="stadium-chips">
            @foreach([
                ['all', 'All ('.number_format($levelCounts['all']).')'],
                ['professional', 'Professional ('.number_format($levelCounts['professional']).')'],
                ['club', 'Club ('.number_format($levelCounts['club']).')'],
                ['school', 'School ('.number_format($levelCounts['school']).')'],
            ] as [$key, $label])
                <button type="button" wire:click="setLevel('{{ $key }}')" @class(['chip', 'is-active' => $level === $key])>{{ $label }}</button>
            @endforeach
        </div>

        {{-- Cards --}}
        <div class="comp-card-grid">
            @forelse($competitions as $competition)
                @php
                    $season = $competition->seasons->first();
                    $score = $season?->completeness_score ?? 0;
                    $state = $score >= 100 ? 'complete' : ($score >= 50 ? 'partial' : 'poor');
                @endphp
                <a href="{{ route('competitions.show', ['competition' => $competition, 'season' => $season?->id]) }}" class="cc" data-state="{{ $state }}">
                    <span class="kind">{{ strtoupper($competition->format) }}</span>
                    <div class="name">
                        {{ $competition->name }}
                        @if($season?->is_verified) <span class="verified">✓</span>@endif
                    </div>
                    @if($competition->grade)
                        <div class="grade">{{ $competition->grade }}</div>
                    @endif
                    <div class="loc">{{ $competition->country ?? 'International' }}</div>
                    <div class="seasons">{{ $competition->seasons_count }} {{ Str::plural('season', $competition->seasons_count) }}</div>
                    <div class="bar-outer" style="--pct: {{ $score }}%;"><span></span></div>
                    <div class="pct">
                        <span>Data coverage</span>
                        <b>{{ (int) $score }}% {{ $state === 'complete' ? 'COMPLETE' : ($state === 'partial' ? 'PARTIAL' : 'LIMITED') }}</b>
                    </div>
                </a>
            @empty
                <div style="grid-column: 1 / -1; padding: 40px; text-align: center; color: var(--color-muted); background: var(--color-bg-2);">
                    No competitions match your filters. <button wire:click="setLevel('all')" style="color: var(--color-brand-yellow); background: none; border: none; cursor: pointer; text-decoration: underline;">Clear level filter</button>.
                </div>
            @endforelse
        </div>

        @if($competitions->hasPages())
            <div style="margin-top: 24px;">{{ $competitions->links() }}</div>
        @endif
    </div>
</div>
