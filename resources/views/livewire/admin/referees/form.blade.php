<div>
    <div class="mb-4 text-sm text-gray-400">
        <a href="{{ route('admin.index') }}" class="hover:text-white transition">Admin</a>
        <span class="mx-1 text-gray-600">/</span>
        <a href="{{ route('admin.referees.index') }}" class="hover:text-white transition">Referees</a>
        <span class="mx-1 text-gray-600">/</span>
        <span class="text-white">{{ $referee ? 'Edit' : 'New' }}</span>
    </div>

    <h1 class="text-2xl font-bold text-white mb-6">{{ $referee ? trim($first_name.' '.$last_name) : 'New Referee' }}</h1>

    <form wire:submit="save" class="max-w-xl space-y-5">
        <div class="rounded-xl bg-gray-900 border border-gray-800 p-5 space-y-5">
            <div class="grid grid-cols-2 gap-5">
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

            <div class="grid grid-cols-2 gap-5">
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
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Tier <span class="text-gray-600 normal-case">(optional)</span></label>
                    <input type="text" wire:model="tier" placeholder="world_rugby_panel, national, regional" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                    @error('tier') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Photo URL <span class="text-gray-600 normal-case">(optional)</span></label>
                <input type="url" wire:model="photo_url" placeholder="https://…" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                @error('photo_url') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-emerald-600 px-5 py-2 text-sm font-medium text-white hover:bg-emerald-500 transition disabled:opacity-60">
                <span wire:loading.remove wire:target="save">{{ $referee ? 'Save Changes' : 'Create Referee' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
            <a href="{{ route('admin.referees.index') }}" class="text-sm text-gray-400 hover:text-white transition">Cancel</a>
        </div>
    </form>
</div>
