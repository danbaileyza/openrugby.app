<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Models\PlayerContract;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Imports ESPN-scraped data to enrich players with nationality, position,
 * height, weight, and team linkage.
 *
 * Workflow:
 *   1. Run: python3 scripts/espn_scraper.py
 *   2. Run: php artisan rugby:import-espn
 */
class ImportEspnCommand extends Command
{
    protected $signature = 'rugby:import-espn
                            {--teams : Import teams only}
                            {--players : Import players only}
                            {--enrich : Only enrich existing players, no new creates}
                            {--path= : Custom path to ESPN JSON files}
                            {--dry-run : Preview without writing}';

    protected $description = 'Import ESPN rugby data (teams + players with nationality, position, team linkage)';

    private string $basePath;
    private array $teamCache = [];
    private int $teamsCreated = 0;
    private int $teamsUpdated = 0;
    private int $playersCreated = 0;
    private int $playersUpdated = 0;
    private int $playersEnriched = 0;
    private int $contractsCreated = 0;
    private bool $dryRun = false;

    public function handle(): int
    {
        $this->basePath = $this->option('path')
            ?? storage_path('app/espn');

        $this->dryRun = (bool) $this->option('dry-run');

        if ($this->dryRun) {
            $this->info('DRY RUN — no data will be written.');
        }

        if (! is_dir($this->basePath)) {
            $this->error("ESPN data directory not found: {$this->basePath}");
            $this->line('Run: python3 scripts/espn_scraper.py');
            return 1;
        }

        $importAll = ! $this->option('teams') && ! $this->option('players');

        if ($importAll || $this->option('teams')) {
            $this->importTeams();
        }

        if ($importAll || $this->option('players') || $this->option('enrich')) {
            $this->importPlayers();
        }

        $this->newLine();
        $this->info('═══ ESPN Import Summary ═══');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Teams created', $this->teamsCreated],
                ['Teams updated', $this->teamsUpdated],
                ['Players created', $this->playersCreated],
                ['Players updated/enriched', $this->playersEnriched],
                ['Contracts created', $this->contractsCreated],
            ]
        );

        return 0;
    }

    private function importTeams(): void
    {
        $path = $this->basePath . '/teams.json';
        if (! file_exists($path)) {
            $this->warn('No teams.json found — skipping teams.');
            return;
        }

        $teams = json_decode(file_get_contents($path), true);
        if (! $teams) {
            $this->warn('teams.json is empty or invalid.');
            return;
        }

        $count = count($teams);
        $this->info("Importing {$count} ESPN teams...");
        $bar = $this->output->createProgressBar($count);

        foreach ($teams as $data) {
            $this->processTeam($data);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    private function processTeam(array $data): void
    {
        $espnId = $data['espn_id'] ?? null;
        if (! $espnId) {
            return;
        }

        // Try to match existing team by ESPN external_id
        $team = Team::where('external_source', 'espn')
            ->where('external_id', $espnId)
            ->first();

        // Try fuzzy name match if no ESPN link yet
        if (! $team) {
            $team = $this->fuzzyMatchTeam($data['name'] ?? '', $data['short_name'] ?? '');
        }

        $attributes = array_filter([
            'logo_url' => $data['logo_url'] ?? null,
            'primary_color' => $data['color'] ? "#{$data['color']}" : null,
            'secondary_color' => $data['alt_color'] ? "#{$data['alt_color']}" : null,
            'short_name' => $data['short_name'] ?? null,
        ]);

        if ($team) {
            if ($this->dryRun) {
                $this->line("  Would update team: {$team->name} (ESPN {$espnId})");
                $this->teamsUpdated++;
                return;
            }

            // Link ESPN ID and fill missing fields
            $team->external_source = $team->external_source ?? 'espn';
            $team->external_id = $team->external_id ?? $espnId;

            foreach ($attributes as $key => $value) {
                if (empty($team->$key) && $value) {
                    $team->$key = $value;
                }
            }

            $team->save();
            $this->teamsUpdated++;
        } else {
            if ($this->dryRun) {
                $this->line("  Would create team: {$data['name']} (ESPN {$espnId})");
                $this->teamsCreated++;
                return;
            }

            $team = Team::create([
                'name' => $data['name'] ?? 'Unknown',
                'short_name' => $data['short_name'] ?? null,
                'country' => $this->guessCountryFromLeague($data['league_key'] ?? ''),
                'type' => $this->guessTeamType($data['league_key'] ?? ''),
                'logo_url' => $data['logo_url'] ?? null,
                'primary_color' => isset($data['color']) ? "#{$data['color']}" : null,
                'secondary_color' => isset($data['alt_color']) ? "#{$data['alt_color']}" : null,
                'external_id' => $espnId,
                'external_source' => 'espn',
            ]);
            $this->teamsCreated++;
        }

        // Cache for player import
        $this->teamCache[$espnId] = $team;
    }

    private function importPlayers(): void
    {
        $path = $this->basePath . '/players.json';
        if (! file_exists($path)) {
            $this->warn('No players.json found — skipping players.');
            return;
        }

        $players = json_decode(file_get_contents($path), true);
        if (! $players) {
            $this->warn('players.json is empty or invalid.');
            return;
        }

        $enrichOnly = (bool) $this->option('enrich');

        $count = count($players);
        $this->info(
            $enrichOnly
                ? "Enriching existing players from {$count} ESPN records..."
                : "Importing {$count} ESPN players..."
        );
        $bar = $this->output->createProgressBar($count);

        foreach ($players as $data) {
            $this->processPlayer($data, $enrichOnly);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    private function processPlayer(array $data, bool $enrichOnly): void
    {
        $espnId = $data['espn_id'] ?? null;
        $firstName = $data['first_name'] ?? '';
        $lastName = $data['last_name'] ?? '';

        if (! $firstName && ! $lastName) {
            return;
        }

        // Try to find existing player
        $player = null;

        // 1. Match by ESPN external_id
        if ($espnId) {
            $player = Player::where('external_source', 'espn')
                ->where('external_id', $espnId)
                ->first();
        }

        // 2. Match by name (first + last)
        if (! $player && $firstName && $lastName) {
            $player = Player::where('first_name', $firstName)
                ->where('last_name', $lastName)
                ->first();
        }

        // 3. Fuzzy match: try last name + similar first name
        if (! $player && $lastName) {
            $candidates = Player::where('last_name', $lastName)->get();
            foreach ($candidates as $candidate) {
                if ($this->namesSimilar($candidate->first_name, $firstName)) {
                    $player = $candidate;
                    break;
                }
            }
        }

        if ($player) {
            $this->enrichPlayer($player, $data);
            return;
        }

        // No existing player found
        if ($enrichOnly) {
            return;
        }

        if ($this->dryRun) {
            $this->line("  Would create: {$firstName} {$lastName}");
            $this->playersCreated++;
            return;
        }

        // Create new player
        $player = Player::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'nationality' => $data['nationality'] ?? null,
            'dob' => $this->parseDate($data['dob'] ?? ''),
            'position' => $data['position'] ?? null,
            'position_group' => $data['position_group'] ?? null,
            'height_cm' => $data['height_cm'] ?? null,
            'weight_kg' => $data['weight_kg'] ?? null,
            'photo_url' => $data['photo_url'] ?? null,
            'is_active' => true,
            'external_id' => $espnId,
            'external_source' => 'espn',
        ]);

        $this->playersCreated++;

        // Create contract linking player to team
        $this->linkPlayerToTeam($player, $data);
    }

    private function enrichPlayer(Player $player, array $data): void
    {
        $updated = false;

        // Fill in missing fields — never overwrite existing data
        $fields = [
            'nationality' => $data['nationality'] ?? null,
            'position' => $data['position'] ?? null,
            'position_group' => $data['position_group'] ?? null,
            'height_cm' => $data['height_cm'] ?? null,
            'weight_kg' => $data['weight_kg'] ?? null,
            'photo_url' => $data['photo_url'] ?? null,
            'dob' => $this->parseDate($data['dob'] ?? ''),
        ];

        foreach ($fields as $key => $value) {
            if (empty($player->$key) && ! empty($value)) {
                if ($this->dryRun) {
                    $this->line("  Would set {$key} on {$player->full_name}");
                } else {
                    $player->$key = $value;
                }
                $updated = true;
            }
        }

        // Link ESPN external ID if not set
        if (empty($player->external_id) && ! empty($data['espn_id'])) {
            if (! $this->dryRun) {
                $player->external_id = $data['espn_id'];
                $player->external_source = 'espn';
            }
            $updated = true;
        }

        if ($updated && ! $this->dryRun) {
            $player->save();
        }

        if ($updated) {
            $this->playersEnriched++;
        }

        // Try to create team linkage
        $this->linkPlayerToTeam($player, $data);
    }

    private function linkPlayerToTeam(Player $player, array $data): void
    {
        $teamEspnId = $data['team_espn_id'] ?? null;
        if (! $teamEspnId) {
            return;
        }

        // Find the team
        $team = $this->resolveTeam($teamEspnId, $data['team_name'] ?? '');
        if (! $team) {
            return;
        }

        // Check if contract already exists
        $existingContract = PlayerContract::where('player_id', $player->id)
            ->where('team_id', $team->id)
            ->where('is_current', true)
            ->exists();

        if ($existingContract) {
            return;
        }

        if ($this->dryRun) {
            $this->line("  Would link {$player->full_name} → {$team->name}");
            $this->contractsCreated++;
            return;
        }

        // Mark any existing current contracts as not current
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

    private function resolveTeam(string $espnId, string $teamName): ?Team
    {
        // Check cache
        if (isset($this->teamCache[$espnId])) {
            return $this->teamCache[$espnId];
        }

        // Check DB
        $team = Team::where('external_source', 'espn')
            ->where('external_id', $espnId)
            ->first();

        if (! $team && $teamName) {
            $team = $this->fuzzyMatchTeam($teamName, '');
        }

        if ($team) {
            $this->teamCache[$espnId] = $team;
        }

        return $team;
    }

    private function fuzzyMatchTeam(string $name, string $shortName): ?Team
    {
        if (empty($name)) {
            return null;
        }

        // Exact name match
        $team = Team::where('name', $name)->first();
        if ($team) {
            return $team;
        }

        // DB name contains input — prefer shortest match
        $team = Team::where('name', 'like', "%{$name}%")
            ->orderByRaw('LENGTH(name) ASC')
            ->first();
        if ($team) {
            return $team;
        }

        // Input contains DB name — prefer longest DB name (most specific)
        $team = Team::whereRaw('LOWER(?) LIKE CONCAT("%", LOWER(name), "%")', [$name])
            ->where('name', '!=', '')
            ->whereRaw('LENGTH(name) >= 3')
            ->orderByRaw('LENGTH(name) DESC')
            ->first();
        if ($team) {
            return $team;
        }

        // Strip trailing " Rugby" suffix
        if (preg_match('/^(.+)\s+Rugby$/i', $name, $m)) {
            $baseName = trim($m[1]);
            $team = Team::where('name', $baseName)->first();
            if (! $team) {
                $team = Team::where('name', 'like', "%{$baseName}%")
                    ->orderByRaw('LENGTH(name) ASC')
                    ->first();
            }
            if ($team) {
                return $team;
            }
        }

        // Strip sponsor prefix (e.g., "DHL Stormers" → "Stormers")
        $coreName = preg_replace(
            '/^(vodacom|dhl|hollywoodbets|fidelity\s+securedrive|toyota|cell\s+c)\s+/i',
            '',
            trim($name)
        );
        if ($coreName !== $name) {
            $team = Team::where('name', $coreName)->first();
            if (! $team) {
                $team = Team::where('name', 'like', "%{$coreName}%")
                    ->orderByRaw('LENGTH(name) ASC')
                    ->first();
            }
            if ($team) {
                return $team;
            }
        }

        // Short name match
        if ($shortName && strlen($shortName) >= 2) {
            $team = Team::where('short_name', $shortName)->first();
            if ($team) {
                return $team;
            }
        }

        return null;
    }

    private function namesSimilar(string $a, string $b): bool
    {
        if (empty($a) || empty($b)) {
            return false;
        }

        $a = Str::lower(trim($a));
        $b = Str::lower(trim($b));

        // Exact
        if ($a === $b) {
            return true;
        }

        // One is a prefix of the other (e.g., "Dan" vs "Daniel")
        if (Str::startsWith($a, $b) || Str::startsWith($b, $a)) {
            return true;
        }

        // Levenshtein distance
        if (levenshtein($a, $b) <= 2) {
            return true;
        }

        return false;
    }

    private function parseDate(?string $date): ?string
    {
        if (empty($date)) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($date)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    private function guessCountryFromLeague(string $leagueKey): string
    {
        return match ($leagueKey) {
            'urc' => 'International',
            'premiership' => 'England',
            'top14' => 'France',
            'super_rugby' => 'International',
            'currie_cup' => 'South Africa',
            'six_nations' => 'International',
            'rugby_championship' => 'International',
            'european_champions', 'challenge_cup' => 'International',
            'world_cup' => 'International',
            default => 'Unknown',
        };
    }

    private function guessTeamType(string $leagueKey): string
    {
        return match ($leagueKey) {
            'six_nations', 'rugby_championship', 'world_cup', 'autumn_internationals' => 'national',
            'currie_cup' => 'provincial',
            'super_rugby', 'urc' => 'franchise',
            default => 'club',
        };
    }
}
