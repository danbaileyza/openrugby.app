<div>
    {{-- Breadcrumb --}}
    <div class="mb-4 text-sm text-gray-400">
        <a href="{{ route('admin.index') }}" class="hover:text-white transition">Admin</a>
        <span class="mx-1 text-gray-600">/</span>
        <a href="{{ route('admin.competitions.index') }}" class="hover:text-white transition">Competitions</a>
        <span class="mx-1 text-gray-600">/</span>
        <span class="text-white">{{ $competition->name }}</span>
    </div>

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">{{ $competition->name }}</h1>
            <p class="text-sm text-gray-400 mt-1">
                <span class="capitalize">{{ $competition->level }}</span>
                @if($competition->grade) &middot; {{ $competition->grade }} @endif
                &middot; <span class="capitalize">{{ $competition->format }}</span>
            </p>
        </div>
        <a href="{{ route('admin.competitions.seasons.create', $competition) }}" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500 transition">+ New Season</a>
    </div>

    @if(session()->has('message'))
        <div class="mb-4 rounded-md bg-emerald-900/40 border border-emerald-800 px-4 py-2 text-sm text-emerald-300">
            {{ session('message') }}
        </div>
    @endif

    <div class="rounded-xl bg-gray-900 border border-gray-800 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-900/50 text-gray-500">
                <tr class="text-xs uppercase tracking-wider">
                    <th class="px-4 py-3 text-left font-medium">Label</th>
                    <th class="px-4 py-3 text-left font-medium">Dates</th>
                    <th class="px-4 py-3 text-right font-medium">Teams</th>
                    <th class="px-4 py-3 text-right font-medium">Matches</th>
                    <th class="px-4 py-3 text-center font-medium">Current</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @forelse($seasons as $season)
                    <tr class="hover:bg-gray-800/30 transition">
                        <td class="px-4 py-3 text-white font-medium">{{ $season->label }}</td>
                        <td class="px-4 py-3 text-gray-400">
                            {{ $season->start_date?->format('j M Y') }} – {{ $season->end_date?->format('j M Y') }}
                        </td>
                        <td class="px-4 py-3 text-right text-gray-300">{{ $season->teams_count }}</td>
                        <td class="px-4 py-3 text-right text-gray-300">{{ $season->matches_count }}</td>
                        <td class="px-4 py-3 text-center">
                            @if($season->is_current)
                                <span class="rounded bg-emerald-900/40 text-emerald-300 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider">Current</span>
                            @else
                                <span class="text-gray-600">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.seasons.edit', $season) }}" class="text-xs text-emerald-400 hover:text-emerald-300 transition">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">
                            No seasons yet. <a href="{{ route('admin.competitions.seasons.create', $competition) }}" class="text-emerald-400 hover:text-emerald-300">Create one</a>.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
