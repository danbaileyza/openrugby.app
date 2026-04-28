<div>
    {{-- ═══ Stadium Hero ═══ --}}
    <section class="detail-hero">
        <div class="crumb">← <a href="{{ route('competitions.index') }}">Competitions</a></div>
        <div style="display:flex; justify-content:space-between; align-items:flex-end; gap: 20px; flex-wrap: wrap;">
            <div>
                @php
                    // Split the name at the last space for two-line yellow-accent title
                    $nameWords = explode(' ', $competition->name);
                    $firstPart = count($nameWords) > 1 ? implode(' ', array_slice($nameWords, 0, -1)) : $competition->name;
                    $lastWord = count($nameWords) > 1 ? end($nameWords) : '';
                @endphp
                <h1>
                    {{ $firstPart }}@if($lastWord)<br><span class="yellow">{{ $lastWord }}</span>@endif
                </h1>
                <div class="sub-meta">
                    <span>{{ $competition->country ?? 'International' }} · {{ strtoupper($competition->format) }}</span>
                    @if($competition->grade)<span class="yellow" style="color: var(--color-brand-yellow);">{{ $competition->grade }}</span>@endif
                    <span>{{ $competition->seasons->count() }} {{ Str::plural('season', $competition->seasons->count()) }} tracked</span>
                    @if($currentSeason)
                        <span>{{ $currentSeason->start_date?->format('M Y') ?? '—' }} → {{ $currentSeason->end_date?->format('M Y') ?? '—' }}</span>
                    @endif
                </div>
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                @auth
                    <livewire:favourite-button type="competition" :id="$competition->id" :key="'fav-comp-'.$competition->id" />
                @endauth
                @if($competition->seasons->count() > 1)
                    <select wire:model.live="selectedSeason" style="background: var(--color-bg-2); border: 1px solid var(--color-brand-yellow); color: var(--color-brand-yellow); padding: 8px 14px; border-radius: var(--r-sm); font-family: var(--font-mono); font-size: 12px; cursor: pointer;">
                        @foreach($competition->seasons as $s)
                            <option value="{{ $s->id }}">{{ $s->label }}@if($s->is_current) (Current)@endif</option>
                        @endforeach
                    </select>
                @endif
            </div>
        </div>

        @if($currentSeason)
            {{-- Ticker --}}
            <div class="ticker" style="margin-top: 24px;">
                <div class="tick">
                    <span class="tick-label">Teams</span>
                    <span class="tick-n">{{ $teams->count() }}</span>
                </div>
                <div class="tick hot">
                    <span class="tick-label">Matches</span>
                    <span class="tick-n">{{ $totalMatches }}</span>
                    <span class="tick-delta">{{ $matches->where('status', 'ft')->count() }} played</span>
                </div>
                <div class="tick">
                    <span class="tick-label">Start</span>
                    <span class="tick-n" style="font-size: 28px;">{{ $currentSeason->start_date?->format('M Y') ?? '—' }}</span>
                </div>
                <div class="tick">
                    <span class="tick-label">End</span>
                    <span class="tick-n" style="font-size: 28px;">{{ $currentSeason->end_date?->format('M Y') ?? '—' }}</span>
                </div>
            </div>
        @endif
    </section>

    @if($currentSeason)

        @if($seriesSummary)
            <div class="widget" style="padding: 20px; margin-bottom: 22px;">
                <div style="font-family: var(--font-mono); font-size: 10px; color: var(--color-muted); letter-spacing: .15em; text-transform: uppercase; margin-bottom: 14px; text-align: center;">
                    Series Result · {{ $seriesSummary['tests_played'] }} Test{{ $seriesSummary['tests_played'] === 1 ? '' : 's' }}
                </div>
                <div style="display: flex; align-items: center; justify-content: center; gap: 48px;">
                    @foreach($seriesSummary['rows'] as $row)
                        @php $isWinner = $seriesSummary['winner'] && $seriesSummary['winner']->id === $row['team']->id; @endphp
                        <div style="text-align: center;">
                            <div style="font-family: var(--font-mono); font-size: 11px; color: var(--color-ink-dim); letter-spacing: .08em; text-transform: uppercase;">{{ $row['team']->name }}</div>
                            <div style="font-family: var(--font-display); font-weight: 900; font-size: 44px; letter-spacing: -.02em; line-height: 1; margin-top: 6px; color: {{ $isWinner ? 'var(--color-brand-yellow)' : 'var(--color-ink)' }};">{{ $row['wins'] }}</div>
                        </div>
                    @endforeach
                </div>
                @if($seriesSummary['winner'])
                    <div style="text-align: center; font-family: var(--font-mono); font-size: 10px; color: var(--color-brand-yellow); letter-spacing: .15em; text-transform: uppercase; margin-top: 14px;">Series won by {{ $seriesSummary['winner']->name }}</div>
                @endif
            </div>
        @endif

        {{-- Tabs --}}
        <nav class="detail-tabs">
            @if($competition->has_standings)
                <button wire:click="setTab('standings')" @class(['is-active' => $activeTab === 'standings'])>Standings</button>
            @endif
            <button wire:click="setTab('matches')" @class(['is-active' => $activeTab === 'matches'])>Matches</button>
            <button wire:click="setTab('teams')" @class(['is-active' => $activeTab === 'teams'])>Teams ({{ $teams->count() }})</button>
            <button wire:click="setTab('referees')" @class(['is-active' => $activeTab === 'referees'])>Referees ({{ $referees->count() }})</button>
            <button wire:click="setTab('stats')" @class(['is-active' => $activeTab === 'stats'])>Stats</button>
        </nav>

        <div class="page-body">

        {{-- Standings Tab --}}
        @if($activeTab === 'standings')
            @if($standings->isNotEmpty())
                @php
                    $pools = $standings->groupBy('pool');
                    $totalTeams = $standings->count();
                    $playoffCutoff = min(8, (int) ceil($totalTeams / 2));
                    $relegationCutoff = max(1, $totalTeams - 3);
                @endphp
                @foreach($pools as $pool => $poolStandings)
                    @if($pool)
                        <h3 class="sec-title" style="margin-top: 10px;">Pool {{ $pool }}</h3>
                    @endif
                    <div class="std-wrap">
                        <table class="std">
                            <thead>
                                <tr>
                                    <th class="is-left" style="width: 36px;">#</th>
                                    <th class="is-left">Team</th>
                                    <th>P</th>
                                    <th>W</th>
                                    <th>D</th>
                                    <th>L</th>
                                    <th>PF</th>
                                    <th>PA</th>
                                    <th>PD</th>
                                    <th>BP</th>
                                    <th>Pts</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($poolStandings as $standing)
                                    @php
                                        $posClass = '';
                                        if ($competition->has_standings) {
                                            if ($standing->position <= $playoffCutoff) $posClass = 'is-top';
                                            elseif ($standing->position > $relegationCutoff) $posClass = 'is-rel';
                                        }
                                    @endphp
                                    <tr>
                                        <td class="is-left"><span class="pos {{ $posClass }}">{{ $standing->position }}</span></td>
                                        <td class="is-left team-name">
                                            <a href="{{ route('teams.show', $standing->team) }}">{{ $standing->team->name }}</a>
                                        </td>
                                        <td>{{ $standing->played }}</td>
                                        <td class="win">{{ $standing->won }}</td>
                                        <td>{{ $standing->drawn }}</td>
                                        <td class="loss">{{ $standing->lost }}</td>
                                        <td>{{ $standing->points_for }}</td>
                                        <td>{{ $standing->points_against }}</td>
                                        <td style="color: {{ $standing->point_differential >= 0 ? 'var(--color-home-bright)' : 'var(--color-live)' }};">
                                            {{ $standing->point_differential >= 0 ? '+' : '' }}{{ $standing->point_differential }}
                                        </td>
                                        <td>{{ $standing->bonus_points }}</td>
                                        <td class="pts">{{ $standing->total_points }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
                @if($competition->has_standings)
                    <div style="display: flex; gap: 20px; font-family: var(--font-mono); font-size: 10px; color: var(--color-muted); margin-top: 14px; letter-spacing: .1em;">
                        <span><span style="display:inline-block; width:10px; height:10px; background: var(--color-brand-yellow); margin-right: 6px; vertical-align: middle;"></span>Playoff spots (top {{ $playoffCutoff }})</span>
                        <span><span style="display:inline-block; width:10px; height:10px; background: var(--color-live); margin-right: 6px; vertical-align: middle;"></span>Bottom {{ $totalTeams - $relegationCutoff }} · no playoffs</span>
                    </div>
                @endif
            @else
                <div class="widget" style="padding: 40px; text-align: center; color: var(--color-muted);">
                    No standings available for this season.
                </div>
            @endif
        @endif

        {{-- Matches Tab --}}
        @if($activeTab === 'matches')
            <div>
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
                    <p style="font-family: var(--font-mono); font-size: 11px; color: var(--color-muted); letter-spacing: .1em; text-transform: uppercase; margin: 0;">
                        @if($selectedRound !== '')
                            Round {{ $selectedRound }} · {{ $matches->count() }} of {{ $totalMatches }}
                        @else
                            {{ $totalMatches }} matches
                        @endif
                    </p>
                    @if($rounds->isNotEmpty())
                        <select wire:model.live="selectedRound" style="background: var(--color-bg-2); border: 1px solid var(--color-line); color: var(--color-ink-dim); padding: 8px 12px; font-family: var(--font-mono); font-size: 11px; letter-spacing: .08em; border-radius: var(--r-sm); cursor: pointer;">
                            <option value="">All Rounds</option>
                            @foreach($rounds as $round)
                                <option value="{{ $round }}">Round {{ $round }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>

                @php
                    $stageOrder = [
                        'round_of_16'    => '5001',
                        'quarter_finals' => '5002',
                        'semi_finals'    => '5003',
                        'bronze_final'   => '5004',
                        'final'          => '5005',
                    ];

                    // Test series / tours / cups (no standings) → group by weekend instead of round
                    $groupByWeekend = ! $competition->has_standings;

                    $groupedMatches = $matches->groupBy(function ($match) use ($stageOrder, $groupByWeekend) {
                        if ($match->stage && $match->stage !== 'pool') {
                            $order = $stageOrder[$match->stage] ?? '5099';
                            return $order . ':' . $match->stage;
                        }
                        if ($groupByWeekend && $match->kickoff) {
                            // Group to the Saturday of the weekend (Fri/Sat/Sun cluster)
                            $weekend = $match->kickoff->copy()->startOfWeek(\Carbon\Carbon::SATURDAY);
                            if ($match->kickoff->isSunday()) {
                                $weekend = $match->kickoff->copy()->subDay();
                            } elseif ($match->kickoff->isFriday()) {
                                $weekend = $match->kickoff->copy()->addDay();
                            }
                            return $weekend->format('Ymd') . ':weekend';
                        }
                        if ($match->round) {
                            return str_pad($match->round, 4, '0', STR_PAD_LEFT) . ':round';
                        }
                        return '0000:unassigned';
                    })->sortKeysDesc();
                @endphp

                @forelse($groupedMatches as $groupKey => $groupMatches)
                    @php
                        $parts = explode(':', $groupKey, 2);
                        $sortKey = $parts[0];
                        $type = $parts[1] ?? '';

                        if ($type === 'round') {
                            $groupLabel = 'Round ' . (int) $sortKey;
                        } elseif ($type === 'weekend') {
                            $weekendDate = \Carbon\Carbon::createFromFormat('Ymd', $sortKey);
                            $groupLabel = 'Weekend of ' . $weekendDate->format('d M Y');
                        } elseif ($type === 'unassigned') {
                            $groupLabel = 'Unassigned';
                        } else {
                            $groupLabel = str($type)->replace('_', ' ')->title();
                        }
                    @endphp
                    @php
                        $isFixture = $groupMatches->first()->status !== 'ft';
                    @endphp
                    <div style="display: flex; align-items: center; gap: 10px; margin-top: 20px; margin-bottom: 8px;">
                        <h3 class="sec-title" style="margin: 0;">{{ $groupLabel }}</h3>
                        @if($isFixture)
                            <span style="font-family: var(--font-mono); font-size: 9px; padding: 2px 6px; background: rgba(255, 209, 0, .15); color: var(--color-brand-yellow); letter-spacing: .15em; text-transform: uppercase; border-radius: var(--r-xs); font-weight: 700;">Fixture</span>
                        @endif
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 2px;">
                        @foreach($groupMatches->sortByDesc('kickoff') as $match)
                            @php
                                $home = $match->matchTeams->firstWhere('side', 'home');
                                $away = $match->matchTeams->firstWhere('side', 'away');
                                $isLive = $match->status === 'live';
                                $isScheduled = ! in_array($match->status, ['ft', 'live']);
                                $homeWin = $home?->is_winner === true;
                                $awayWin = $away?->is_winner === true;
                            @endphp
                            <a href="{{ route('matches.show', $match) }}" @class(['fx-row', 'is-live' => $isLive])>
                                <div class="fx-date @if(! $isLive && ! $isScheduled) past @endif">
                                    @if($isLive)
                                        <span class="pulse-dot" style="width: 6px; height: 6px; margin-right: 4px;"></span> LIVE
                                    @elseif($isScheduled)
                                        {{ strtoupper($match->kickoff->format('D j M')) }}
                                    @else
                                        {{ strtoupper($match->kickoff->format('j M Y')) }}
                                    @endif
                                </div>
                                <div class="fx-home">{{ $home?->team->name ?? 'TBD' }}</div>
                                <div class="fx-score">
                                    @if($isScheduled && ! $isLive)
                                        <span class="dash">V</span>
                                    @else
                                        <span @class(['fx-winner' => $homeWin])>{{ $home?->score ?? 0 }}</span>
                                        <span class="dash">—</span>
                                        <span @class(['fx-winner' => $awayWin])>{{ $away?->score ?? 0 }}</span>
                                    @endif
                                </div>
                                <div class="fx-away">{{ $away?->team->name ?? 'TBD' }}</div>
                                <div class="fx-meta">
                                    @if($isScheduled && ! $isLive)
                                        {{ $match->kickoff->format('H:i') }}
                                    @elseif($match->venue?->city)
                                        {{ $match->venue->city }}
                                    @endif
                                </div>
                            </a>
                        @endforeach
                    </div>
                @empty
                    <div class="widget" style="padding: 40px; text-align: center; color: var(--color-muted);">
                        No matches found for this season.
                    </div>
                @endforelse
            </div>
        @endif

        {{-- Teams Tab --}}
        @if($activeTab === 'teams')
            <h3 class="sec-title" style="margin-top: 0;">Teams in Season</h3>
            @if($teams->isNotEmpty())
                <div class="team-grid">
                    @foreach($teams as $team)
                        @php
                            // Derive team initials for the badge
                            $initials = collect(explode(' ', $team->name))
                                ->map(fn ($w) => mb_substr($w, 0, 1))
                                ->take(3)
                                ->implode('');
                            $initials = strtoupper($initials ?: mb_substr($team->name, 0, 2));
                            $accent = $team->primary_color ?? null;
                        @endphp
                        <a href="{{ route('teams.show', $team) }}" class="tt" @if($accent) style="--clr: {{ $accent }};" @endif>
                            <div class="tt-badge">
                                @if($team->logo_url)
                                    <img src="{{ $team->logo_url }}" alt="{{ $team->name }}" style="width:28px;height:28px;object-fit:contain;">
                                @else
                                    {{ $initials }}
                                @endif
                            </div>
                            <div class="tt-name">{{ $team->name }}</div>
                            @if($team->country)
                                <div class="tt-loc">{{ $team->country }}</div>
                            @endif
                            @if($team->type && $team->type !== 'club')
                                <div class="tt-tag">{{ strtoupper($team->type) }}</div>
                            @endif
                        </a>
                    @endforeach
                </div>
            @else
                <div class="widget" style="padding: 40px; text-align: center; color: var(--color-muted);">
                    No teams found.
                </div>
            @endif
        @endif

        {{-- Referees Tab --}}
        @if($activeTab === 'referees')
            <h3 class="sec-title" style="margin-top: 0;">Match Officials</h3>
            @if($referees->isNotEmpty())
                <div class="std-wrap">
                    <table class="std">
                        <thead>
                            <tr>
                                <th class="is-left" style="width: 36px;">#</th>
                                <th class="is-left">Name</th>
                                <th class="is-left">Nationality</th>
                                <th>Appointments</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($referees as $i => $referee)
                                <tr>
                                    <td class="is-left"><span class="pos">{{ $i + 1 }}</span></td>
                                    <td class="is-left team-name">
                                        <a href="{{ route('referees.show', $referee) }}">{{ $referee->full_name }}</a>
                                    </td>
                                    <td class="is-left" style="color: var(--color-ink-dim);">{{ $referee->nationality ?? '—' }}</td>
                                    <td class="pts">{{ $referee->match_officials_count }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="widget" style="padding: 40px; text-align: center; color: var(--color-muted);">
                    No referees found for this season.
                </div>
            @endif
        @endif

        {{-- Stats Tab: all-time competition stats --}}
        @if($activeTab === 'stats')
            {{-- Titles by team --}}
            <h3 class="sec-title" style="margin-top: 0;">Champions</h3>
            @if($titlesByTeam->isNotEmpty())
                <div class="std-wrap">
                    <table class="std">
                        <thead>
                            <tr>
                                <th class="is-left" style="width: 36px;">#</th>
                                <th class="is-left">Team</th>
                                <th>Titles</th>
                                <th class="is-left">Seasons Won</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($titlesByTeam->values() as $i => $entry)
                                <tr>
                                    <td class="is-left"><span class="pos @if($i === 0) is-top @endif">{{ $i + 1 }}</span></td>
                                    <td class="is-left team-name">
                                        <a href="{{ route('teams.show', $entry['team']) }}">{{ $entry['team']->name }}</a>
                                    </td>
                                    <td class="pts">{{ $entry['count'] }}</td>
                                    <td class="is-left" style="color: var(--color-ink-dim); font-family: var(--font-mono); font-size: 11px; letter-spacing: .05em;">
                                        {{ collect($entry['seasons'])->sortDesc()->implode(' · ') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="widget" style="padding: 40px; text-align: center; color: var(--color-muted);">
                    No titles tracked yet — finals matches need a stage='final' tag to count.
                </div>
            @endif

            {{-- Appearances by team (all-time matches played in this competition) --}}
            <h3 class="sec-title" style="margin-top: 32px;">All-Time Appearances</h3>
            @if(! empty($appearancesByTeam) && count($appearancesByTeam) > 0)
                <div class="std-wrap">
                    <table class="std">
                        <thead>
                            <tr>
                                <th class="is-left" style="width: 36px;">#</th>
                                <th class="is-left">Team</th>
                                <th>Seasons</th>
                                <th>Matches</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($appearancesByTeam as $i => $row)
                                <tr>
                                    <td class="is-left"><span class="pos">{{ $i + 1 }}</span></td>
                                    <td class="is-left team-name">
                                        <a href="{{ route('teams.show', $row->id) }}">{{ $row->name }}</a>
                                    </td>
                                    <td>{{ $row->seasons_in }}</td>
                                    <td class="pts">{{ $row->matches_played }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="widget" style="padding: 40px; text-align: center; color: var(--color-muted);">
                    No appearance data yet.
                </div>
            @endif
        @endif
        </div> {{-- /.page-body --}}
    @else
        <div class="page-body">
            <div class="widget" style="padding: 40px; text-align: center; color: var(--color-muted);">
                No seasons found for this competition.
            </div>
        </div>
    @endif
</div>
