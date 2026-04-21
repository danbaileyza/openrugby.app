<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\MatchEvent;
use App\Models\MatchLineup;
use App\Models\Player;
use App\Models\RugbyMatch;
use App\Models\Season;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ComputePlayerSeasonStatsCommand extends Command
{
    protected $signature = 'rugby:compute-player-stats
                            {--competition= : Competition code}
                            {--season= : Season label}
                            {--all-seasons : Compute for all seasons}
                            {--audit : Show detailed audit report}
                            {--dry-run : Preview without writing}';

    protected $description = 'Compute player season statistics from match events and lineups';

    private int $seasonsProcessed = 0;
    private int $statsWritten = 0;

    public function handle(): int
    {
        $seasons = $this->resolveSeasons();

        if ($seasons->isEmpty()) {
            $this->warn('No seasons found.');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $audit = (bool) $this->option('audit');

        if ($dryRun) {
            $this->info('DRY RUN mode.');
        }

        foreach ($seasons as $season) {
            $season->loadMissing('competition');
            $label = "{$season->competition->name} {$season->label}";

            $matchIds = RugbyMatch::where('season_id', $season->id)
                ->where('status', 'ft')
                ->pluck('id');

            if ($matchIds->isEmpty()) {
                continue;
            }

            $this->newLine();
            $this->info("=== {$label} ({$matchIds->count()} matches) ===");

            $stats = $this->computeForSeason($season, $matchIds);

            if ($stats->isEmpty()) {
                $this->warn('  No player data found.');
                continue;
            }

            if ($audit) {
                $this->renderAudit($stats, $season);
            }

            if (! $dryRun) {
                $this->writeStats($stats, $season);
            }

            $this->info("  {$stats->count()} players, " . $stats->sum(fn ($s) => count($s)) . " stat rows");
            $this->seasonsProcessed++;
        }

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['Seasons processed', $this->seasonsProcessed],
            ['Stat rows written', $dryRun ? '0 (dry run)' : $this->statsWritten],
        ]);

        return self::SUCCESS;
    }

    /**
     * Compute stats for a season. Returns Collection keyed by player_id,
     * each value is an array of stat_key => stat_value.
     */
    private function computeForSeason(Season $season, $matchIds): \Illuminate\Support\Collection
    {
        $playerStats = collect();

        // 1. Appearances from lineups
        $appearances = MatchLineup::whereIn('match_id', $matchIds)
            ->whereNotNull('player_id')
            ->select('player_id', 'role')
            ->selectRaw('COUNT(*) as cnt')
            ->groupBy('player_id', 'role')
            ->get();

        foreach ($appearances as $a) {
            $key = $a->role === 'starter' ? 'starts' : 'replacement_appearances';
            $this->addStat($playerStats, $a->player_id, $key, $a->cnt);
            $this->addStat($playerStats, $a->player_id, 'appearances', $a->cnt);
        }

        // 2. Scoring and cards from match events
        $eventTypes = [
            'try' => 'tries',
            'conversion' => 'conversions',
            'penalty_goal' => 'penalties_kicked',
            'drop_goal' => 'drop_goals',
            'yellow_card' => 'yellow_cards',
            'red_card' => 'red_cards',
        ];

        $events = MatchEvent::whereIn('match_id', $matchIds)
            ->whereNotNull('player_id')
            ->whereIn('type', array_keys($eventTypes))
            ->select('player_id', 'type')
            ->selectRaw('COUNT(*) as cnt')
            ->groupBy('player_id', 'type')
            ->get();

        foreach ($events as $e) {
            $statKey = $eventTypes[$e->type];
            $this->addStat($playerStats, $e->player_id, $statKey, $e->cnt);
        }

        // 3. Compute total_points from scoring events
        foreach ($playerStats->keys() as $playerId) {
            $stats = $playerStats[$playerId];
            $points = 0;
            $points += ($stats['tries'] ?? 0) * 5;
            $points += ($stats['conversions'] ?? 0) * 2;
            $points += ($stats['penalties_kicked'] ?? 0) * 3;
            $points += ($stats['drop_goals'] ?? 0) * 3;

            if ($points > 0) {
                $stats['total_points'] = $points;
                $playerStats[$playerId] = $stats;
            }
        }

        return $playerStats;
    }

    private function addStat(\Illuminate\Support\Collection &$playerStats, string $playerId, string $key, int $value): void
    {
        if (! $playerStats->has($playerId)) {
            $playerStats[$playerId] = [];
        }

        $current = $playerStats[$playerId];
        $current[$key] = ($current[$key] ?? 0) + $value;
        $playerStats[$playerId] = $current;
    }

    private function writeStats(\Illuminate\Support\Collection $playerStats, Season $season): void
    {
        // Clear existing computed stats for this season
        DB::table('player_season_stats')
            ->where('season_id', $season->id)
            ->delete();

        $rows = [];
        foreach ($playerStats as $playerId => $stats) {
            foreach ($stats as $key => $value) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'player_id' => $playerId,
                    'season_id' => $season->id,
                    'stat_key' => $key,
                    'stat_value' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // Bulk insert in chunks
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('player_season_stats')->insert($chunk);
        }

        $this->statsWritten += count($rows);
    }

    private function renderAudit(\Illuminate\Support\Collection $playerStats, Season $season): void
    {
        // Top try scorers
        $topTries = $playerStats->filter(fn ($s) => isset($s['tries']))
            ->sortByDesc(fn ($s) => $s['tries'])
            ->take(10);

        $playerNames = Player::whereIn('id', $topTries->keys())->pluck(DB::raw("CONCAT(first_name, ' ', last_name)"), 'id');

        $this->newLine();
        $this->info('  Top try scorers:');
        $rows = [];
        foreach ($topTries as $playerId => $stats) {
            $rows[] = [
                $playerNames[$playerId] ?? $playerId,
                $stats['appearances'] ?? 0,
                $stats['tries'] ?? 0,
                $stats['conversions'] ?? 0,
                $stats['penalties_kicked'] ?? 0,
                $stats['yellow_cards'] ?? 0,
                $stats['total_points'] ?? 0,
            ];
        }
        $this->table(['Player', 'Apps', 'T', 'C', 'PK', 'YC', 'Pts'], $rows);
    }

    private function resolveSeasons(): \Illuminate\Support\Collection
    {
        $code = $this->option('competition');
        $label = $this->option('season');
        $all = (bool) $this->option('all-seasons');

        if ($code) {
            $comp = Competition::where('code', $code)->first();
            if (! $comp) {
                $this->error("Competition not found: {$code}");
                return collect();
            }
            $query = $comp->seasons();
            if ($label) {
                $query->where('label', $label);
            } elseif (! $all) {
                $query->where('is_current', true);
            }
            return $query->orderByDesc('label')->get();
        }

        if ($all) {
            return Season::with('competition')->orderBy('competition_id')->orderByDesc('label')->get();
        }

        return Season::with('competition')->where('is_current', true)->get();
    }
}
