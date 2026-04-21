<?php

namespace App\Livewire;

use App\Models\Competition;
use App\Models\MatchLineup;
use App\Models\Player;
use App\Models\PlayerMatchStat;
use App\Models\PlayerMeasurement;
use Livewire\Component;
use Livewire\WithPagination;

class PlayerShow extends Component
{
    use WithPagination;

    public Player $player;

    public string $activeTab = 'overview';

    public ?int $newHeightCm = null;

    public ?int $newWeightKg = null;

    public ?string $newRecordedAt = null;

    public ?string $newNotes = null;

    public string $appsCompetition = 'all';

    public function mount(Player $player)
    {
        $this->player = $player;
        $this->newRecordedAt = now()->toDateString();
    }

    public function showTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetPage();
    }

    public function filterAppsByCompetition(string $competitionId): void
    {
        $this->appsCompetition = $competitionId;
        $this->resetPage();
    }

    public function addMeasurement(): void
    {
        if (! auth()->user()?->canManagePlayer($this->player)) {
            abort(403);
        }

        $data = $this->validate([
            'newHeightCm' => 'nullable|integer|min:100|max:230',
            'newWeightKg' => 'nullable|integer|min:30|max:200',
            'newRecordedAt' => 'required|date|before_or_equal:today',
            'newNotes' => 'nullable|string|max:255',
        ]);

        if (! $data['newHeightCm'] && ! $data['newWeightKg']) {
            $this->addError('newHeightCm', 'Enter height or weight (or both).');

            return;
        }

        PlayerMeasurement::create([
            'player_id' => $this->player->id,
            'height_cm' => $data['newHeightCm'],
            'weight_kg' => $data['newWeightKg'],
            'recorded_at' => $data['newRecordedAt'],
            'source' => PlayerMeasurement::SOURCE_MANUAL,
            'notes' => $data['newNotes'],
            'captured_by_user_id' => auth()->id(),
        ]);

        $this->reset(['newHeightCm', 'newWeightKg', 'newNotes']);
        $this->newRecordedAt = now()->toDateString();
        session()->flash('measurement-message', 'Measurement recorded.');
    }

    public function deleteMeasurement(string $measurementId): void
    {
        if (! auth()->user()?->canManagePlayer($this->player)) {
            abort(403);
        }

        PlayerMeasurement::where('id', $measurementId)
            ->where('player_id', $this->player->id)
            ->delete();
    }

    public function render()
    {
        $this->player->load([
            'contracts.team',
            'seasonStats.season.competition',
        ]);

        $grouped = $this->player->seasonStats
            ->groupBy(fn ($s) => $s->season->competition->name.' '.$s->season->label);

        $appsQuery = $this->player->matchLineups()
            ->select('match_lineups.*')
            ->join('matches', 'matches.id', '=', 'match_lineups.match_id')
            ->join('seasons', 'seasons.id', '=', 'matches.season_id')
            ->with([
                'team',
                'match.season.competition',
                'match.matchTeams.team',
            ]);

        if ($this->appsCompetition !== 'all') {
            $appsQuery->where('seasons.competition_id', $this->appsCompetition);
        }

        $recentAppearances = $appsQuery
            ->orderByDesc('matches.kickoff')
            ->orderByDesc('match_lineups.created_at')
            ->paginate(20);

        // Distinct competitions the player has appearances in — for filter chips
        $competitionsPlayed = Competition::query()
            ->whereIn('id', MatchLineup::where('player_id', $this->player->id)
                ->join('matches', 'matches.id', '=', 'match_lineups.match_id')
                ->join('seasons', 'seasons.id', '=', 'matches.season_id')
                ->distinct()
                ->pluck('seasons.competition_id'))
            ->withCount(['seasons as appearances' => function ($q) {
                $q->join('matches', 'matches.season_id', '=', 'seasons.id')
                    ->join('match_lineups', 'match_lineups.match_id', '=', 'matches.id')
                    ->where('match_lineups.player_id', $this->player->id);
            }])
            ->orderByDesc('appearances')
            ->get();

        $statsByMatch = PlayerMatchStat::where('player_id', $this->player->id)
            ->whereIn('match_id', $recentAppearances->pluck('match_id'))
            ->get()
            ->groupBy('match_id');

        // Build chart data from season stats
        $chartData = $this->buildChartData($grouped);

        $measurements = $this->player->measurements()
            ->orderBy('recorded_at')
            ->get();

        $measurementChart = $measurements->map(fn ($m) => [
            'date' => $m->recorded_at->format('Y-m-d'),
            'label' => $m->recorded_at->format('j M y'),
            'height' => $m->height_cm,
            'weight' => $m->weight_kg,
        ])->values()->all();

        return view('livewire.player-show', [
            'seasonStats' => $grouped,
            'recentAppearances' => $recentAppearances,
            'statsByMatch' => $statsByMatch,
            'chartData' => $chartData,
            'measurements' => $measurements->sortByDesc('recorded_at')->values(),
            'measurementChart' => $measurementChart,
            'competitionsPlayed' => $competitionsPlayed,
        ])->layout('layouts.app', ['title' => $this->player->full_name, 'fullBleed' => true]);
    }

    private function buildChartData($grouped): array
    {
        // Collect per-season totals across all competitions
        $seasonTotals = [];

        foreach ($grouped as $label => $stats) {
            $keyed = $stats->keyBy('stat_key');
            $seasonTotals[] = [
                'label' => $label,
                'appearances' => (int) ($keyed->get('appearances')?->stat_value ?? 0),
                'tries' => (int) ($keyed->get('tries')?->stat_value ?? 0),
                'conversions' => (int) ($keyed->get('conversions')?->stat_value ?? 0),
                'penalties_kicked' => (int) ($keyed->get('penalties_kicked')?->stat_value ?? 0),
                'drop_goals' => (int) ($keyed->get('drop_goals')?->stat_value ?? 0),
                'yellow_cards' => (int) ($keyed->get('yellow_cards')?->stat_value ?? 0),
                'red_cards' => (int) ($keyed->get('red_cards')?->stat_value ?? 0),
                'total_points' => (int) ($keyed->get('total_points')?->stat_value ?? 0),
            ];
        }

        // Reverse so oldest first for charts
        $seasonTotals = array_reverse($seasonTotals);

        // Career totals for pie chart
        $careerTries = array_sum(array_column($seasonTotals, 'tries'));
        $careerConversions = array_sum(array_column($seasonTotals, 'conversions'));
        $careerPenalties = array_sum(array_column($seasonTotals, 'penalties_kicked'));
        $careerDropGoals = array_sum(array_column($seasonTotals, 'drop_goals'));
        $careerYellows = array_sum(array_column($seasonTotals, 'yellow_cards'));
        $careerReds = array_sum(array_column($seasonTotals, 'red_cards'));
        $careerApps = array_sum(array_column($seasonTotals, 'appearances'));
        $careerPoints = array_sum(array_column($seasonTotals, 'total_points'));

        return [
            'seasons' => $seasonTotals,
            'career' => [
                'appearances' => $careerApps,
                'tries' => $careerTries,
                'conversions' => $careerConversions,
                'penalties_kicked' => $careerPenalties,
                'drop_goals' => $careerDropGoals,
                'yellow_cards' => $careerYellows,
                'red_cards' => $careerReds,
                'total_points' => $careerPoints,
            ],
        ];
    }
}
