<div>
    <div class="mb-4 text-sm text-gray-400">
        <a href="{{ route('admin.index') }}" class="hover:text-white transition">Admin</a>
        <span class="mx-1 text-gray-600">/</span>
        <span class="text-white">Users</span>
    </div>

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Users</h1>
            <p class="text-sm text-gray-400 mt-1">Admins manage the whole system. Team users capture scores for linked teams only.</p>
        </div>
        <a href="{{ route('admin.users.create') }}" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500 transition">+ Invite User</a>
    </div>

    @if(session()->has('message'))
        <div class="mb-4 rounded-md bg-emerald-900/40 border border-emerald-800 px-4 py-2 text-sm text-emerald-300">
            {{ session('message') }}
        </div>
    @endif
    @if(session()->has('new-password'))
        <div class="mb-4 rounded-md bg-amber-900/40 border border-amber-800 px-4 py-3 text-sm text-amber-200">
            <strong>One-time password for {{ session('new-email') }}:</strong>
            <code class="ml-2 font-mono bg-gray-900 px-2 py-0.5 rounded">{{ session('new-password') }}</code>
            <p class="mt-1 text-xs text-amber-300">Share this with the user through a secure channel. It won't be shown again.</p>
        </div>
    @endif

    <div class="flex flex-wrap gap-3 mb-4">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search name or email…" class="flex-1 min-w-[200px] rounded-md bg-gray-900 border border-gray-800 px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-emerald-500 focus:outline-none">
        <select wire:model.live="role" class="rounded-md bg-gray-900 border border-gray-800 px-3 py-2 text-sm text-white">
            <option value="all">All roles</option>
            <option value="admin">Admin</option>
            <option value="team_user">Team User</option>
        </select>
    </div>

    <div class="rounded-xl bg-gray-900 border border-gray-800 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-900/50 text-gray-500">
                <tr class="text-xs uppercase tracking-wider">
                    <th class="px-4 py-3 text-left font-medium">Name</th>
                    <th class="px-4 py-3 text-left font-medium">Email</th>
                    <th class="px-4 py-3 text-left font-medium">Role</th>
                    <th class="px-4 py-3 text-right font-medium">Teams</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @forelse($users as $u)
                    <tr class="hover:bg-gray-800/30 transition">
                        <td class="px-4 py-3 text-white font-medium">{{ $u->name }}</td>
                        <td class="px-4 py-3 text-gray-400 font-mono text-xs">{{ $u->email }}</td>
                        <td class="px-4 py-3">
                            @if($u->role === 'admin')
                                <span class="rounded bg-emerald-900/40 text-emerald-300 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wider">Admin</span>
                            @else
                                <span class="rounded bg-gray-800 text-gray-300 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wider">Team User</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right text-gray-300">{{ $u->teams_count }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.users.edit', $u) }}" class="text-xs text-emerald-400 hover:text-emerald-300 transition">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">No users. <a href="{{ route('admin.users.create') }}" class="text-emerald-400 hover:text-emerald-300">Invite one</a>.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($users->hasPages())
        <div class="mt-4">{{ $users->links() }}</div>
    @endif
</div>
