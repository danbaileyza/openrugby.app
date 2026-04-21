<div>
    {{-- Breadcrumb --}}
    <div class="mb-4 text-sm text-gray-400">
        <a href="{{ route('admin.index') }}" class="hover:text-white transition">Admin</a>
        <span class="mx-1 text-gray-600">/</span>
        <a href="{{ route('admin.fixtures.index') }}" class="hover:text-white transition">Fixtures</a>
        <span class="mx-1 text-gray-600">/</span>
        <span class="text-white">{{ $match ? 'Edit' : 'New' }}</span>
    </div>

    <h1 class="text-2xl font-bold text-white mb-6">{{ $match ? 'Edit Fixture' : 'New Fixture' }}</h1>

    <form wire:submit="save" class="max-w-2xl space-y-5">
        {{-- Competition + Season --}}
        <div class="rounded-xl bg-gray-900 border border-gray-800 p-5 space-y-5">
            <div>
                <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Competition</label>
                <select wire:model.live="competition_id" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                    <option value="">Select competition…</option>
                    @foreach($competitions as $comp)
                        <option value="{{ $comp->id }}">{{ $comp->name }}@if($comp->grade) ({{ $comp->grade }})@endif &middot; {{ ucfirst($comp->level) }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Season</label>
                <select wire:model.live="season_id" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none disabled:opacity-50" @disabled(!$competition_id)>
                    <option value="">{{ $competition_id ? 'Select season…' : 'Pick a competition first' }}</option>
                    @foreach($seasons as $season)
                        <option value="{{ $season->id }}">{{ $season->label }} @if($season->is_current)(current)@endif</option>
                    @endforeach
                </select>
                @error('season_id') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                @if($season_id && $teams->isEmpty())
                    <p class="mt-1 text-xs text-amber-400">No teams are registered in this season yet. <a href="{{ route('admin.teams.index') }}" class="underline hover:text-amber-300">Register teams</a>.</p>
                @endif
            </div>
        </div>

        {{-- Teams --}}
        <div class="rounded-xl bg-gray-900 border border-gray-800 p-5">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-medium text-emerald-400 uppercase tracking-wider mb-1">Home Team</label>
                    <select wire:model="home_team_id" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none disabled:opacity-50" @disabled(!$season_id)>
                        <option value="">{{ $season_id ? 'Select home team…' : 'Pick a season first' }}</option>
                        @foreach($teams as $team)
                            <option value="{{ $team->id }}">{{ $team->name }}</option>
                        @endforeach
                    </select>
                    @error('home_team_id') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-blue-400 uppercase tracking-wider mb-1">Away Team</label>
                    <select wire:model="away_team_id" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none disabled:opacity-50" @disabled(!$season_id)>
                        <option value="">{{ $season_id ? 'Select away team…' : 'Pick a season first' }}</option>
                        @foreach($teams as $team)
                            <option value="{{ $team->id }}">{{ $team->name }}</option>
                        @endforeach
                    </select>
                    @error('away_team_id') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        {{-- Kickoff + metadata --}}
        <div class="rounded-xl bg-gray-900 border border-gray-800 p-5 space-y-5">
            <div class="grid grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Kickoff date</label>
                    <input type="date" wire:model="kickoff_date" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    @error('kickoff_date') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Kickoff time</label>
                    <input type="time" wire:model="kickoff_time" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    @error('kickoff_time') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Round <span class="text-gray-600 normal-case">(optional)</span></label>
                    <input type="text" wire:model="round" placeholder="1, 2, 3…" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Stage <span class="text-gray-600 normal-case">(optional)</span></label>
                    <input type="text" wire:model="stage" placeholder="pool, quarter_final, final" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Status</label>
                    <select wire:model="status" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                        <option value="scheduled">Scheduled</option>
                        <option value="live">Live</option>
                        <option value="ft">Full Time</option>
                        <option value="postponed">Postponed</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="abandoned">Abandoned</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-emerald-600 px-5 py-2 text-sm font-medium text-white hover:bg-emerald-500 transition disabled:opacity-60">
                <span wire:loading.remove wire:target="save">{{ $match ? 'Save Changes' : 'Create Fixture' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
            <a href="{{ route('admin.fixtures.index') }}" class="text-sm text-gray-400 hover:text-white transition">Cancel</a>
        </div>
    </form>
</div>
