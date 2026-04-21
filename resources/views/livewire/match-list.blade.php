<div>
    <h1 class="text-2xl font-bold text-white mb-6">Matches</h1>

    <div class="flex flex-wrap gap-3 mb-6">
        <select wire:model.live="competition" class="rounded-lg bg-gray-800 border-gray-700 text-sm text-gray-300 px-3 py-2">
            <option value="">All competitions</option>
            @foreach($competitions as $comp)
                <option value="{{ $comp->id }}">{{ $comp->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="status" class="rounded-lg bg-gray-800 border-gray-700 text-sm text-gray-300 px-3 py-2">
            <option value="">All statuses</option>
            <option value="scheduled">Scheduled</option>
            <option value="live">Live</option>
            <option value="ft">Full Time</option>
            <option value="postponed">Postponed</option>
        </select>
    </div>

    <div class="rounded-xl bg-gray-900 border border-gray-800 divide-y divide-gray-800">
        @forelse($matches as $match)
            @php
                $home = $match->matchTeams->firstWhere('side', 'home');
                $away = $match->matchTeams->firstWhere('side', 'away');
            @endphp
            <a href="{{ route('matches.show', $match) }}" class="flex items-center justify-between px-5 py-4 hover:bg-gray-800/50 transition">
                <div class="flex-1">
                    <div class="text-xs text-gray-500 mb-1">
                        {{ $match->season->competition->name }} &middot;
                        @if($match->round) R{{ $match->round }} @endif
                        @if($match->stage) &middot; {{ str($match->stage)->replace('_', ' ')->title() }} @endif
                    </div>
                    <div class="flex items-center gap-4">
                        <span class="w-40 text-right font-medium {{ $home?->is_winner ? 'text-white' : 'text-gray-400' }}">
                            {{ $home?->team->name ?? 'TBD' }}
                        </span>
                        @if($match->status === 'ft')
                            <span class="rounded bg-gray-800 px-3 py-1 text-sm font-mono font-bold text-emerald-400 min-w-[70px] text-center">
                                {{ $home?->score ?? '-' }} - {{ $away?->score ?? '-' }}
                            </span>
                        @elseif($match->status === 'live')
                            <span class="rounded bg-red-600/20 px-3 py-1 text-sm font-bold text-red-400 min-w-[70px] text-center animate-pulse">
                                LIVE
                            </span>
                        @else
                            <span class="rounded bg-gray-800 px-3 py-1 text-xs text-gray-500 min-w-[70px] text-center">
                                {{ $match->kickoff->format('H:i') }}
                            </span>
                        @endif
                        <span class="w-40 font-medium {{ $away?->is_winner ? 'text-white' : 'text-gray-400' }}">
                            {{ $away?->team->name ?? 'TBD' }}
                        </span>
                    </div>
                </div>
                <div class="text-right ml-4">
                    <div class="text-xs text-gray-500">{{ $match->kickoff->format('d M Y') }}</div>
                    @if($match->venue)
                        <div class="text-xs text-gray-600">{{ $match->venue->name }}</div>
                    @endif
                </div>
            </a>
        @empty
            <div class="px-4 py-12 text-center text-gray-500">
                No matches found. Run <code class="text-emerald-400">php artisan rugby:sync-daily</code> to pull data.
            </div>
        @endforelse
    </div>

    <div class="mt-6">{{ $matches->links() }}</div>
</div>
