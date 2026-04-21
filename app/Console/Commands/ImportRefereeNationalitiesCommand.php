<?php

namespace App\Console\Commands;

use App\Models\Referee;
use Illuminate\Console\Command;

class ImportRefereeNationalitiesCommand extends Command
{
    protected $signature = 'rugby:import-referee-nationalities
                            {--path= : Custom path to referee_nationalities.json}
                            {--dry-run : Preview without writing}';

    protected $description = 'Import referee nationality data from scraped JSON';

    public function handle(): int
    {
        $path = $this->option('path') ?? storage_path('app/referee_nationalities.json');
        $dryRun = (bool) $this->option('dry-run');

        if (! file_exists($path)) {
            $this->error("File not found: {$path}");
            $this->line('Run: python3 scripts/referee_nationality_scraper.py');
            return self::FAILURE;
        }

        $data = json_decode(file_get_contents($path), true);
        if (! $data) {
            $this->error('Empty or invalid JSON.');
            return self::FAILURE;
        }

        $this->info('Matching ' . count($data) . ' scraped officials to referee records...');
        if ($dryRun) {
            $this->info('DRY RUN mode.');
        }

        $matched = 0;
        $updated = 0;
        $unmatched = [];

        foreach ($data as $name => $nationality) {
            $parts = preg_split('/\s+/', trim($name), 2);
            if (count($parts) < 2) {
                continue;
            }

            $firstName = $parts[0];
            $lastName = $parts[1];

            // Try exact match
            $referee = Referee::where('first_name', $firstName)
                ->where('last_name', $lastName)
                ->first();

            // Try case-insensitive
            if (! $referee) {
                $referee = Referee::whereRaw('LOWER(first_name) = ?', [strtolower($firstName)])
                    ->whereRaw('LOWER(last_name) = ?', [strtolower($lastName)])
                    ->first();
            }

            // Try last name only if unique
            if (! $referee) {
                $candidates = Referee::whereRaw('LOWER(last_name) = ?', [strtolower($lastName)])->get();
                if ($candidates->count() === 1) {
                    $referee = $candidates->first();
                }
            }

            if (! $referee) {
                $unmatched[] = $name;
                continue;
            }

            $matched++;

            if (empty($referee->nationality) || $referee->nationality !== $nationality) {
                if (! $dryRun) {
                    $referee->update(['nationality' => $nationality]);
                }
                $updated++;
                $this->line("  <info>Updated</info> {$referee->full_name} → {$nationality}");
            }
        }

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['Scraped officials', count($data)],
            ['Matched to DB', $matched],
            ['Nationality updated', $dryRun ? "{$updated} (dry run)" : $updated],
            ['Unmatched', count($unmatched)],
        ]);

        if (! empty($unmatched)) {
            $this->newLine();
            $this->warn('Unmatched officials (not in DB):');
            foreach ($unmatched as $name) {
                $this->line("  {$name}");
            }
        }

        // Report referees still missing nationality
        $missing = Referee::whereNull('nationality')
            ->orWhere('nationality', '')
            ->count();
        if ($missing > 0) {
            $this->newLine();
            $this->warn("{$missing} referee(s) still missing nationality after import.");
        }

        return self::SUCCESS;
    }
}
