<div>
    {{-- Breadcrumb --}}
    <div class="mb-4 text-sm text-gray-400">
        <a href="{{ route('admin.index') }}" class="hover:text-white transition">Admin</a>
        <span class="mx-1 text-gray-600">/</span>
        <a href="{{ route('admin.teams.index') }}" class="hover:text-white transition">Teams</a>
        <span class="mx-1 text-gray-600">/</span>
        <a href="{{ route('admin.teams.edit', $team) }}" class="hover:text-white transition">{{ $team->name }}</a>
        <span class="mx-1 text-gray-600">/</span>
        <span class="text-white">Squad</span>
    </div>

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">{{ $team->name }} — Squad</h1>
            <p class="text-sm text-gray-400 mt-1">Players currently registered for this team. Needed for lineups and event capture.</p>
        </div>
        <span class="text-sm text-gray-500">{{ $squad->count() }} players</span>
    </div>

    @if(session()->has('message'))
        <div class="mb-4 rounded-md bg-emerald-900/40 border border-emerald-800 px-4 py-2 text-sm text-emerald-300">
            {{ session('message') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-[1fr_340px] gap-6">
        {{-- Squad list --}}
        <div class="rounded-xl bg-gray-900 border border-gray-800 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-900/50 text-gray-500">
                    <tr class="text-xs uppercase tracking-wider">
                        <th class="px-4 py-3 text-left font-medium">Name</th>
                        <th class="px-4 py-3 text-left font-medium">Position</th>
                        <th class="px-4 py-3 text-right font-medium">Ht / Wt</th>
                        <th class="px-4 py-3 text-left font-medium">DoB</th>
                        <th class="px-4 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @forelse($squad as $contract)
                        <tr class="hover:bg-gray-800/30 transition">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.players.edit', $contract->player) }}" class="text-white hover:text-emerald-400 transition font-medium">
                                    {{ $contract->player->first_name }} {{ $contract->player->last_name }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-gray-400 capitalize">{{ str($contract->player->position)->replace('_', ' ') }}</td>
                            <td class="px-4 py-3 text-right text-xs text-gray-400 font-mono tabular-nums">
                                @if($contract->player->height_cm || $contract->player->weight_kg)
                                    {{ $contract->player->height_cm ? $contract->player->height_cm.' cm' : '—' }}
                                    <span class="text-gray-600"> / </span>
                                    {{ $contract->player->weight_kg ? $contract->player->weight_kg.' kg' : '—' }}
                                @else
                                    <span class="text-gray-600">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-400">{{ $contract->player->dob?->format('j M Y') ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('admin.players.edit', $contract->player) }}" class="text-xs text-emerald-400 hover:text-emerald-300 transition">Edit</a>
                                <span class="mx-1 text-gray-700">·</span>
                                <button type="button" wire:click="removeFromSquad('{{ $contract->id }}')" wire:confirm="Remove {{ $contract->player->first_name }} {{ $contract->player->last_name }} from the squad?" class="text-xs text-red-400 hover:text-red-300 transition">Remove</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">
                                No players in squad yet. Use the form on the right to add one.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Add player form --}}
        <div class="rounded-xl bg-gray-900 border border-gray-800 p-5">
            <h2 class="text-sm font-semibold text-white mb-3">Add Player</h2>
            <form wire:submit="addPlayer" class="space-y-3">
                <div>
                    <label class="block text-xs text-gray-500 uppercase tracking-wider mb-1">First name</label>
                    <input type="text" wire:model="newFirstName" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                    @error('newFirstName') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs text-gray-500 uppercase tracking-wider mb-1">Last name</label>
                    <input type="text" wire:model="newLastName" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                    @error('newLastName') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs text-gray-500 uppercase tracking-wider mb-1">Position</label>
                    <select wire:model="newPosition" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                        @foreach($positions as $pos)
                            <option value="{{ $pos }}">{{ str($pos)->replace('_', ' ')->title() }}</option>
                        @endforeach
                    </select>
                    @error('newPosition') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-gray-500 uppercase tracking-wider mb-1">Height (cm)</label>
                        <input type="number" wire:model="newHeightCm" min="100" max="230" placeholder="185" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white font-mono focus:border-emerald-500 focus:outline-none">
                        @error('newHeightCm') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 uppercase tracking-wider mb-1">Weight (kg)</label>
                        <input type="number" wire:model="newWeightKg" min="30" max="200" placeholder="95" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white font-mono focus:border-emerald-500 focus:outline-none">
                        @error('newWeightKg') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 uppercase tracking-wider mb-1">DoB <span class="text-gray-600 normal-case">(optional)</span></label>
                    <input type="date" wire:model="newDob" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                    @error('newDob') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs text-gray-500 uppercase tracking-wider mb-1">Nationality <span class="text-gray-600 normal-case">(optional)</span></label>
                    <select wire:model="newNationality" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                        <option value="">— None —</option>
                        @foreach($nationalities as $nat)
                            <option value="{{ $nat }}">{{ $nat }}</option>
                        @endforeach
                    </select>
                    @error('newNationality') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <button type="submit" wire:loading.attr="disabled" class="w-full rounded-md bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold py-2 transition disabled:opacity-60">
                    <span wire:loading.remove wire:target="addPlayer">+ Add to Squad</span>
                    <span wire:loading wire:target="addPlayer">Adding…</span>
                </button>
            </form>
        </div>
    </div>
</div>
