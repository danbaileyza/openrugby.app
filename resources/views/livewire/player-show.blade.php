<div>
    {{-- ═══ Stadium Player Hero ═══ --}}
    @php
        $initials = strtoupper(substr($player->first_name, 0, 1).substr($player->last_name, 0, 1));
        $currentTeam = $player->contracts->where('is_current', true)->first()?->team;
        $teamColor = $currentTeam?->primary_color ?: '#1d8a5b';
    @endphp
    <section class="detail-hero player-hero" style="--crest-clr: {{ $teamColor }};">
        <div>
            <div class="portrait">{{ $initials }}</div>
        </div>
        <div>
            <div class="crumb">← <a href="{{ route('players.index') }}">Players</a>@if($currentTeam) · <a href="{{ route('teams.show', $currentTeam) }}">{{ $currentTeam->name }}</a>@endif</div>
            <h1>{{ $player->first_name }}<br><span class="yellow">{{ $player->last_name }}</span></h1>
            <div class="sub-meta">
                <span>{{ str($player->position)->replace('_', ' ')->title() }}</span>
                @if($player->dob)<span>Born <b>{{ $player->dob->format('j M Y') }}</b> · age {{ $player->dob->age }}</span>@endif
                @if($player->height_cm || $player->weight_kg)<span>{{ $player->height_cm ? $player->height_cm.'cm' : '—' }} / {{ $player->weight_kg ? $player->weight_kg.'kg' : '—' }}</span>@endif
                @if($player->nationality)<span>{{ $player->nationality }}</span>@endif
                <span style="background: {{ $player->is_active ? 'var(--color-home)' : '#555' }}; color: #fff; padding: 3px 8px; font-size: 10px; letter-spacing: .15em; border-radius: var(--r-xs);">{{ $player->is_active ? 'ACTIVE' : 'RETIRED' }}</span>
            </div>
            <div style="margin-top: 14px;">
                <livewire:favourite-button type="player" :id="$player->id" :key="'fav-player-'.$player->id" />
            </div>
        </div>
        <div class="big-stat">
            <div class="lbl">Career Points</div>
            <div class="n">{{ number_format($chartData['career']['total_points']) }}</div>
            <div style="font-family: var(--font-mono); font-size: 11px; color: var(--color-ink-dim); margin-top: 4px;">
                {{ $chartData['career']['appearances'] }} apps · {{ $chartData['career']['tries'] }} tries
            </div>
        </div>
    </section>

    @php $canManage = auth()->user()?->canManagePlayer($player) ?? false; @endphp

    <div class="page-body">
        {{-- ═══ Top-level tabs ═══ --}}
        <nav class="detail-tabs" style="padding: 0; margin-bottom: 24px;">
            <button type="button" wire:click="showTab('overview')" @class(['is-active' => $activeTab === 'overview'])>Overview</button>
            <button type="button" wire:click="showTab('appearances')" @class(['is-active' => $activeTab === 'appearances'])>Appearances</button>
            <button type="button" wire:click="showTab('season-stats')" @class(['is-active' => $activeTab === 'season-stats'])>Season Stats</button>
            <button type="button" wire:click="showTab('growth')" @class(['is-active' => $activeTab === 'growth'])>Growth — H &amp; W</button>
        </nav>

        {{-- ═══ OVERVIEW TAB ═══ --}}
        @if($activeTab === 'overview')
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-[320px_1fr]">
                {{-- Career sidebar --}}
                <div>
                    @if($chartData['career']['appearances'] > 0)
                        <h3 class="sec-title">Career Totals</h3>
                        <div class="stat-grid-sq">
                            <div class="stat-sq">
                                <span class="lbl"><span class="lbl-full">Appearances</span><span class="lbl-short">Apps</span></span>
                                <span class="n">{{ number_format($chartData['career']['appearances']) }}</span>
                            </div>
                            <div class="stat-sq hot">
                                <span class="lbl"><span class="lbl-full">Points</span><span class="lbl-short">Pts</span></span>
                                <span class="n">{{ number_format($chartData['career']['total_points']) }}</span>
                            </div>
                            <div class="stat-sq green">
                                <span class="lbl">Tries</span>
                                <span class="n">{{ number_format($chartData['career']['tries']) }}</span>
                            </div>
                            <div class="stat-sq">
                                <span class="lbl" style="color: var(--color-away-bright);"><span class="lbl-full">Conversions</span><span class="lbl-short">Conv</span></span>
                                <span class="n" style="color: var(--color-away-bright);">{{ number_format($chartData['career']['conversions']) }}</span>
                            </div>
                            @if($chartData['career']['penalties_kicked'] > 0)
                                <div class="stat-sq">
                                    <span class="lbl"><span class="lbl-full">Penalties</span><span class="lbl-short">Pen</span></span>
                                    <span class="n" style="color: var(--color-brand-yellow);">{{ number_format($chartData['career']['penalties_kicked']) }}</span>
                                </div>
                            @endif
                            @if($chartData['career']['drop_goals'] > 0)
                                <div class="stat-sq">
                                    <span class="lbl"><span class="lbl-full">Drop Goals</span><span class="lbl-short">DG</span></span>
                                    <span class="n" style="color: var(--color-drop);">{{ number_format($chartData['career']['drop_goals']) }}</span>
                                </div>
                            @endif
                        </div>
                        @if($chartData['career']['yellow_cards'] > 0 || $chartData['career']['red_cards'] > 0)
                            <div class="stat-grid-sq" style="margin-top: 2px;">
                                <div class="stat-sq" style="aspect-ratio: 2;">
                                    <span class="lbl" style="color: var(--color-brand-yellow);"><span class="lbl-full">Yellow Cards</span><span class="lbl-short">YC</span></span>
                                    <span class="n" style="color: var(--color-brand-yellow);">{{ $chartData['career']['yellow_cards'] }}</span>
                                </div>
                                <div class="stat-sq red" style="aspect-ratio: 2;">
                                    <span class="lbl"><span class="lbl-full">Red Cards</span><span class="lbl-short">RC</span></span>
                                    <span class="n">{{ $chartData['career']['red_cards'] }}</span>
                                </div>
                            </div>
                        @endif
                    @endif

                    @if($player->contracts->isNotEmpty())
                        <h3 class="sec-title" style="margin-top: 28px;">Career</h3>
                        <div style="display: flex; flex-direction: column; gap: 2px;">
                            @foreach($player->contracts->sortByDesc('from_date') as $contract)
                                <a href="{{ route('teams.show', $contract->team) }}" class="career-item @if($contract->is_current) current @endif">
                                    <div>
                                        <div class="tc">{{ $contract->team->name }}</div>
                                        <div class="yrs">{{ $contract->from_date->format('Y') }} — {{ $contract->to_date?->format('Y') ?? 'Present' }}</div>
                                    </div>
                                    @if($contract->is_current)
                                        <span class="cur-tag">CURRENT</span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Charts --}}
                <div style="display: flex; flex-direction: column; gap: 16px;">
                    @if(count($chartData['seasons']) > 1)
                        <div class="widget" style="padding: 18px 20px;" wire:ignore>
                            <h3 class="t-label-sm" style="color: var(--color-brand-yellow); margin: 0 0 12px; display: flex; justify-content: space-between; align-items: baseline;">
                                <span>Tries Per Season</span>
                                <span style="color: var(--color-muted); font-weight: 400; text-transform: none; letter-spacing: .05em;">{{ count($chartData['seasons']) }} seasons</span>
                            </h3>
                            <div style="height: 220px;"><canvas id="triesChart"></canvas></div>
                        </div>
                    @endif

                    @if($chartData['career']['total_points'] > 0)
                        @php
                            $tryPts = $chartData['career']['tries'] * 5;
                            $convPts = $chartData['career']['conversions'] * 2;
                            $penPts = $chartData['career']['penalties_kicked'] * 3;
                            $dgPts = $chartData['career']['drop_goals'] * 3;
                            $totalPts = max($tryPts + $convPts + $penPts + $dgPts, 1);
                        @endphp
                        <div class="widget" style="padding: 18px 20px;">
                            <h3 class="t-label-sm" style="color: var(--color-brand-yellow); margin: 0; display: flex; justify-content: space-between; align-items: baseline;">
                                <span>Career Points Breakdown</span>
                                <span style="color: var(--color-muted); font-weight: 400; text-transform: none; letter-spacing: .05em;">{{ number_format($chartData['career']['total_points']) }} total</span>
                            </h3>
                            <div class="dist-chart">
                                @if($tryPts > 0)
                                    <div class="seg" style="background: var(--color-home-bright); flex: {{ $tryPts }};" title="Tries">
                                        <span class="seg-label">Tries</span>
                                        <span class="seg-value">{{ $tryPts }}</span>
                                    </div>
                                @endif
                                @if($convPts > 0)
                                    <div class="seg" style="background: var(--color-away-bright); flex: {{ $convPts }};" title="Conversions">
                                        <span class="seg-label">Conv.</span>
                                        <span class="seg-value">{{ $convPts }}</span>
                                    </div>
                                @endif
                                @if($penPts > 0)
                                    <div class="seg" style="background: var(--color-brand-yellow); flex: {{ $penPts }};" title="Penalties">
                                        <span class="seg-label">Pen.</span>
                                        <span class="seg-value">{{ $penPts }}</span>
                                    </div>
                                @endif
                                @if($dgPts > 0)
                                    <div class="seg" style="background: var(--color-drop); color: #fff; flex: {{ $dgPts }};" title="Drop Goals">
                                        <span class="seg-label">DG</span>
                                        <span class="seg-value">{{ $dgPts }}</span>
                                    </div>
                                @endif
                            </div>
                            <div class="dist-legend">
                                @if($tryPts > 0)<span><i style="background: var(--color-home-bright);"></i>Tries ({{ $tryPts }})</span>@endif
                                @if($convPts > 0)<span><i style="background: var(--color-away-bright);"></i>Conversions ({{ $convPts }})</span>@endif
                                @if($penPts > 0)<span><i style="background: var(--color-brand-yellow);"></i>Penalties ({{ $penPts }})</span>@endif
                                @if($dgPts > 0)<span><i style="background: var(--color-drop);"></i>Drop Goals ({{ $dgPts }})</span>@endif
                            </div>
                        </div>
                    @endif

                    @if(count($chartData['seasons']) === 0)
                        <div class="widget" style="padding: 40px; text-align: center; color: var(--color-muted);">
                            No season statistics available yet — charts appear once match data is imported.
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- ═══ GROWTH TAB ═══ --}}
        @if($activeTab === 'growth' && (count($measurementChart) > 0 || $canManage))
                <div id="growth" class="widget" style="padding: 16px 18px; margin-bottom: 22px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                        <h3 class="t-label-sm" style="color: var(--color-brand-yellow); margin: 0;">Growth · Height &amp; Weight</h3>
                        <span class="t-label-xs">{{ count($measurementChart) }} measurement{{ count($measurementChart) === 1 ? '' : 's' }}</span>
                    </div>

                    @if(session()->has('measurement-message'))
                        <div style="margin-bottom: 12px; padding: 8px 12px; background: rgba(29,138,91,.2); border: 1px solid var(--color-home); color: var(--color-home-bright); font-size: 12px; border-radius: var(--r-sm);">{{ session('measurement-message') }}</div>
                    @endif

                    @if(count($measurementChart) >= 2)
                        <div style="height: 220px;" wire:ignore>
                            <canvas id="growthChart"></canvas>
                        </div>
                    @elseif(count($measurementChart) === 1)
                        <p style="font-size: 13px; color: var(--color-muted); margin: 0;">Only one measurement so far — chart appears from two onwards.</p>
                    @else
                        <p style="font-size: 13px; color: var(--color-muted); margin: 0;">No measurements recorded yet.</p>
                    @endif

                    {{-- Admin: add measurement --}}
                    @auth
                        @if($canManage)
                            <form wire:submit="addMeasurement" style="margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--color-line);">
                                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 8px; align-items: end;">
                                    <div>
                                        <label class="t-label-xs" style="display: block; margin-bottom: 4px;">Height (cm)</label>
                                        <input type="number" wire:model="newHeightCm" min="100" max="230" style="width: 100%; background: var(--color-bg); border: 1px solid var(--color-line); color: var(--color-ink); padding: 8px 10px; font-family: var(--font-mono); font-size: 13px; border-radius: var(--r-sm);">
                                    </div>
                                    <div>
                                        <label class="t-label-xs" style="display: block; margin-bottom: 4px;">Weight (kg)</label>
                                        <input type="number" wire:model="newWeightKg" min="30" max="200" style="width: 100%; background: var(--color-bg); border: 1px solid var(--color-line); color: var(--color-ink); padding: 8px 10px; font-family: var(--font-mono); font-size: 13px; border-radius: var(--r-sm);">
                                    </div>
                                    <div>
                                        <label class="t-label-xs" style="display: block; margin-bottom: 4px;">Date</label>
                                        <input type="date" wire:model="newRecordedAt" style="width: 100%; background: var(--color-bg); border: 1px solid var(--color-line); color: var(--color-ink); padding: 8px 10px; font-family: var(--font-display); font-size: 12px; border-radius: var(--r-sm);">
                                    </div>
                                    <button type="submit" style="background: var(--color-brand-yellow); color: var(--color-brand-yellow-ink); border: none; padding: 9px 16px; font-family: var(--font-display); font-weight: 800; font-size: 11px; letter-spacing: .08em; text-transform: uppercase; border-radius: var(--r-sm); cursor: pointer;">+ Add</button>
                                </div>
                                <input type="text" wire:model="newNotes" placeholder="Notes (optional) — e.g. Pre-season weigh-in" style="margin-top: 8px; width: 100%; background: var(--color-bg); border: 1px solid var(--color-line); color: var(--color-ink-dim); padding: 7px 10px; font-size: 12px; border-radius: var(--r-sm);">
                                @error('newHeightCm') <p style="margin-top: 4px; font-size: 11px; color: var(--color-live);">{{ $message }}</p> @enderror
                                @error('newWeightKg') <p style="margin-top: 4px; font-size: 11px; color: var(--color-live);">{{ $message }}</p> @enderror
                                @error('newRecordedAt') <p style="margin-top: 4px; font-size: 11px; color: var(--color-live);">{{ $message }}</p> @enderror
                            </form>

                            @if($measurements->isNotEmpty())
                                <div style="margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--color-line);">
                                    <div class="t-label-xs" style="margin-bottom: 8px;">Measurement log</div>
                                    <div style="display: flex; flex-direction: column; gap: 4px; max-height: 180px; overflow-y: auto;">
                                        @foreach($measurements as $m)
                                            <div style="display: flex; align-items: center; gap: 10px; font-size: 12px; padding: 4px 0; border-bottom: 1px solid var(--color-line-2);">
                                                <span style="width: 72px; font-family: var(--font-mono); color: var(--color-muted);">{{ $m->recorded_at->format('j M y') }}</span>
                                                <span style="width: 110px; font-family: var(--font-mono); color: var(--color-ink); font-variant-numeric: tabular-nums;">{{ $m->height_cm ? $m->height_cm.' cm' : '—' }} / {{ $m->weight_kg ? $m->weight_kg.' kg' : '—' }}</span>
                                                <span class="t-tag" style="font-size: 9px; color: var(--color-muted);">{{ str($m->source)->replace('_', ' ') }}</span>
                                                @if($m->notes)<span style="font-size: 11px; color: var(--color-muted); font-style: italic; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $m->notes }}</span>@endif
                                                <button type="button" wire:click="deleteMeasurement('{{ $m->id }}')" wire:confirm="Delete this measurement?" style="margin-left: auto; background: none; border: none; color: var(--color-muted); cursor: pointer; font-size: 13px;">✕</button>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endif
                    @endauth
                </div>
            @endif

        {{-- ═══ APPEARANCES TAB ═══ --}}
        @if($activeTab === 'appearances')
            @if($competitionsPlayed->count() > 1)
                <div class="stadium-chips" style="margin-bottom: 16px;">
                    <button type="button" wire:click="filterAppsByCompetition('all')" @class(['chip', 'is-active' => $appsCompetition === 'all'])>All comps ({{ $competitionsPlayed->sum('appearances') }})</button>
                    @foreach($competitionsPlayed as $comp)
                        <button type="button" wire:click="filterAppsByCompetition('{{ $comp->id }}')" @class(['chip', 'is-active' => $appsCompetition === $comp->id])>
                            {{ $comp->name }} ({{ $comp->appearances }})
                        </button>
                    @endforeach
                </div>
            @endif
            <div class="apps-list" style="display: flex; flex-direction: column; gap: 2px;">
                @forelse($recentAppearances as $appearance)
                    @php
                        $match = $appearance->match;
                        $ourTeam = $match?->matchTeams->firstWhere('team_id', $appearance->team_id);
                        $opponent = $match?->matchTeams->firstWhere(fn ($entry) => $entry->team_id !== $appearance->team_id);
                        $result = null;
                        if ($ourTeam && $opponent && $ourTeam->score !== null && $opponent->score !== null) {
                            $result = match (true) {
                                $ourTeam->score > $opponent->score => 'W',
                                $ourTeam->score < $opponent->score => 'L',
                                default => 'D',
                            };
                        }
                        $matchStats = $statsByMatch->get($appearance->match_id, collect())->keyBy('stat_key');
                        $min = $appearance->minutes_played ?: (int) ($matchStats->get('minutes')?->stat_value ?? 0);
                        $roundLabel = $match?->round ? ' · R'.$match->round : '';
                        $resultClass = match ($result) { 'W' => 'win', 'L' => 'loss', 'D' => 'draw', default => '' };
                        $hasScore = $ourTeam?->score !== null && $opponent?->score !== null;
                    @endphp
                    <a href="{{ route('matches.show', $match) }}" @class(['apps-row', $resultClass => (bool) $result])>
                        <span class="result-chip">{{ $result ?? '·' }}</span>
                        <div class="col-date">
                            <span class="dt">{{ strtoupper($match?->kickoff?->format('j M Y') ?? '—') }}</span>
                            <span class="comp">{{ ($match?->season?->competition?->name ?? '').$roundLabel }}</span>
                        </div>
                        <div class="tm">{{ $appearance->team?->name ?? '—' }}</div>
                        <div class="sc">
                            @if($hasScore)
                                <span @class(['fx-winner' => $result === 'W'])>{{ $ourTeam->score }}</span>
                                <span class="d">—</span>
                                <span @class(['fx-winner' => $result === 'L'])>{{ $opponent->score }}</span>
                            @else
                                <span class="d">V</span>
                            @endif
                            @if($min > 0)
                                <span class="mins">{{ $min }}'@if($appearance->jersey_number) · #{{ $appearance->jersey_number }}@endif</span>
                            @endif
                        </div>
                        <div class="tm a">{{ $opponent?->team?->name ?? '—' }}</div>
                    </a>
                @empty
                    <div style="padding: 40px; text-align: center; color: var(--color-muted); background: var(--color-bg-2);">
                        No match appearances recorded yet.
                    </div>
                @endforelse
            </div>
            @if($recentAppearances->hasPages())
                <div style="margin-top: 20px;">{{ $recentAppearances->links() }}</div>
            @endif
        @endif

        {{-- ═══ SEASON STATS TAB ═══ --}}
        @if($activeTab === 'season-stats')
            @forelse($seasonStats as $label => $stats)
                @php
                    $keyed = $stats->keyBy('stat_key');
                    $statOrder = ['appearances', 'starts', 'replacement_appearances', 'tries', 'conversions', 'penalties_kicked', 'drop_goals', 'total_points', 'yellow_cards', 'red_cards'];
                    $ordered = collect($statOrder)->filter(fn ($k) => $keyed->has($k));
                @endphp
                <div style="margin-bottom: 18px;">
                    <h3 class="sec-title">{{ $label }}</h3>
                    <div class="widget" style="padding: 14px 16px;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 14px 20px;">
                            @foreach($ordered as $key)
                                @php $stat = $keyed->get($key); @endphp
                                <div>
                                    <div class="t-label-xs">{{ str($key)->replace('_', ' ')->title() }}</div>
                                    <div style="font-family: var(--font-display); font-weight: 900; font-size: 24px; color: var(--color-ink); letter-spacing: -.02em; line-height: 1; margin-top: 4px;">{{ (int) $stat->stat_value }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @empty
                <div class="widget" style="padding: 40px; text-align: center; color: var(--color-muted);">
                    No season statistics available yet.
                </div>
            @endforelse
        @endif
    </div> {{-- /.page-body --}}

    {{-- Chart.js initialization --}}
    @if(count($chartData['seasons']) > 0)
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const chartDefaults = {
                    color: '#9ca3af',
                    borderColor: '#1f2937',
                    font: { family: 'ui-monospace, monospace', size: 11 },
                };

                Chart.defaults.color = chartDefaults.color;
                Chart.defaults.borderColor = chartDefaults.borderColor;

                const seasons = @json($chartData['seasons']);

                @if(count($chartData['seasons']) > 1)
                // Tries bar chart
                const triesCtx = document.getElementById('triesChart');
                if (triesCtx) {
                    new Chart(triesCtx, {
                        type: 'bar',
                        data: {
                            labels: seasons.map(s => {
                                // Shorten labels: "United Rugby Championship 2025-26" -> "URC 25-26"
                                return s.label.replace(/United Rugby Championship/i, 'URC')
                                    .replace(/European Rugby Champions Cup/i, 'CC')
                                    .replace(/Premiership/i, 'Prem')
                                    .replace(/Super Rugby/i, 'SR')
                                    .replace(/Six Nations/i, '6N')
                                    .replace(/Rugby Championship/i, 'RC')
                                    .replace(/Top 14/i, 'T14')
                                    .replace(/20(\d{2})/g, "'$1");
                            }),
                            datasets: [{
                                label: 'Tries',
                                data: seasons.map(s => s.tries),
                                backgroundColor: '#10b981',
                                borderRadius: 4,
                            }],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false },
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: { stepSize: 1 },
                                    grid: { color: '#1f2937' },
                                },
                                x: {
                                    grid: { display: false },
                                    ticks: { maxRotation: 45 },
                                },
                            },
                        },
                    });
                }
                @endif

                // Points donut chart
                const pointsCtx = document.getElementById('pointsChart');
                if (pointsCtx) {
                    const career = @json($chartData['career']);
                    const tryPts = career.tries * 5;
                    const conPts = career.conversions * 2;
                    const penPts = career.penalties_kicked * 3;
                    const dgPts = career.drop_goals * 3;

                    const data = [];
                    const labels = [];
                    const colors = [];

                    if (tryPts > 0) { data.push(tryPts); labels.push('Tries (' + tryPts + ')'); colors.push('#10b981'); }
                    if (conPts > 0) { data.push(conPts); labels.push('Conversions (' + conPts + ')'); colors.push('#3b82f6'); }
                    if (penPts > 0) { data.push(penPts); labels.push('Penalties (' + penPts + ')'); colors.push('#f59e0b'); }
                    if (dgPts > 0) { data.push(dgPts); labels.push('Drop Goals (' + dgPts + ')'); colors.push('#a855f7'); }

                    if (data.length > 0) {
                        new Chart(pointsCtx, {
                            type: 'doughnut',
                            data: {
                                labels: labels,
                                datasets: [{
                                    data: data,
                                    backgroundColor: colors,
                                    borderWidth: 0,
                                    hoverOffset: 4,
                                }],
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                cutout: '60%',
                                plugins: {
                                    legend: {
                                        position: 'bottom',
                                        labels: {
                                            padding: 12,
                                            usePointStyle: true,
                                            pointStyleWidth: 8,
                                            font: { size: 11 },
                                        },
                                    },
                                },
                            },
                        });
                    }
                }
            });
        </script>
    @endif

    {{-- Growth chart init --}}
    @if(count($measurementChart) >= 2)
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const growthCtx = document.getElementById('growthChart');
                if (!growthCtx) return;

                const data = @json($measurementChart);
                const labels = data.map(d => d.label);

                new Chart(growthCtx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                label: 'Weight (kg)',
                                data: data.map(d => d.weight),
                                borderColor: '#10b981',
                                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                yAxisID: 'yWeight',
                                tension: 0.25,
                                pointRadius: 3,
                                pointHoverRadius: 5,
                                spanGaps: true,
                            },
                            {
                                label: 'Height (cm)',
                                data: data.map(d => d.height),
                                borderColor: '#60a5fa',
                                backgroundColor: 'rgba(96, 165, 250, 0.1)',
                                yAxisID: 'yHeight',
                                tension: 0.25,
                                pointRadius: 3,
                                pointHoverRadius: 5,
                                spanGaps: true,
                                borderDash: [4, 3],
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { position: 'bottom', labels: { usePointStyle: true, pointStyleWidth: 10, font: { size: 11 } } },
                            tooltip: { mode: 'index', intersect: false },
                        },
                        scales: {
                            yWeight: { type: 'linear', position: 'left', title: { display: true, text: 'kg', color: '#10b981' }, grid: { color: '#1f2937' } },
                            yHeight: { type: 'linear', position: 'right', title: { display: true, text: 'cm', color: '#60a5fa' }, grid: { display: false } },
                            x: { grid: { color: '#1f2937' } },
                        },
                    },
                });
            });
        </script>
    @endif
</div>
