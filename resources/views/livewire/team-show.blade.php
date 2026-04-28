<div>
    {{-- ═══ Stadium Team Hero ═══ --}}
    @php
        $initials = $team->short_name
            ? strtoupper(substr($team->short_name, 0, 3))
            : strtoupper(substr(str_replace([' ', '-', '('], '', $team->name), 0, 2));
        $teamColor = $team->primary_color ?: '#1d8a5b';
        // Split name for yellow-accent last word
        $nameWords = explode(' ', $team->name);
        $firstPart = count($nameWords) > 1 ? implode(' ', array_slice($nameWords, 0, -1)) : $team->name;
        $lastWord = count($nameWords) > 1 ? end($nameWords) : '';
    @endphp
    <section class="detail-hero team-hero" style="--crest-clr: {{ $teamColor }}; --hero-tint: {{ $teamColor }}40;">
        <div class="crumb">← <a href="{{ route('teams.index') }}">Teams</a></div>
        <div class="top-row">
            @if($team->logo_url)
                <img src="{{ $team->logo_url }}" alt="{{ $team->name }}" class="crest" style="object-fit: contain; background: var(--color-bg-2);">
            @else
                <div class="crest">{{ $initials }}</div>
            @endif
            <div style="flex: 1;">
                <h1>{{ $firstPart }}@if($lastWord) <span class="yellow">{{ $lastWord }}</span>@endif</h1>
                <div class="sub-meta">
                    @if($team->country_display)<span>{{ $team->country_display }}</span>@endif
                    <span>{{ ucfirst($team->type) }}</span>
                    @if($team->founded_year)<span>Est. {{ $team->founded_year }}</span>@endif
                </div>
            </div>
            @auth
                <livewire:favourite-button type="team" :id="$team->id" :key="'fav-team-'.$team->id" />
            @endauth
        </div>

        {{-- Ticker: record --}}
        <div class="ticker" style="margin-top: 22px;">
            <div class="tick">
                <span class="tick-label">Played</span>
                <span class="tick-n">{{ $record['played'] }}</span>
            </div>
            <div class="tick">
                <span class="tick-label">Won</span>
                <span class="tick-n" style="color: var(--color-home-bright);">{{ $record['won'] }}</span>
                <span class="tick-delta">vs {{ $record['lost'] }} lost</span>
            </div>
            <div class="tick">
                <span class="tick-label">Drawn</span>
                <span class="tick-n">{{ $record['drawn'] }}</span>
            </div>
            <div class="tick hot">
                <span class="tick-label">Win Rate</span>
                <span class="tick-n">{{ $record['played'] > 0 ? round(($record['won'] / $record['played']) * 100) : 0 }}<span style="font-size: 22px;">%</span></span>
            </div>
        </div>
    </section>

    <div class="page-body">
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Recent Matches --}}
        <div class="lg:col-span-2">
            <h2 class="text-lg font-semibold text-white mb-4">Recent Matches</h2>
            <div class="rounded-xl bg-gray-900 border border-gray-800 divide-y divide-gray-800">
                @forelse($recentMatches as $match)
                    @php
                        $home = $match->matchTeams->firstWhere('side', 'home');
                        $away = $match->matchTeams->firstWhere('side', 'away');
                        $us = $match->matchTeams->firstWhere('team_id', $team->id);
                        $them = $match->matchTeams->where('team_id', '!=', $team->id)->first();
                    @endphp
                    <a href="{{ route('matches.show', $match) }}" class="flex items-center justify-between px-4 py-3 hover:bg-gray-800/50 transition">
                        <div>
                            <div class="text-xs text-gray-500">{{ $match->season->competition->name }} &middot; R{{ $match->round }}</div>
                            <div class="flex items-center gap-2 mt-1">
                                @if($us && $them)
                                    @php
                                        $result = match(true) {
                                            $us->score > $them->score => 'W',
                                            $us->score < $them->score => 'L',
                                            default => 'D',
                                        };
                                    @endphp
                                    <span @class([
                                        'rounded px-1.5 py-0.5 text-xs font-bold',
                                        'bg-emerald-600/20 text-emerald-400' => $result === 'W',
                                        'bg-red-600/20 text-red-400' => $result === 'L',
                                        'bg-gray-600/20 text-gray-400' => $result === 'D',
                                    ])>{{ $result }}</span>
                                @endif
                                <span class="text-white">{{ $home?->team->name }} {{ $home?->score }} - {{ $away?->score }} {{ $away?->team->name }}</span>
                            </div>
                        </div>
                        <span class="text-xs text-gray-500">{{ $match->kickoff->format('d M Y') }}</span>
                    </a>
                @empty
                    <div class="px-4 py-8 text-center text-gray-500">No matches found for this team.</div>
                @endforelse
            </div>
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            {{-- Competitions --}}
            @if($competitions->isNotEmpty())
                <div>
                    <h2 class="text-lg font-semibold text-white mb-4">Competitions</h2>
                    <div class="rounded-xl bg-gray-900 border border-gray-800 divide-y divide-gray-800">
                        @foreach($competitions as $comp)
                            <a href="{{ route('competitions.show', $comp) }}" class="flex items-center justify-between px-4 py-3 hover:bg-gray-800/50 transition">
                                <span class="text-sm text-white">{{ $comp->name }}</span>
                                <span class="text-xs text-gray-500 rounded-full bg-gray-800 px-2 py-0.5">{{ $comp->format }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Current Squad --}}
            <div>
                <h2 class="text-lg font-semibold text-white mb-4">Squad</h2>
                <div class="rounded-xl bg-gray-900 border border-gray-800 divide-y divide-gray-800">
                    @forelse($players as $player)
                        <a href="{{ route('players.show', $player) }}" class="flex items-center justify-between px-4 py-2 hover:bg-gray-800/50 transition">
                            <span class="text-sm text-white">{{ $player->first_name }} {{ $player->last_name }}</span>
                            <span class="text-xs text-gray-500">{{ str($player->position)->replace('_', ' ')->title() }}</span>
                        </a>
                    @empty
                        <div class="px-4 py-6 text-center text-gray-500 text-sm">
                            No squad data yet — import players with rugbypy.
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Standings --}}
            @if($standings->isNotEmpty())
                <div>
                    <h2 class="text-lg font-semibold text-white mb-4">Standings</h2>
                    @foreach($standings as $standing)
                        <div class="rounded-xl bg-gray-900 border border-gray-800 p-4 mb-3">
                            <div class="text-xs text-gray-500 mb-1">{{ $standing->season->competition->name }} {{ $standing->season->label }}</div>
                            <div class="text-2xl font-bold text-white">{{ ordinal($standing->position) }}</div>
                            <div class="text-sm text-gray-400 mt-1">
                                P{{ $standing->played }} W{{ $standing->won }} D{{ $standing->drawn }} L{{ $standing->lost }}
                                &middot; {{ $standing->total_points }}pts
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
    </div> {{-- /.page-body --}}
</div>
