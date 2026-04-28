<?php

namespace App\Console\Commands;

use App\Models\Competition;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class RefreshCompetitionCommand extends Command
{
    protected $signature = 'rugby:refresh
                            {--competition=* : Competition code(s) to refresh (e.g. urc, six_nations). Omit for current-season default set.}
                            {--skip-scrape : Skip ESPN scraping, only re-import existing data}
                            {--details : Also re-scrape match details (lineups, events, stats)}
                            {--recompute : Run standings + player stats computation after import}
                            {--audit : Run season audit after refresh}';

    protected $description = 'Full refresh pipeline: scrape ESPN → import → recompute stats → audit';

    /** Default set of active competitions to refresh (those with current seasons on ESPN) */
    private array $defaultCompetitions = [
        'urc', 'six_nations', 'premiership', 'top14', 'champions_cup',
        'challenge_cup', 'super_rugby', 'rugby_championship',
    ];

    public function handle(): int
    {
        $codes = $this->option('competition') ?: $this->defaultCompetitions;
        $doScrape = ! (bool) $this->option('skip-scrape');
        $doDetails = (bool) $this->option('details');
        $doRecompute = (bool) $this->option('recompute');
        $doAudit = (bool) $this->option('audit');

        $this->info('RugbyStats Refresh');
        $this->line('Competitions: ' . implode(', ', $codes));
        $this->newLine();

        $currentYear = (int) date('Y');

        foreach ($codes as $code) {
            $comp = Competition::where('code', $code)->first();
            if (! $comp) {
                $this->warn("Skipping unknown competition: {$code}");
                continue;
            }

            $this->info("=== {$comp->name} ({$code}) ===");

            // Map internal code to ESPN league key
            $espnLeague = $this->espnLeagueKey($code);
            if (! $espnLeague) {
                $this->warn("  No ESPN mapping for {$code} — skipping scrape.");
                continue;
            }

            if ($doScrape) {
                // Scrape current and previous ESPN season years
                $years = [$currentYear - 1, $currentYear];
                $this->line("  Scraping ESPN {$espnLeague} for " . implode(', ', $years) . '...');

                $args = ['python3', 'scripts/espn_match_scraper.py', '--league', $espnLeague,
                    '--from-year', (string) min($years), '--to-year', (string) max($years)];

                if ($doDetails) {
                    $args[] = '--details';
                    $args[] = '--refresh-details';
                }

                $result = Process::timeout(600)->run($args);
                if (! $result->successful()) {
                    $this->warn("  Scrape failed: " . trim($result->errorOutput() ?: $result->output()));
                    continue;
                }
                $this->line('  Scrape complete.');
            }

            // Import
            $this->line("  Importing {$code}...");
            $this->call('rugby:import-espn-matches', ['--league' => $espnLeague]);
        }

        // Dedup + recompute
        if ($doRecompute) {
            $this->newLine();
            $this->info('Running dedup + recompute...');
            $this->call('rugby:deduplicate-matches', ['--force' => true]);
            $this->call('rugby:compute-standings', ['--all-seasons' => true]);
            $this->call('rugby:compute-player-stats', ['--all-seasons' => true]);
        }

        // Audit
        if ($doAudit) {
            $this->newLine();
            $this->info('Running season audit...');
            foreach ($codes as $code) {
                $this->call('rugby:audit-season', ['--competition' => $code]);
            }
        }

        $this->newLine();
        $this->info('Refresh complete.');
        return self::SUCCESS;
    }

    private function espnLeagueKey(string $code): ?string
    {
        $map = [
            'urc' => 'urc',
            'six_nations' => 'six_nations',
            'premiership' => 'premiership',
            'top14' => 'top14',
            'champions_cup' => 'champions_cup',
            'challenge_cup' => 'challenge_cup',
            'super_rugby' => 'super_rugby',
            'rugby_championship' => 'rugby_championship',
            'world_cup' => 'world_cup',
            'autumn_internationals' => 'autumn_internationals',
            'currie_cup' => 'currie_cup',
            'major_league_rugby' => 'mlr',
        ];
        return $map[$code] ?? null;
    }
}
