<?php

namespace App\Console\Commands;

use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Wraps both SA schools scrapers + the importer into one cron entry.
 *
 * Two sources cover different gaps:
 *   - schoolrugby.co.za  → per-school feeds, score-focused, but each
 *                          school's recent-history page is short.
 *   - schoolboyrugby.co.za → weekly roundup posts with full results
 *                            tables (and upcoming fixtures previewed).
 *
 * Both scrapers are plain-HTTP (no Playwright), safe to run on prod.
 * Importer is idempotent — adds new matches, updates scores on
 * existing ones (promotes scheduled → ft when scores arrive).
 */
class SyncSchoolsCommand extends Command
{
    protected $signature = 'rugby:sync-schools
                            {--year= : Year to scrape (default: current)}
                            {--skip-scrape : Reuse the existing JSONs, only re-import}
                            {--source= : Limit to one source: schoolrugby | schoolboyrugby}';

    protected $description = 'Scrape SA schools rugby (both sources) and import into the DB';

    public function handle(): int
    {
        $year = $this->option('year') ?: (string) now()->year;
        $only = $this->option('source');
        $skipScrape = (bool) $this->option('skip-scrape');

        // When run unattended (cron) we don't want one source's failure to
        // break the other. Single-source manual runs propagate the exit code.
        $tolerant = $only === null;

        $codes = [];

        if (in_array($only, [null, 'schoolrugby'], true)) {
            $codes[] = $this->syncSchoolrugby($year, $skipScrape);
        }

        if (in_array($only, [null, 'schoolboyrugby'], true)) {
            $codes[] = $this->syncSchoolboyrugby($year, $skipScrape);
        }

        if ($tolerant) {
            // At least one source must have succeeded for the run to count.
            return in_array(self::SUCCESS, $codes, true) ? self::SUCCESS : self::FAILURE;
        }

        return collect($codes)->every(fn ($c) => $c === self::SUCCESS) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * schoolrugby.co.za — per-school feeds. We scrape the IDs we already
     * have rosters for so the list stays self-maintaining.
     */
    private function syncSchoolrugby(string $year, bool $skipScrape): int
    {
        $this->newLine();
        $this->info('━━━ schoolrugby.co.za ━━━');

        $schoolIds = Team::where('external_source', 'schoolrugby.co.za')
            ->whereNotNull('external_id')
            ->pluck('external_id')
            ->unique()
            ->values();

        if ($schoolIds->isEmpty()) {
            $this->warn('No tracked schools — skipping schoolrugby.co.za.');

            return self::SUCCESS;
        }

        if (! $skipScrape) {
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
                $this->warn("No previous schoolrugby JSON for {$year} — skipping.");

                return self::SUCCESS;
            }
            usort($candidates, fn ($a, $b) => filemtime($b) <=> filemtime($a));
            $jsonPath = $candidates[0];
        }

        $this->info("Importing {$jsonPath}...");

        return $this->call('rugby:import-school-rugby', ['--path' => $jsonPath]);
    }

    /**
     * schoolboyrugby.co.za — weekly roundup posts. --fixtures lets us
     * also seed scheduled matches from the previews so per-school
     * "next match" lookups work.
     */
    private function syncSchoolboyrugby(string $year, bool $skipScrape): int
    {
        $this->newLine();
        $this->info('━━━ schoolboyrugby.co.za ━━━');

        $jsonPath = storage_path("app/schoolboyrugby_y{$year}.json");

        if (! $skipScrape) {
            $this->info("Scraping weekly roundups for {$year}...");

            $script = base_path('scripts/schoolboyrugby_scraper.py');
            $result = Process::timeout(1800)->run([
                'python3', $script, '--year', $year,
            ]);

            if (! $result->successful()) {
                $this->error('Scraper failed: '.trim($result->errorOutput() ?: $result->output()));

                return self::FAILURE;
            }

            $this->line(trim($result->output()));
        }

        if (! is_file($jsonPath)) {
            $this->warn("No schoolboyrugby JSON at {$jsonPath} — skipping.");

            return self::SUCCESS;
        }

        $this->info("Importing {$jsonPath} (with --fixtures)...");

        return $this->call('rugby:import-school-rugby', [
            '--path' => $jsonPath,
            '--fixtures' => true,
        ]);
    }
}
