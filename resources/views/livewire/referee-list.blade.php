<div>
    {{-- ═══ Page head ═══ --}}
    <section class="page-head">
        <div class="crumb">← <a href="{{ route('dashboard') }}">back to dashboard</a></div>
        <h1>Refer<span class="yellow">ees</span>.</h1>
        <p class="sub">{{ number_format($totalReferees) }} {{ Str::plural('official', $totalReferees) }} on file — international panel, national bodies, and everyone in between. Search by name or filter by nationality.</p>

        <div class="search-row">
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search referees by name...">
            <select wire:model.live="nationality">
                <option value="">All nationalities</option>
                @foreach($nationalities as $n)
                    <option value="{{ $n }}">{{ $n }}</option>
                @endforeach
            </select>
        </div>
    </section>

    {{-- ═══ Body ═══ --}}
    <div class="page-body">
        <div class="player-table-wrap">
            <table class="player-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Nationality</th>
                        <th class="num">Matches</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($referees as $referee)
                        <tr onclick="window.location='{{ route('referees.show', $referee) }}'">
                            <td>
                                <a href="{{ route('referees.show', $referee) }}" class="pl-name" wire:navigate>
                                    {{ $referee->full_name }}
                                </a>
                            </td>
                            <td class="pl-team">{{ $referee->nationality ?? '—' }}</td>
                            <td class="num">{{ number_format($referee->match_officials_count) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" style="padding: 32px; text-align: center; color: var(--color-muted);">
                                No referees match your search.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($referees->hasPages())
            <div style="margin-top: 18px;">{{ $referees->links() }}</div>
        @endif
    </div>
</div>
