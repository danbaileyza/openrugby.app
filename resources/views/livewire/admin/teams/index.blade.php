<div>
    {{-- Breadcrumb --}}
    <div class="mb-4 text-sm text-gray-400">
        <a href="{{ route('admin.index') }}" class="hover:text-white transition">Admin</a>
        <span class="mx-1 text-gray-600">/</span>
        <span class="text-white">Teams</span>
    </div>

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Teams</h1>
            <p class="text-sm text-gray-400 mt-1">Clubs, schools, and professional teams.</p>
        </div>
        <a href="{{ route('admin.teams.create') }}" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500 transition">+ New Team</a>
    </div>

    @if(session()->has('message'))
        <div class="mb-4 rounded-md bg-emerald-900/40 border border-emerald-800 px-4 py-2 text-sm text-emerald-300">
            {{ session('message') }}
        </div>
    @endif

    <div class="flex flex-wrap gap-3 mb-4">
        <input
            type="text"
            wire:model.live.debounce.300ms="search"
            placeholder="Search team name…"
            class="flex-1 min-w-[200px] rounded-md bg-gray-900 border border-gray-800 px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-emerald-500 focus:outline-none"
        >
        <select wire:model.live="type" class="rounded-md bg-gray-900 border border-gray-800 px-3 py-2 text-sm text-white">
            <option value="all">All types</option>
            <option value="club">Club</option>
            <option value="national">National</option>
            <option value="franchise">Franchise</option>
            <option value="provincial">Provincial</option>
            <option value="invitational">Invitational</option>
        </select>
    </div>

    <div class="rounded-xl bg-gray-900 border border-gray-800 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-900/50 text-gray-500">
                <tr class="text-xs uppercase tracking-wider">
                    <th class="px-4 py-3 text-left font-medium">Name</th>
                    <th class="px-4 py-3 text-left font-medium">Short</th>
                    <th class="px-4 py-3 text-left font-medium">Type</th>
                    <th class="px-4 py-3 text-left font-medium">Country</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @forelse($teams as $team)
                    <tr class="hover:bg-gray-800/30 transition">
                        <td class="px-4 py-3 text-white font-medium">{{ $team->name }}</td>
                        <td class="px-4 py-3 text-gray-400 font-mono">{{ $team->short_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-400 capitalize">{{ $team->type }}</td>
                        <td class="px-4 py-3 text-gray-400">{{ $team->country_display ?: '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.teams.squad', $team) }}" class="text-xs text-gray-400 hover:text-white transition">Squad</a>
                            <span class="mx-1 text-gray-700">·</span>
                            <a href="{{ route('admin.teams.edit', $team) }}" class="text-xs text-emerald-400 hover:text-emerald-300 transition">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">
                            No teams match. <a href="{{ route('admin.teams.create') }}" class="text-emerald-400 hover:text-emerald-300">Create one</a>.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($teams->hasPages())
        <div class="mt-4">{{ $teams->links() }}</div>
    @endif
</div>
