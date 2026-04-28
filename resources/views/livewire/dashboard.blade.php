<div>
    {{-- ═══ HERO ═══ --}}
    <section class="hero">
        <div>
            @if($liveMatches->isNotEmpty())
                <div class="hero-eyebrow">
                    <span class="pulse-dot"></span>
                    Live · {{ $liveMatches->count() }} {{ Str::plural('match', $liveMatches->count()) }} in progress
                </div>
            @else
                <div class="hero-eyebrow" style="color: var(--color-muted);">
                    Open Rugby · Dashboard
                </div>
            @endif

            <h1 class="hero-title">
                Every league.<br>
                <span class="yellow">Every match.</span><br>
                <span class="stroke">Every player.</span>
            </h1>

            <p class="hero-sub">
                Results, fixtures, standings and stats across the world's top rugby competitions — updated live through the final whistle.
                <span class="brand-link">openrugby.app</span>
            </p>
        </div>

        {{-- Ticker --}}
        <div class="ticker">
            <div class="tick">
                <span class="tick-label">Competitions</span>
                <span class="tick-n">{{ number_format($stats['competitions']) }}</span>
                <span class="tick-delta">{{ number_format(\App\Models\Competition::count()) }} total</span>
            </div>
            <div class="tick">
                <span class="tick-label">Teams</span>
                <span class="tick-n">{{ number_format($stats['teams']) }}</span>
                <span class="tick-delta">across {{ \App\Models\Team::distinct('country')->count('country') }} nations</span>
            </div>
            <div class="tick">
                <span class="tick-label">Players</span>
                <span class="tick-n">{{ number_format($stats['players']) }}</span>
                <span class="tick-delta">active roster</span>
            </div>
            <div class="tick hot">
                <span class="tick-label">Matches Played</span>
                <span class="tick-n">{{ number_format($stats['completed_matches']) }}</span>
                <span class="tick-delta">of {{ number_format($stats['matches']) }} scheduled</span>
            </div>
        </div>
    </section>

    {{-- ═══ MAIN ═══ --}}
    <div class="stadium-main">
        {{-- Left column: action feed + competitions --}}
        <div>
            @auth
                @if($favouriteTeams->isNotEmpty())
                    <div class="section-head">
                        <div class="section-title">Your Teams</div>
                        <a class="section-link" href="{{ route('matches.index', ['favouritesOnly' => 1]) }}">My matches →</a>
                    </div>
                    @if($favouriteFixtures->isNotEmpty())
                        <div class="fixture-list" style="margin-bottom: 28px;">
                            @foreach($favouriteFixtures as $match)
                                @php
                                    $home = $match->matchTeams->firstWhere('side', 'home');
                                    $away = $match->matchTeams->firstWhere('side', 'away');
                                @endphp
                                <a href="{{ route('matches.show', $match) }}" class="fixture" wire:navigate>
                                    <div>
                                        <div class="f-time">{{ strtoupper($match->kickoff->format('D j M')) }} · {{ $match->kickoff->format('H:i') }}</div>
                                        <div class="f-comp">{{ $match->season->competition->name }}@if($match->round) · R{{ $match->round }}@endif</div>
                                    </div>
                                    <div class="f-home">{{ $home?->team->name ?? 'TBD' }}</div>
                                    <div class="f-vs">V</div>
                                    <div class="f-away">{{ $away?->team->name ?? 'TBD' }}</div>
                                </a>
                            @endforeach
                        </div>
                    @else
                        <div style="padding: 18px 24px; background: var(--color-bg-2); margin-bottom: 28px; color: var(--color-muted); font-family: var(--font-mono); font-size: 11px; letter-spacing: .08em;">
                            No upcoming fixtures for your favourite teams.
                        </div>
                    @endif
                @endif
            @endauth

            <div class="section-head">
                <div class="section-title">The Action</div>
                <a class="section-link" href="{{ route('matches.index') }}">All matches →</a>
            </div>

            <div x-data="{ tab: 'results' }">
                <div class="stadium-tabs">
                    <button type="button" class="stadium-tab" :class="{ 'is-active': tab === 'results' }" x-on:click="tab = 'results'">
                        Latest Results
                        @if($liveMatches->isNotEmpty())
                            <span style="color: var(--color-live); margin-left: 6px; font-size: 10px;">● {{ $liveMatches->count() }} LIVE</span>
                        @endif
                    </button>
                    <button type="button" class="stadium-tab" :class="{ 'is-active': tab === 'fixtures' }" x-on:click="tab = 'fixtures'">
                        Upcoming Fixtures
                    </button>
                </div>

                <div x-show="tab === 'results'">
                    {{-- Live matches first, then recent results --}}
                    <div class="result-list" wire:poll.30s>
                        @foreach($liveMatches as $match)
                            @php
                                $home = $match->matchTeams->firstWhere('side', 'home');
                                $away = $match->matchTeams->firstWhere('side', 'away');
                                $clock = $match->live_started_at ? now()->diffInMinutes($match->live_started_at, false) : null;
                                $clockLabel = $clock !== null ? abs((int) $clock).'\'' : 'LIVE';
                            @endphp
                            <a href="{{ route('matches.show', $match) }}" class="result is-live">
                                <div class="r-comp">
                                    <span class="pulse-dot" style="width: 7px; height: 7px; margin-right: 6px;"></span>
                                    LIVE · {{ $clockLabel }}
                                </div>
                                <div class="r-home">{{ $home?->team->name ?? 'TBD' }}</div>
                                <div class="r-score">
                                    <span>{{ $home?->score ?? 0 }}</span>
                                    <span class="r-score-dash">—</span>
                                    <span>{{ $away?->score ?? 0 }}</span>
                                </div>
                                <div class="r-away">{{ $away?->team->name ?? 'TBD' }}</div>
                                <div class="r-date">{{ $match->venue?->city ?? '' }}</div>
                            </a>
                        @endforeach

                        @forelse($recentMatches as $match)
                            @php
                                $home = $match->matchTeams->firstWhere('side', 'home');
                                $away = $match->matchTeams->firstWhere('side', 'away');
                                if (! $home || ! $away) continue;
                                $homeWon = $home->score > $away->score;
                                $awayWon = $away->score > $home->score;
                                $blowout = abs(($home->score ?? 0) - ($away->score ?? 0)) >= 20;
                            @endphp
                            <a href="{{ route('matches.show', $match) }}" @class([
                                'result',
                                'home-won' => $homeWon,
                                'away-won' => $awayWon,
                            ])>
                                <div class="r-comp">{{ $match->season->competition->name }}@if($match->round) · R{{ $match->round }}@endif</div>
                                <div class="r-home">{{ $home->team->name }}</div>
                                <div class="r-score" @if($blowout) style="color: var(--color-brand-yellow);" @endif>
                                    <span>{{ $home->score }}</span>
                                    <span class="r-score-dash">—</span>
                                    <span>{{ $away->score }}</span>
                                </div>
                                <div class="r-away">{{ $away->team->name }}</div>
                                <div class="r-date">{{ $match->kickoff->format('j M') }}</div>
                            </a>
                        @empty
                            @if($liveMatches->isEmpty())
                                <div style="padding: 24px; text-align: center; color: var(--color-muted); background: var(--color-bg-2);">No completed matches yet.</div>
                            @endif
                        @endforelse
                    </div>
                </div>

                <div x-show="tab === 'fixtures'" style="display:none;">
                    <div class="fixture-list">
                        @forelse($upcomingMatches as $match)
                            @php
                                $home = $match->matchTeams->firstWhere('side', 'home');
                                $away = $match->matchTeams->firstWhere('side', 'away');
                            @endphp
                            <a href="{{ route('matches.show', $match) }}" class="fixture">
                                <div>
                                    <div class="f-time">{{ strtoupper($match->kickoff->format('D j M')) }} · {{ $match->kickoff->format('H:i') }}</div>
                                    <div class="f-comp">{{ $match->season->competition->name }}@if($match->round) · R{{ $match->round }}@endif</div>
                                </div>
                                <div class="f-home">{{ $home?->team->name ?? 'TBD' }}</div>
                                <div class="f-vs">V</div>
                                <div class="f-away">{{ $away?->team->name ?? 'TBD' }}</div>
                            </a>
                        @empty
                            <div style="padding: 24px; text-align: center; color: var(--color-muted); background: var(--color-bg-2);">No scheduled fixtures.</div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- Competition tiles --}}
            @if($featuredCompetitions->isNotEmpty())
                <div class="section-head" style="margin-top: 40px;">
                    <div class="section-title">Competitions</div>
                    <a class="section-link" href="{{ route('competitions.index') }}">All {{ $stats['competitions'] }} →</a>
                </div>
                <div class="comp-tiles">
                    @foreach($featuredCompetitions->take(6) as $competition)
                        @php
                            $season = $competition->seasons->first();
                            // Match the Competitions list page: use the persisted
                            // data-coverage audit score (coverage + teams + scores +
                            // lineups + events + officials), not a live played/total.
                            $pct = (int) ($season?->completeness_score ?? 0);
                        @endphp
                        <a href="{{ route('competitions.show', ['competition' => $competition, 'season' => $season?->id]) }}" class="comp-tile">
                            @if($pct >= 100)
                                <span class="ct-check">✓</span>
                            @endif
                            <div class="ct-name">{{ $competition->name }}</div>
                            <div class="ct-season">{{ $season?->label ?? '—' }}</div>
                            <div class="ct-meter" style="--pct: {{ $pct }}%;"><span></span></div>
                            <div class="ct-pct">{{ $pct }}% DATA COVERAGE</div>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Right column: standings + top scorers --}}
        <aside class="side">
            @if($featuredSeason && $standings->isNotEmpty())
                <div class="widget">
                    <div class="widget-head">
                        <h3>{{ $featuredSeason->competition->name }} · Standings</h3>
                        <span style="font-family: var(--font-mono); font-size: 11px; color: var(--color-muted);">{{ $featuredSeason->label }}</span>
                    </div>
                    <table class="widget-table">
                        <thead>
                            <tr>
                                <th class="is-left" style="width: 30px;">#</th>
                                <th class="is-left">Team</th>
                                <th>P</th>
                                <th>W</th>
                                <th>PD</th>
                                <th>Pts</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($standings as $s)
                                <tr>
                                    <td class="widget-pos @if($s->position <= 4) is-top @endif">{{ $s->position }}</td>
                                    <td class="is-left t-team">
                                        <a href="{{ route('teams.show', $s->team) }}" style="color: inherit; text-decoration: none;">{{ $s->team->name }}</a>
                                    </td>
                                    <td>{{ $s->played }}</td>
                                    <td>{{ $s->won }}</td>
                                    <td style="color: {{ $s->point_differential >= 0 ? 'var(--color-home-bright)' : '#ff6b6b' }};">
                                        {{ $s->point_differential >= 0 ? '+' : '' }}{{ $s->point_differential }}
                                    </td>
                                    <td class="t-pts">{{ $s->total_points }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if($featuredSeason && $topScorers->isNotEmpty())
                <div class="widget">
                    <div class="widget-head">
                        <h3>Top Scorers · {{ $featuredSeason->competition->name }}</h3>
                    </div>
                    <table class="widget-table">
                        <tbody>
                            @foreach($topScorers as $i => $stat)
                                @php
                                    $currentTeam = $stat->player->contracts->where('is_current', true)->first()?->team;
                                @endphp
                                <tr>
                                    <td class="widget-pos @if($i < 3) is-top @endif">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</td>
                                    <td class="is-left t-team">
                                        <a href="{{ route('players.show', $stat->player) }}" style="color: inherit; text-decoration: none;">
                                            {{ $stat->player->first_name }} {{ $stat->player->last_name }}
                                        </a>
                                        @if($currentTeam)
                                            <span style="color: var(--color-muted); font-family: var(--font-mono); font-size: 11px; margin-left: 4px;">{{ $currentTeam->short_name ?? $currentTeam->name }}</span>
                                        @endif
                                    </td>
                                    <td class="t-pts">{{ (int) $stat->stat_value }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </aside>
    </div>

    {{-- ═══ Ask the Bot card ═══ --}}
    <section class="ask-card">
        <div>
            <h3>Ask the Rugby Bot.</h3>
            <p>Ask in plain English about any team, player, match or competition — powered by the full historical dataset.</p>
        </div>
        <div>
            <a href="{{ route('chat') }}" class="ask-cta">Ask a question →</a>
            <div class="t-label-xs" style="margin-top: 10px;">Try: "who did Grey High School last play?" · "top scorers in URC 2025-26"</div>
        </div>
    </section>
</div>
