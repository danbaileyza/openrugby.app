<div>
    <div class="mb-4 text-sm text-gray-400">
        <a href="{{ route('admin.index') }}" class="hover:text-white transition">Admin</a>
        <span class="mx-1 text-gray-600">/</span>
        <span class="text-white">Referees</span>
    </div>

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Referees</h1>
            <p class="text-sm text-gray-400 mt-1">Match officials.</p>
        </div>
        <a href="{{ route('admin.referees.create') }}" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500 transition">+ New Referee</a>
    </div>

    @if(session()->has('message'))
        <div class="mb-4 rounded-md bg-emerald-900/40 border border-emerald-800 px-4 py-2 text-sm text-emerald-300">
            {{ session('message') }}
        </div>
    @endif

    <div class="flex gap-3 mb-4">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search name or nationality…" class="flex-1 rounded-md bg-gray-900 border border-gray-800 px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-emerald-500 focus:outline-none">
    </div>

    <div class="rounded-xl bg-gray-900 border border-gray-800 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-900/50 text-gray-500">
                <tr class="text-xs uppercase tracking-wider">
                    <th class="px-4 py-3 text-left font-medium">Name</th>
                    <th class="px-4 py-3 text-left font-medium">Nationality</th>
                    <th class="px-4 py-3 text-left font-medium">Tier</th>
                    <th class="px-4 py-3 text-right font-medium">Matches</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @forelse($referees as $r)
                    <tr class="hover:bg-gray-800/30 transition">
                        <td class="px-4 py-3 text-white font-medium">{{ $r->first_name }} {{ $r->last_name }}</td>
                        <td class="px-4 py-3 text-gray-400">{{ $r->nationality ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-400 capitalize">{{ $r->tier ? str($r->tier)->replace('_', ' ') : '—' }}</td>
                        <td class="px-4 py-3 text-right text-gray-300">{{ $r->match_officials_count }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('referees.show', $r) }}" class="text-xs text-gray-400 hover:text-white transition">View</a>
                            <span class="mx-1 text-gray-700">·</span>
                            <a href="{{ route('admin.referees.edit', $r) }}" class="text-xs text-emerald-400 hover:text-emerald-300 transition">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">No referees. <a href="{{ route('admin.referees.create') }}" class="text-emerald-400 hover:text-emerald-300">Create one</a>.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($referees->hasPages())
        <div class="mt-4">{{ $referees->links() }}</div>
    @endif
</div>
