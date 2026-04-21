<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">Referees</h1>
        <p class="text-gray-400">Match officials database</p>
    </div>

    {{-- Search --}}
    <div class="mb-6">
        <input
            wire:model.live.debounce.300ms="search"
            type="text"
            placeholder="Search referees..."
            class="w-full sm:w-80 rounded-lg bg-gray-900 border-gray-700 text-sm text-gray-300 px-4 py-2 focus:border-emerald-500 focus:ring-emerald-500"
        >
    </div>

    {{-- Referees Table --}}
    <div class="rounded-xl bg-gray-900 border border-gray-800 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-gray-500 uppercase tracking-wider border-b border-gray-800">
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Nationality</th>
                    <th class="px-4 py-3 text-center">Matches</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @forelse($referees as $referee)
                    <tr class="hover:bg-gray-800/50 transition">
                        <td class="px-4 py-3">
                            <a href="{{ route('referees.show', $referee) }}" class="font-medium text-white hover:text-emerald-400 transition">
                                {{ $referee->first_name }} {{ $referee->last_name }}
                            </a>
                        </td>
                        <td class="px-4 py-3 text-gray-400">{{ $referee->nationality ?? '—' }}</td>
                        <td class="px-4 py-3 text-center text-gray-400">{{ $referee->match_officials_count }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-8 text-center text-gray-500">
                            No referees found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $referees->links() }}
    </div>
</div>
