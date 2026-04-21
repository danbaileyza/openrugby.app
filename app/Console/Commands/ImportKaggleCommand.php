<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\DataImport;
use App\Models\MatchTeam;
use App\Models\RugbyMatch;
use App\Models\Season;
use App\Models\Team;
use App\Models\Venue;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Import historical international rugby results from Kaggle CSV.
 *
 * Dataset: lylebegbie/international-rugby-union-results-from-18712022
 * Download from: https://www.kaggle.com/datasets/lylebegbie/international-rugby-union-results-from-18712022
 *
 * Place the CSV at: storage/app/kaggle/results.csv
 *
 * Expected columns: date, home_team, away_team, home_score, away_score,
 *                   tournament, city, country, neutral
 */
class ImportKaggleCommand extends Command
{
    protected $signature = 'rugby:import-kaggle
                            {file? : Path to CSV file (default: storage/app/kaggle/results.csv)}
                            {--since= : Only import matches from this year onwards}';

    protected $description = 'Import historical international rugby results from Kaggle CSV';

    public function handle(): int
    {
        $path = $this->argument('file')
            ?? storage_path('app/kaggle/results.csv');

        if (! file_exists($path)) {
            $this->error("CSV file not found at: {$path}");
            $this->info('Download from: https://www.kaggle.com/datasets/lylebegbie/international-rugby-union-results-from-18712022');
            $this->info('Place at: storage/app/kaggle/results.csv');
            return self::FAILURE;
        }

        $import = DataImport::create([
            'source'      => 'kaggle',
            'entity_type' => 'matches',
            'status'      => 'running',
            'started_at'  => now(),
        ]);

        $since = $this->option('since') ? (int) $this->option('since') : null;

        // Read CSV
        $handle = fopen($path, 'r');
        $headers = fgetcsv($handle);
        $headers = array_map('trim', $headers);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);
            if ($since && Carbon::parse($data['date'])->year < $since) {
                continue;
            }
            $rows[] = $data;
        }
        fclose($handle);

        $this->info("Importing " . count($rows) . " international matches from Kaggle CSV...");

        // Ensure "Test Matches" competition exists
        $testComp = Competition::updateOrCreate(
            ['code' => 'international_tests_kaggle'],
            [
                'name'   => 'International Tests (Historical)',
                'format' => 'union',
                'tier'   => 'tier_1',
            ]
        );

        $bar = $this->output->createProgressBar(count($rows));

        foreach ($rows as $row) {
            try {
                $date = Carbon::parse($row['date']);
                $year = $date->year;

                // Get or create season for this year
                $season = Season::firstOrCreate(
                    ['competition_id' => $testComp->id, 'label' => (string) $year],
                    [
                        'start_date' => "{$year}-01-01",
                        'end_date'   => "{$year}-12-31",
                        'is_current' => $year >= (int) date('Y'),
                    ]
                );

                // Get or create teams
                $homeTeam = Team::firstOrCreate(
                    ['name' => $row['home_team'], 'external_source' => 'kaggle'],
                    ['country' => $row['home_team'], 'type' => 'national']
                );
                $awayTeam = Team::firstOrCreate(
                    ['name' => $row['away_team'], 'external_source' => 'kaggle'],
                    ['country' => $row['away_team'], 'type' => 'national']
                );

                // Get or create venue
                $venue = null;
                if (! empty($row['city'])) {
                    $venue = Venue::firstOrCreate(
                        ['name' => $row['city'], 'country' => $row['country'] ?? 'Unknown'],
                        ['city' => $row['city']]
                    );
                }

                // Determine tournament → use as stage
                $tournament = $row['tournament'] ?? null;

                // Create match
                $homeScore = is_numeric($row['home_score']) ? (int) $row['home_score'] : null;
                $awayScore = is_numeric($row['away_score']) ? (int) $row['away_score'] : null;

                $externalId = "kaggle_{$date->format('Ymd')}_{$homeTeam->id}_{$awayTeam->id}";

                $match = RugbyMatch::updateOrCreate(
                    ['external_id' => $externalId, 'external_source' => 'kaggle'],
                    [
                        'season_id' => $season->id,
                        'venue_id'  => $venue?->id,
                        'kickoff'   => $date,
                        'status'    => $homeScore !== null ? 'ft' : 'scheduled',
                        'stage'     => $tournament,
                    ]
                );

                // Create match teams
                if ($homeScore !== null && $awayScore !== null) {
                    MatchTeam::updateOrCreate(
                        ['match_id' => $match->id, 'side' => 'home'],
                        [
                            'team_id'   => $homeTeam->id,
                            'score'     => $homeScore,
                            'is_winner' => $homeScore > $awayScore,
                        ]
                    );
                    MatchTeam::updateOrCreate(
                        ['match_id' => $match->id, 'side' => 'away'],
                        [
                            'team_id'   => $awayTeam->id,
                            'score'     => $awayScore,
                            'is_winner' => $awayScore > $homeScore,
                        ]
                    );
                }

                $import->increment('records_processed');
                $import->increment('records_created');
            } catch (\Throwable $e) {
                $import->increment('records_failed');
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $import->update(['status' => 'completed', 'completed_at' => now()]);

        $this->info("Done. Imported {$import->records_created} matches, {$import->records_failed} failed.");
        $this->info("Total matches in DB: " . RugbyMatch::count());

        return self::SUCCESS;
    }
}
