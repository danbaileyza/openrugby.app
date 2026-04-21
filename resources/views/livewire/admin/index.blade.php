<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">Admin</h1>
        <p class="text-sm text-gray-400 mt-1">Manage leagues, teams, fixtures, and score capture.</p>
    </div>

    {{-- Stat tiles --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-8">
        <div class="rounded-xl bg-gray-900 border border-gray-800 p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wider">Competitions</div>
            <div class="mt-1 text-2xl font-bold text-white">{{ $stats['competitions'] }}</div>
            <div class="text-[11px] text-gray-500 mt-1">{{ $stats['schoolCompetitions'] }} school &middot; {{ $stats['clubCompetitions'] }} club</div>
        </div>
        <div class="rounded-xl bg-gray-900 border border-gray-800 p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wider">Teams</div>
            <div class="mt-1 text-2xl font-bold text-white">{{ $stats['teams'] }}</div>
        </div>
        <div class="rounded-xl bg-gray-900 border border-gray-800 p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wider">Matches</div>
            <div class="mt-1 text-2xl font-bold text-white">{{ $stats['matches'] }}</div>
        </div>
        <div class="rounded-xl bg-gray-900 border border-gray-800 p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wider">Users</div>
            <div class="mt-1 text-2xl font-bold text-white">{{ $stats['users'] }}</div>
        </div>
    </div>

    {{-- Sections --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <a href="{{ route('admin.competitions.index') }}" class="rounded-xl bg-gray-900 border border-gray-800 p-5 hover:border-emerald-500/40 transition block">
            <div class="text-base font-semibold text-white">Competitions & Seasons</div>
            <p class="text-sm text-gray-400 mt-1">Create school, club, or professional competitions. Add seasons and grade levels.</p>
        </a>
        <a href="{{ route('admin.teams.index') }}" class="rounded-xl bg-gray-900 border border-gray-800 p-5 hover:border-emerald-500/40 transition block">
            <div class="text-base font-semibold text-white">Teams</div>
            <p class="text-sm text-gray-400 mt-1">Create teams and register them in seasons.</p>
        </a>
        <a href="{{ route('admin.fixtures.index') }}" class="rounded-xl bg-gray-900 border border-gray-800 p-5 hover:border-emerald-500/40 transition block">
            <div class="text-base font-semibold text-white">Fixtures</div>
            <p class="text-sm text-gray-400 mt-1">Create matches within a season. Capture scores from the match page.</p>
        </a>
        <a href="{{ route('admin.referees.index') }}" class="rounded-xl bg-gray-900 border border-gray-800 p-5 hover:border-emerald-500/40 transition block">
            <div class="text-base font-semibold text-white">Referees</div>
            <p class="text-sm text-gray-400 mt-1">Match officials register.</p>
        </a>
        <a href="{{ route('admin.users.index') }}" class="rounded-xl bg-gray-900 border border-gray-800 p-5 hover:border-emerald-500/40 transition block">
            <div class="text-base font-semibold text-white">Users</div>
            <p class="text-sm text-gray-400 mt-1">Invite team users and link them to teams.</p>
        </a>
    </div>
</div>
