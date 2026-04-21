<div>
    {{-- ═══ Page head ═══ --}}
    <section class="page-head">
        <div class="crumb">← <a href="{{ route('dashboard') }}">back to dashboard</a></div>
        <h1>Play<span class="yellow">ers</span>.</h1>
        <p class="sub">{{ number_format($totalActive) }} active {{ Str::plural('player', $totalActive) }} across every competition we track. Search by name, filter by position or nationality, and click any row for a full profile.</p>

        <div class="search-row">
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search players by name...">
            <select wire:model.live="position">
                <option value="">All positions</option>
                <option value="front_row">Front Row</option>
                <option value="second_row">Second Row</option>
                <option value="back_row">Back Row</option>
                <option value="halfback">Halfbacks</option>
                <option value="centre">Centres</option>
                <option value="back_three">Back Three</option>
            </select>
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
        @php
            $sortArrow = fn($col) => $sort === $col ? ($dir === 'asc' ? '↑' : '↓') : '';
        @endphp
        <div class="player-table-wrap">
            <table class="player-table">
                <thead>
                    <tr>
                        <th>
                            <button type="button" wire:click="sortBy('last_name')" style="background:none;border:none;color:inherit;font:inherit;letter-spacing:inherit;text-transform:inherit;cursor:pointer;padding:0;">
                                Player {{ $sortArrow('last_name') }}
                            </button>
                        </th>
                        <th>
                            <button type="button" wire:click="sortBy('position')" style="background:none;border:none;color:inherit;font:inherit;letter-spacing:inherit;text-transform:inherit;cursor:pointer;padding:0;">
                                Position {{ $sortArrow('position') }}
                            </button>
                        </th>
                        <th>Team</th>
                        <th>
                            <button type="button" wire:click="sortBy('nationality')" style="background:none;border:none;color:inherit;font:inherit;letter-spacing:inherit;text-transform:inherit;cursor:pointer;padding:0;">
                                Nation {{ $sortArrow('nationality') }}
                            </button>
                        </th>
                        <th class="num">
                            <button type="button" wire:click="sortBy('dob')" style="background:none;border:none;color:inherit;font:inherit;letter-spacing:inherit;text-transform:inherit;cursor:pointer;padding:0;">
                                Age {{ $sortArrow('dob') }}
                            </button>
                        </th>
                        <th class="num">
                            <button type="button" wire:click="sortBy('height_cm')" style="background:none;border:none;color:inherit;font:inherit;letter-spacing:inherit;text-transform:inherit;cursor:pointer;padding:0;">
                                Ht {{ $sortArrow('height_cm') }}
                            </button>
                        </th>
                        <th class="num">
                            <button type="button" wire:click="sortBy('weight_kg')" style="background:none;border:none;color:inherit;font:inherit;letter-spacing:inherit;text-transform:inherit;cursor:pointer;padding:0;">
                                Wt {{ $sortArrow('weight_kg') }}
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($players as $player)
                        @php
                            $currentTeam = $player->contracts->first()?->team;
                        @endphp
                        <tr onclick="window.location='{{ route('players.show', $player) }}'">
                            <td>
                                <a href="{{ route('players.show', $player) }}" class="pl-name">{{ $player->first_name }} {{ $player->last_name }}</a>
                            </td>
                            <td>
                                <span class="pl-pos">{{ str($player->position)->replace('_', ' ')->title() }}</span>
                            </td>
                            <td class="pl-team">
                                @if($currentTeam)
                                    {{ $currentTeam->name }}
                                @else
                                    <span style="color: var(--color-muted);">—</span>
                                @endif
                            </td>
                            <td class="pl-team">{{ $player->nationality ?? '—' }}</td>
                            <td class="num">{{ $player->dob?->age ?? '—' }}</td>
                            <td class="num">{{ $player->height_cm ? $player->height_cm.' cm' : '—' }}</td>
                            <td class="num">{{ $player->weight_kg ? $player->weight_kg.' kg' : '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="padding: 40px; text-align: center; color: var(--color-muted);">
                                No players match your filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($players->hasPages())
            <div style="margin-top: 24px;">{{ $players->links() }}</div>
        @endif
    </div>
</div>
