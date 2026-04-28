<?php

namespace App\Console\Commands;

use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Schools imported via schoolboyrugby.co.za only have short names ("Drostdy",
 * "KES") and no schoolrugby.co.za external_id, so the daily per-school
 * scraper skips them. This command scrapes schoolrugby.co.za's tournament
 * pages to build a {id: name} directory, then matches against existing
 * teams without an external_id and links them.
 *
 * Once linked, the next rugby:sync-schools picks them up automatically and
 * pulls their full match history.
 */
class LinkSchoolIdsCommand extends Command
{
    protected $signature = 'rugby:link-school-ids
                            {--scrape : Re-scrape the directory first (otherwise reuses cached JSON)}
                            {--dry-run : Report only, do not write external_id updates}';

    protected $description = 'Backfill schoolrugby.co.za external_ids on schools we already have by name';

    public function handle(): int
    {
        $jsonPath = storage_path('app/schoolrugby_directory.json');

        if ($this->option('scrape') || ! is_file($jsonPath)) {
            $this->info('Scraping tournament pages for school directory...');
            $script = base_path('scripts/schoolrugby_directory_scraper.py');
            $result = Process::timeout(900)->run(['python3', $script]);
            if (! $result->successful()) {
                $this->error('Scrape failed: '.trim($result->errorOutput() ?: $result->output()));

                return self::FAILURE;
            }
            $this->line(trim($result->output()));
        }

        if (! is_file($jsonPath)) {
            $this->error("Directory JSON missing: {$jsonPath}");

            return self::FAILURE;
        }

        $directory = json_decode(file_get_contents($jsonPath), true)['schools'] ?? [];
        $this->info('Directory entries: '.count($directory));

        // Lowercase index for case-insensitive matching.
        $byName = [];
        foreach ($directory as $sid => $name) {
            $byName[mb_strtolower(trim($name))] = ['id' => (string) $sid, 'name' => $name];
        }

        $unlinked = Team::where('type', 'school')
            ->whereNull('external_id')
            ->get();

        $this->info('Schools without external_id: '.$unlinked->count());

        $matched = 0;
        $ambiguous = 0;
        $unmatched = 0;
        $skipped = 0;
        $dryRun = (bool) $this->option('dry-run');

        // Patterns that should never link — placeholders or distinct squads
        // that share a parent name (junior teams, 2nd XV, etc.).
        $skipRegex = '/^(tba|tbc|bye|tbd)$|\\b(2nd|3rd|4th|5th)\\s*xv\\b|\\bu1[2-9][a-z]?\\b|\\bcolts?\\b/i';

        foreach ($unlinked as $team) {
            $needle = mb_strtolower(trim($team->name));

            if (preg_match($skipRegex, $team->name)) {
                $skipped++;
                continue;
            }

            // Exact name match against the directory.
            if (isset($byName[$needle])) {
                $hit = $byName[$needle];
                if (! $dryRun) {
                    $team->update([
                        'external_id' => $hit['id'],
                        'external_source' => 'schoolrugby.co.za',
                    ]);
                }
                $this->line(sprintf('  link  %-40s -> %s (id=%s)', $team->name, $hit['name'], $hit['id']));
                $matched++;

                continue;
            }

            // Substring match — collect all directory entries that contain or
            // are contained by this team's name. Accept only when exactly one.
            $candidates = [];
            foreach ($byName as $dirName => $hit) {
                if (str_contains($dirName, $needle) || str_contains($needle, $dirName)) {
                    $candidates[] = $hit;
                }
            }

            if (count($candidates) === 1) {
                $hit = $candidates[0];
                if (! $dryRun) {
                    $team->update([
                        'external_id' => $hit['id'],
                        'external_source' => 'schoolrugby.co.za',
                    ]);
                }
                $this->line(sprintf('  link~ %-40s -> %s (id=%s)', $team->name, $hit['name'], $hit['id']));
                $matched++;
            } elseif (count($candidates) > 1) {
                $ambiguous++;
            } else {
                $unmatched++;
            }
        }

        $this->newLine();
        $this->table(['', 'Count'], [
            ['Linked', $matched],
            ['Ambiguous (skipped)', $ambiguous],
            ['Skipped (junior/placeholder)', $skipped],
            ['Not in directory', $unmatched],
        ]);

        if ($dryRun) {
            $this->warn('Dry run — no changes written.');
        }

        return self::SUCCESS;
    }
}
