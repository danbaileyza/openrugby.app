<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\DataImport;
use App\Models\MatchTeam;
use App\Models\Player;
use App\Models\PlayerMatchStat;
use App\Models\RugbyMatch;
use App\Models\Season;
use App\Models\Team;
use App\Models\Venue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Imports data from rugbypy JSON exports.
 *
 * Workflow:
 *   1. Run: pip3 install rugbypy
 *   2. Run: python3 scripts/rugbypy_export.py
 *   3. Run: php artisan rugby:import-rugbypy
 */
class ImportRugbypyCommand extends Command
{
    protected $signature = 'rugby:import-rugbypy
                            {--players : Import players only}
                            {--matches : Import matches only}
                            {--stats : Import player stats only}
                            {--path= : Custom path to JSON files}';

    protected $description = 'Import rugby data from rugbypy JSON exports';

    private string $basePath;

    /** Cache of competition_id => Competition model to avoid repeated lookups. */
    private array $competitionCache = [];

    /** Cache of team external_id => Team model. */
    private array $teamCache = [];

    public function handle(): int
    {
        $this->basePath = $this->option('path')
            ?? storage_path('app/rugbypy');

        if (! is_dir($this->basePath)) {
            $this->error("No rugbypy data found at {$this->basePath}");
            $this->info('Run: python3 scripts/rugbypy_export.py');
            return self::FAILURE;
        }

        $this->info("Reading from: {$this->basePath}");
        $this->listFiles();
        $this->newLine();

        $importAll = ! $this->option('players') && ! $this->option('matches') && ! $this->option('stats');

        if ($importAll || $this->option('players')) {
            $this->importPlayers();
        }

        if ($importAll || $this->option('matches')) {
            $this->importMatches();
            if (file_exists("{$this->basePath}/match_details.json")) {
                $this->importMatchDetails();
            }
        }

        if ($importAll || $this->option('stats')) {
            $this->importPlayerStats();
        }

        $this->newLine();
        $this->info('── Import Summary ──');
        $this->info('  Competitions: ' . Competition::where('external_source', 'rugbypy')->count());
        $this->info('  Teams:        ' . Team::where('external_source', 'rugbypy')->count());
        $this->info('  Players:      ' . Player::where('external_source', 'rugbypy')->count());
        $this->info('  Matches:      ' . RugbyMatch::where('external_source', 'rugbypy')->count());

        return self::SUCCESS;
    }

    private function listFiles(): void
    {
        foreach (['players.json', 'matches.json', 'match_details.json', 'player_stats.json'] as $file) {
            $path = "{$this->basePath}/{$file}";
            if (file_exists($path)) {
                $size = round(filesize($path) / 1024 / 1024, 1);
                $this->info("  Found: {$file} ({$size} MB)");
            }
        }
    }

    // ─── Players ─────────────────────────────────────────────

    private function importPlayers(): void
    {
        $path = "{$this->basePath}/players.json";
        if (! file_exists($path)) {
            $this->warn('No players.json found — skipping.');
            return;
        }

        $import = $this->startImport('players');
        $players = json_decode(file_get_contents($path), true);
        $this->info("Importing {$this->countItems($players)} players...");

        $bar = $this->output->createProgressBar(count($players));
        $created = 0;

        foreach ($players as $raw) {
            try {
                $this->upsertPlayer($raw);
                $created++;
                $import->increment('records_processed');
            } catch (\Throwable $e) {
                $import->increment('records_failed');
                Log::warning('rugbypy player import failed', [
                    'error' => $e->getMessage(),
                    'data' => array_slice($raw, 0, 5),
                ]);
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("  → {$created} players upserted");
        $this->completeImport($import);
    }

    private function upsertPlayer(array $raw): void
    {
        $playerId = $raw['player_id'] ?? $raw['id'] ?? null;
        $name = $raw['player_name'] ?? $raw['name'] ?? 'Unknown';
        $nameParts = explode(' ', $name, 2);

        Player::updateOrCreate(
            ['external_id' => (string) $playerId, 'external_source' => 'rugbypy'],
            [
                'first_name'     => $nameParts[0],
                'last_name'      => $nameParts[1] ?? '',
                'nationality'    => $raw['nationality'] ?? $raw['country'] ?? null,
                'position'       => $raw['position'] ?? 'unknown',
                'position_group' => $this->mapPositionGroup($raw['position'] ?? ''),
                'height_cm'      => $raw['height'] ?? null,
                'weight_kg'      => $raw['weight'] ?? null,
                'is_active'      => true,
            ]
        );
    }

    // ─── Matches ─────────────────────────────────────────────

    private function importMatches(): void
    {
        $path = "{$this->basePath}/matches.json";
        if (! file_exists($path)) {
            $this->warn('No matches.json found — skipping.');
            return;
        }

        $import = $this->startImport('matches');
        $matches = json_decode(file_get_contents($path), true);
        $this->info("Importing {$this->countItems($matches)} matches...");

        if (! empty($matches)) {
            $this->info("  Sample columns: " . implode(', ', array_keys($matches[0])));
        }

        $bar = $this->output->createProgressBar(count($matches));
        $created = 0;

        foreach ($matches as $raw) {
            try {
                $this->upsertMatch($raw);
                $created++;
                $import->increment('records_processed');
            } catch (\Throwable $e) {
                $import->increment('records_failed');
                Log::warning('rugbypy match import failed', [
                    'error' => $e->getMessage(),
                    'data'  => array_slice($raw, 0, 8),
                ]);
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("  → {$created} matches upserted");
        $this->completeImport($import);
    }

    private function upsertMatch(array $raw): void
    {
        $matchId = $raw['match_id'] ?? null;
        if (! $matchId) return;

        // Resolve or create competition + season
        $competitionId = $raw['competition_id'] ?? $raw['comp_id'] ?? null;
        $competitionName = $raw['competition'] ?? $raw['comp_name'] ?? 'Unknown Competition';
        $season = null;

        if ($competitionId) {
            $competition = $this->findOrCreateCompetition($competitionId, $competitionName);
            $season = $this->findOrCreateSeason($competition, $raw);
        }

        // Resolve or create teams
        $homeTeamId = $raw['home_team_id'] ?? null;
        $awayTeamId = $raw['away_team_id'] ?? null;
        $homeTeamName = $raw['home_team'] ?? $raw['home_team_name'] ?? 'Unknown';
        $awayTeamName = $raw['away_team'] ?? $raw['away_team_name'] ?? 'Unknown';

        $homeTeam = $homeTeamId ? $this->findOrCreateTeam($homeTeamId, $homeTeamName) : null;
        $awayTeam = $awayTeamId ? $this->findOrCreateTeam($awayTeamId, $awayTeamName) : null;

        // Resolve venue
        $venueId = null;
        $venueName = $raw['venue'] ?? $raw['venue_name'] ?? null;
        if ($venueName) {
            $venue = Venue::firstOrCreate(
                ['name' => $venueName, 'external_source' => 'rugbypy'],
                [
                    'city'        => $raw['city_played'] ?? $raw['city'] ?? null,
                    'country'     => $raw['country'] ?? null,
                    'external_id' => $raw['venue_id'] ?? $venueName,
                ]
            );
            $venueId = $venue->id;
        }

        // Parse kickoff date
        $kickoff = $raw['date'] ?? $raw['kickoff'] ?? $raw['match_date'] ?? null;

        // Create/update the match
        $match = RugbyMatch::updateOrCreate(
            ['external_id' => (string) $matchId, 'external_source' => 'rugbypy'],
            [
                'season_id'  => $season?->id,
                'venue_id'   => $venueId,
                'kickoff'    => $kickoff,
                'status'     => 'ft', // rugbypy only returns completed matches
                'round'      => $raw['round'] ?? $raw['game_week'] ?? null,
                'stage'      => $raw['stage'] ?? null,
                'attendance' => $raw['attendance'] ?? null,
            ]
        );

        // Create match_teams with scores
        $homeScore = $raw['home_score'] ?? $raw['home_points'] ?? null;
        $awayScore = $raw['away_score'] ?? $raw['away_points'] ?? null;

        if ($homeTeam) {
            MatchTeam::updateOrCreate(
                ['match_id' => $match->id, 'side' => 'home'],
                [
                    'team_id'   => $homeTeam->id,
                    'score'     => $homeScore,
                    'is_winner' => ($homeScore !== null && $awayScore !== null) ? $homeScore > $awayScore : null,
                ]
            );

            // Link team to season
            if ($season) {
                $homeTeam->seasons()->syncWithoutDetaching([$season->id]);
            }
        }

        if ($awayTeam) {
            MatchTeam::updateOrCreate(
                ['match_id' => $match->id, 'side' => 'away'],
                [
                    'team_id'   => $awayTeam->id,
                    'score'     => $awayScore,
                    'is_winner' => ($homeScore !== null && $awayScore !== null) ? $awayScore > $homeScore : null,
                ]
            );

            if ($season) {
                $awayTeam->seasons()->syncWithoutDetaching([$season->id]);
            }
        }
    }

    // ─── Match Details ───────────────────────────────────────

    private function importMatchDetails(): void
    {
        $path = "{$this->basePath}/match_details.json";
        if (! file_exists($path)) {
            return;
        }

        $import = $this->startImport('match_details');
        $details = json_decode(file_get_contents($path), true);
        $this->info("Importing {$this->countItems($details)} match detail records...");

        $bar = $this->output->createProgressBar(count($details));
        $updated = 0;

        foreach ($details as $raw) {
            try {
                $matchId = $raw['match_id'] ?? null;
                if (! $matchId) {
                    $bar->advance();
                    continue;
                }

                // Find the match we already created
                $match = RugbyMatch::where('external_id', (string) $matchId)
                    ->where('external_source', 'rugbypy')
                    ->first();

                if ($match) {
                    // Update with additional detail fields
                    $match->update(array_filter([
                        'attendance' => $raw['attendance'] ?? $match->attendance,
                    ]));
                    $updated++;
                }

                $import->increment('records_processed');
            } catch (\Throwable $e) {
                $import->increment('records_failed');
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("  → {$updated} matches enriched with details");
        $this->completeImport($import);
    }

    // ─── Player Stats ────────────────────────────────────────

    private function importPlayerStats(): void
    {
        $path = "{$this->basePath}/player_stats.json";
        if (! file_exists($path)) {
            $this->warn('No player_stats.json found — skipping.');
            return;
        }

        $import = $this->startImport('player_stats');
        $stats = json_decode(file_get_contents($path), true);
        $this->info("Importing {$this->countItems($stats)} player stat records...");

        $bar = $this->output->createProgressBar(count($stats));
        $created = 0;

        $skipKeys = ['player_id', 'match_id', 'date', 'team', 'team_id', 'opposition',
                     'opposition_id', 'competition', 'competition_id', 'season',
                     'venue', 'venue_id', 'city_played', 'country', 'result'];

        foreach ($stats as $raw) {
            try {
                $playerId = $raw['player_id'] ?? null;
                $matchExtId = $raw['match_id'] ?? null;

                if (! $playerId) {
                    $bar->advance();
                    continue;
                }

                $player = Player::where('external_id', (string) $playerId)
                    ->where('external_source', 'rugbypy')
                    ->first();

                $match = $matchExtId
                    ? RugbyMatch::where('external_id', (string) $matchExtId)
                        ->where('external_source', 'rugbypy')
                        ->first()
                    : null;

                if (! $player || ! $match) {
                    $bar->advance();
                    continue;
                }

                foreach ($raw as $key => $value) {
                    if (in_array($key, $skipKeys) || $value === null) continue;
                    if (! is_numeric($value)) continue;

                    PlayerMatchStat::updateOrCreate(
                        [
                            'match_id'  => $match->id,
                            'player_id' => $player->id,
                            'stat_key'  => $key,
                        ],
                        ['stat_value' => $value]
                    );
                    $created++;
                }

                $import->increment('records_processed');
            } catch (\Throwable $e) {
                $import->increment('records_failed');
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("  → {$created} stat entries created");
        $this->completeImport($import);
    }

    // ─── Helpers ─────────────────────────────────────────────

    private function findOrCreateCompetition(string $externalId, string $name): Competition
    {
        if (isset($this->competitionCache[$externalId])) {
            return $this->competitionCache[$externalId];
        }

        $competition = Competition::where('external_id', $externalId)
            ->where('external_source', 'rugbypy')
            ->first();

        if (! $competition) {
            $competition = Competition::where('code', Competition::canonicalCodeFromName($name))->first();
        }

        if (! $competition) {
            $competition = Competition::create([
                'external_id' => $externalId,
                'external_source' => 'rugbypy',
                'name'   => $name,
                'code'   => Competition::canonicalCodeFromName($name),
                'format' => 'union',
            ]);
        }

        $this->competitionCache[$externalId] = $competition;
        return $competition;
    }

    private function findOrCreateSeason(Competition $competition, array $raw): Season
    {
        // Determine season year from the match date or explicit season field
        $seasonLabel = $raw['season'] ?? null;
        if (! $seasonLabel && isset($raw['date'])) {
            $seasonLabel = substr($raw['date'], 0, 4); // YYYY from date
        }
        $seasonLabel = $seasonLabel ?: (string) date('Y');

        return Season::firstOrCreate(
            [
                'competition_id' => $competition->id,
                'label'          => (string) $seasonLabel,
            ],
            [
                'start_date'      => "{$seasonLabel}-01-01",
                'end_date'        => "{$seasonLabel}-12-31",
                'is_current'      => ((int) $seasonLabel >= (int) date('Y')),
                'external_id'     => $seasonLabel,
                'external_source' => 'rugbypy',
            ]
        );
    }

    private function findOrCreateTeam(string $externalId, string $name): Team
    {
        if (isset($this->teamCache[$externalId])) {
            return $this->teamCache[$externalId];
        }

        $team = Team::firstOrCreate(
            ['external_id' => $externalId, 'external_source' => 'rugbypy'],
            [
                'name'    => $name,
                'country' => 'Unknown',
                'type'    => 'club',
            ]
        );

        $this->teamCache[$externalId] = $team;
        return $team;
    }

    private function mapPositionGroup(string $position): ?string
    {
        $position = strtolower($position);

        return match (true) {
            str_contains($position, 'prop') || str_contains($position, 'hooker') => 'front_row',
            str_contains($position, 'lock') => 'second_row',
            str_contains($position, 'flanker') || str_contains($position, 'number 8') || str_contains($position, 'no.8') => 'back_row',
            str_contains($position, 'scrum') || str_contains($position, 'halfback') || str_contains($position, 'fly') || str_contains($position, 'half') => 'halfback',
            str_contains($position, 'centre') || str_contains($position, 'center') => 'centre',
            str_contains($position, 'wing') || str_contains($position, 'fullback') || str_contains($position, 'full back') => 'back_three',
            default => null,
        };
    }

    private function countItems($data): int
    {
        return is_array($data) ? count($data) : 0;
    }

    private function startImport(string $entityType): DataImport
    {
        return DataImport::create([
            'source'      => 'rugbypy',
            'entity_type' => $entityType,
            'status'      => 'running',
            'started_at'  => now(),
        ]);
    }

    private function completeImport(DataImport $import): void
    {
        $import->update([
            'status'       => 'completed',
            'completed_at' => now(),
        ]);
    }
}
