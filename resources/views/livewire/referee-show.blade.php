<div>
    {{-- ═══ Stadium Hero ═══ --}}
    <section class="detail-hero">
        <div class="crumb">← <a href="{{ route('referees.index') }}">Referees</a></div>
        @php
            // Split the name at the last space for two-line yellow-accent title
            $fullName = $referee->first_name.' '.$referee->last_name;
            $nameWords = explode(' ', $fullName);
            $firstPart = count($nameWords) > 1 ? implode(' ', array_slice($nameWords, 0, -1)) : $fullName;
            $lastWord = count($nameWords) > 1 ? end($nameWords) : '';
        @endphp
        <h1>
            {{ $firstPart }}@if($lastWord)<br><span class="yellow">{{ $lastWord }}</span>@endif
        </h1>
        <div class="sub-meta">
            <span>{{ $referee->nationality ?? 'Unknown nationality' }}</span>
            @if($referee->tier)
                <span class="yellow" style="color: var(--color-brand-yellow);">{{ str($referee->tier)->replace('_', ' ')->upper() }}</span>
            @endif
            <span>{{ $totalMatches }} {{ Str::plural('appointment', $totalMatches) }}</span>
        </div>

        {{-- Role ticker --}}
        <div class="ticker" style="margin-top: 24px;">
            <div class="tick hot">
                <span class="tick-label">As Referee</span>
                <span class="tick-n">{{ $asReferee }}</span>
                <span class="tick-delta">{{ $totalMatches > 0 ? round(($asReferee / $totalMatches) * 100) : 0 }}% of total</span>
            </div>
            @foreach($roleBreakdown->except('referee') as $role => $count)
                <div class="tick">
                    <span class="tick-label">{{ str($role)->replace('_', ' ')->title() }}</span>
                    <span class="tick-n">{{ $count }}</span>
                </div>
            @endforeach
            @if($roleBreakdown->except('referee')->isEmpty())
                <div class="tick">
                    <span class="tick-label">Total</span>
                    <span class="tick-n">{{ $totalMatches }}</span>
                </div>
            @endif
        </div>
    </section>

    {{-- Tabs --}}
    <nav class="detail-tabs">
        <button wire:click="setTab('matches')" @class(['is-active' => $activeTab === 'matches'])>Match History</button>
        <button wire:click="setTab('team-stats')" @class(['is-active' => $activeTab === 'team-stats'])>Team Stats</button>
    </nav>

    <div class="page-body">

        {{-- ═══ MATCH HISTORY TAB ═══ --}}
        @if($activeTab === 'matches')
            @if($appointments->isNotEmpty())
                <div style="display: flex; flex-direction: column; gap: 2px;">
                    @foreach($appointments as $appointment)
                        @php
                            $match = $appointment->match;
                            $home = $match->matchTeams->firstWhere('side', 'home');
                            $away = $match->matchTeams->firstWhere('side', 'away');
                            $hasScore = $home?->score !== null && $away?->score !== null;
                            $homeWin = $hasScore && $home->score > $away->score;
                            $awayWin = $hasScore && $away->score > $home->score;
                            $isLive = $match->status === 'live';
                        @endphp
                        <a href="{{ route('matches.show', $match) }}" @class(['fx-row', 'is-live' => $isLive])>
                            <div class="fx-date @if($match->kickoff?->isPast() && ! $isLive) past @endif">
                                @if($isLive)
                                    <span class="pulse-dot" style="width: 6px; height: 6px; margin-right: 4px;"></span> LIVE
                                @elseif($match->kickoff)
                                    {{ strtoupper($match->kickoff->format('j M Y')) }}
                                @else
                                    TBD
                                @endif
                            </div>
                            <div class="fx-home">{{ $home?->team->name ?? 'TBD' }}</div>
                            <div class="fx-score">
                                @if($hasScore)
                                    <span @class(['fx-winner' => $homeWin])>{{ $home->score }}</span>
                                    <span class="dash">—</span>
                                    <span @class(['fx-winner' => $awayWin])>{{ $away->score }}</span>
                                @else
                                    <span class="dash">V</span>
                                @endif
                            </div>
                            <div class="fx-away">{{ $away?->team->name ?? 'TBD' }}</div>
                            <div class="fx-meta">
                                {{ $match->season?->competition?->name ?? '' }}
                                @if($appointment->role !== 'referee')
                                    <br><span style="color: var(--color-brand-yellow); font-size: 9px; letter-spacing: .15em;">{{ str($appointment->role)->replace('_', ' ')->upper() }}</span>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>
            @else
                <div class="widget" style="padding: 40px; text-align: center; color: var(--color-muted);">
                    No match appointments found.
                </div>
            @endif
        @endif

        {{-- ═══ TEAM STATS TAB ═══ --}}
        @if($activeTab === 'team-stats')
            @if($teamStats->isNotEmpty())
                <p style="font-family: var(--font-mono); font-size: 11px; color: var(--color-muted); letter-spacing: .08em; text-transform: uppercase; margin: 0 0 14px;">
                    Win/loss record for teams in matches {{ $referee->first_name }} {{ $referee->last_name }} refereed.
                </p>
                <div class="std-wrap">
                    <table class="std" style="max-width: 720px;">
                        <thead>
                            <tr>
                                <th class="is-left" style="width: 100%;">Team</th>
                                <th>Matches</th>
                                <th>W</th>
                                <th>L</th>
                                <th>D</th>
                                <th>Win %</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($teamStats as $teamId => $stat)
                                @php
                                    $winPct = $stat['matches'] > 0 ? round(($stat['wins'] / $stat['matches']) * 100) : 0;
                                    $isExpanded = $expandedTeam === $teamId;
                                    $teamMatches = $isExpanded
                                        ? $appointments->where('role', 'referee')->filter(fn ($a) => $a->match->matchTeams->contains('team_id', $teamId))
                                        : collect();
                                    $winPctColor = $winPct >= 60 ? 'var(--color-home-bright)' : ($winPct >= 40 ? 'var(--color-brand-yellow)' : 'var(--color-live)');
                                @endphp
                                <tr wire:click="toggleTeam('{{ $teamId }}')" style="cursor: pointer;">
                                    <td class="is-left team-name">
                                        <span style="color: var(--color-muted); margin-right: 6px; font-size: 10px;">{{ $isExpanded ? '▼' : '▶' }}</span>
                                        {{ $stat['team']->name }}
                                    </td>
                                    <td>{{ $stat['matches'] }}</td>
                                    <td class="win">{{ $stat['wins'] }}</td>
                                    <td class="loss">{{ $stat['losses'] }}</td>
                                    <td>{{ $stat['draws'] }}</td>
                                    <td style="color: {{ $winPctColor }}; font-weight: 700;">{{ $winPct }}%</td>
                                </tr>
                                @if($isExpanded && $teamMatches->isNotEmpty())
                                    <tr>
                                        <td colspan="6" style="padding: 12px 14px; background: rgba(255, 209, 0, .02);">
                                            <div style="display: flex; flex-direction: column; gap: 2px;">
                                                @foreach($teamMatches->sortByDesc(fn ($a) => $a->match->kickoff) as $appointment)
                                                    @php
                                                        $match = $appointment->match;
                                                        $home = $match->matchTeams->firstWhere('side', 'home');
                                                        $away = $match->matchTeams->firstWhere('side', 'away');
                                                        $thisTeam = $match->matchTeams->firstWhere('team_id', $teamId);
                                                        $opponent = $match->matchTeams->firstWhere(fn ($mt) => $mt->team_id !== $teamId);
                                                        $result = null;
                                                        if ($thisTeam && $opponent && $thisTeam->score !== null && $opponent->score !== null) {
                                                            $result = $thisTeam->score > $opponent->score ? 'W'
                                                                : ($thisTeam->score < $opponent->score ? 'L' : 'D');
                                                        }
                                                        $resultColor = match ($result) {
                                                            'W' => 'var(--color-home)',
                                                            'L' => 'var(--color-live)',
                                                            'D' => 'var(--color-muted)',
                                                            default => 'transparent',
                                                        };
                                                    @endphp
                                                    <a href="{{ route('matches.show', $match) }}" style="display: flex; align-items: center; gap: 12px; padding: 8px 12px; background: var(--color-bg-2); color: var(--color-ink); text-decoration: none; font-size: 12px;">
                                                        @if($result)
                                                            <span style="display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; background: {{ $resultColor }}; color: #fff; font-family: var(--font-display); font-weight: 900; font-size: 11px;">{{ $result }}</span>
                                                        @endif
                                                        <span style="flex: 1;">
                                                            {{ $home?->team?->name ?? 'TBD' }}
                                                            <span style="font-family: var(--font-mono); color: var(--color-muted); margin: 0 4px;">{{ $home?->score ?? '-' }}–{{ $away?->score ?? '-' }}</span>
                                                            {{ $away?->team?->name ?? 'TBD' }}
                                                        </span>
                                                        <span style="font-family: var(--font-mono); font-size: 10px; color: var(--color-muted); letter-spacing: .08em;">
                                                            {{ $match->kickoff?->format('d M Y') }}
                                                        </span>
                                                    </a>
                                                @endforeach
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="widget" style="padding: 40px; text-align: center; color: var(--color-muted);">
                    No matches as main referee yet — team stats are calculated from matches where this official was the referee.
                </div>
            @endif
        @endif
    </div>
</div>
