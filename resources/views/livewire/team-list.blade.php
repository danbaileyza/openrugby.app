<div>
    {{-- ═══ Page head ═══ --}}
    <section class="page-head">
        <div class="crumb">← <a href="{{ route('dashboard') }}">back to dashboard</a></div>
        <h1>Teams<span class="yellow">.</span></h1>
        <p class="sub">{{ number_format($typeCounts['all']) }} {{ Str::plural('team', $typeCounts['all']) }} across every tier of the game — international sides, professional franchises, clubs and schools. Filter by nation or type.</p>

        <div class="search-row">
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search teams...">
            <select wire:model.live="country">
                <option value="">All countries</option>
                @foreach($countries as $c)
                    @php
                        $label = (string) $c;
                        if (str_starts_with($label, '{')) {
                            $decoded = json_decode($label, true);
                            $label = $decoded['name'] ?? $decoded['code'] ?? $label;
                        }
                    @endphp
                    <option value="{{ $c }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </section>

    {{-- ═══ Body ═══ --}}
    <div class="page-body">
        {{-- Type chips --}}
        <div class="stadium-chips">
            @foreach([
                ['all', 'All ('.number_format($typeCounts['all']).')'],
                ['club', 'Club ('.number_format($typeCounts['club']).')'],
                ['national', 'National ('.number_format($typeCounts['national']).')'],
                ['franchise', 'Franchise ('.number_format($typeCounts['franchise']).')'],
                ['provincial', 'Provincial ('.number_format($typeCounts['provincial']).')'],
                ['invitational', 'Invitational ('.number_format($typeCounts['invitational']).')'],
            ] as [$key, $label])
                @if($key === 'all' || $typeCounts[$key] > 0)
                    <button type="button" wire:click="setType('{{ $key }}')" @class(['chip', 'is-active' => $type === $key])>{{ $label }}</button>
                @endif
            @endforeach
        </div>

        {{-- Team cards --}}
        <div class="team-grid">
            @forelse($teams as $team)
                @php
                    $initials = $team->short_name
                        ? strtoupper(substr($team->short_name, 0, 3))
                        : strtoupper(substr(str_replace([' ', '-', '('], '', $team->name), 0, 2));
                    $color = $team->primary_color ?: '#1d8a5b';
                    $countryLabel = $team->country_display ?: '';
                @endphp
                <a href="{{ route('teams.show', $team) }}" class="tt" style="--clr: {{ $color }};">
                    <span class="tt-tag">{{ strtoupper($team->type) }}</span>
                    <div class="tt-badge">{{ $initials }}</div>
                    <div class="tt-name">{{ $team->name }}</div>
                    <div class="tt-loc">{{ $countryLabel ?: ' ' }}</div>
                </a>
            @empty
                <div style="grid-column: 1 / -1; padding: 40px; text-align: center; color: var(--color-muted); background: var(--color-bg-2);">
                    No teams match your filters.
                </div>
            @endforelse
        </div>

        @if($teams->hasPages())
            <div style="margin-top: 24px;">{{ $teams->links() }}</div>
        @endif
    </div>
</div>
