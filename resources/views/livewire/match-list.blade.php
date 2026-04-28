<div>
    {{-- ═══ Page head ═══ --}}
    <section class="page-head">
        <div class="crumb">← <a href="{{ route('dashboard') }}">back to dashboard</a></div>
        <h1>Match<span class="yellow">es</span>.</h1>
        <p class="sub">{{ number_format($matches->total()) }} {{ Str::plural('match', $matches->total()) }} across the competitions we track. Filter by competition or status, then click any row for the full breakdown.</p>

        <div class="search-row">
            <select wire:model.live="competition">
                <option value="">All competitions</option>
                @foreach($competitions as $comp)
                    <option value="{{ $comp->id }}">{{ $comp->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="status">
                <option value="">All statuses</option>
                <option value="scheduled">Scheduled</option>
                <option value="live">Live</option>
                <option value="ft">Full Time</option>
                <option value="postponed">Postponed</option>
            </select>
        </div>

        @auth
            <div class="stadium-chips" style="margin-top: 14px;">
                <button type="button"
                    wire:click="$toggle('favouritesOnly')"
                    @class(['chip', 'is-active' => $favouritesOnly])>
                    ★ Favourites only
                </button>
            </div>
        @endauth
    </section>

    {{-- ═══ Body ═══ --}}
    <div class="page-body">
        <div class="result-list">
            @forelse($matches as $match)
                @php
                    $home = $match->matchTeams->firstWhere('side', 'home');
                    $away = $match->matchTeams->firstWhere('side', 'away');
                    $isLive = $match->status === 'live';
                    $isFinal = $match->status === 'ft';
                    $homeWon = $isFinal && $home?->is_winner;
                    $awayWon = $isFinal && $away?->is_winner;
                    $rowClass = 'result';
                    if ($isLive) $rowClass .= ' is-live';
                    if ($homeWon) $rowClass .= ' home-won';
                    if ($awayWon) $rowClass .= ' away-won';
                @endphp
                <a href="{{ route('matches.show', $match) }}" class="{{ $rowClass }}" wire:navigate>
                    <div class="r-comp">
                        @if($isLive)
                            <span class="pulse-dot" style="width: 7px; height: 7px; margin-right: 6px; display:inline-block; vertical-align:middle;"></span>LIVE ·
                        @endif
                        {{ $match->season->competition->name }}@if($match->round) · R{{ $match->round }}@endif@if($match->stage) · {{ str($match->stage)->replace('_', ' ')->title() }}@endif
                    </div>
                    <div class="r-home">{{ $home?->team->name ?? 'TBD' }}</div>
                    <div class="r-score">
                        @if($isFinal || $isLive)
                            <span>{{ $home?->score ?? 0 }}</span>
                            <span class="r-score-dash">—</span>
                            <span>{{ $away?->score ?? 0 }}</span>
                        @else
                            <span style="font-size: 14px; font-family: var(--font-mono); color: var(--color-muted); letter-spacing: .06em;">
                                {{ $match->kickoff->format('H:i') }}
                            </span>
                        @endif
                    </div>
                    <div class="r-away">{{ $away?->team->name ?? 'TBD' }}</div>
                    <div class="r-date">
                        {{ strtoupper($match->kickoff->format('d M Y')) }}
                        @if($match->venue)
                            <div style="font-size: 10px; margin-top: 2px;">{{ $match->venue->name }}</div>
                        @endif
                    </div>
                </a>
            @empty
                <div style="padding: 40px 24px; text-align: center; color: var(--color-muted); background: var(--color-bg-2); font-family: var(--font-mono); font-size: 12px; letter-spacing: .08em;">
                    No matches found. Run <code style="color: var(--color-brand-yellow);">php artisan rugby:sync-daily</code> to pull data.
                </div>
            @endforelse
        </div>

        @if($matches->hasPages())
            <div style="margin-top: 18px;">{{ $matches->links() }}</div>
        @endif
    </div>
</div>
