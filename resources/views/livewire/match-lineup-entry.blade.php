<div class="max-w-3xl mx-auto">
    {{-- Breadcrumb --}}
    <div class="mb-4 text-sm text-gray-400">
        <a href="{{ route('matches.show', $match) }}" class="hover:text-white transition">&larr; Match detail</a>
    </div>

    @if(session()->has('message'))
        <div class="mb-4 rounded-md bg-emerald-900/40 border border-emerald-800 px-4 py-2 text-sm text-emerald-300">
            {{ session('message') }}
        </div>
    @endif

    <h1 class="text-2xl font-bold text-white mb-1">Lineup</h1>
    <p class="text-sm text-gray-400 mb-4">Select players, assign jersey numbers, mark captain, and choose starter or replacement.</p>

    {{-- Team toggle --}}
    <div class="grid grid-cols-2 gap-2 mb-5">
        <button type="button" wire:click="switchSide('home')" @class([
            'rounded-xl px-4 py-3 text-sm font-semibold transition border-2',
            'bg-emerald-600 border-emerald-500 text-white' => $side === 'home',
            'bg-gray-900 border-gray-800 text-gray-400 hover:bg-gray-800' => $side !== 'home',
        ]) @disabled($side !== 'home' && ! $canEditOther)>
            {{ $home?->team->name ?? 'Home' }}
        </button>
        <button type="button" wire:click="switchSide('away')" @class([
            'rounded-xl px-4 py-3 text-sm font-semibold transition border-2',
            'bg-blue-600 border-blue-500 text-white' => $side === 'away',
            'bg-gray-900 border-gray-800 text-gray-400 hover:bg-gray-800' => $side !== 'away',
        ]) @disabled($side !== 'away' && ! $canEditOther)>
            {{ $away?->team->name ?? 'Away' }}
        </button>
    </div>

    @if(empty($entries))
        <div class="rounded-xl bg-gray-900 border border-gray-800 p-6 text-center">
            <p class="text-sm text-gray-400 mb-2">No squad registered for {{ $activeTeam?->team->name ?? 'this team' }}.</p>
            @if($activeTeam)
                <a href="{{ route('admin.teams.squad', $activeTeam->team) }}" class="text-sm text-emerald-400 hover:text-emerald-300 transition">Manage squad →</a>
            @endif
        </div>
    @else
        <form wire:submit="save">
            @error('entries.captain') <p class="mb-3 rounded-md bg-red-900/40 border border-red-800 px-3 py-2 text-xs text-red-300">{{ $message }}</p> @enderror

            <div class="rounded-xl bg-gray-900 border border-gray-800 overflow-hidden">
                <div class="grid grid-cols-[56px_1fr_72px_100px_72px] items-center gap-2 px-3 py-2 text-[10px] uppercase tracking-wider text-gray-500 border-b border-gray-800 bg-gray-900/60">
                    <div class="text-center">In</div>
                    <div>Player</div>
                    <div class="text-center">Jersey</div>
                    <div>Role</div>
                    <div class="text-center">Capt.</div>
                </div>
                <div class="divide-y divide-gray-800">
                    @foreach($entries as $pid => $entry)
                        <div class="grid grid-cols-[56px_1fr_72px_100px_72px] items-center gap-2 px-3 py-2">
                            <label class="flex justify-center">
                                <input type="checkbox" wire:model.live="entries.{{ $pid }}.included" class="rounded bg-gray-800 border-gray-700 text-emerald-500 focus:ring-emerald-500 focus:ring-offset-gray-900">
                            </label>
                            <div class="min-w-0">
                                <div class="text-sm text-white truncate">{{ $entry['first_name'] }} {{ $entry['last_name'] }}</div>
                                <div class="text-[11px] text-gray-500 capitalize">{{ str($entry['default_position'])->replace('_', ' ') }}</div>
                            </div>
                            <input type="number" wire:model="entries.{{ $pid }}.jersey" min="1" max="99"
                                @disabled(! ($entries[$pid]['included'] ?? false))
                                class="rounded-md bg-gray-800 border border-gray-700 px-2 py-1.5 text-sm text-white text-center font-mono focus:border-emerald-500 focus:outline-none disabled:opacity-40">
                            <select wire:model="entries.{{ $pid }}.role"
                                @disabled(! ($entries[$pid]['included'] ?? false))
                                class="rounded-md bg-gray-800 border border-gray-700 px-2 py-1.5 text-xs text-white focus:border-emerald-500 focus:outline-none disabled:opacity-40">
                                <option value="starter">Starter</option>
                                <option value="replacement">Bench</option>
                            </select>
                            <label class="flex justify-center">
                                <input type="checkbox" wire:model="entries.{{ $pid }}.captain"
                                    @disabled(! ($entries[$pid]['included'] ?? false))
                                    class="rounded bg-gray-800 border-gray-700 text-yellow-500 focus:ring-yellow-500 focus:ring-offset-gray-900 disabled:opacity-40">
                            </label>
                        </div>
                        @error('entries.'.$pid.'.jersey') <div class="px-3 pb-2 text-xs text-red-400 text-right">{{ $message }}</div> @enderror
                    @endforeach
                </div>
            </div>

            <div class="flex items-center gap-3 mt-5">
                <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-emerald-600 hover:bg-emerald-500 px-5 py-2 text-sm font-medium text-white transition disabled:opacity-60">
                    <span wire:loading.remove wire:target="save">Save Lineup</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>
                <a href="{{ route('matches.show', $match) }}" class="text-sm text-gray-400 hover:text-white transition">Cancel</a>
                @if($activeTeam)
                    <a href="{{ route('admin.teams.squad', $activeTeam->team) }}" class="ml-auto text-xs text-gray-500 hover:text-gray-300 transition">Manage squad →</a>
                @endif
            </div>
        </form>
    @endif
</div>
