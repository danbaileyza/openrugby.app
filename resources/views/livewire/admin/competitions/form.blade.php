<div>
    {{-- Breadcrumb --}}
    <div class="mb-4 text-sm text-gray-400">
        <a href="{{ route('admin.index') }}" class="hover:text-white transition">Admin</a>
        <span class="mx-1 text-gray-600">/</span>
        <a href="{{ route('admin.competitions.index') }}" class="hover:text-white transition">Competitions</a>
        <span class="mx-1 text-gray-600">/</span>
        <span class="text-white">{{ $competition ? 'Edit' : 'New' }}</span>
    </div>

    <h1 class="text-2xl font-bold text-white mb-6">{{ $competition ? 'Edit Competition' : 'New Competition' }}</h1>

    <form wire:submit="save" class="max-w-2xl space-y-5">
        <div class="rounded-xl bg-gray-900 border border-gray-800 p-5 space-y-5">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Name</label>
                    <input type="text" wire:model.live.debounce.400ms="name" placeholder="e.g. WP Schools U16A League" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    @error('name') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Code <span class="text-gray-600 normal-case">(slug, auto-filled)</span></label>
                    <input type="text" wire:model="code" placeholder="wp_schools_u16a" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 font-mono">
                    @error('code') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Level</label>
                    <select wire:model="level" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                        <option value="school">School</option>
                        <option value="club">Club</option>
                        <option value="professional">Professional</option>
                    </select>
                    @error('level') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Grade <span class="text-gray-600 normal-case">(optional)</span></label>
                    <input type="text" wire:model="grade" placeholder="U16A, 1st XV" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    @error('grade') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Format</label>
                    <select wire:model="format" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                        <option value="union">Union (XV)</option>
                        <option value="league">League (XIII)</option>
                        <option value="sevens">Sevens (7s)</option>
                    </select>
                    @error('format') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Country <span class="text-gray-600 normal-case">(optional)</span></label>
                    <input type="text" wire:model="country" placeholder="South Africa" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    @error('country') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Tier <span class="text-gray-600 normal-case">(optional)</span></label>
                    <input type="text" wire:model="tier" placeholder="tier_1, regional_a" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    @error('tier') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <label class="flex items-start gap-2 text-sm text-gray-300">
                <input type="checkbox" wire:model="has_standings" class="mt-0.5 rounded bg-gray-800 border-gray-700 text-emerald-500 focus:ring-emerald-500 focus:ring-offset-gray-900">
                <span>
                    Track standings (log/table) for this competition
                    <span class="block text-xs text-gray-500 mt-0.5">Leave checked for leagues; disable for knockout-only or friendly tours.</span>
                </span>
            </label>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-emerald-600 px-5 py-2 text-sm font-medium text-white hover:bg-emerald-500 transition disabled:opacity-60">
                <span wire:loading.remove wire:target="save">{{ $competition ? 'Save Changes' : 'Create Competition' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
            <a href="{{ route('admin.competitions.index') }}" class="text-sm text-gray-400 hover:text-white transition">Cancel</a>
        </div>
    </form>
</div>
