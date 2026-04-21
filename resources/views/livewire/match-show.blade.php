<div>
    @php
        $hasScore = $home?->score !== null && $away?->score !== null;
        $hasHalfTime = $home?->ht_score !== null && $away?->ht_score !== null;
        $hasBreakdown = collect([
            $home?->tries, $home?->conversions, $home?->penalties_kicked, $home?->drop_goals,
            $away?->tries, $away?->conversions, $away?->penalties_kicked, $away?->drop_goals,
        ])->contains(fn ($value) => $value !== null);
        $winner = collect([$home, $away])->firstWhere('is_winner', true);
        $kickoffHasTime = $match->kickoff && ($match->kickoff->hour !== 0 || $match->kickoff->minute !== 0);
        $hasEvents = $events->isNotEmpty();
        $hasLineups = $homeLineup->isNotEmpty() || $awayLineup->isNotEmpty();
        $hasStats = $stats->isNotEmpty();
        $hasOfficials = $match->officials->isNotEmpty();

        $tabs = collect([
            ['key' => 'overview', 'label' => 'Overview', 'show' => true],
            ['key' => 'lineups', 'label' => 'Lineups', 'show' => $hasLineups],
            ['key' => 'stats', 'label' => 'Stats', 'show' => $hasStats],
            ['key' => 'officials', 'label' => 'Officials', 'show' => $hasOfficials],
        ])->where('show', true);
    @endphp

    {{-- ═══ Breadcrumb + actions ═══ --}}
    <div class="match-crumb">
        <div>← <a href="{{ route('competitions.show', ['competition' => $match->season->competition, 'season' => $match->season->id]) }}">{{ $match->season->competition->name }}</a></div>
        @auth
            @if(auth()->user()->canCaptureForMatch($match))
                <div class="actions">
                    <a href="{{ route('matches.lineup', ['match' => $match, 'side' => 'home']) }}">Lineup</a>
                    <a href="{{ route('matches.capture', $match) }}" class="primary">Capture Scores</a>
                </div>
            @endif
        @endauth
    </div>

    {{-- ═══ Broadcast scorecard ═══ --}}
    @php
        $homeColor = $home?->team?->primary_color ?: '#1d8a5b';
        $awayColor = $away?->team?->primary_color ?: '#3b82f6';
        // Convert hex to a light-tint rgba for gradient bg (accept #RRGGBB)
        $hexToTint = function (?string $hex) {
            if (! $hex || ! preg_match('/^#?([0-9a-fA-F]{6})$/', $hex, $m)) return 'rgba(108,180,255,.22)';
            $r = hexdec(substr($m[1], 0, 2));
            $g = hexdec(substr($m[1], 2, 2));
            $b = hexdec(substr($m[1], 4, 2));
            return "rgba({$r},{$g},{$b},.25)";
        };
        // The big score text sits on the dark broadcast-inset bg (both themes).
        // Many team primaries are very dark (navy, black, maroon) → unreadable on dark.
        // Fall back to the bright semantic token when luminance is too low.
        $scoreColor = function (?string $hex, string $fallback) {
            if (! $hex || ! preg_match('/^#?([0-9a-fA-F]{6})$/', $hex, $m)) return $fallback;
            $r = hexdec(substr($m[1], 0, 2));
            $g = hexdec(substr($m[1], 2, 2));
            $b = hexdec(substr($m[1], 4, 2));
            $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
            return $lum < 0.5 ? $fallback : $hex;
        };
        $homeScoreColor = $scoreColor($homeColor, '#6cd4a4');
        $awayScoreColor = $scoreColor($awayColor, '#6cb4ff');
        $isLive = $match->status === 'live';
        $isFt = $match->status === 'ft';
    @endphp
    @php
        $homeInitials = $home?->team?->short_name
            ? strtoupper(substr($home->team->short_name, 0, 3))
            : strtoupper(substr(str_replace([' ', '-', '('], '', $home?->team?->name ?? 'H'), 0, 2));
        $awayInitials = $away?->team?->short_name
            ? strtoupper(substr($away->team->short_name, 0, 3))
            : strtoupper(substr(str_replace([' ', '-', '('], '', $away?->team?->name ?? 'A'), 0, 2));
    @endphp
    <div class="scorecard"
        style="--home-clr: {{ $homeColor }}; --away-clr: {{ $awayColor }}; --home-tint: {{ $hexToTint($homeColor) }}; --away-tint: {{ $hexToTint($awayColor) }}; --home-score-clr: {{ $homeScoreColor }}; --away-score-clr: {{ $awayScoreColor }};">
        {{-- Mobile-only status bar --}}
        <div class="mobile-status">
            <span>
                @if($isLive)
                    <span class="live-tag"><span class="pulse-dot"></span> LIVE</span>
                @elseif($isFt)
                    <span class="ft-tag">Full Time</span>
                @else
                    {{ strtoupper(str_replace('_', ' ', $match->status)) }}
                @endif
            </span>
            <span>
                {{ $match->season->competition->name }}@if($match->round) · R{{ $match->round }}@endif
                @if($match->venue?->city) · {{ $match->venue->city }} @endif
            </span>
        </div>

        <div class="side home">
            <div class="team-tag">Home</div>
            <div class="mobile-crest">{{ $homeInitials }}</div>
            <div class="team-name">{{ $home?->team->name ?? 'TBD' }}</div>
            <div class="mobile-score">{{ $hasScore ? $home->score : '—' }}</div>
            @if($home && $hasBreakdown)
                <div class="team-meta">{{ $home->tries ?? 0 }}T · {{ $home->conversions ?? 0 }}C · {{ $home->penalties_kicked ?? 0 }}P · {{ $home->drop_goals ?? 0 }}DG</div>
            @endif
        </div>

        <div class="mobile-dash">—</div>

        <div class="middle">
            @if($isLive)
                <span class="status-pill live"><span class="pulse-dot"></span> LIVE</span>
            @elseif($isFt)
                <span class="status-pill ft">Full Time</span>
            @else
                <span class="status-pill scheduled">{{ strtoupper(str_replace('_', ' ', $match->status)) }}</span>
            @endif

            @if($hasScore)
                <div class="scores">
                    <span class="s-home">{{ $home->score }}</span>
                    <span class="s-dash">—</span>
                    <span class="s-away">{{ $away->score }}</span>
                </div>
                @if($hasHalfTime)
                    <div class="halftime">HT · {{ $home->ht_score }}–{{ $away->ht_score }}</div>
                @endif
            @else
                <div class="scores"><span class="s-dash">— v —</span></div>
            @endif

            <div class="venue">
                {{ $match->kickoff->format($kickoffHasTime ? 'l, j F Y · H:i' : 'l, j F Y') }}
                @if($match->venue)<br>{{ $match->venue->name }}@if($match->venue->city), {{ $match->venue->city }}@endif @endif
                @if($match->attendance)<br>{{ number_format($match->attendance) }} attendance @endif
            </div>
        </div>

        <div class="side away">
            <div class="team-tag">Away</div>
            <div class="mobile-crest">{{ $awayInitials }}</div>
            <div class="team-name">{{ $away?->team->name ?? 'TBD' }}</div>
            <div class="mobile-score">{{ $hasScore ? $away->score : '—' }}</div>
            @if($away && $hasBreakdown)
                <div class="team-meta">{{ $away->tries ?? 0 }}T · {{ $away->conversions ?? 0 }}C · {{ $away->penalties_kicked ?? 0 }}P · {{ $away->drop_goals ?? 0 }}DG</div>
            @endif
        </div>

        <div class="competition-bar">
            <span>{{ $match->season->competition->name }}</span>
            @if($match->round)<span>· R{{ $match->round }}</span>@endif
            @if($match->stage)<span>· {{ strtoupper(str_replace('_', ' ', $match->stage)) }}</span>@endif
            <span>· {{ $match->season->label }}</span>
        </div>
    </div>

    {{-- ═══ Tabs ═══ --}}
    <nav class="detail-tabs" style="margin-top: 24px;">
        @foreach($tabs as $tab)
            <button type="button" wire:click="setTab('{{ $tab['key'] }}')" @class(['is-active' => $activeTab === $tab['key']])>{{ $tab['label'] }}</button>
        @endforeach
    </nav>

    <div class="page-body">

    {{-- Tab Content --}}

    {{-- ═══ OVERVIEW TAB ═══ --}}
    @if($activeTab === 'overview')
        @php
            $showLineupSidebars = $homeLineup->isNotEmpty() || $awayLineup->isNotEmpty();
        @endphp
        <div @class([
            'md:grid md:gap-6 md:grid-cols-[200px_minmax(0,1fr)_200px]' => $showLineupSidebars,
        ])>
            {{-- Home Lineup Sidebar (desktop only) --}}
            @if($showLineupSidebars)
                <aside class="hidden md:block">
                    <div class="lineup-side home">
                        <div class="ls-title">{{ $home?->team->name ?? 'Home' }}</div>
                        @if($homeLineup->isNotEmpty())
                            @php
                                $homeStarters = $homeLineup->where('role', '!=', 'replacement');
                                $homeReps = $homeLineup->where('role', 'replacement');
                            @endphp
                            <div class="ls-label">Starting XV</div>
                            @foreach($homeStarters as $entry)
                                <a href="{{ route('players.show', $entry->player) }}" class="ls-row">
                                    <span class="ls-num">{{ $entry->jersey_number }}</span>
                                    <span class="ls-name">{{ $entry->player->last_name }}</span>
                                    @if($entry->captain)<span class="ls-c">(C)</span>@endif
                                </a>
                            @endforeach
                            @if($homeReps->isNotEmpty())
                                <div class="ls-label">Replacements</div>
                                @foreach($homeReps as $entry)
                                    <a href="{{ route('players.show', $entry->player) }}" class="ls-row">
                                        <span class="ls-num">{{ $entry->jersey_number }}</span>
                                        <span class="ls-name">{{ $entry->player->last_name }}</span>
                                    </a>
                                @endforeach
                            @endif
                        @else
                            <div class="ls-empty">Lineup not available</div>
                        @endif
                    </div>
                </aside>
            @endif

            {{-- Center Content --}}
            <div class="space-y-6">
            {{-- Event Timeline --}}
            @if($hasEvents)
                <div>
                    <h3 class="sec-title">Match Timeline</h3>
                    <div class="event-list">
                        @foreach($events as $event)
                            @php
                                $isHome = $home && $event->team_id === $home->team_id;
                                $typeConfig = match($event->type) {
                                    'try' => ['icon' => '🏉', 'class' => 'try', 'label' => 'Try'],
                                    'conversion' => ['icon' => '🥅', 'class' => 'conv', 'label' => 'Conversion'],
                                    'penalty_goal' => ['icon' => '🎯', 'class' => 'pen', 'label' => 'Penalty Goal'],
                                    'penalty_conceded' => ['icon' => '🚩', 'class' => 'pen', 'label' => 'Penalty'],
                                    'drop_goal' => ['icon' => '🎯', 'class' => 'drop', 'label' => 'Drop Goal'],
                                    'conversion_miss' => ['icon' => '✕', 'class' => 'miss', 'label' => 'Conversion Missed'],
                                    'penalty_miss' => ['icon' => '✕', 'class' => 'miss', 'label' => 'Penalty Missed'],
                                    'yellow_card' => ['icon' => '🟨', 'class' => 'yc', 'label' => 'Yellow Card'],
                                    'red_card' => ['icon' => '🟥', 'class' => 'rc', 'label' => 'Red Card'],
                                    default => ['icon' => '•', 'class' => '', 'label' => str($event->type)->replace('_', ' ')->title()],
                                };
                            @endphp
                            <div class="event-row">
                                <span class="ev-min {{ $typeConfig['class'] }}">{{ $event->minute }}'</span>
                                <span class="ev-icon">{{ $typeConfig['icon'] }}</span>
                                <div class="ev-body">
                                    @if($event->player)
                                        <a href="{{ route('players.show', $event->player) }}">{{ $event->player->first_name }} {{ $event->player->last_name }}</a>
                                    @else
                                        <span style="color: var(--color-muted);">Unknown</span>
                                    @endif
                                    <span class="ev-type">{{ $typeConfig['label'] }}</span>
                                </div>
                                <span class="ev-team {{ $isHome ? 'home' : 'away' }}">{{ $isHome ? 'HOME' : 'AWAY' }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Key Stats Preview --}}
            @if($hasStats && !$statsAreDerived && $home && $away)
                <div>
                    <h3 class="sec-title">Key Stats</h3>
                    <div class="widget" style="padding: 18px 20px;">
                        @php
                            $preferredKeys = ['possession_pct', 'territory_pct', 'tackles_made', 'carries', 'metres_carried', 'clean_breaks', 'offloads', 'turnovers_won', 'penalties_conceded'];
                            $hasPair = fn ($key) =>
                                $stats->where('team_id', $home->team_id)->where('stat_key', $key)->isNotEmpty()
                                && $stats->where('team_id', $away->team_id)->where('stat_key', $key)->isNotEmpty();

                            $statKeys = collect($preferredKeys)->filter($hasPair)->values();
                            if ($statKeys->isEmpty()) {
                                $statKeys = $stats->pluck('stat_key')->unique()->filter($hasPair)->sort()->take(8)->values();
                            }
                        @endphp
                        <div style="display: flex; flex-direction: column; gap: 14px;">
                            @foreach($statKeys as $key)
                                @php
                                    $homeStat = $stats->where('team_id', $home->team_id)->where('stat_key', $key)->first();
                                    $awayStat = $stats->where('team_id', $away->team_id)->where('stat_key', $key)->first();
                                @endphp
                                @if($homeStat && $awayStat)
                                    @php
                                        $total = max($homeStat->stat_value + $awayStat->stat_value, 1);
                                        $homePct = ($homeStat->stat_value / $total) * 100;
                                    @endphp
                                    <div>
                                        <div style="display: flex; justify-content: space-between; font-family: var(--font-mono); font-size: 11px; margin-bottom: 6px; letter-spacing: .06em;">
                                            <span style="color: var(--color-home-bright); font-weight: 700;">{{ number_format($homeStat->stat_value) }}</span>
                                            <span style="color: var(--color-muted); text-transform: uppercase; letter-spacing: .12em;">{{ str($key)->replace('_', ' ')->title() }}</span>
                                            <span style="color: var(--color-away-bright); font-weight: 700;">{{ number_format($awayStat->stat_value) }}</span>
                                        </div>
                                        <div style="display: flex; height: 6px; overflow: hidden; background: var(--color-bg-inset);">
                                            <div style="background: var(--color-home); width: {{ $homePct }}%;"></div>
                                            <div style="background: var(--color-away); width: {{ 100 - $homePct }}%;"></div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            @if(!$hasEvents && !$hasStats)
                <div class="widget" style="padding: 24px;">
                    <h3 class="sec-title">Match Detail Pending</h3>
                    <p style="color: var(--color-ink-dim); font-size: 14px; margin: 0;">
                        No detailed event feed or team stats have been imported yet for this match.
                    </p>
                </div>
            @endif
            </div>

            {{-- Away Lineup Sidebar (desktop only) --}}
            @if($showLineupSidebars)
                <aside class="hidden md:block">
                    <div class="lineup-side away">
                        <div class="ls-title">{{ $away?->team->name ?? 'Away' }}</div>
                        @if($awayLineup->isNotEmpty())
                            @php
                                $awayStarters = $awayLineup->where('role', '!=', 'replacement');
                                $awayReps = $awayLineup->where('role', 'replacement');
                            @endphp
                            <div class="ls-label">Starting XV</div>
                            @foreach($awayStarters as $entry)
                                <a href="{{ route('players.show', $entry->player) }}" class="ls-row">
                                    <span class="ls-num">{{ $entry->jersey_number }}</span>
                                    <span class="ls-name">{{ $entry->player->last_name }}</span>
                                    @if($entry->captain)<span class="ls-c">(C)</span>@endif
                                </a>
                            @endforeach
                            @if($awayReps->isNotEmpty())
                                <div class="ls-label">Replacements</div>
                                @foreach($awayReps as $entry)
                                    <a href="{{ route('players.show', $entry->player) }}" class="ls-row">
                                        <span class="ls-num">{{ $entry->jersey_number }}</span>
                                        <span class="ls-name">{{ $entry->player->last_name }}</span>
                                    </a>
                                @endforeach
                            @endif
                        @else
                            <div class="ls-empty">Lineup not available</div>
                        @endif
                    </div>
                </aside>
            @endif
        </div>
    @endif

    {{-- ═══ LINEUPS TAB ═══ --}}
    @if($activeTab === 'lineups' && $hasLineups)
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            @foreach([
                ['label' => $home?->team->name ?? 'Home', 'lineup' => $homeLineup, 'side' => 'home'],
                ['label' => $away?->team->name ?? 'Away', 'lineup' => $awayLineup, 'side' => 'away'],
            ] as $col)
                <div>
                    <h3 class="sec-title">{{ $col['label'] }}</h3>
                    @if($col['lineup']->isNotEmpty())
                        @php
                            $starters = $col['lineup']->where('role', '!=', 'replacement');
                            $replacements = $col['lineup']->where('role', 'replacement');
                        @endphp
                        <div class="lineup-side {{ $col['side'] }}">
                            <div class="ls-label" style="border-top: none;">Starting XV</div>
                            @foreach($starters as $entry)
                                <a href="{{ route('players.show', $entry->player) }}" class="ls-row" style="padding: 8px 14px;">
                                    <span class="ls-num">{{ $entry->jersey_number }}</span>
                                    <span class="ls-name" style="font-weight: 600;">{{ $entry->player->first_name }} {{ $entry->player->last_name }}</span>
                                    @if($entry->captain)<span class="ls-c">(C)</span>@endif
                                    @if($entry->position || $entry->minutes_played)
                                        <span style="font-family: var(--font-mono); font-size: 10px; color: var(--color-muted); letter-spacing: .08em; margin-left: auto;">
                                            @if($entry->position){{ $entry->position }}@endif
                                            @if($entry->minutes_played) · {{ $entry->minutes_played }}'@endif
                                        </span>
                                    @endif
                                </a>
                            @endforeach

                            @if($replacements->isNotEmpty())
                                <div class="ls-label">Replacements</div>
                                @foreach($replacements as $entry)
                                    <a href="{{ route('players.show', $entry->player) }}" class="ls-row" style="padding: 8px 14px;">
                                        <span class="ls-num">{{ $entry->jersey_number }}</span>
                                        <span class="ls-name" style="font-weight: 600;">{{ $entry->player->first_name }} {{ $entry->player->last_name }}</span>
                                        @if($entry->position || $entry->minutes_played)
                                            <span style="font-family: var(--font-mono); font-size: 10px; color: var(--color-muted); letter-spacing: .08em; margin-left: auto;">
                                                @if($entry->position){{ $entry->position }}@endif
                                                @if($entry->minutes_played) · {{ $entry->minutes_played }}'@endif
                                            </span>
                                        @endif
                                    </a>
                                @endforeach
                            @endif
                        </div>
                    @else
                        <div class="widget" style="padding: 28px; text-align: center; color: var(--color-muted); font-size: 13px;">
                            Lineup not available.
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- ═══ STATS TAB ═══ --}}
    @if($activeTab === 'stats' && $hasStats && $home && $away)
        <div>
            {{-- Home/Away key --}}
            <div style="display: flex; justify-content: center; gap: 48px; margin-bottom: 14px; font-family: var(--font-mono); font-size: 11px; letter-spacing: .12em; text-transform: uppercase;">
                <span style="color: var(--color-home-bright); font-weight: 700;"><span style="display:inline-block; width:10px; height:10px; background: var(--color-home); vertical-align:middle; margin-right:6px;"></span>{{ $home->team->name }}</span>
                <span style="color: var(--color-away-bright); font-weight: 700;"><span style="display:inline-block; width:10px; height:10px; background: var(--color-away); vertical-align:middle; margin-right:6px;"></span>{{ $away->team->name }}</span>
            </div>

            @if($statsAreDerived)
                <p style="text-align: center; font-family: var(--font-mono); font-size: 11px; color: var(--color-muted); letter-spacing: .08em; margin: 0 0 18px;">Computed from match events · detailed team stats not yet imported</p>
            @endif

            {{-- Scoring Summary --}}
            @php
                $scoringTypes = [
                    'try' => ['label' => 'Tries', 'var' => '--color-home-bright'],
                    'conversion' => ['label' => 'Conversions', 'var' => '--color-away-bright'],
                    'penalty_goal' => ['label' => 'Penalty Goals', 'var' => '--color-penalty'],
                    'drop_goal' => ['label' => 'Drop Goals', 'var' => '--color-drop'],
                ];
                $homeScoring = $events->where('team_id', $home->team_id)->whereIn('type', array_keys($scoringTypes));
                $awayScoring = $events->where('team_id', $away->team_id)->whereIn('type', array_keys($scoringTypes));
                $hasScoring = $homeScoring->isNotEmpty() || $awayScoring->isNotEmpty();
            @endphp

            @if($hasScoring)
                <div>
                    <h3 class="sec-title">Scoring Summary</h3>
                    <div class="widget" style="padding: 20px 22px; margin-bottom: 18px;">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            @foreach([
                                ['scoring' => $homeScoring, 'border' => 'var(--color-home)'],
                                ['scoring' => $awayScoring, 'border' => 'var(--color-away)'],
                            ] as $col)
                                <div style="border-left: 3px solid {{ $col['border'] }}; padding-left: 14px;">
                                    @if($col['scoring']->isEmpty())
                                        <div style="font-size: 13px; color: var(--color-muted); font-style: italic;">No scoring</div>
                                    @else
                                        @foreach($scoringTypes as $type => $config)
                                            @php $typeEvents = $col['scoring']->where('type', $type)->sortBy('minute'); @endphp
                                            @if($typeEvents->isNotEmpty())
                                                <div style="margin-bottom: 14px;">
                                                    <div class="t-label-xs" style="margin-bottom: 6px;">
                                                        <span style="color: var({{ $config['var'] }});">{{ $config['label'] }}</span>
                                                        <span style="color: var(--color-muted); margin-left: 4px;">· {{ $typeEvents->count() }}</span>
                                                    </div>
                                                    <div style="display: flex; flex-direction: column; gap: 3px; font-size: 13px;">
                                                        @foreach($typeEvents as $event)
                                                            <div style="display: flex; align-items: baseline; justify-content: space-between; gap: 10px;">
                                                                @if($event->player)
                                                                    <a href="{{ route('players.show', $event->player) }}" style="color: var(--color-ink); text-decoration: none; font-weight: 600;">
                                                                        {{ $event->player->first_name }} {{ $event->player->last_name }}
                                                                    </a>
                                                                @else
                                                                    <span style="color: var(--color-muted);">Unknown</span>
                                                                @endif
                                                                <span style="font-family: var(--font-mono); font-size: 11px; color: var(--color-muted);">{{ $event->minute }}'</span>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif
                                        @endforeach
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            {{-- Team stat bars --}}
            <div>
                <h3 class="sec-title">Team Stats</h3>
                <div class="widget" style="padding: 22px 24px;">
                    @php $allStatKeys = $stats->pluck('stat_key')->unique()->sort(); @endphp
                    <div style="display: flex; flex-direction: column; gap: 16px;">
                        @foreach($allStatKeys as $key)
                            @php
                                $homeStat = $stats->where('team_id', $home->team_id)->where('stat_key', $key)->first();
                                $awayStat = $stats->where('team_id', $away->team_id)->where('stat_key', $key)->first();
                            @endphp
                            @if($homeStat && $awayStat)
                                @php
                                    $total = max($homeStat->stat_value + $awayStat->stat_value, 1);
                                    $homePct = ($homeStat->stat_value / $total) * 100;
                                @endphp
                                <div>
                                    <div style="display: flex; justify-content: space-between; font-family: var(--font-mono); font-size: 11px; margin-bottom: 6px; letter-spacing: .06em;">
                                        <span style="color: var(--color-home-bright); font-weight: 700; font-size: 14px;">{{ number_format($homeStat->stat_value) }}</span>
                                        <span style="color: var(--color-muted); text-transform: uppercase; letter-spacing: .14em;">{{ str($key)->replace('_', ' ')->title() }}</span>
                                        <span style="color: var(--color-away-bright); font-weight: 700; font-size: 14px;">{{ number_format($awayStat->stat_value) }}</span>
                                    </div>
                                    <div style="display: flex; height: 6px; overflow: hidden; background: var(--color-bg-inset);">
                                        <div style="background: var(--color-home); width: {{ $homePct }}%;"></div>
                                        <div style="background: var(--color-away); width: {{ 100 - $homePct }}%;"></div>
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══ OFFICIALS TAB ═══ --}}
    @if($activeTab === 'officials' && $hasOfficials)
        <div>
            <h3 class="sec-title">Match Officials</h3>
            <div class="officials-list">
                @foreach($match->officials as $official)
                    <a href="{{ route('referees.show', $official->referee) }}">
                        <span style="font-weight: 600;">{{ $official->referee->first_name }} {{ $official->referee->last_name }}</span>
                        <span class="off-role">{{ str($official->role)->replace('_', ' ')->title() }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif
    </div> {{-- /.page-body --}}
</div>
