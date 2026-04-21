<div>
    <div class="mb-4 text-sm text-gray-400">
        <a href="{{ route('admin.index') }}" class="hover:text-white transition">Admin</a>
        <span class="mx-1 text-gray-600">/</span>
        <a href="{{ route('admin.users.index') }}" class="hover:text-white transition">Users</a>
        <span class="mx-1 text-gray-600">/</span>
        <span class="text-white">{{ $user ? 'Edit' : 'Invite' }}</span>
    </div>

    <h1 class="text-2xl font-bold text-white mb-6">{{ $user ? 'Edit User' : 'Invite User' }}</h1>

    <form wire:submit="save" class="max-w-2xl space-y-5">
        <div class="rounded-xl bg-gray-900 border border-gray-800 p-5 space-y-5">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Name</label>
                    <input type="text" wire:model="name" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                    @error('name') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Email</label>
                    <input type="email" wire:model="email" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                    @error('email') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Role</label>
                <select wire:model.live="role" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                    <option value="team_user">Team User — can only capture for linked teams</option>
                    <option value="admin">Admin — full access</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">
                    Password
                    @if($user) <span class="text-gray-600 normal-case">(leave blank to keep current)</span>
                    @else <span class="text-gray-600 normal-case">(blank = auto-generate, shown once after save)</span>
                    @endif
                </label>
                <input type="text" wire:model="password" placeholder="{{ $user ? '' : 'auto-generate' }}" class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white font-mono placeholder-gray-600 focus:border-emerald-500 focus:outline-none">
                @error('password') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                @if($user)
                    <label class="mt-2 flex items-center gap-2 text-xs text-gray-400">
                        <input type="checkbox" wire:model="resetPassword" class="rounded bg-gray-800 border-gray-700 text-emerald-500 focus:ring-emerald-500 focus:ring-offset-gray-900">
                        Generate a new password even if the field is blank
                    </label>
                @endif
            </div>
        </div>

        @if($role === 'team_user')
            <div class="rounded-xl bg-gray-900 border border-gray-800 p-5">
                <h2 class="text-sm font-semibold text-white mb-1">Linked Teams</h2>
                <p class="text-xs text-gray-500 mb-3">This user can capture scores for any match where one of these teams is playing.</p>
                @if($teams->isEmpty())
                    <p class="text-sm text-gray-500">No teams exist yet. <a href="{{ route('admin.teams.create') }}" class="text-emerald-400 hover:text-emerald-300">Create a team</a>.</p>
                @else
                    <div class="max-h-64 overflow-y-auto space-y-1 border border-gray-800 rounded-md p-3">
                        @foreach($teams as $team)
                            <label class="flex items-center gap-2 text-sm text-gray-300 py-1 hover:bg-gray-800/40 px-2 rounded">
                                <input type="checkbox" value="{{ $team->id }}" wire:model="team_ids" class="rounded bg-gray-800 border-gray-700 text-emerald-500 focus:ring-emerald-500 focus:ring-offset-gray-900">
                                <span>{{ $team->name }} <span class="text-xs text-gray-500">· {{ ucfirst($team->type) }}</span></span>
                            </label>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        <div class="flex items-center gap-3">
            <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-emerald-600 px-5 py-2 text-sm font-medium text-white hover:bg-emerald-500 transition disabled:opacity-60">
                <span wire:loading.remove wire:target="save">{{ $user ? 'Save Changes' : 'Create User' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
            <a href="{{ route('admin.users.index') }}" class="text-sm text-gray-400 hover:text-white transition">Cancel</a>
        </div>
    </form>
</div>
