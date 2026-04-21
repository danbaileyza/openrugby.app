<div>
    {{-- Breadcrumb --}}
    <div class="mb-4 text-sm text-gray-400">
        <a href="{{ route('admin.index') }}" class="hover:text-white transition">Admin</a>
        <span class="mx-1 text-gray-600">/</span>
        <span class="text-white">Fixtures</span>
    </div>

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Fixtures</h1>
            <p class="text-sm text-gray-400 mt-1">Match scheduling. Capture scores from the match page.</p>
        </div>
        <a href="{{ route('admin.fixtures.create') }}" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500 transition">+ New Fixture</a>
    </div>

    @if(session()->has('message'))
        <div class="mb-4 rounded-md bg-emerald-900/40 border border-emerald-800 px-4 py-2 text-sm text-emerald-300">
            {{ session('message') }}
        </div>
    @endif

    <div class="flex flex-wrap gap-3 mb-4">
        <select wire:model.live="competition_id" class="rounded-md bg-gray-900 border border-gray-800 px-3 py-2 text-sm text-white">
            <option value="all">All competitions</option>
            @foreach($competitions as $comp)
                <option value="{{ $comp->id }}">{{ $comp->name }}@if($comp->grade) ({{ $comp->grade }})@endif</option>
            @endforeach
        </select>
        @if($seasonsForFilter->isNotEmpty())
            <select wire:model.live="season_id" class="rounded-md bg-gray-900 border border-gray-800 px-3 py-2 text-sm text-white">
                <option value="all">All seasons</option>
                @foreach($seasonsForFilter as $season)
                    <option value="{{ $season->id }}">{{ $season->label }}</option>
                @endforeach
            </select>
        @endif
        <select wire:model.live="status" class="rounded-md bg-gray-900 border border-gray-800 px-3 py-2 text-sm text-white">
            <option value="all">Any status</option>
            <option value="scheduled">Scheduled</option>
            <option value="live">Live</option>
            <option value="ft">Full Time</option>
            <option value="postponed">Postponed</option>
            <option value="cancelled">Cancelled</option>
            <option value="abandoned">Abandoned</option>
        </select>
    </div>

    <div class="rounded-xl bg-gray-900 border border-gray-800 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-900/50 text-gray-500">
                <tr class="text-xs uppercase tracking-wider">
                    <th class="px-4 py-3 text-left font-medium">Kickoff</th>
                    <th class="px-4 py-3 text-left font-medium">Match</th>
                    <th class="px-4 py-3 text-left font-medium">Competition</th>
                    <th class="px-4 py-3 text-center font-medium">Score</th>
                    <th class="px-4 py-3 text-center font-medium">Status</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @forelse($matches as $match)
                    @php
                        $home = $match->matchTeams->firstWhere('side', 'home');
                        $away = $match->matchTeams->firstWhere('side', 'away');
                        $hasScore = $home?->score !== null && $away?->score !== null;
                    @endphp
                    <tr class="hover:bg-gray-800/30 transition">
                        <td class="px-4 py-3 text-gray-300 whitespace-nowrap">{{ $match->kickoff->format('j M \'y H:i') }}</td>
                        <td class="px-4 py-3 text-white">
                            {{ $home?->team->name ?? 'TBD' }} <span class="text-gray-600">vs</span> {{ $away?->team->name ?? 'TBD' }}
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-400">
                            {{ $match->season->competition->name }}
                            @if($match->season->competition->grade) <span class="text-gray-600">({{ $match->season->competition->grade }})</span> @endif
                            <span class="block text-[11px] text-gray-600">{{ $match->season->label }}</span>
                        </td>
                        <td class="px-4 py-3 text-center font-mono">
                            @if($hasScore)
                                <span class="text-emerald-400">{{ $home->score }}–{{ $away->score }}</span>
                            @else
                                <span class="text-gray-600">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center text-xs text-gray-400 capitalize">{{ str($match->status)->replace('_', ' ') }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('matches.show', $match) }}" class="text-xs text-gray-400 hover:text-white transition">View</a>
                            <span class="mx-1 text-gray-700">·</span>
                            <a href="{{ route('admin.fixtures.edit', $match) }}" class="text-xs text-emerald-400 hover:text-emerald-300 transition">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">
                            No fixtures match. <a href="{{ route('admin.fixtures.create') }}" class="text-emerald-400 hover:text-emerald-300">Create one</a>.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($matches->hasPages())
        <div class="mt-4">{{ $matches->links() }}</div>
    @endif
</div>
