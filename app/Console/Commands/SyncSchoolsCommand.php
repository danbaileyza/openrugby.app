<?php

namespace App\Console\Commands;

use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Wraps schoolrugby_scraper.py + rugby:import-school-rugby into a single
 * cron-friendly entry. The scraper hits a plain HTTP API (no Playwright),
 * so this safely runs on prod nightly. Importer is idempotent — adds new
 * matches, updates scores on existing ones.
 *
 * School IDs are pulled from teams already in the DB (external_source =
 * schoolrugby.co.za). Add a school via the admin UI or one-off scrape and
 * it gets picked up automatically on the next run.
 */
class SyncSchoolsCommand extends Command
{
    protected $signature = 'rugby:sync-schools
                            {--year= : Year to scrape (default: current)}
                            {--skip-scrape : Reuse the existing JSON, only re-import}';

    protected $description = 'Scrape SA schools rugby results and import into the DB';

    public function handle(): int
    {
        $year = $this->option('year') ?: (string) now()->year;

        // Pull tracked school IDs straight from the DB so the list is always
        // in sync with what we already have rosters for.
        $schoolIds = Team::where('external_source', 'schoolrugby.co.za')
            ->whereNotNull('external_id')
            ->pluck('external_id')
            ->unique()
            ->values();

        if ($schoolIds->isEmpty()) {
            $this->error('No tracked schools in the DB (external_source = schoolrugby.co.za).');

            return self::FAILURE;
        }

        // The scraper hardcodes the school count into the filename, which
        // moves every time we discover new schools. For the scrape step we
        // know the exact count up front; for --skip-scrape we glob and use
        // the most recent matching file so a previous run's output is reusable.
        if (! $this->option('skip-scrape')) {
            $this->info("Scraping {$schoolIds->count()} schools for {$year}...");

            $script = base_path('scripts/schoolrugby_scraper.py');
            $result = Process::timeout(1800)->run([
                'python3', $script,
                '--schools', $schoolIds->implode(','),
                '--year', $year,
            ]);

            if (! $result->successful()) {
                $this->error('Scraper failed: '.trim($result->errorOutput() ?: $result->output()));

                return self::FAILURE;
            }

            $this->line(trim($result->output()));

            $jsonPath = storage_path("app/schoolrugby_schools-{$schoolIds->count()}_{$year}.json");
        } else {
            $candidates = glob(storage_path("app/schoolrugby_schools-*_{$year}.json")) ?: [];
            if (empty($candidates)) {
                $this->error("No previous scrape JSON found for {$year}. Run without --skip-scrape first.");

                return self::FAILURE;
            }
            // Pick the most recently modified — usually the largest school count too.
            usort($candidates, fn ($a, $b) => filemtime($b) <=> filemtime($a));
            $jsonPath = $candidates[0];
        }

        if (! is_file($jsonPath)) {
            $this->error("JSON not found: {$jsonPath}");

            return self::FAILURE;
        }

        $this->info("Importing {$jsonPath}...");

        return $this->call('rugby:import-school-rugby', ['--path' => $jsonPath]);
    }
}
