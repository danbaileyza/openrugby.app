<?php

namespace App\Console\Commands;

use App\Models\MatchTeam;
use App\Models\RugbyMatch;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Imports saved all.rugby match pages to backfill scores and match metadata.
 *
 * Workflow:
 *   1. Run: python3 scripts/allrugby_scraper.py --matches-from-careers
 *   2. Run: php artisan rugby:import-allrugby-matches
 */
class ImportAllRugbyMatchesCommand extends Command
{
    protected $signature = 'rugby:import-allrugby-matches
                            {--path= : Custom path to allrugby directory}
                            {--match-id= : Import a single all.rugby match ID}
                            {--dry-run : Preview without writing}';

    protected $description = 'Import all.rugby match pages: scores and match metadata';

    private string $basePath;
    private bool $dryRun = false;

    private int $filesProcessed = 0;
    private int $matchesUpdated = 0;
    private int $scoresUpdated = 0;
    private int $skipped = 0;

    public function handle(): int
    {
        $this->basePath = $this->option('path') ?? storage_path('app/allrugby');
        $this->dryRun = (bool) $this->option('dry-run');

        if (! is_dir($this->basePath)) {
            $this->error("Data directory not found: {$this->basePath}");
            return 1;
        }

        if ($this->dryRun) {
            $this->info('DRY RUN mode.');
        }

        $matchId = $this->option('match-id');
        if ($matchId) {
            $this->importSingleMatch((string) $matchId);
        } else {
            $this->importAllMatches();
        }

        $this->newLine();
        $this->info('=== All.Rugby Match Import Summary ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Files processed', $this->filesProcessed],
                ['Matches updated', $this->matchesUpdated],
                ['Scores updated', $this->scoresUpdated],
                ['Skipped', $this->skipped],
            ]
        );

        return 0;
    }

    private function importSingleMatch(string $matchId): void
    {
        $paths = [
            "{$this->basePath}/matches/match_{$matchId}.json",
            "{$this->basePath}/match_{$matchId}.json",
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $this->processFile($path);
                return;
            }
        }

        $this->error("Match file not found for ID: {$matchId}");
    }

    private function importAllMatches(): void
    {
        $matchesDir = $this->basePath . '/matches';
        $files = is_dir($matchesDir)
            ? glob("{$matchesDir}/match_*.json")
            : glob("{$this->basePath}/match_*.json");

        if (! $files) {
            $this->warn('No saved all.rugby match pages found.');
            $this->line('Run: python3 scripts/allrugby_scraper.py --matches-from-careers');
            return;
        }

        sort($files);
        $this->info('Processing ' . count($files) . ' match pages...');

        $bar = $this->output->createProgressBar(count($files));
        foreach ($files as $file) {
            $this->processFile($file);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
    }

    private function processFile(string $path): void
    {
        $data = json_decode(file_get_contents($path), true);
        $this->filesProcessed++;

        if (! is_array($data)) {
            $this->skipped++;
            return;
        }

        $matchId = (string) ($data['match_id'] ?? '');
        if ($matchId === '') {
            $this->skipped++;
            return;
        }

        $match = RugbyMatch::with('matchTeams')
            ->where('external_id', $matchId)
            ->where('external_source', 'allrugby')
            ->first();

        if (! $match) {
            $this->skipped++;
            return;
        }

        $updated = false;

        $matchDate = $data['date'] ?? null;
        if ($matchDate) {
            try {
                $date = Carbon::parse($matchDate)->startOfDay();
                $currentDate = $match->kickoff?->copy()->startOfDay();

                if (! $currentDate || ! $currentDate->equalTo($date)) {
                    if (! $this->dryRun) {
                        $match->kickoff = $date;
                    }
                    $updated = true;
                }
            } catch (\Exception $e) {
                // Ignore bad dates from scraped payloads.
            }
        }

        $homeScore = $data['home_score'] ?? null;
        $awayScore = $data['away_score'] ?? null;

        if ($homeScore !== null && $awayScore !== null) {
            $home = $match->matchTeams->firstWhere('side', 'home');
            $away = $match->matchTeams->firstWhere('side', 'away');

            if ($home) {
                $updated = $this->updateMatchTeamScore($home, (int) $homeScore, (int) $awayScore) || $updated;
            }

            if ($away) {
                $updated = $this->updateMatchTeamScore($away, (int) $awayScore, (int) $homeScore) || $updated;
            }
        }

        if ($updated) {
            if (! $this->dryRun) {
                $match->save();
            }
            $this->matchesUpdated++;
        } else {
            $this->skipped++;
        }
    }

    private function updateMatchTeamScore(MatchTeam $team, int $score, int $otherScore): bool
    {
        $winner = $score > $otherScore ? true : ($score < $otherScore ? false : false);

        $hasChanges =
            $team->score !== $score ||
            $team->is_winner !== $winner;

        if (! $hasChanges) {
            return false;
        }

        if (! $this->dryRun) {
            $team->score = $score;
            $team->is_winner = $winner;
            $team->save();
        }

        $this->scoresUpdated++;

        return true;
    }
}
