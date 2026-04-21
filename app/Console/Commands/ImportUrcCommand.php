<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\MatchTeam;
use App\Models\Player;
use App\Models\PlayerContract;
use App\Models\RugbyMatch;
use App\Models\Season;
use App\Models\Team;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Imports URC-scraped data (squads, matches, standings).
 *
 * Workflow:
 *   1. Run: python3 scripts/urc_scraper.py --squads
 *   2. Run: php artisan rugby:import-urc
 */
class ImportUrcCommand extends Command
{
    use Concerns\ResolvesMatches;
    protected $signature = 'rugby:import-urc
                            {--squads : Import squad/player data only}
                            {--matches= : Import matches for a season ID, e.g. 202501}
                            {--path= : Custom path to URC JSON files}
                            {--dry-run : Preview without writing}';

    protected $description = 'Import URC data with player nationality, position, team linkage';

    private string $basePath;
    private array $teamCache = [];
    private bool $dryRun = false;

    private int $playersCreated = 0;
    private int $playersEnriched = 0;
    private int $contractsCreated = 0;
    private int $matchesCreated = 0;

    public function handle(): int
    {
        $this->basePath = $this->option('path') ?? storage_path('app/urc');
        $this->dryRun = (bool) $this->option('dry-run');

        if (! is_dir($this->basePath)) {
            $this->error("URC data directory not found: {$this->basePath}");
            $this->line('Run: python3 scripts/urc_scraper.py --squads');
            return 1;
        }

        if ($this->dryRun) {
            $this->info('DRY RUN mode.');
        }

        $importAll = ! $this->option('squads') && ! $this->option('matches');

        if ($importAll || $this->option('squads')) {
            $this->importSquads();
        }

        if ($this->option('matches')) {
            $this->importMatches($this->option('matches'));
        }

        $this->newLine();
        $this->info('=== URC Import Summary ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Players created', $this->playersCreated],
                ['Players enriched', $this->playersEnriched],
                ['Contracts created', $this->contractsCreated],
                ['Matches created', $this->matchesCreated],
            ]
        );

        return 0;
    }

    private function importSquads(): void
    {
        $path = $this->basePath . '/squads.json';
        if (! file_exists($path)) {
            $this->warn('No squads.json found. Run: python3 scripts/urc_scraper.py --squads');
            return;
        }

        $players = json_decode(file_get_contents($path), true);
        if (! $players) {
            $this->warn('squads.json is empty or invalid.');
            return;
        }

        $count = count($players);
        $this->info("Importing {$count} URC squad players...");
        $bar = $this->output->createProgressBar($count);

        foreach ($players as $data) {
            $this->processSquadPlayer($data);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    private function processSquadPlayer(array $data): void
    {
        $firstName = trim($data['first_name'] ?? '');
        $lastName = trim($data['last_name'] ?? '');
        $urcId = $data['urc_id'] ?? null;

        if (! $firstName && ! $lastName) {
            return;
        }

        // Try to find existing player
        $player = null;

        // 1. Match by URC external ID
        if ($urcId) {
            $player = Player::where('external_source', 'urc')
                ->where('external_id', (string) $urcId)
                ->first();
        }

        // 2. Match by name
        if (! $player && $firstName && $lastName) {
            $player = Player::where('first_name', $firstName)
                ->where('last_name', $lastName)
                ->first();
        }

        // 3. Fuzzy match on last name
        if (! $player && $lastName) {
            $candidates = Player::where('last_name', $lastName)->get();
            foreach ($candidates as $candidate) {
                if ($this->namesSimilar($candidate->first_name, $firstName)) {
                    $player = $candidate;
                    break;
                }
            }
        }

        // Map position to position_group
        $position = $data['position'] ?? '';
        $positionGroup = $this->classifyPosition($position);

        // Parse height
        $heightCm = $data['height_cm'] ?? null;
        if (! $heightCm && ! empty($data['height'])) {
            $heightCm = $this->parseHeight($data['height']);
        }

        // Parse weight
        $weightKg = null;
        if (! empty($data['weight'])) {
            $weightKg = $this->parseWeight($data['weight']);
        }

        // Parse DOB
        $dob = $this->parseDate($data['date_of_birth'] ?? '');

        // Nationality
        $nationality = $data['nationality'] ?? '';
        if (empty($nationality) && ! empty($data['national_team'])) {
            $nationality = $data['national_team'];
        }

        if ($player) {
            $this->enrichPlayer($player, [
                'nationality' => $nationality,
                'position' => $position,
                'position_group' => $positionGroup,
                'height_cm' => $heightCm,
                'weight_kg' => $weightKg,
                'dob' => $dob,
                'photo_url' => $data['photo_url'] ?? null,
                'external_id' => $urcId ? (string) $urcId : null,
                'external_source' => 'urc',
            ]);
        } else {
            if ($this->dryRun) {
                $this->playersCreated++;
                return;
            }

            $player = Player::create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'nationality' => $nationality,
                'dob' => $dob,
                'position' => $position,
                'position_group' => $positionGroup,
                'height_cm' => $heightCm,
                'weight_kg' => $weightKg,
                'photo_url' => $data['photo_url'] ?? null,
                'is_active' => true,
                'external_id' => $urcId ? (string) $urcId : null,
                'external_source' => 'urc',
            ]);
            $this->playersCreated++;
        }

        // Link to team
        $this->linkPlayerToTeam($player, $data);
    }

    private function enrichPlayer(Player $player, array $fields): void
    {
        $updated = false;

        foreach ($fields as $key => $value) {
            if ($key === 'external_source') {
                continue;
            }
            if (empty($player->$key) && ! empty($value)) {
                if (! $this->dryRun) {
                    $player->$key = $value;
                }
                $updated = true;
            }
        }

        // Always link URC ID if not set
        if (empty($player->external_id) && ! empty($fields['external_id'])) {
            if (! $this->dryRun) {
                $player->external_id = $fields['external_id'];
                $player->external_source = 'urc';
            }
            $updated = true;
        }

        if ($updated) {
            if (! $this->dryRun) {
                $player->save();
            }
            $this->playersEnriched++;
        }
    }

    private function linkPlayerToTeam(Player $player, array $data): void
    {
        $clubCode = $data['club_code'] ?? '';
        $clubName = $data['club_name'] ?? '';

        if (! $clubCode && ! $clubName) {
            return;
        }

        $team = $this->resolveTeam($clubCode, $clubName);
        if (! $team) {
            return;
        }

        // Check existing contract
        $exists = PlayerContract::where('player_id', $player->id)
            ->where('team_id', $team->id)
            ->where('is_current', true)
            ->exists();

        if ($exists) {
            return;
        }

        if ($this->dryRun) {
            $this->contractsCreated++;
            return;
        }

        // Mark old contracts as not current
        PlayerContract::where('player_id', $player->id)
            ->where('is_current', true)
            ->update(['is_current' => false]);

        PlayerContract::create([
            'player_id' => $player->id,
            'team_id' => $team->id,
            'from_date' => now(),
            'is_current' => true,
        ]);

        $this->contractsCreated++;
    }

    /**
     * URC canonical team names — the official short names used by URC.
     * Maps URC API name → preferred DB name.
     */
    private static array $urcCanonicalNames = [
        'Benetton Rugby' => 'Benetton',
        'Cardiff Rugby' => 'Cardiff Rugby',
        'Connacht Rugby' => 'Connacht',
        'Dragons RFC' => 'Dragons',
        'Edinburgh Rugby' => 'Edinburgh',
        'Glasgow Warriors' => 'Glasgow Warriors',
        'Leinster Rugby' => 'Leinster',
        'Lions' => 'Lions',
        'Munster Rugby' => 'Munster',
        'Ospreys' => 'Ospreys',
        'Scarlets' => 'Scarlets',
        'Sharks' => 'Sharks',
        'Stormers' => 'Stormers',
        'Bulls' => 'Bulls',
        'Ulster Rugby' => 'Ulster',
        'Zebre Parma' => 'Zebre',
    ];

    private function resolveTeam(string $clubCode, string $clubName): ?Team
    {
        $cacheKey = $clubCode ?: $clubName;
        if (isset($this->teamCache[$cacheKey])) {
            return $this->teamCache[$cacheKey];
        }

        // Determine the canonical name for this URC team
        $canonicalName = self::$urcCanonicalNames[$clubName] ?? null;

        // Try short name match
        if ($clubCode) {
            $team = Team::where('short_name', $clubCode)->first();
            if ($team) {
                $this->normalizeTeamName($team, $canonicalName);
                $this->teamCache[$cacheKey] = $team;
                return $team;
            }
        }

        // Try name match
        if ($clubName) {
            // Exact match on canonical name first
            $team = null;
            if ($canonicalName) {
                $team = Team::where('name', $canonicalName)->first();
            }

            // Exact match on source name
            if (! $team) {
                $team = Team::where('name', $clubName)->first();
            }

            // DB name contains input — prefer shortest match
            if (! $team) {
                $team = Team::where('name', 'like', "%{$clubName}%")
                    ->orderByRaw('LENGTH(name) ASC')
                    ->first();
            }

            // Input contains DB name (e.g. "Connacht Rugby" contains DB "Connacht")
            if (! $team) {
                $team = Team::whereRaw('LOWER(?) LIKE CONCAT("%", LOWER(name), "%")', [$clubName])
                    ->where('name', '!=', '')
                    ->whereRaw('LENGTH(name) >= 3')
                    ->orderByRaw('LENGTH(name) DESC')
                    ->first();
            }

            // Strip trailing " Rugby"/" RFC" suffix and try again
            if (! $team && preg_match('/^(.+)\s+(Rugby|RFC)$/i', $clubName, $m)) {
                $baseName = trim($m[1]);
                $team = Team::where('name', $baseName)->first();
                if (! $team) {
                    $team = Team::where('name', 'like', "%{$baseName}%")
                        ->orderByRaw('LENGTH(name) ASC')
                        ->first();
                }
            }

            // Strip sponsor prefix (e.g. "Vodacom Bulls" → "Bulls")
            if (! $team) {
                $coreName = preg_replace(
                    '/^(vodacom|dhl|hollywoodbets|fidelity\s+securedrive|toyota|cell\s+c)\s+/i',
                    '',
                    trim($clubName)
                );
                if ($coreName !== $clubName) {
                    $team = Team::where('name', $coreName)->first();
                    if (! $team) {
                        $team = Team::where('name', 'like', "%{$coreName}%")
                            ->orderByRaw('LENGTH(name) ASC')
                            ->first();
                    }
                }
            }

            if ($team) {
                $this->normalizeTeamName($team, $canonicalName);
                $this->teamCache[$cacheKey] = $team;
                return $team;
            }
        }

        return null;
    }

    /**
     * Rename a team to the URC canonical name if it doesn't match.
     * URC is authoritative for URC team names.
     */
    private function normalizeTeamName(Team $team, ?string $canonicalName): void
    {
        if (! $canonicalName || $this->dryRun) {
            return;
        }

        if ($team->name !== $canonicalName) {
            $team->update(['name' => $canonicalName]);
        }
    }

    private function importMatches(string $seasonId): void
    {
        $path = $this->basePath . "/season_{$seasonId}_matches.json";
        if (! file_exists($path)) {
            $this->warn("No season_{$seasonId}_matches.json found.");
            $this->line("Run: python3 scripts/urc_scraper.py --season {$seasonId}");
            return;
        }

        $matches = json_decode(file_get_contents($path), true);
        if (! $matches) {
            $this->warn('Matches file is empty or invalid.');
            return;
        }

        // Find or create the URC competition
        $competition = Competition::firstOrCreate(
            ['code' => 'urc'],
            [
                'name' => 'United Rugby Championship',
                'format' => 'union',
                'country' => 'International',
                'tier' => 'tier_1',
            ]
        );

        $seasonLabel = $this->seasonIdToLabel($seasonId);
        $startYear = (int) substr($seasonId, 0, 4);
        $season = Season::firstOrCreate(
            ['competition_id' => $competition->id, 'label' => $seasonLabel],
            [
                'start_date' => Carbon::create($startYear, 9, 1),   // September
                'end_date' => Carbon::create($startYear + 1, 6, 30), // June next year
                'is_current' => $seasonId === '202501',
            ]
        );

        // Fix dates if season already exists with wrong values
        if ($season->start_date && $season->start_date->month === 1) {
            $season->update([
                'start_date' => Carbon::create($startYear, 9, 1),
                'end_date' => Carbon::create($startYear + 1, 6, 30),
            ]);
        }

        $completed = array_filter($matches, fn ($m) => ($m['match_status'] ?? '') === 'result');
        $count = count($completed);
        $this->info("Importing {$count} completed URC matches for {$seasonLabel}...");
        $bar = $this->output->createProgressBar($count);

        foreach ($completed as $match) {
            $this->processMatch($match, $season);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    private function processMatch(array $data, Season $season): void
    {
        $statsData = $data['stats_data'] ?? [];
        $matchId = $statsData['id'] ?? $data['match_id'] ?? null;

        if (! $matchId) {
            return;
        }

        $homeTeamName = $statsData['homeTeam']['team']['name'] ?? 'Unknown';
        $awayTeamName = $statsData['awayTeam']['team']['name'] ?? 'Unknown';
        $homeScore = $statsData['homeTeam']['score']['finalScore'] ?? 0;
        $awayScore = $statsData['awayTeam']['score']['finalScore'] ?? 0;
        $venueName = $statsData['venue']['name'] ?? null;
        $dateTime = $statsData['dateTime'] ?? $data['match_datetime'] ?? null;

        // Check if match already exists (same source)
        $existing = RugbyMatch::where('external_source', 'urc')
            ->where('external_id', (string) $matchId)
            ->first();

        if ($existing) {
            // Re-resolve teams in case they were linked incorrectly before
            if (! $this->dryRun) {
                $existing->update([
                    'season_id' => $season->id,
                    'round' => $statsData['round'] ?? $existing->round,
                ]);

                $homeTeam = $this->resolveTeam('', $homeTeamName);
                $awayTeam = $this->resolveTeam('', $awayTeamName);

                if ($homeTeam) {
                    $this->upsertMatchTeam($existing, 'home', $homeTeam->id, $homeScore, $homeScore > $awayScore);
                }
                if ($awayTeam) {
                    $this->upsertMatchTeam($existing, 'away', $awayTeam->id, $awayScore, $awayScore > $homeScore);
                }
            }
            return;
        }

        // Cross-source dedup: check if same match exists from another source
        if ($dateTime) {
            try {
                $kickoff = Carbon::parse($dateTime);
                $crossMatch = $this->findExistingMatchByTeamsAndDate($kickoff, $homeTeamName, $awayTeamName);
                if ($crossMatch) {
                    if (! $this->dryRun) {
                        // Update match to URC source and move to URC season
                        $crossMatch->update([
                            'external_id' => (string) $matchId,
                            'external_source' => 'urc',
                            'season_id' => $season->id,
                            'round' => $statsData['round'] ?? $crossMatch->round,
                        ]);

                        // Update team associations to use correctly resolved teams
                        $homeTeam = $this->resolveTeam('', $homeTeamName);
                        $awayTeam = $this->resolveTeam('', $awayTeamName);

                        if ($homeTeam) {
                            $this->upsertMatchTeam($crossMatch, 'home', $homeTeam->id, $homeScore, $homeScore > $awayScore);
                        }

                        if ($awayTeam) {
                            $this->upsertMatchTeam($crossMatch, 'away', $awayTeam->id, $awayScore, $awayScore > $homeScore);
                        }
                    }
                    return;
                }
            } catch (\Exception $e) {
                // Ignore parse errors, proceed with creation
            }
        }

        if ($this->dryRun) {
            $this->matchesCreated++;
            return;
        }

        // Resolve venue
        $venue = null;
        if ($venueName) {
            $venue = Venue::firstOrCreate(
                ['name' => $venueName],
                [
                    'city' => '',
                    'country' => '',
                ]
            );
        }

        // Create the match
        $match = RugbyMatch::create([
            'season_id' => $season->id,
            'venue_id' => $venue?->id,
            'round' => $statsData['round'] ?? null,
            'kickoff' => $dateTime ? Carbon::parse($dateTime) : null,
            'status' => 'ft',
            'external_id' => (string) $matchId,
            'external_source' => 'urc',
        ]);

        // Link teams
        $homeTeam = $this->resolveTeam('', $homeTeamName);
        $awayTeam = $this->resolveTeam('', $awayTeamName);

        if ($homeTeam) {
            $this->upsertMatchTeam($match, 'home', $homeTeam->id, $homeScore, $homeScore > $awayScore);
        }

        if ($awayTeam) {
            $this->upsertMatchTeam($match, 'away', $awayTeam->id, $awayScore, $awayScore > $homeScore);
        }

        $this->matchesCreated++;
    }

    private function upsertMatchTeam(RugbyMatch $match, string $side, string $teamId, int $score, bool $isWinner): void
    {
        MatchTeam::updateOrCreate(
            [
                'match_id' => $match->id,
                'side' => $side,
            ],
            [
                'team_id' => $teamId,
                'score' => $score,
                'is_winner' => $isWinner,
            ]
        );
    }

    private function namesSimilar(string $a, string $b): bool
    {
        $a = Str::lower(trim($a));
        $b = Str::lower(trim($b));
        if ($a === $b) return true;
        if (Str::startsWith($a, $b) || Str::startsWith($b, $a)) return true;
        if (levenshtein($a, $b) <= 2) return true;
        return false;
    }

    private function classifyPosition(string $position): string
    {
        $pos = strtolower($position);

        $forwards = ['prop', 'hooker', 'lock', 'flanker', 'number eight', 'no.8',
            'loosehead', 'tighthead', 'second row', 'back row'];
        $backs = ['scrum half', 'fly half', 'centre', 'wing', 'full back',
            'halfback', 'first five', 'stand-off'];

        foreach ($forwards as $f) {
            if (str_contains($pos, $f)) return 'Forward';
        }
        foreach ($backs as $b) {
            if (str_contains($pos, $b)) return 'Back';
        }
        return '';
    }

    private function parseHeight(?string $height): ?int
    {
        if (empty($height)) return null;
        // Handle "1.85m" or "185cm" or "6'1""
        if (preg_match('/(\d+)cm/', $height, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/(\d+\.\d+)m/', $height, $m)) {
            return (int) round((float) $m[1] * 100);
        }
        if (is_numeric($height) && (int) $height > 100) {
            return (int) $height;
        }
        return null;
    }

    private function parseWeight(?string $weight): ?int
    {
        if (empty($weight)) return null;
        if (preg_match('/(\d+)/', $weight, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function parseDate(?string $date): ?string
    {
        if (empty($date)) return null;
        try {
            return Carbon::parse($date)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    private function seasonIdToLabel(string $seasonId): string
    {
        $year = (int) substr($seasonId, 0, 4);
        return $year . '-' . substr((string) ($year + 1), 2);
    }
}
