<div>
    {{-- Breadcrumb --}}
    <div class="mb-4 text-sm text-gray-400">
        <a href="{{ route('admin.index') }}" class="hover:text-white transition">Admin</a>
        <span class="mx-1 text-gray-600">/</span>
        <a href="{{ route('admin.competitions.index') }}" class="hover:text-white transition">Competitions</a>
        <span class="mx-1 text-gray-600">/</span>
        <a href="{{ route('admin.competitions.seasons', $competition) }}" class="hover:text-white transition">{{ $competition->name }}</a>
        <span class="mx-1 text-gray-600">/</span>
        <span class="text-white">{{ $season ? 'Edit Season' : 'New Season' }}</span>
    </div>

    <h1 class="text-2xl font-bold text-white mb-6">{{ $season ? 'Edit Season' : 'New Season' }}</h1>

    <form wire:submit="save" class="max-w-xl space-y-5">
        <div class="rounded-xl bg-gray-900 border border-gray-800 p-5 space-y-5">
            <div>
                <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Label</label>
                <input type="text" wire:model="label" placeholder="2026, 2026-27, Term 1 2026" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                @error('label') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Start date</label>
                    <input type="date" wire:model="start_date" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    @error('start_date') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">End date</label>
                    <input type="date" wire:model="end_date" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    @error('end_date') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <label class="flex items-start gap-2 text-sm text-gray-300">
                <input type="checkbox" wire:model="is_current" class="mt-0.5 rounded bg-gray-800 border-gray-700 text-emerald-500 focus:ring-emerald-500 focus:ring-offset-gray-900">
                <span>
                    Mark as current season
                    <span class="block text-xs text-gray-500 mt-0.5">Other seasons in this competition will be un-marked automatically.</span>
                </span>
            </label>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-emerald-600 px-5 py-2 text-sm font-medium text-white hover:bg-emerald-500 transition disabled:opacity-60">
                <span wire:loading.remove wire:target="save">{{ $season ? 'Save Changes' : 'Create Season' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
            <a href="{{ route('admin.competitions.seasons', $competition) }}" class="text-sm text-gray-400 hover:text-white transition">Cancel</a>
        </div>
    </form>
</div>
