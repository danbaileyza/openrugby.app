<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Models\PlayerContract;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Imports data from all.rugby scraper.
 *
 * Workflow:
 *   1. Run: python3 scripts/allrugby_scraper.py --squads urc
 *   2. Run: php artisan rugby:import-allrugby
 */
class ImportAllRugbyCommand extends Command
{
    protected $signature = 'rugby:import-allrugby
                            {--path= : Custom path to allrugby JSON files}
                            {--enrich : Only enrich existing players}
                            {--dry-run : Preview without writing}';

    protected $description = 'Import all.rugby data: players with nationality, height, weight, DOB, team linkage';

    private string $basePath;
    private array $teamCache = [];
    private bool $dryRun = false;

    private int $playersCreated = 0;
    private int $playersEnriched = 0;
    private int $contractsCreated = 0;
    private int $skipped = 0;

    public function handle(): int
    {
        $this->basePath = $this->option('path') ?? storage_path('app/allrugby');
        $this->dryRun = (bool) $this->option('dry-run');

        if (! is_dir($this->basePath)) {
            $this->error("Data directory not found: {$this->basePath}");
            $this->line('Run: python3 scripts/allrugby_scraper.py --squads urc');
            return 1;
        }

        if ($this->dryRun) {
            $this->info('DRY RUN mode.');
        }

        $this->importSquads();

        $this->newLine();
        $this->info('=== All.Rugby Import Summary ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Players created', $this->playersCreated],
                ['Players enriched', $this->playersEnriched],
                ['Contracts created', $this->contractsCreated],
                ['Skipped (no name)', $this->skipped],
            ]
        );

        return 0;
    }

    private function importSquads(): void
    {
        $path = $this->basePath . '/squads.json';
        if (! file_exists($path)) {
            $this->warn('No squads.json found.');
            $this->line('Run: python3 scripts/allrugby_scraper.py --squads urc');
            return;
        }

        $players = json_decode(file_get_contents($path), true);
        if (! $players) {
            $this->warn('squads.json is empty or invalid.');
            return;
        }

        $enrichOnly = (bool) $this->option('enrich');
        $count = count($players);
        $this->info("Processing {$count} players from all.rugby...");
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
        $firstName = trim($data['first_name'] ?? '');
        $lastName = trim($data['last_name'] ?? '');

        if (! $firstName && ! $lastName) {
            $this->skipped++;
            return;
        }

        // Find existing player
        $player = $this->findExistingPlayer($firstName, $lastName, $data['slug'] ?? '');

        // Map position
        $position = $data['position'] ?? '';
        $positionGroup = $this->classifyPosition($position);

        $fields = [
            'nationality' => $data['nationality'] ?? null,
            'position' => $position ?: null,
            'position_group' => $positionGroup ?: null,
            'height_cm' => $data['height_cm'] ?? null,
            'weight_kg' => $data['weight_kg'] ?? null,
            'dob' => $data['dob'] ?? null,
        ];

        if ($player) {
            $this->enrichPlayer($player, $fields, $data);
        } elseif (! $enrichOnly) {
            if ($this->dryRun) {
                $this->playersCreated++;
                return;
            }

            $player = Player::create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'nationality' => $fields['nationality'],
                'dob' => $fields['dob'],
                'position' => $fields['position'],
                'position_group' => $fields['position_group'],
                'height_cm' => $fields['height_cm'],
                'weight_kg' => $fields['weight_kg'],
                'is_active' => true,
                'external_id' => $data['slug'] ?? null,
                'external_source' => 'allrugby',
            ]);
            $this->playersCreated++;
            $this->linkPlayerToTeam($player, $data['club_slug'] ?? '');
        }
    }

    private function findExistingPlayer(string $firstName, string $lastName, string $slug): ?Player
    {
        // 1. By all.rugby slug
        if ($slug) {
            $player = Player::where('external_source', 'allrugby')
                ->where('external_id', $slug)
                ->first();
            if ($player) {
                return $player;
            }
        }

        // 2. Exact name match
        if ($firstName && $lastName) {
            $player = Player::where('first_name', $firstName)
                ->where('last_name', $lastName)
                ->first();
            if ($player) {
                return $player;
            }
        }

        // 3. Fuzzy last name match
        if ($lastName) {
            $candidates = Player::where('last_name', $lastName)->get();
            foreach ($candidates as $candidate) {
                if ($this->namesSimilar($candidate->first_name, $firstName)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function enrichPlayer(Player $player, array $fields, array $data): void
    {
        $updated = false;

        foreach ($fields as $key => $value) {
            if (empty($player->$key) && ! empty($value)) {
                if (! $this->dryRun) {
                    $player->$key = $value;
                }
                $updated = true;
            }
        }

        // Link all.rugby slug
        if (empty($player->external_id) && ! empty($data['slug'])) {
            if (! $this->dryRun) {
                $player->external_id = $data['slug'];
                $player->external_source = 'allrugby';
            }
            $updated = true;
        }

        if ($updated) {
            if (! $this->dryRun) {
                $player->save();
            }
            $this->playersEnriched++;
        }

        $this->linkPlayerToTeam($player, $data['club_slug'] ?? '');
    }

    private function linkPlayerToTeam(Player $player, string $clubSlug): void
    {
        if (! $clubSlug) {
            return;
        }

        $team = $this->resolveTeam($clubSlug);
        if (! $team) {
            return;
        }

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

    private function resolveTeam(string $clubSlug): ?Team
    {
        if (isset($this->teamCache[$clubSlug])) {
            return $this->teamCache[$clubSlug];
        }

        // Map common slug variations to team names
        $slugToName = [
            'stormers' => 'Stormers',
            'bulls' => 'Bulls',
            'sharks' => 'Sharks',
            'lions' => 'Lions',
            'leinster' => 'Leinster',
            'munster' => 'Munster',
            'connacht' => 'Connacht',
            'ulster' => 'Ulster',
            'edinburgh' => 'Edinburgh',
            'glasgow' => 'Glasgow Warriors',
            'scarlets' => 'Scarlets',
            'ospreys' => 'Ospreys',
            'cardiff' => 'Cardiff',
            'dragons' => 'Dragons',
            'benetton' => 'Benetton',
            'zebre' => 'Zebre',
            'bath' => 'Bath',
            'bristol' => 'Bristol',
            'exeter' => 'Exeter',
            'gloucester' => 'Gloucester',
            'harlequins' => 'Harlequins',
            'leicester' => 'Leicester',
            'newcastle' => 'Newcastle',
            'northampton' => 'Northampton',
            'sale' => 'Sale',
            'saracens' => 'Saracens',
            'toulouse' => 'Toulouse',
            'toulon' => 'Toulon',
            'la-rochelle' => 'La Rochelle',
            'racing-92' => 'Racing 92',
            'clermont' => 'Clermont',
            'bordeaux' => 'Bordeaux',
            'lyon' => 'Lyon',
            'crusaders' => 'Crusaders',
            'blues' => 'Blues',
            'chiefs' => 'Chiefs',
            'hurricanes' => 'Hurricanes',
            'highlanders' => 'Highlanders',
            'reds' => 'Reds',
            'brumbies' => 'Brumbies',
            'waratahs' => 'Waratahs',
        ];

        $name = $slugToName[$clubSlug] ?? ucfirst(str_replace('-', ' ', $clubSlug));
        $normalizedName = Str::lower(trim($name));

        // Try exact normalized name match first (prevents Sharks -> Sale Sharks)
        $team = Team::whereRaw('LOWER(name) = ?', [$normalizedName])->first();

        if (! $team) {
            $team = Team::whereRaw('LOWER(name) = ?', [Str::lower(trim(str_replace('-', ' ', $clubSlug)))])->first();
        }

        // Fallback: fuzzy contains match
        if (! $team) {
            $team = Team::where('name', 'like', "%{$name}%")
                ->orderByRaw('LENGTH(name) ASC')
                ->first();
        }

        if (! $team) {
            // Try slug as part of the name
            $slugName = ucfirst($clubSlug);
            $team = Team::where('name', 'like', "%{$slugName}%")
                ->orderByRaw('LENGTH(name) ASC')
                ->first();
        }

        if ($team) {
            $this->teamCache[$clubSlug] = $team;
        }

        return $team;
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
        $forwards = ['prop', 'hooker', 'lock', 'back row', 'flanker', 'number eight'];
        $backs = ['scrum-half', 'fly-half', 'centre', 'winger', 'fullback', 'wing'];
        foreach ($forwards as $f) {
            if (str_contains($pos, $f)) return 'Forward';
        }
        foreach ($backs as $b) {
            if (str_contains($pos, $b)) return 'Back';
        }
        return '';
    }
}
