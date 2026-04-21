<div>
    <div class="mb-6">
        <a href="{{ route('referees.index') }}" class="text-sm text-gray-400 hover:text-white transition">&larr; Referees</a>
        <h1 class="text-2xl font-bold text-white mt-2">{{ $referee->first_name }} {{ $referee->last_name }}</h1>
        <p class="text-gray-400">
            {{ $referee->nationality ?? 'Unknown nationality' }}
            @if($referee->tier) &middot; {{ str($referee->tier)->replace('_', ' ')->title() }} @endif
        </p>
    </div>

    {{-- Stats Bar --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="rounded-xl bg-gray-900 border border-gray-800 p-4 text-center">
            <p class="text-2xl font-bold text-white">{{ $totalMatches }}</p>
            <p class="text-xs text-gray-500 mt-1">Total Matches</p>
        </div>
        <div class="rounded-xl bg-gray-900 border border-gray-800 p-4 text-center">
            <p class="text-2xl font-bold text-emerald-400">{{ $asReferee }}</p>
            <p class="text-xs text-gray-500 mt-1">As Referee</p>
        </div>
        @foreach($roleBreakdown->except('referee') as $role => $count)
            <div class="rounded-xl bg-gray-900 border border-gray-800 p-4 text-center">
                <p class="text-2xl font-bold text-white">{{ $count }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ str($role)->replace('_', ' ')->title() }}</p>
            </div>
        @endforeach
    </div>

    {{-- Tab Navigation --}}
    <div class="flex gap-1 mb-6 border-b border-gray-800">
        @foreach([
            'matches' => 'Match History',
            'team-stats' => 'Team Stats',
        ] as $key => $label)
            <button
                wire:click="setTab('{{ $key }}')"
                @class([
                    'px-4 py-2.5 text-sm font-medium transition border-b-2 -mb-px',
                    'border-emerald-400 text-emerald-400' => $activeTab === $key,
                    'border-transparent text-gray-400 hover:text-white hover:border-gray-600' => $activeTab !== $key,
                ])
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- ═══ MATCH HISTORY TAB ═══ --}}
    @if($activeTab === 'matches')
        <div class="rounded-xl bg-gray-900 border border-gray-800 divide-y divide-gray-800">
            @forelse($appointments as $appointment)
                @php
                    $match = $appointment->match;
                    $home = $match->matchTeams->firstWhere('side', 'home');
                    $away = $match->matchTeams->firstWhere('side', 'away');
                @endphp
                <a href="{{ route('matches.show', $match) }}" class="flex items-center justify-between px-4 py-3 hover:bg-gray-800/50 transition block">
                    <div class="flex-1">
                        <div class="text-xs text-gray-500 mb-1">
                            {{ $match->season?->competition?->name }}
                            @if($match->round) &middot; R{{ $match->round }} @endif
                            <span @class([
                                'ml-2 rounded-full px-2 py-0.5 text-xs font-medium',
                                'bg-emerald-600/15 text-emerald-400' => $appointment->role === 'referee',
                                'bg-gray-800 text-gray-400' => $appointment->role !== 'referee',
                            ])>
                                {{ str($appointment->role)->replace('_', ' ')->title() }}
                            </span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="font-medium {{ $home?->is_winner ? 'text-white' : 'text-gray-400' }}">
                                {{ $home?->team->name ?? 'TBD' }}
                            </span>
                            <span class="rounded bg-gray-800 px-2 py-0.5 text-sm font-mono font-bold text-emerald-400">
                                {{ $home?->score ?? '-' }} - {{ $away?->score ?? '-' }}
                            </span>
                            <span class="font-medium {{ $away?->is_winner ? 'text-white' : 'text-gray-400' }}">
                                {{ $away?->team->name ?? 'TBD' }}
                            </span>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-gray-500">{{ $match->kickoff?->format('d M Y') }}</div>
                        @if($match->venue)
                            <div class="text-xs text-gray-600">{{ $match->venue->name }}</div>
                        @endif
                    </div>
                </a>
            @empty
                <div class="px-4 py-8 text-center text-gray-500">
                    No match appointments found.
                </div>
            @endforelse
        </div>
    @endif

    {{-- ═══ TEAM STATS TAB ═══ --}}
    @if($activeTab === 'team-stats')
        @if($teamStats->isNotEmpty())
            <p class="text-sm text-gray-500 mb-4">Win/loss record for teams in matches where {{ $referee->first_name }} {{ $referee->last_name }} was the referee.</p>
            <div class="rounded-xl bg-gray-900 border border-gray-800 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 uppercase tracking-wider border-b border-gray-800">
                            <th class="px-4 py-3">Team</th>
                            <th class="px-4 py-3 text-center">Matches</th>
                            <th class="px-4 py-3 text-center">W</th>
                            <th class="px-4 py-3 text-center">L</th>
                            <th class="px-4 py-3 text-center">D</th>
                            <th class="px-4 py-3 text-center">Win %</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        @foreach($teamStats as $teamId => $stat)
                            @php
                                $winPct = $stat['matches'] > 0
                                    ? round(($stat['wins'] / $stat['matches']) * 100)
                                    : 0;
                                $isExpanded = $expandedTeam === $teamId;
                                // Get matches where this ref officiated this team (as main referee)
                                $teamMatches = $isExpanded
                                    ? $appointments->where('role', 'referee')->filter(fn ($a) => $a->match->matchTeams->contains('team_id', $teamId))
                                    : collect();
                            @endphp
                            <tr wire:click="toggleTeam('{{ $teamId }}')" class="hover:bg-gray-800/50 transition cursor-pointer">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <span class="text-gray-500 text-xs">{{ $isExpanded ? '▼' : '▶' }}</span>
                                        <span class="font-medium text-white hover:text-emerald-400 transition">
                                            {{ $stat['team']->name }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center text-gray-400">{{ $stat['matches'] }}</td>
                                <td class="px-4 py-3 text-center text-emerald-400">{{ $stat['wins'] }}</td>
                                <td class="px-4 py-3 text-center text-red-400">{{ $stat['losses'] }}</td>
                                <td class="px-4 py-3 text-center text-gray-400">{{ $stat['draws'] }}</td>
                                <td class="px-4 py-3 text-center">
                                    <span @class([
                                        'font-medium',
                                        'text-emerald-400' => $winPct >= 60,
                                        'text-yellow-400' => $winPct >= 40 && $winPct < 60,
                                        'text-red-400' => $winPct < 40,
                                    ])>
                                        {{ $winPct }}%
                                    </span>
                                </td>
                            </tr>
                            @if($isExpanded && $teamMatches->isNotEmpty())
                                <tr>
                                    <td colspan="6" class="px-6 py-3 bg-gray-950/50">
                                        <div class="space-y-2">
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
                                                @endphp
                                                <a href="{{ route('matches.show', $match) }}" class="flex items-center justify-between px-3 py-2 rounded-lg hover:bg-gray-800/60 transition text-sm">
                                                    <div class="flex items-center gap-3">
                                                        @if($result)
                                                            <span @class([
                                                                'rounded px-1.5 py-0.5 text-xs font-bold',
                                                                'bg-emerald-600/20 text-emerald-400' => $result === 'W',
                                                                'bg-red-600/20 text-red-400' => $result === 'L',
                                                                'bg-gray-600/20 text-gray-400' => $result === 'D',
                                                            ])>{{ $result }}</span>
                                                        @endif
                                                        <span class="text-gray-300">
                                                            {{ $home?->team?->name ?? 'TBD' }}
                                                            <span class="font-mono text-gray-500 mx-1">{{ $home?->score ?? '-' }}-{{ $away?->score ?? '-' }}</span>
                                                            {{ $away?->team?->name ?? 'TBD' }}
                                                        </span>
                                                    </div>
                                                    <div class="text-xs text-gray-500">
                                                        {{ $match->season?->competition?->name }}
                                                        &middot; {{ $match->kickoff?->format('d M Y') }}
                                                    </div>
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
            <div class="rounded-xl bg-gray-900 border border-gray-800 p-6 text-center text-gray-500">
                No matches as main referee yet — team stats are calculated from matches where this official was the referee.
            </div>
        @endif
    @endif
</div>
