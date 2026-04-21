<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\Season;
use App\Models\Standing;
use App\Services\Rugby\StandingsComputer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ComputeStandingsCommand extends Command
{
    protected $signature = 'rugby:compute-standings
                            {--competition= : Competition code (e.g. urc, rugby_championship)}
                            {--season= : Season label (e.g. 2024-25). Omit for current seasons.}
                            {--all-seasons : Compute for all seasons, not just current}
                            {--audit : Run data integrity audit and show detailed report}
                            {--dry-run : Show computed standings without writing to database}';

    protected $description = 'Compute league standings from match results, with optional data integrity audit';

    private int $seasonsProcessed = 0;
    private int $standingsWritten = 0;

    public function handle(): int
    {
        $seasons = $this->resolveSeasons();

        if ($seasons->isEmpty()) {
            $this->warn('No seasons found matching the given criteria.');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $audit = (bool) $this->option('audit');

        if ($dryRun) {
            $this->info('DRY RUN mode — no standings will be written.');
        }

        foreach ($seasons as $season) {
            $season->loadMissing('competition');
            $label = "{$season->competition->name} {$season->label}";

            $this->newLine();
            $this->info("=== {$label} ===");

            $computer = StandingsComputer::forSeason($season);

            if ($audit) {
                $report = $computer->audit();
                $report->renderTo($this);
            }

            $standings = $computer->compute();

            if ($standings->isEmpty()) {
                $this->warn("  No standings computed (no completed matches with scores).");
                continue;
            }

            // Display standings table
            $this->renderStandingsTable($standings, $season);

            if (! $dryRun) {
                $this->writeStandings($standings, $season);
            }

            $this->seasonsProcessed++;
        }

        $this->newLine();
        $this->info('=== Summary ===');
        $this->table(['Metric', 'Count'], [
            ['Seasons processed', $this->seasonsProcessed],
            ['Standing rows written', $dryRun ? '0 (dry run)' : $this->standingsWritten],
        ]);

        return self::SUCCESS;
    }

    private function resolveSeasons(): \Illuminate\Support\Collection
    {
        $competitionCode = $this->option('competition');
        $seasonLabel = $this->option('season');
        $allSeasons = (bool) $this->option('all-seasons');

        if ($competitionCode) {
            $competition = Competition::where('code', $competitionCode)->first();
            if (! $competition) {
                $this->error("Competition not found: {$competitionCode}");
                return collect();
            }

            $query = $competition->seasons();

            if ($seasonLabel) {
                $query->where('label', $seasonLabel);
            } elseif (! $allSeasons) {
                $query->where('is_current', true);
                // Fall back to latest season if none marked current
                if ($query->count() === 0) {
                    return $competition->seasons()->orderByDesc('label')->limit(1)->get();
                }
            }

            return $query->orderByDesc('label')->get();
        }

        // No competition specified
        if ($allSeasons) {
            return Season::with('competition')->orderBy('competition_id')->orderByDesc('label')->get();
        }

        // Default: current seasons across all competitions
        $current = Season::with('competition')->where('is_current', true)->get();
        if ($current->isEmpty()) {
            $this->warn('No current seasons found. Use --competition and --season, or --all-seasons.');
        }

        return $current;
    }

    private function renderStandingsTable(\Illuminate\Support\Collection $standings, Season $season): void
    {
        $season->loadMissing('competition');

        // Load team names
        $teamIds = $standings->pluck('team_id')->unique();
        $teamNames = \App\Models\Team::whereIn('id', $teamIds)->pluck('name', 'id');

        $pools = $standings->groupBy('pool');

        foreach ($pools as $pool => $poolStandings) {
            if ($pool) {
                $this->newLine();
                $this->line("  Pool: {$pool}");
            }

            $rows = $poolStandings->map(fn ($s) => [
                $s['position'],
                $teamNames[$s['team_id']] ?? 'Unknown',
                $s['played'],
                $s['won'],
                $s['drawn'],
                $s['lost'],
                $s['points_for'],
                $s['points_against'],
                $s['point_differential'] >= 0 ? "+{$s['point_differential']}" : $s['point_differential'],
                $s['tries_for'],
                $s['bonus_points'],
                $s['total_points'],
            ])->toArray();

            $this->table(
                ['#', 'Team', 'P', 'W', 'D', 'L', 'PF', 'PA', 'PD', 'TF', 'BP', 'Pts'],
                $rows,
            );
        }
    }

    private function writeStandings(\Illuminate\Support\Collection $standings, Season $season): void
    {
        // Delete existing standings for this season, then bulk insert.
        // This is safer than upsert with nullable pool and UUID PKs.
        Standing::where('season_id', $season->id)->delete();

        $rows = $standings->map(function ($s) {
            $s['id'] = (string) Str::uuid();
            $s['created_at'] = now();
            $s['updated_at'] = now();
            return $s;
        })->toArray();

        Standing::insert($rows);
        $this->standingsWritten += count($rows);

        $this->info("  Wrote " . count($rows) . " standing rows.");
    }
}
