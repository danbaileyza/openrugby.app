<div>
    {{-- Breadcrumb --}}
    <div class="mb-4 text-sm text-gray-400">
        <a href="{{ route('admin.index') }}" class="hover:text-white transition">Admin</a>
        <span class="mx-1 text-gray-600">/</span>
        <span class="text-white">Competitions</span>
    </div>

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Competitions</h1>
            <p class="text-sm text-gray-400 mt-1">School, club, and professional leagues.</p>
        </div>
        <a href="{{ route('admin.competitions.create') }}" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500 transition">+ New Competition</a>
    </div>

    @if(session()->has('message'))
        <div class="mb-4 rounded-md bg-emerald-900/40 border border-emerald-800 px-4 py-2 text-sm text-emerald-300">
            {{ session('message') }}
        </div>
    @endif

    {{-- Filters --}}
    <div class="flex flex-wrap gap-3 mb-4">
        <input
            type="text"
            wire:model.live.debounce.300ms="search"
            placeholder="Search name, code, or grade…"
            class="flex-1 min-w-[200px] rounded-md bg-gray-900 border border-gray-800 px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-emerald-500 focus:outline-none"
        >
        <select wire:model.live="level" class="rounded-md bg-gray-900 border border-gray-800 px-3 py-2 text-sm text-white">
            <option value="all">All levels</option>
            <option value="professional">Professional</option>
            <option value="club">Club</option>
            <option value="school">School</option>
        </select>
    </div>

    {{-- List --}}
    <div class="rounded-xl bg-gray-900 border border-gray-800 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-900/50 text-gray-500">
                <tr class="text-xs uppercase tracking-wider">
                    <th class="px-4 py-3 text-left font-medium">Name</th>
                    <th class="px-4 py-3 text-left font-medium">Level</th>
                    <th class="px-4 py-3 text-left font-medium">Grade</th>
                    <th class="px-4 py-3 text-left font-medium">Format</th>
                    <th class="px-4 py-3 text-right font-medium">Seasons</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @forelse($competitions as $competition)
                    <tr class="hover:bg-gray-800/30 transition">
                        <td class="px-4 py-3">
                            <div class="text-white font-medium">{{ $competition->name }}</div>
                            <div class="text-xs text-gray-500 font-mono">{{ $competition->code }}</div>
                        </td>
                        <td class="px-4 py-3">
                            @php
                                $levelColor = match($competition->level) {
                                    'professional' => 'bg-blue-900/40 text-blue-300',
                                    'club' => 'bg-purple-900/40 text-purple-300',
                                    'school' => 'bg-amber-900/40 text-amber-300',
                                    default => 'bg-gray-800 text-gray-400',
                                };
                            @endphp
                            <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wider {{ $levelColor }}">{{ $competition->level }}</span>
                        </td>
                        <td class="px-4 py-3 text-gray-300">{{ $competition->grade ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-400 capitalize">{{ $competition->format }}</td>
                        <td class="px-4 py-3 text-right text-gray-300">{{ $competition->seasons_count }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.competitions.seasons', $competition) }}" class="text-xs text-gray-400 hover:text-white transition">Seasons</a>
                            <span class="mx-1 text-gray-700">·</span>
                            <a href="{{ route('admin.competitions.edit', $competition) }}" class="text-xs text-emerald-400 hover:text-emerald-300 transition">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">
                            No competitions yet. <a href="{{ route('admin.competitions.create') }}" class="text-emerald-400 hover:text-emerald-300">Create one</a>.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($competitions->hasPages())
        <div class="mt-4">{{ $competitions->links() }}</div>
    @endif
</div>
