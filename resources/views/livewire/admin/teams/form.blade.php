<div>
    {{-- Breadcrumb --}}
    <div class="mb-4 text-sm text-gray-400">
        <a href="{{ route('admin.index') }}" class="hover:text-white transition">Admin</a>
        <span class="mx-1 text-gray-600">/</span>
        <a href="{{ route('admin.teams.index') }}" class="hover:text-white transition">Teams</a>
        <span class="mx-1 text-gray-600">/</span>
        <span class="text-white">{{ $team ? 'Edit' : 'New' }}</span>
    </div>

    <h1 class="text-2xl font-bold text-white mb-6">{{ $team ? 'Edit Team' : 'New Team' }}</h1>

    <form wire:submit="save" class="max-w-2xl space-y-5">
        <div class="rounded-xl bg-gray-900 border border-gray-800 p-5 space-y-5">
            <div class="grid grid-cols-1 md:grid-cols-[1fr_140px] gap-5">
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Name</label>
                    <input type="text" wire:model="name" placeholder="e.g. Paarl Boys High 1st XV" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    @error('name') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Short</label>
                    <input type="text" wire:model="short_name" placeholder="PBH" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 font-mono uppercase">
                    @error('short_name') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Type</label>
                    <select wire:model="type" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                        <option value="club">Club</option>
                        <option value="national">National</option>
                        <option value="franchise">Franchise</option>
                        <option value="provincial">Provincial</option>
                        <option value="invitational">Invitational</option>
                        <option value="school">School</option>
                    </select>
                    @error('type') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Country</label>
                    <input type="text" wire:model="country" placeholder="South Africa" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    @error('country') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Founded <span class="text-gray-600 normal-case">(year)</span></label>
                    <input type="text" wire:model="founded_year" placeholder="1898" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    @error('founded_year') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Primary colour <span class="text-gray-600 normal-case">(hex)</span></label>
                    <input type="text" wire:model="primary_color" placeholder="#047857" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 font-mono">
                    @error('primary_color') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Secondary colour</label>
                    <input type="text" wire:model="secondary_color" placeholder="#1f2937" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 font-mono">
                    @error('secondary_color') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Parent team <span class="text-gray-600 normal-case">(optional — for sub-squads)</span></label>
                <select wire:model="parent_team_id" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                    <option value="">— None (top-level team) —</option>
                    @foreach($parentCandidates as $p)
                        <option value="{{ $p->id }}">{{ $p->name }}</option>
                    @endforeach
                </select>
                @error('parent_team_id') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Season registration --}}
        <div class="rounded-xl bg-gray-900 border border-gray-800 p-5">
            <h2 class="text-sm font-semibold text-white mb-1">Register in seasons</h2>
            <p class="text-xs text-gray-500 mb-3">Select all seasons this team participates in. Needed for fixtures and standings.</p>
            @if($seasons->isEmpty())
                <p class="text-sm text-gray-500">No seasons exist yet. <a href="{{ route('admin.competitions.index') }}" class="text-emerald-400 hover:text-emerald-300">Create a competition first</a>.</p>
            @else
                <div class="max-h-64 overflow-y-auto space-y-1 border border-gray-800 rounded-md p-3">
                    @foreach($seasons as $season)
                        <label class="flex items-center gap-2 text-sm text-gray-300 py-1 hover:bg-gray-800/40 px-2 rounded">
                            <input type="checkbox" value="{{ $season->id }}" wire:model="season_ids" class="rounded bg-gray-800 border-gray-700 text-emerald-500 focus:ring-emerald-500 focus:ring-offset-gray-900">
                            <span>
                                {{ $season->competition->name }}
                                @if($season->competition->grade) <span class="text-gray-500">({{ $season->competition->grade }})</span> @endif
                                <span class="text-xs text-gray-500 ml-1">&middot; {{ $season->label }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('season_ids.*') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            @endif
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-emerald-600 px-5 py-2 text-sm font-medium text-white hover:bg-emerald-500 transition disabled:opacity-60">
                <span wire:loading.remove wire:target="save">{{ $team ? 'Save Changes' : 'Create Team' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
            <a href="{{ route('admin.teams.index') }}" class="text-sm text-gray-400 hover:text-white transition">Cancel</a>
        </div>
    </form>
</div>
