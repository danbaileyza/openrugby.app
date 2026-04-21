<div>
    <div class="mb-4 text-sm text-gray-400">
        <a href="{{ route('admin.index') }}" class="hover:text-white transition">Admin</a>
        <span class="mx-1 text-gray-600">/</span>
        @if($current_team_id)
            <a href="{{ route('admin.teams.squad', $current_team_id) }}" class="hover:text-white transition">Squad</a>
            <span class="mx-1 text-gray-600">/</span>
        @endif
        <span class="text-white">{{ $player ? 'Edit Player' : 'New Player' }}</span>
    </div>

    <h1 class="text-2xl font-bold text-white mb-6">{{ $player ? trim($first_name.' '.$last_name) : 'New Player' }}</h1>

    <form wire:submit="save" class="max-w-2xl space-y-5">
        {{-- Identity --}}
        <div class="rounded-xl bg-gray-900 border border-gray-800 p-5 space-y-5">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">First name</label>
                    <input type="text" wire:model="first_name" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                    @error('first_name') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Last name</label>
                    <input type="text" wire:model="last_name" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                    @error('last_name') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Position</label>
                    <select wire:model="position" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                        @foreach($positions as $pos)
                            <option value="{{ $pos }}">{{ str($pos)->replace('_', ' ')->title() }}</option>
                        @endforeach
                    </select>
                    @error('position') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Position group <span class="text-gray-600 normal-case">(optional)</span></label>
                    <input type="text" wire:model="position_group" placeholder="forwards / backs" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">DoB <span class="text-gray-600 normal-case">(optional)</span></label>
                    <input type="date" wire:model="dob" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                    @error('dob') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        {{-- Physical + nationality --}}
        <div class="rounded-xl bg-gray-900 border border-gray-800 p-5 space-y-5">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Height (cm)</label>
                    <input type="number" wire:model="height_cm" min="100" max="230" placeholder="185" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white font-mono focus:border-emerald-500 focus:outline-none">
                    @error('height_cm') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Weight (kg)</label>
                    <input type="number" wire:model="weight_kg" min="30" max="200" placeholder="95" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white font-mono focus:border-emerald-500 focus:outline-none">
                    @error('weight_kg') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Nationality</label>
                    <select wire:model="nationality" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                        <option value="">— None —</option>
                        @foreach($nationalities as $nat)
                            <option value="{{ $nat }}">{{ $nat }}</option>
                        @endforeach
                    </select>
                    @error('nationality') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Photo URL <span class="text-gray-600 normal-case">(optional)</span></label>
                <input type="url" wire:model="photo_url" placeholder="https://…" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                @error('photo_url') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-300">
                <input type="checkbox" wire:model="is_active" class="rounded bg-gray-800 border-gray-700 text-emerald-500 focus:ring-emerald-500 focus:ring-offset-gray-900">
                <span>Active <span class="text-xs text-gray-500 ml-1">(uncheck to archive — won't appear in rosters)</span></span>
            </label>
        </div>

        {{-- Team assignment --}}
        <div class="rounded-xl bg-gray-900 border border-gray-800 p-5">
            <h2 class="text-sm font-semibold text-white mb-1">Current Team</h2>
            <p class="text-xs text-gray-500 mb-3">Changing this will close any existing current contract and open a new one.</p>
            <select wire:model="current_team_id" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                <option value="">— No team (free agent) —</option>
                @foreach($teams as $team)
                    <option value="{{ $team->id }}">{{ $team->name }} <span class="text-gray-500">· {{ ucfirst($team->type) }}</span></option>
                @endforeach
            </select>
            @error('current_team_id') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-emerald-600 px-5 py-2 text-sm font-medium text-white hover:bg-emerald-500 transition disabled:opacity-60">
                <span wire:loading.remove wire:target="save">{{ $player ? 'Save Changes' : 'Create Player' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
            @if($current_team_id)
                <a href="{{ route('admin.teams.squad', $current_team_id) }}" class="text-sm text-gray-400 hover:text-white transition">Cancel</a>
            @else
                <a href="{{ route('admin.index') }}" class="text-sm text-gray-400 hover:text-white transition">Cancel</a>
            @endif
        </div>
    </form>
</div>
