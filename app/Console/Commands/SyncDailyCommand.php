<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\Season;
use App\Services\Rugby\Import\Sources\ApiSportsImporter;
use Illuminate\Console\Command;

/**
 * Daily sync command — designed to run within the free tier limit (100 requests/day).
 *
 * IMPORTANT: Free API-Sports plan only has access to seasons 2022–2024.
 * Current season data requires a paid plan ($10+/month).
 *
 * Sync strategy (budget: 100 API calls/day):
 *   - 1 call:  GET /leagues (refresh competitions + creates seasons)
 *   - ~N calls: GET /teams per season (needs league + season param)
 *   - ~N calls: GET /games per season
 */
class SyncDailyCommand extends Command
{
    protected $signature = 'rugby:sync-daily
                            {--source=api_sports : Data source to sync from}
                            {--competition= : Specific competition code to sync}
                            {--season= : Specific season year to sync (default: latest free tier)}
                            {--full : Sync all priority competitions}';

    protected $description = 'Sync rugby data from external APIs';

    /**
     * Free tier: seasons 2022–2024 only.
     * Paid tier unlocks current + historical seasons.
     */
    private array $freeSeasonRange = [2022, 2023, 2024];

    /** Priority competitions to sync (SA-focused + major international). */
    private array $priorityLeagues = [
        'united_rugby_championship',
        'super_rugby',
        'european_rugby_champions_cup',
        'premiership_rugby',
        'pro_d2',
        'top_14',
        'major_league_rugby',
    ];

    public function handle(): int
    {
        $source = $this->option('source');
        $this->info("Starting daily sync from {$source}...");

        // Step 1: Sync competitions + seasons (1 API call)
        $this->info('→ Syncing competitions & seasons...');
        $importer = new ApiSportsImporter('competitions');
        $result = $importer->run();
        $this->info("  Competitions: {$result->records_processed} processed, {$result->records_created} created/updated");
        $this->info("  Total seasons in DB: " . Season::count());

        // Step 2: Determine which seasons to sync
        $targetYear = $this->option('season')
            ? (int) $this->option('season')
            : max($this->freeSeasonRange); // Default: 2024 (latest free)

        $this->info("→ Targeting season year: {$targetYear}");

        // Find seasons for that year
        $seasons = Season::where('label', (string) $targetYear)
            ->with('competition')
            ->get();

        if ($this->option('competition')) {
            $seasons = $seasons->filter(
                fn ($s) => $s->competition->code === $this->option('competition')
            );
        } elseif (! $this->option('full')) {
            // Filter to priority leagues
            $filtered = $seasons->filter(
                fn ($s) => in_array($s->competition->code, $this->priorityLeagues)
            );

            if ($filtered->isNotEmpty()) {
                $seasons = $filtered;
            } else {
                // Priority codes might not match exactly — try partial matching
                $filtered = $seasons->filter(function ($s) {
                    $code = $s->competition->code;
                    foreach ($this->priorityLeagues as $priority) {
                        if (str_contains($code, $priority) || str_contains($priority, $code)) {
                            return true;
                        }
                    }
                    return false;
                });

                $seasons = $filtered->isNotEmpty() ? $filtered : $seasons->take(20);
            }
        }

        // Budget check: each season costs 2 API calls (teams + matches)
        $budget = config('services.api_sports.daily_limit', 100) - 1;
        $maxSeasons = intdiv($budget, 2);
        $seasons = $seasons->take($maxSeasons);

        if ($seasons->isEmpty()) {
            $this->warn("No seasons found for year {$targetYear}.");
            $this->info('Daily sync complete.');
            return self::SUCCESS;
        }

        $this->info("→ Syncing {$seasons->count()} season(s) for {$targetYear}...");

        foreach ($seasons as $season) {
            $comp = $season->competition;
            $leagueId = (int) $comp->external_id;
            $seasonYear = (int) $season->label;

            // Sync teams (needs both league AND season)
            $this->info("  [{$comp->name} {$season->label}] Syncing teams...");
            $importer = new ApiSportsImporter(
                entityTypeOverride: 'teams',
                leagueId: $leagueId,
                seasonYear: $seasonYear,
            );
            $result = $importer->run();
            $this->info("    Teams: {$result->records_processed} processed, {$result->records_created} created");

            // Sync matches
            $this->info("  [{$comp->name} {$season->label}] Syncing matches...");
            $importer = new ApiSportsImporter(
                entityTypeOverride: 'matches',
                leagueId: $leagueId,
                seasonYear: $seasonYear,
            );
            $result = $importer->run();
            $this->info("    Matches: {$result->records_processed} processed, {$result->records_created} created");
        }

        // Summary
        $this->newLine();
        $this->info('── Sync Summary ──');
        $this->info("  Competitions: " . Competition::count());
        $this->info("  Seasons:      " . Season::count());
        $this->info("  Teams:        " . \App\Models\Team::count());
        $this->info("  Matches:      " . \App\Models\RugbyMatch::count());
        $this->info("  Match Teams:  " . \App\Models\MatchTeam::count());
        $this->info('Daily sync complete.');

        return self::SUCCESS;
    }
}
