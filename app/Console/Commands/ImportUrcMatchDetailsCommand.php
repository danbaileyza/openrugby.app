<?php

namespace App\Console\Commands;

use App\Models\MatchEvent;
use App\Models\MatchLineup;
use App\Models\MatchOfficial;
use App\Models\MatchStat;
use App\Models\MatchTeam;
use App\Models\Player;
use App\Models\Referee;
use App\Models\RugbyMatch;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Imports rich URC match detail data: lineups, events, substitutions.
 *
 * Reads per-match JSON files saved by the URC scraper that contain
 * structured GraphQL data: 23-player lineups with positions and jersey
 * numbers, match events (tries, conversions, penalties, cards, subs)
 * with player names and minutes.
 *
 * Workflow:
 *   1. Run: python3 scripts/urc_scraper.py --match-details 202501
 *      (or --match <id> for a single match)
 *   2. Run: php artisan rugby:import-urc-match-details
 */
class ImportUrcMatchDetailsCommand extends Command
{
    protected $signature = 'rugby:import-urc-match-details
                            {--path= : Custom path to URC data directory}
                            {--match-id= : Import a single URC match ID}
                            {--season= : Import all matches for a season ID, e.g. 202501}
                            {--dry-run : Preview without writing}
                            {--force : Overwrite existing lineups/events}';

    protected $description = 'Import URC match details: lineups, events, substitutions';

    private string $basePath;
    private bool $dryRun = false;
    private bool $force = false;

    private array $teamCache = [];
    private array $playerCache = [];

    private int $filesProcessed = 0;
    private int $matchesUpdated = 0;
    private int $lineupsCreated = 0;
    private int $eventsCreated = 0;
    private int $statsCreated = 0;
    private int $subsCreated = 0;
    private int $officialsCreated = 0;
    private int $skipped = 0;
    private int $errors = 0;

    /** Map URC event type names to match_events.type values */
    private array $eventTypeMap = [
        'try' => 'try',
        'goal kick' => 'conversion', // display field disambiguates
        'substitution' => 'substitution',
        'yellow card' => 'yellow_card',
        'red card' => 'red_card',
        'penalty conceded' => 'penalty_conceded',
    ];

    public function handle(): int
    {
        $this->basePath = $this->option('path') ?? storage_path('app/urc');
        $this->dryRun = (bool) $this->option('dry-run');
        $this->force = (bool) $this->option('force');

        if (! is_dir($this->basePath)) {
            $this->error("URC data directory not found: {$this->basePath}");
            $this->line('Run: python3 scripts/urc_scraper.py --match-details 202501');
            return 1;
        }

        if ($this->dryRun) {
            $this->info('DRY RUN mode.');
        }

        $matchId = $this->option('match-id');
        $seasonId = $this->option('season');

        if ($matchId) {
            $this->importSingleMatch((string) $matchId);
        } elseif ($seasonId) {
            $this->importSeasonDetails($seasonId);
        } else {
            $this->importAllMatches();
        }

        $this->newLine();
        $this->info('=== URC Match Details Import Summary ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Files processed', $this->filesProcessed],
                ['Matches updated', $this->matchesUpdated],
                ['Lineups created', $this->lineupsCreated],
                ['Events created', $this->eventsCreated],
                ['Stats created', $this->statsCreated],
                ['Substitutions created', $this->subsCreated],
                ['Officials created', $this->officialsCreated],
                ['Skipped', $this->skipped],
                ['Errors', $this->errors],
            ]
        );

        return 0;
    }

    // ── Team Stats Import ──

    private function importTeamStats(RugbyMatch $match, array $data): bool
    {
        $stats = $data['lineups']['team_stats'] ?? $data['team_stats'] ?? [];
        if (! is_array($stats) || empty($stats)) {
            return false;
        }

        if (! $this->force && $match->matchStats()->count() > 0) {
            return false;
        }

        if ($this->force && ! $this->dryRun) {
            $match->matchStats()->delete();
        }

        $created = false;

        foreach ($stats as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $side = (string) ($entry['side'] ?? '');
            $label = trim((string) ($entry['label'] ?? ''));
            $rawValue = trim((string) ($entry['value'] ?? ''));

            if ($side === '' || $label === '' || $rawValue === '') {
                continue;
            }

            $matchTeam = $match->matchTeams->firstWhere('side', $side);
            $teamId = $matchTeam?->team_id;
            if (! $teamId) {
                continue;
            }

            if (! preg_match('/^-?\d+(?:\.\d+)?/', $rawValue, $m)) {
                continue;
            }

            $value = (float) $m[0];
            if (stripos($rawValue, '%') !== false) {
                $label .= ' %';
            }

            $statKey = Str::of(Str::lower($label))
                ->replaceMatches('/[^a-z0-9]+/', '_')
                ->trim('_')
                ->toString();

            if ($statKey === '') {
                continue;
            }

            if (! $this->dryRun) {
                MatchStat::updateOrCreate(
                    [
                        'match_id' => $match->id,
                        'team_id' => $teamId,
                        'stat_key' => $statKey,
                    ],
                    [
                        'stat_value' => $value,
                    ]
                );
            }

            $this->statsCreated++;
            $created = true;
        }

        return $created;
    }

    private function importSingleMatch(string $matchId): void
    {
        $paths = [
            "{$this->basePath}/matches/match_{$matchId}.json",
            "{$this->basePath}/match_{$matchId}.json",
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $this->processFile($path);
                return;
            }
        }

        $this->error("Match file not found for URC ID: {$matchId}");
    }

    private function importSeasonDetails(string $seasonId): void
    {
        // Try the consolidated match_details file first
        $detailsFile = "{$this->basePath}/match_details_{$seasonId}.json";
        if (file_exists($detailsFile)) {
            $allDetails = json_decode(file_get_contents($detailsFile), true);
            if (is_array($allDetails)) {
                $this->info("Processing " . count($allDetails) . " matches from match_details_{$seasonId}.json...");
                $bar = $this->output->createProgressBar(count($allDetails));

                foreach ($allDetails as $matchData) {
                    $this->processMatchData($matchData);
                    $bar->advance();
                }

                $bar->finish();
                $this->newLine();
                return;
            }
        }

        // Fall back to individual match files
        $this->importAllMatches();
    }

    private function importAllMatches(): void
    {
        $matchesDir = $this->basePath . '/matches';
        $pattern = is_dir($matchesDir)
            ? "{$matchesDir}/match_*.json"
            : "{$this->basePath}/match_*.json";

        $files = glob($pattern);

        if (! $files) {
            $this->warn('No URC match detail files found.');
            $this->line('Run: python3 scripts/urc_scraper.py --match-details 202501');
            return;
        }

        sort($files);
        $this->info('Processing ' . count($files) . ' match files...');

        $bar = $this->output->createProgressBar(count($files));
        foreach ($files as $file) {
            $this->processFile($file);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
    }

    private function processFile(string $path): void
    {
        $data = json_decode(file_get_contents($path), true);
        $this->filesProcessed++;

        if (! is_array($data)) {
            $this->skipped++;
            return;
        }

        if (empty($data['match_id']) && preg_match('/match_(\d+)\.json$/', $path, $m)) {
            $data['match_id'] = $m[1];
        }

        $this->processMatchData($data);
    }

    private function processMatchData(array $data): void
    {
        $matchId = (string) ($data['match_id'] ?? '');
        if ($matchId === '') {
            $this->skipped++;
            return;
        }

        // Normalize the raw GraphQL response into a flat structure.
        // The scraper saves: events = [{"stats_data": {"events": [...]}}]
        //                    lineups = [{"stats_data": {"homeTeam": {...}, "officials": [...]}}]
        // We normalize to: events = [...flat event list...]
        //                  lineups = {"home": {...}, "away": {...}, "officials": [...]}
        $data = $this->normalizeMatchData($data);

        // Find existing match by URC external_id
        $match = RugbyMatch::with('matchTeams.team')
            ->where('external_id', $matchId)
            ->where('external_source', 'urc')
            ->first();

        // Try to find by date + teams from the season data
        if (! $match) {
            $match = $this->findMatchByDateAndTeams($data);
        }

        if (! $match) {
            $this->skipped++;
            return;
        }

        $updated = false;

        // Import lineups
        $updated = $this->importLineups($match, $data) || $updated;

        // Import events (tries, conversions, penalties, cards, substitutions)
        $updated = $this->importEvents($match, $data) || $updated;

        // Import team stats (if present in payload)
        $updated = $this->importTeamStats($match, $data) || $updated;

        // Import officials (referee, assistant referees, TMO)
        $updated = $this->importOfficials($match, $data) || $updated;

        if ($updated) {
            $this->matchesUpdated++;
        }
    }

    /**
     * Normalize the raw GraphQL scraper output into a flat structure.
     *
     * Handles both:
     *   - Raw format: events/lineups are arrays of GraphQL matchstats objects
     *   - Pre-normalized format: events is a flat event array, lineups has home/away/officials
     */
    private function normalizeMatchData(array $data): array
    {
        // Normalize events: raw format is [{"stats_data": {"events": [...]}}]
        if (isset($data['events']) && is_array($data['events'])) {
            $first = $data['events'][0] ?? null;
            if ($first && isset($first['stats_data']['events'])) {
                // Raw GraphQL format — extract flat event list
                $rawEvents = $first['stats_data']['events'] ?? [];
                $flatEvents = [];
                foreach ($rawEvents as $e) {
                    if (! is_array($e)) continue;
                    $typeData = $e['type'] ?? [];
                    $typeName = is_array($typeData) ? ($typeData['name'] ?? '') : '';
                    if ($typeName === 'period') continue;

                    $team = $e['team'] ?? [];
                    $flatEvents[] = [
                        'id' => $e['id'] ?? null,
                        'type' => $typeName,
                        'display' => $e['display'] ?? '',
                        'time' => $e['time'] ?? null,
                        'timestamp' => $e['timestamp'] ?? null,
                        'period' => is_array($e['period'] ?? null) ? ($e['period']['name'] ?? null) : null,
                        'player' => is_array($e['player'] ?? null) ? ($e['player']['name'] ?? null) : null,
                        'team_id' => is_array($team) ? ($team['id'] ?? null) : null,
                        'team_name' => is_array($team) ? ($team['name'] ?? null) : null,
                        'player_on' => is_array($e['playerOn'] ?? null) ? ($e['playerOn']['name'] ?? null) : null,
                        'player_off' => is_array($e['playerOff'] ?? null) ? ($e['playerOff']['name'] ?? null) : null,
                    ];
                }
                $data['events'] = $flatEvents;

                // Extract URC team IDs from the events response top-level
                if (! isset($data['urc_home_team_id']) && isset($first['home_team_id'])) {
                    $data['urc_home_team_id'] = $first['home_team_id'];
                }
                if (! isset($data['urc_away_team_id']) && isset($first['away_team_id'])) {
                    $data['urc_away_team_id'] = $first['away_team_id'];
                }

                // Also extract datetime for cross-source matching
                if (! isset($data['datetime'])) {
                    $data['datetime'] = $first['stats_data']['dateTime'] ?? null;
                }
            }
        }

        // Normalize lineups: raw format is [{"stats_data": {"homeTeam": {...}, "awayTeam": {...}, "officials": [...]}}]
        if (isset($data['lineups']) && is_array($data['lineups'])) {
            $first = $data['lineups'][0] ?? null;
            if ($first && isset($first['stats_data'])) {
                // Raw GraphQL format
                $sd = $first['stats_data'];
                $normalized = ['home' => null, 'away' => null, 'officials' => [], 'team_stats' => []];

                foreach (['homeTeam' => 'home', 'awayTeam' => 'away'] as $gqlKey => $side) {
                    $teamData = $sd[$gqlKey] ?? [];
                    if (! $teamData) continue;

                    $team = $teamData['team'] ?? [];
                    $players = [];
                    foreach ($teamData['players'] ?? [] as $p) {
                        if (! is_array($p)) continue;
                        $pos = $p['position'] ?? [];
                        $players[] = [
                            'id' => $p['id'] ?? null,
                            'first_name' => $p['firstName'] ?? '',
                            'last_name' => $p['lastName'] ?? '',
                            'position' => is_array($pos) ? ($pos['name'] ?? '') : '',
                            'jersey_number' => is_array($pos) ? ($pos['shirtNumber'] ?? null) : null,
                        ];
                    }

                    $normalized[$side] = [
                        'team_id' => is_array($team) ? ($team['id'] ?? null) : null,
                        'team_name' => is_array($team) ? ($team['name'] ?? '') : '',
                        'players' => $players,
                    ];

                    $statsPayload = $teamData['statistics'] ?? $teamData['stats'] ?? [];
                    if (is_array($statsPayload)) {
                        foreach ($statsPayload as $statEntry) {
                            if (! is_array($statEntry)) {
                                continue;
                            }

                            $label = trim((string) ($statEntry['name'] ?? $statEntry['label'] ?? $statEntry['key'] ?? ''));
                            $value = $statEntry['value'] ?? $statEntry['stat'] ?? null;

                            if ($label === '' || $value === null || $value === '') {
                                continue;
                            }

                            $normalized['team_stats'][] = [
                                'side' => $side,
                                'label' => $label,
                                'value' => (string) $value,
                            ];
                        }
                    }
                }

                // Officials
                foreach ($sd['officials'] ?? [] as $o) {
                    if (! is_array($o)) continue;
                    $normalized['officials'][] = [
                        'id' => $o['id'] ?? null,
                        'name' => $o['name'] ?? '',
                        'role' => $o['role'] ?? '',
                    ];
                }

                $data['lineups'] = $normalized;

                // Extract team names for cross-source matching
                if (! isset($data['home_team'])) {
                    $data['home_team'] = ['name' => $normalized['home']['team_name'] ?? ''];
                }
                if (! isset($data['away_team'])) {
                    $data['away_team'] = ['name' => $normalized['away']['team_name'] ?? ''];
                }
            }
        }

        return $data;
    }

    // ── Lineup Import ──

    private function importLineups(RugbyMatch $match, array $data): bool
    {
        $lineups = $data['lineups'] ?? null;
        if (! $lineups) {
            return false;
        }

        // Check if lineups already exist
        if (! $this->force && $match->lineups()->count() > 0) {
            return false;
        }

        // If forcing, clear existing lineups
        if ($this->force && ! $this->dryRun) {
            $match->lineups()->delete();
        }

        $created = false;

        foreach (['home', 'away'] as $side) {
            $sideData = $lineups[$side] ?? [];
            $players = $sideData['players'] ?? [];
            $urcTeamId = $sideData['team_id'] ?? null;
            $teamName = $sideData['team_name'] ?? '';

            // Resolve team — prefer the match's own team records (most reliable)
            $team = null;
            $matchTeam = $match->matchTeams->firstWhere('side', $side);
            if ($matchTeam && $matchTeam->team) {
                $team = $matchTeam->team;
            }
            if (! $team && $teamName) {
                $team = $this->resolveTeamByName($teamName);
            }
            if (! $team && $urcTeamId) {
                $team = $this->resolveTeamByUrcId($urcTeamId);
            }

            foreach ($players as $entry) {
                $firstName = trim($entry['first_name'] ?? '');
                $lastName = trim($entry['last_name'] ?? '');
                $urcPlayerId = $entry['id'] ?? null;
                $jerseyNumber = $entry['jersey_number'] ?? null;
                $position = $entry['position'] ?? '';

                if (! $firstName && ! $lastName) {
                    continue;
                }
                if (! $jerseyNumber) {
                    continue;
                }

                if (! $team) {
                    $this->errors++;
                    continue;
                }

                $player = $this->resolvePlayer($urcPlayerId, $firstName, $lastName, $team);
                if (! $player) {
                    $this->errors++;
                    continue;
                }

                $role = $jerseyNumber <= 15 ? 'starter' : 'replacement';

                if (! $this->dryRun) {
                    MatchLineup::updateOrCreate(
                        [
                            'match_id' => $match->id,
                            'player_id' => $player->id,
                        ],
                        [
                            'team_id' => $team->id,
                            'jersey_number' => (int) $jerseyNumber,
                            'role' => $role,
                            'position' => $position,
                            'captain' => false,
                        ]
                    );
                }
                $this->lineupsCreated++;
                $created = true;
            }
        }

        return $created;
    }

    // ── Event Import ──

    private function importEvents(RugbyMatch $match, array $data): bool
    {
        $events = $data['events'] ?? [];
        if (empty($events)) {
            return false;
        }

        // Check if events already exist
        if (! $this->force && $match->events()->count() > 0) {
            return false;
        }

        if ($this->force && ! $this->dryRun) {
            $match->events()->delete();
        }

        // Build URC team ID → local Team mapping from multiple sources
        $urcToLocalTeam = [];
        $teamSideMap = [];
        foreach ($match->matchTeams as $mt) {
            if ($mt->team) {
                $teamSideMap[$mt->team->id] = $mt->side;
            }
        }

        // Map URC team IDs to local teams via side — try lineup data first
        $lineups = $data['lineups'] ?? [];
        foreach (['home', 'away'] as $side) {
            $matchTeam = $match->matchTeams->firstWhere('side', $side);
            if (! $matchTeam || ! $matchTeam->team) {
                continue;
            }

            // From lineup data
            $urcTeamId = $lineups[$side]['team_id'] ?? null;
            if ($urcTeamId) {
                $urcToLocalTeam[$urcTeamId] = $matchTeam->team;
            }

            // From events response top-level home_team_id / away_team_id
            $topLevelKey = "urc_{$side}_team_id";
            $topLevelId = $data[$topLevelKey] ?? null;
            if ($topLevelId) {
                $urcToLocalTeam[$topLevelId] = $matchTeam->team;
            }
        }

        $created = false;

        foreach ($events as $event) {
            $urcType = $event['type'] ?? '';
            $display = $event['display'] ?? '';
            $minute = $event['time'] ?? null;
            $playerName = $event['player'] ?? null;
            $teamId = $event['team_id'] ?? null;
            $teamName = $event['team_name'] ?? null;
            $playerOn = $event['player_on'] ?? null;
            $playerOff = $event['player_off'] ?? null;

            // Resolve team — prefer URC ID → local team map from lineups
            $team = null;
            if ($teamId && isset($urcToLocalTeam[$teamId])) {
                $team = $urcToLocalTeam[$teamId];
            }
            if (! $team && $teamName) {
                $team = $this->resolveTeamByName($teamName);
            }
            if (! $team && $teamId) {
                $team = $this->resolveTeamByUrcId($teamId);
            }

            // Determine side for this team
            $side = null;
            if ($team) {
                $side = $teamSideMap[$team->id] ?? null;
            }

            // Skip events where team can't be resolved (team_id is NOT NULL in DB)
            if (! $team) {
                $this->errors++;
                continue;
            }

            // Handle substitutions
            if ($urcType === 'substitution') {
                $created = $this->importSubstitution($match, $event, $team, $minute) || $created;
                continue;
            }

            // Map event type
            $type = $this->mapEventType($urcType, $display);
            if (! $type) {
                continue;
            }

            // Resolve player
            $player = null;
            if ($playerName) {
                $player = $this->resolvePlayerByFullName($playerName, $team);
            }

            // Check for duplicate
            $exists = MatchEvent::where('match_id', $match->id)
                ->where('type', $type)
                ->where('minute', $minute)
                ->where('player_id', $player?->id)
                ->exists();

            if ($exists) {
                continue;
            }

            if (! $this->dryRun) {
                MatchEvent::create([
                    'match_id' => $match->id,
                    'player_id' => $player?->id,
                    'team_id' => $team->id,
                    'minute' => $minute ? (int) $minute : 0,
                    'type' => $type,
                ]);
            }
            $this->eventsCreated++;
            $created = true;
        }

        return $created;
    }

    private function importSubstitution(RugbyMatch $match, array $event, Team $team, $minute): bool
    {
        $playerOff = $event['player_off'] ?? null;
        $playerOn = $event['player_on'] ?? null;
        $created = false;

        $offPlayer = $playerOff ? $this->resolvePlayerByFullName($playerOff, $team) : null;
        $onPlayer = $playerOn ? $this->resolvePlayerByFullName($playerOn, $team) : null;

        // Create sub_off event
        if ($offPlayer || $playerOff) {
            if (! $this->dryRun) {
                MatchEvent::create([
                    'match_id' => $match->id,
                    'player_id' => $offPlayer?->id,
                    'team_id' => $team->id,
                    'minute' => $minute ? (int) $minute : 0,
                    'type' => 'substitution_off',
                    'meta' => $offPlayer ? null : ['player_name' => $playerOff],
                ]);
            }
            $this->subsCreated++;
            $created = true;
        }

        // Create sub_on event
        if ($onPlayer || $playerOn) {
            if (! $this->dryRun) {
                MatchEvent::create([
                    'match_id' => $match->id,
                    'player_id' => $onPlayer?->id,
                    'team_id' => $team->id,
                    'minute' => $minute ? (int) $minute : 0,
                    'type' => 'substitution_on',
                    'meta' => $onPlayer ? null : ['player_name' => $playerOn],
                ]);
            }
            $this->subsCreated++;
            $created = true;
        }

        // Update minutes_played in lineup
        if ($minute && ! $this->dryRun) {
            $min = (int) $minute;
            if ($offPlayer) {
                MatchLineup::where('match_id', $match->id)
                    ->where('player_id', $offPlayer->id)
                    ->update(['minutes_played' => $min]);
            }
            if ($onPlayer) {
                MatchLineup::where('match_id', $match->id)
                    ->where('player_id', $onPlayer->id)
                    ->update(['minutes_played' => 80 - $min]);
            }
        }

        return $created;
    }

    // ── Officials Import ──

    private function importOfficials(RugbyMatch $match, array $data): bool
    {
        $lineups = $data['lineups'] ?? null;
        if (! $lineups) {
            return false;
        }

        $officials = $lineups['officials'] ?? [];
        if (empty($officials)) {
            return false;
        }

        // Check if officials already exist for this match
        if (! $this->force && $match->officials()->count() > 0) {
            return false;
        }

        if ($this->force && ! $this->dryRun) {
            $match->officials()->delete();
        }

        // Map URC role names to match_officials role enum
        $roleMap = [
            'referee' => 'referee',
            'assistant referee 1' => 'assistant_referee_1',
            'assistant referee 2' => 'assistant_referee_2',
            'assistant referee' => 'assistant_referee_1',
            'tmo' => 'tmo',
            'television match official' => 'tmo',
            'fourth official' => 'reserve_referee',
            'reserve referee' => 'reserve_referee',
        ];

        $created = false;
        $arCount = 0; // Track assistant referee numbering

        foreach ($officials as $official) {
            $name = trim($official['name'] ?? '');
            $urcRole = Str::lower(trim($official['role'] ?? ''));

            if (! $name) {
                continue;
            }

            // Determine the role
            $role = null;
            foreach ($roleMap as $pattern => $mappedRole) {
                if (Str::contains($urcRole, $pattern)) {
                    $role = $mappedRole;
                    break;
                }
            }

            // Handle generic "assistant referee" — assign AR1 then AR2
            if ($urcRole === 'assistant referee' || $urcRole === 'ar') {
                $arCount++;
                $role = $arCount <= 1 ? 'assistant_referee_1' : 'assistant_referee_2';
            }

            if (! $role) {
                // Default unknown roles to reserve_referee
                $role = 'reserve_referee';
            }

            // Parse referee name into first/last
            $nameParts = preg_split('/\s+/', $name);
            $firstName = $nameParts[0] ?? '';
            $lastName = count($nameParts) > 1
                ? implode(' ', array_slice($nameParts, 1))
                : '';

            // Find or create referee
            $referee = $this->resolveReferee($firstName, $lastName, $official['id'] ?? null);

            if (! $referee) {
                continue;
            }

            // Check for existing assignment
            $exists = MatchOfficial::where('match_id', $match->id)
                ->where('referee_id', $referee->id)
                ->where('role', $role)
                ->exists();

            if ($exists) {
                continue;
            }

            if (! $this->dryRun) {
                MatchOfficial::create([
                    'match_id' => $match->id,
                    'referee_id' => $referee->id,
                    'role' => $role,
                ]);
            }
            $this->officialsCreated++;
            $created = true;
        }

        return $created;
    }

    private function resolveReferee(string $firstName, string $lastName, ?int $urcId = null): ?Referee
    {
        $cacheKey = "ref_{$firstName}_{$lastName}";
        if (isset($this->playerCache[$cacheKey])) {
            return $this->playerCache[$cacheKey];
        }

        // Try by URC external ID
        if ($urcId) {
            $referee = Referee::where('external_source', 'urc')
                ->where('external_id', (string) $urcId)
                ->first();
            if ($referee) {
                $this->playerCache[$cacheKey] = $referee;
                return $referee;
            }
        }

        // Try by name
        $referee = Referee::whereRaw('LOWER(first_name) = ?', [Str::lower($firstName)])
            ->whereRaw('LOWER(last_name) = ?', [Str::lower($lastName)])
            ->first();

        // Fuzzy: try last name only
        if (! $referee && $lastName) {
            $candidates = Referee::whereRaw('LOWER(last_name) = ?', [Str::lower($lastName)])->get();
            if ($candidates->count() === 1) {
                $referee = $candidates->first();
            } else {
                foreach ($candidates as $candidate) {
                    if ($this->namesSimilar($candidate->first_name, $firstName)) {
                        $referee = $candidate;
                        break;
                    }
                }
            }
        }

        // Create if not found
        if (! $referee && ! $this->dryRun) {
            $referee = Referee::create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'external_id' => $urcId ? (string) $urcId : null,
                'external_source' => $urcId ? 'urc' : null,
            ]);
        }

        if ($referee) {
            $this->playerCache[$cacheKey] = $referee;
        }

        return $referee;
    }

    // ── Event Type Mapping ──

    private function mapEventType(string $urcType, string $display): ?string
    {
        // Try direct mapping first
        if (isset($this->eventTypeMap[$urcType])) {
            $mapped = $this->eventTypeMap[$urcType];

            // For goal kicks, disambiguate by display text
            if ($mapped === 'conversion') {
                $displayLower = Str::lower($display);

                // Check for misses first (before checking penalty/conversion)
                if (Str::contains($displayLower, 'missed')) {
                    if (Str::contains($displayLower, 'penalty')) {
                        return 'penalty_miss';
                    }
                    return 'conversion_miss';
                }
                if (Str::contains($displayLower, 'penalty')) {
                    return 'penalty_goal';
                }
                if (Str::contains($displayLower, 'drop')) {
                    return 'drop_goal';
                }
                return 'conversion';
            }

            return $mapped;
        }

        return null;
    }

    // ── Match Resolution ──

    private function findMatchByDateAndTeams(array $data): ?RugbyMatch
    {
        $datetime = $data['datetime'] ?? null;
        if (! $datetime) {
            return null;
        }

        try {
            $matchDate = Carbon::parse($datetime);
        } catch (\Exception $e) {
            return null;
        }

        // Resolve team names from different data structures
        $homeTeamName = $data['home_team']['name'] ?? '';
        $awayTeamName = $data['away_team']['name'] ?? '';

        // Also check lineups for team names
        if (! $homeTeamName && isset($data['lineups']['home']['team_name'])) {
            $homeTeamName = $data['lineups']['home']['team_name'];
        }
        if (! $awayTeamName && isset($data['lineups']['away']['team_name'])) {
            $awayTeamName = $data['lineups']['away']['team_name'];
        }

        if (! $homeTeamName || ! $awayTeamName) {
            return null;
        }

        // Find matches on the same date (± 1 day for timezone issues)
        $candidates = RugbyMatch::whereBetween('kickoff', [
                $matchDate->copy()->subDay()->startOfDay(),
                $matchDate->copy()->addDay()->endOfDay(),
            ])
            ->with('matchTeams.team')
            ->get();

        foreach ($candidates as $candidate) {
            $homeTeam = $candidate->matchTeams->firstWhere('side', 'home')?->team;
            $awayTeam = $candidate->matchTeams->firstWhere('side', 'away')?->team;

            if (! $homeTeam || ! $awayTeam) {
                continue;
            }

            $homeMatch = $this->teamNameMatches($homeTeam, $homeTeamName);
            $awayMatch = $this->teamNameMatches($awayTeam, $awayTeamName);

            if ($homeMatch && $awayMatch) {
                return $candidate;
            }
        }

        return null;
    }

    private function teamNameMatches(Team $team, string $urcName): bool
    {
        $teamName = Str::lower($team->name);
        $teamShort = Str::lower($team->short_name ?? '');
        $urcLower = Str::lower($urcName);

        // Direct contains
        if (Str::contains($teamName, $urcLower) || Str::contains($urcLower, $teamName)) {
            return true;
        }

        // Short name match
        if ($teamShort && (Str::contains($urcLower, $teamShort) || Str::contains($teamShort, $urcLower))) {
            return true;
        }

        // Core name matching (strip common prefixes like "Vodacom", "DHL", "Hollywoodbets")
        $coreName = preg_replace('/^(vodacom|dhl|hollywoodbets|fidelity\s+securedrive)\s+/i', '', $urcName);
        $coreName = Str::lower(trim($coreName));
        if (Str::contains($teamName, $coreName) || Str::contains($coreName, $teamName)) {
            return true;
        }

        return false;
    }

    // ── Player Resolution ──

    private function resolvePlayer(?int $urcId, string $firstName, string $lastName, ?Team $team): ?Player
    {
        $cacheKey = "urc_{$urcId}_{$firstName}_{$lastName}";
        if (isset($this->playerCache[$cacheKey])) {
            return $this->playerCache[$cacheKey];
        }

        // 1. Try by URC external ID
        if ($urcId) {
            $player = Player::where('external_source', 'urc')
                ->where('external_id', (string) $urcId)
                ->first();
            if ($player) {
                $this->playerCache[$cacheKey] = $player;
                return $player;
            }
        }

        // 2. Exact name match
        $player = Player::where('first_name', $firstName)
            ->where('last_name', $lastName)
            ->first();
        if ($player) {
            $this->playerCache[$cacheKey] = $player;
            return $player;
        }

        // 3. Case-insensitive name match
        $player = Player::whereRaw('LOWER(first_name) = ?', [Str::lower($firstName)])
            ->whereRaw('LOWER(last_name) = ?', [Str::lower($lastName)])
            ->first();
        if ($player) {
            $this->playerCache[$cacheKey] = $player;
            return $player;
        }

        // 4. Fuzzy last name match with team narrowing
        $candidates = Player::whereRaw('LOWER(last_name) = ?', [Str::lower($lastName)])->get();
        foreach ($candidates as $candidate) {
            if ($this->namesSimilar($candidate->first_name, $firstName)) {
                $this->playerCache[$cacheKey] = $candidate;
                return $candidate;
            }
        }

        // 5. If still not found and we have team context, try narrowing by team contract
        if ($candidates->count() > 1 && $team) {
            foreach ($candidates as $candidate) {
                $hasContract = $candidate->contracts()
                    ->where('team_id', $team->id)
                    ->exists();
                if ($hasContract) {
                    $this->playerCache[$cacheKey] = $candidate;
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function resolvePlayerByFullName(string $fullName, ?Team $team = null): ?Player
    {
        $cacheKey = "name_{$fullName}_{$team?->id}";
        if (isset($this->playerCache[$cacheKey])) {
            return $this->playerCache[$cacheKey];
        }

        $parts = preg_split('/\s+/', trim($fullName));
        if (count($parts) < 2) {
            return null;
        }

        $firstName = $parts[0];
        $lastName = implode(' ', array_slice($parts, 1));

        $player = $this->resolvePlayer(null, $firstName, $lastName, $team);

        if ($player) {
            $this->playerCache[$cacheKey] = $player;
        }

        return $player;
    }

    // ── Team Resolution ──

    private function resolveTeamByUrcId(int $urcId): ?Team
    {
        $cacheKey = "urc_team_{$urcId}";
        if (isset($this->teamCache[$cacheKey])) {
            return $this->teamCache[$cacheKey];
        }

        $team = Team::where('external_source', 'urc')
            ->where('external_id', (string) $urcId)
            ->first();

        if ($team) {
            $this->teamCache[$cacheKey] = $team;
        }

        return $team;
    }

    private function resolveTeamByName(string $name): ?Team
    {
        if (isset($this->teamCache[$name])) {
            return $this->teamCache[$name];
        }

        // Direct match
        $team = Team::where('name', $name)->first();

        // DB name contains input — prefer shortest match
        if (! $team) {
            $team = Team::where('name', 'like', "%{$name}%")
                ->orderByRaw('LENGTH(name) ASC')
                ->first();
        }

        // Input contains DB name — prefer longest DB name (most specific)
        if (! $team) {
            $team = Team::whereRaw('LOWER(?) LIKE CONCAT("%", LOWER(name), "%")', [$name])
                ->where('name', '!=', '')
                ->whereRaw('LENGTH(name) >= 3')
                ->orderByRaw('LENGTH(name) DESC')
                ->first();
        }

        // Strip trailing " Rugby" suffix (URC convention)
        if (! $team && preg_match('/^(.+)\s+Rugby$/i', $name, $m)) {
            $baseName = trim($m[1]);
            $team = Team::where('name', $baseName)->first();
            if (! $team) {
                $team = Team::where('name', 'like', "%{$baseName}%")
                    ->orderByRaw('LENGTH(name) ASC')
                    ->first();
            }
        }

        // Strip sponsor prefixes
        if (! $team) {
            $coreName = preg_replace('/^(vodacom|dhl|hollywoodbets|fidelity\s+securedrive|toyota|cell\s+c)\s+/i', '', $name);
            $coreName = trim($coreName);
            if ($coreName !== $name) {
                $team = Team::where('name', 'like', "%{$coreName}%")
                    ->orderByRaw('LENGTH(name) ASC')
                    ->first();
            }
        }

        if ($team) {
            $this->teamCache[$name] = $team;
        }

        return $team;
    }

    // ── Helpers ──

    private function namesSimilar(string $a, string $b): bool
    {
        $a = Str::lower(trim($a));
        $b = Str::lower(trim($b));
        if ($a === $b) return true;
        if (Str::startsWith($a, $b) || Str::startsWith($b, $a)) return true;
        if (levenshtein($a, $b) <= 2) return true;
        return false;
    }
}
