<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\MatchEvent;
use App\Models\MatchLineup;
use App\Models\MatchOfficial;
use App\Models\MatchStat;
use App\Models\MatchTeam;
use App\Models\Player;
use App\Models\Referee;
use App\Models\RugbyMatch;
use App\Models\Season;
use App\Models\Team;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Imports match data from ESPN's hidden API scraper.
 *
 * This imports:
 *  - Matches (scores, dates, venues, rounds)
 *  - Match teams (home/away with scores)
 *  - Lineups (jersey numbers, positions, starter/sub)
 *
 * Workflow:
 *   1. Run: python3 scripts/espn_match_scraper.py --league urc --season 2024 --details
 *   2. Run: php artisan rugby:import-espn-matches
 */
class ImportEspnMatchesCommand extends Command
{
    use Concerns\ResolvesMatches;
    protected $signature = 'rugby:import-espn-matches
                            {--path= : Custom path to espn_matches directory}
                            {--league= : Only import specific league (e.g., urc)}
                            {--officials-only : Only process officials from detail files (skip lineups, events, stats)}
                            {--dry-run : Preview without writing}';

    protected $description = 'Import ESPN match data: matches, lineups, scores';

    private string $basePath;
    private bool $dryRun = false;
    private bool $officialsOnly = false;
    private array $teamCache = [];
    private array $competitionCache = [];
    private array $seasonCache = [];
    private array $venueCache = [];

    private int $matchesCreated = 0;
    private int $matchesUpdated = 0;
    private int $lineupsCreated = 0;
    private int $matchTeamsCreated = 0;
    private int $eventsCreated = 0;
    private int $statsCreated = 0;
    private int $officialsCreated = 0;

    /** ESPN league key → competition code mapping */
    private array $leagueMap = [
        'urc' => ['code' => 'urc', 'name' => 'United Rugby Championship', 'format' => 'union'],
        'premiership' => ['code' => 'premiership', 'name' => 'Premiership Rugby', 'format' => 'union'],
        'top14' => ['code' => 'top14', 'name' => 'Top 14', 'format' => 'union'],
        'super_rugby' => ['code' => 'super_rugby', 'name' => 'Super Rugby Pacific', 'format' => 'union'],
        'six_nations' => ['code' => 'six_nations', 'name' => 'Six Nations', 'format' => 'union'],
        'rugby_championship' => ['code' => 'rugby_championship', 'name' => 'Rugby Championship', 'format' => 'union'],
        'champions_cup' => ['code' => 'champions_cup', 'name' => 'Champions Cup', 'format' => 'union'],
        'challenge_cup' => ['code' => 'challenge_cup', 'name' => 'Challenge Cup', 'format' => 'union'],
        'world_cup' => ['code' => 'world_cup', 'name' => 'Rugby World Cup', 'format' => 'union'],
        'autumn_internationals' => ['code' => 'autumn_internationals', 'name' => 'Autumn Internationals', 'format' => 'union'],
        'currie_cup' => ['code' => 'currie_cup', 'name' => 'Currie Cup', 'format' => 'union'],
        'mlr' => ['code' => 'mlr', 'name' => 'Major League Rugby', 'format' => 'union'],
    ];

    public function handle(): int
    {
        $this->basePath = $this->option('path') ?? storage_path('app/espn_matches');
        $this->dryRun = (bool) $this->option('dry-run');
        $this->officialsOnly = (bool) $this->option('officials-only');

        if (! is_dir($this->basePath)) {
            $this->error("Data directory not found: {$this->basePath}");
            $this->line('Run: python3 scripts/espn_match_scraper.py --league urc --season 2024 --details');
            return 1;
        }

        if ($this->dryRun) {
            $this->info('DRY RUN mode.');
        }

        if ($this->officialsOnly) {
            $this->info('OFFICIALS-ONLY mode — skipping lineups, events, and stats.');
        }

        // Find match JSON files
        $leagueFilter = $this->option('league');
        $pattern = $leagueFilter
            ? "matches_{$leagueFilter}_*.json"
            : 'matches_*.json';

        $matchFiles = glob("{$this->basePath}/{$pattern}");
        sort($matchFiles);

        if (empty($matchFiles)) {
            $this->warn("No match files found matching: {$pattern}");
            return 1;
        }

        $this->info("Found " . count($matchFiles) . " match file(s)");

        // Build set of ESPN IDs for league filtering on detail files
        $leagueEspnIds = null;
        if ($leagueFilter) {
            $leagueEspnIds = $this->collectEspnIdsFromMatchFiles($matchFiles);
            $this->info("Filtering detail files to " . count($leagueEspnIds) . " {$leagueFilter} match(es).");
        }

        if (! $this->officialsOnly) {
            foreach ($matchFiles as $file) {
                $this->importMatchFile($file);
            }
        }

        // Import detailed match data (lineups, officials, etc.) if available
        $detailsDir = $this->basePath . '/details';
        if (is_dir($detailsDir)) {
            $this->importMatchDetails($detailsDir, $leagueEspnIds);
        }

        $this->newLine();
        $this->info('=== ESPN Match Import Summary ===');

        $rows = $this->officialsOnly
            ? [
                ['Officials created', $this->officialsCreated],
            ]
            : [
                ['Matches created', $this->matchesCreated],
                ['Matches updated', $this->matchesUpdated],
                ['Match teams created', $this->matchTeamsCreated],
                ['Lineups created', $this->lineupsCreated],
                ['Events created', $this->eventsCreated],
                ['Match stats created', $this->statsCreated],
                ['Officials created', $this->officialsCreated],
            ];

        $this->table(['Metric', 'Count'], $rows);

        return 0;
    }

    private function importMatchFile(string $file): void
    {
        $filename = basename($file);
        $this->info("Processing {$filename}...");

        $matches = json_decode(file_get_contents($file), true);
        if (! $matches) {
            $this->warn("  Empty or invalid file.");
            return;
        }

        $bar = $this->output->createProgressBar(count($matches));

        foreach ($matches as $data) {
            $this->processMatch($data);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    private function processMatch(array $data): void
    {
        $espnId = $data['espn_id'] ?? null;
        if (! $espnId) {
            return;
        }

        // Map ESPN status to internal status
        $rawStatus = $data['status'] ?? '';
        $status = match (true) {
            in_array($rawStatus, ['STATUS_FINAL', 'STATUS_FULL_TIME', 'ft']) => 'ft',
            in_array($rawStatus, ['STATUS_SCHEDULED', 'STATUS_POSTPONED', 'STATUS_IN_PROGRESS']) => strtolower(str_replace('STATUS_', '', $rawStatus)),
            $rawStatus === 'STATUS_CANCELED' => 'cancelled',
            default => null,
        };

        if ($status === null) {
            return;
        }

        $leagueKey = $data['league'] ?? '';
        $seasonYear = $data['season_year'] ?? null;

        // Resolve competition + season
        $season = $this->resolveseason($leagueKey, $seasonYear);
        if (! $season) {
            return;
        }

        // Resolve venue
        $venue = null;
        if (! empty($data['venue'])) {
            $venue = $this->resolveVenue($data['venue']);
        }

        // Parse kickoff date
        $kickoff = null;
        if (! empty($data['date'])) {
            try {
                $kickoff = Carbon::parse($data['date']);
            } catch (\Exception $e) {
                return;
            }
        }

        // Parse round from the "round" field (e.g., "Round 5", "Quarter-Final")
        $round = null;
        $stage = null;
        $roundStr = $data['round'] ?? '';
        if (preg_match('/Round\s+(\d+)/i', $roundStr, $m)) {
            $round = (int) $m[1];
            $stage = 'pool';
        } elseif (stripos($roundStr, 'final') !== false || stripos($roundStr, 'semi') !== false || stripos($roundStr, 'quarter') !== false) {
            $stage = Str::slug($roundStr, '_');
        }

        // Find or create match
        $match = RugbyMatch::where('external_id', (string) $espnId)
            ->where('external_source', 'espn')
            ->first();

        if ($match) {
            if (! $this->dryRun) {
                $match->update(array_filter([
                    'status' => $status,
                    'venue_id' => $venue?->id,
                    'attendance' => $data['attendance'] ?? null,
                    'round' => $match->round ?? $round,
                    'stage' => $match->stage ?? $stage,
                ]));
            }
            $this->matchesUpdated++;
        } else {
            // Cross-source dedup: check if same match exists from another source
            $homeTeamName = $data['home_team']['name'] ?? '';
            $awayTeamName = $data['away_team']['name'] ?? '';

            if ($kickoff && $homeTeamName && $awayTeamName) {
                $match = $this->findExistingMatchByTeamsAndDate($kickoff, $homeTeamName, $awayTeamName);
                if ($match) {
                    // Link ESPN ID to existing match and enrich with ESPN-specific data
                    if (! $this->dryRun) {
                        $match->update(array_filter([
                            'external_id' => (string) $espnId,
                            'external_source' => 'espn',
                            'venue_id' => $match->venue_id ?? $venue?->id,
                            'attendance' => $match->attendance ?? ($data['attendance'] ?? null),
                        ]));
                    }
                    $this->matchesUpdated++;
                }
            }

            if (! $match) {
                if ($this->dryRun) {
                    $this->matchesCreated++;
                    return;
                }

                $match = RugbyMatch::create([
                    'season_id' => $season->id,
                    'venue_id' => $venue?->id,
                    'kickoff' => $kickoff,
                    'status' => $status,
                    'round' => $round,
                    'stage' => $stage,
                    'attendance' => $data['attendance'] ?? null,
                    'external_id' => (string) $espnId,
                    'external_source' => 'espn',
                ]);
                $this->matchesCreated++;
            }
        }

        // Create match teams
        foreach (['home_team', 'away_team'] as $side) {
            $teamData = $data[$side] ?? null;
            if (! $teamData) {
                continue;
            }

            $sideKey = $side === 'home_team' ? 'home' : 'away';
            $team = $this->resolveTeam($teamData['name'] ?? '', $teamData['short_name'] ?? '');

            if (! $team) {
                continue;
            }

            $exists = MatchTeam::where('match_id', $match->id)
                ->where('side', $sideKey)
                ->first();

            if ($exists) {
                if (! $this->dryRun) {
                    $exists->update([
                        'team_id' => $team->id,
                        'score' => $teamData['score'] ?? $exists->score,
                        'ht_score' => $teamData['ht_score'] ?? $exists->ht_score,
                        'is_winner' => $teamData['winner'] ?? $exists->is_winner,
                    ]);
                }
                continue;
            }

            if (! $this->dryRun) {
                MatchTeam::create([
                    'match_id' => $match->id,
                    'team_id' => $team->id,
                    'side' => $sideKey,
                    'score' => $teamData['score'] ?? null,
                    'ht_score' => $teamData['ht_score'] ?? null,
                    'is_winner' => $teamData['winner'] ?? null,
                ]);
            }
            $this->matchTeamsCreated++;
        }
    }

    /**
     * Collect ESPN IDs from match list files for league-aware detail filtering.
     */
    private function collectEspnIdsFromMatchFiles(array $matchFiles): array
    {
        $ids = [];
        foreach ($matchFiles as $file) {
            $matches = json_decode(file_get_contents($file), true) ?: [];
            foreach ($matches as $data) {
                $id = $data['espn_id'] ?? null;
                if ($id !== null) {
                    $ids[(string) $id] = true;
                }
            }
        }
        return $ids;
    }

    private function importMatchDetails(string $detailsDir, ?array $leagueEspnIds = null): void
    {
        $files = glob("{$detailsDir}/match_*.json");
        if (empty($files)) {
            return;
        }

        $this->info("Processing " . count($files) . " match detail file(s)...");
        $bar = $this->output->createProgressBar(count($files));

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (! $data) {
                $bar->advance();
                continue;
            }

            $matchData = $data['match'] ?? [];
            $parsed = $data['parsed'] ?? [];
            $espnId = $matchData['espn_id'] ?? $parsed['match_id'] ?? null;

            if (! $espnId) {
                $bar->advance();
                continue;
            }

            // Skip detail files outside the target league
            if ($leagueEspnIds !== null && ! isset($leagueEspnIds[(string) $espnId])) {
                $bar->advance();
                continue;
            }

            $match = RugbyMatch::where('external_id', (string) $espnId)
                ->where('external_source', 'espn')
                ->first();

            if (! $match) {
                $bar->advance();
                continue;
            }

            if (! $this->officialsOnly) {
                // Import lineups
                $lineups = $parsed['lineups'] ?? [];
                foreach ($lineups as $lineup) {
                    $this->processLineup($match, $lineup);
                }

                // Import events
                $events = $parsed['events'] ?? [];
                foreach ($events as $event) {
                    $this->processEvent($match, $event);
                }

                // Import team stats
                $teamStats = $parsed['team_stats'] ?? [];
                foreach ($teamStats as $stat) {
                    $this->processTeamStat($match, $stat);
                }
            }

            // Import officials
            $officials = $parsed['officials'] ?? [];
            foreach ($officials as $official) {
                $this->processOfficial($match, $official);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    private function processLineup(RugbyMatch $match, array $data): void
    {
        $playerName = $data['player_name'] ?? '';
        $teamName = $data['team_name'] ?? '';
        $jersey = $data['jersey_number'] ?? '';

        if (! $playerName || ! $jersey) {
            return;
        }

        // Find player by name
        $player = $this->findPlayerByName($playerName);
        if (! $player) {
            return;
        }

        // Find team
        $team = $this->resolveTeam($teamName);
        if (! $team) {
            return;
        }

        // Skip if lineup already exists
        $exists = MatchLineup::where('match_id', $match->id)
            ->where('player_id', $player->id)
            ->exists();

        if ($exists) {
            return;
        }

        // Determine starter: ESPN's 'starter' flag is unreliable for internationals,
        // but in rugby union jerseys 1-15 are always starters, 16-23 replacements.
        $jerseyNum = (int) $jersey;
        $isStarter = ($data['starter'] ?? false) || ($jerseyNum >= 1 && $jerseyNum <= 15);

        if (! $this->dryRun) {
            MatchLineup::create([
                'match_id' => $match->id,
                'player_id' => $player->id,
                'team_id' => $team->id,
                'jersey_number' => $jerseyNum,
                'role' => $isStarter ? 'starter' : 'replacement',
                'position' => $data['position'] ?? '',
                'captain' => false,
            ]);
        }
        $this->lineupsCreated++;
    }

    private function processEvent(RugbyMatch $match, array $data): void
    {
        $eventType = Str::lower(trim((string) ($data['type'] ?? '')));
        $type = match (true) {
            Str::contains($eventType, 'try') => 'try',
            Str::contains($eventType, 'conversion') => 'conversion',
            Str::contains($eventType, 'penalty') => 'penalty_goal',
            Str::contains($eventType, 'drop') => 'drop_goal',
            Str::contains($eventType, 'yellow') => 'yellow_card',
            Str::contains($eventType, 'red') => 'red_card',
            default => null,
        };

        if (! $type) {
            return;
        }

        $teamName = $data['team_name'] ?? '';
        $team = $teamName ? $this->resolveTeam($teamName) : null;
        if (! $team) {
            return;
        }

        $clock = (string) ($data['clock'] ?? '');
        $minute = preg_match('/(\d+)/', $clock, $matches) ? (int) $matches[1] : 0;

        $playerName = trim((string) ($data['player_name'] ?? ''));
        $player = $playerName ? $this->findPlayerByName($playerName) : null;

        $exists = MatchEvent::where('match_id', $match->id)
            ->where('type', $type)
            ->where('minute', $minute)
            ->where('team_id', $team->id)
            ->where('player_id', $player?->id)
            ->exists();

        if ($exists) {
            return;
        }

        if (! $this->dryRun) {
            MatchEvent::create([
                'match_id' => $match->id,
                'player_id' => $player?->id,
                'team_id' => $team->id,
                'minute' => $minute,
                'type' => $type,
            ]);
        }

        $this->eventsCreated++;
    }

    private function processTeamStat(RugbyMatch $match, array $data): void
    {
        $teamName = $data['team_name'] ?? '';
        $team = $teamName ? $this->resolveTeam($teamName) : null;
        if (! $team) {
            return;
        }

        $rawKey = (string) ($data['stat_key'] ?? $data['stat_label'] ?? '');
        $statKey = Str::slug($rawKey, '_');
        if ($statKey === '') {
            return;
        }

        $rawValue = (string) ($data['stat_value'] ?? '');
        if (! preg_match('/([0-9]+(?:\.[0-9]+)?)/', $rawValue, $matches)) {
            return;
        }

        $value = (float) $matches[1];

        if (! $this->dryRun) {
            MatchStat::updateOrCreate(
                [
                    'match_id' => $match->id,
                    'team_id' => $team->id,
                    'stat_key' => $statKey,
                ],
                [
                    'stat_value' => $value,
                ],
            );
        }

        $this->statsCreated++;
    }

    private function processOfficial(RugbyMatch $match, array $data): void
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return;
        }

        $roleRaw = Str::lower(trim((string) ($data['role'] ?? '')));
        $role = match (true) {
            Str::contains($roleRaw, 'assistant') && Str::contains($roleRaw, '1') => 'assistant_referee_1',
            Str::contains($roleRaw, 'assistant') && Str::contains($roleRaw, '2') => 'assistant_referee_2',
            Str::contains($roleRaw, 'assistant') => 'assistant_referee_1',
            Str::contains($roleRaw, 'tmo') || Str::contains($roleRaw, 'television') => 'tmo',
            Str::contains($roleRaw, 'reserve') || Str::contains($roleRaw, 'fourth') => 'reserve_referee',
            default => 'referee',
        };

        $parts = preg_split('/\s+/', $name) ?: [];
        if (count($parts) < 2) {
            return;
        }

        $firstName = array_shift($parts);
        $lastName = implode(' ', $parts);

        $referee = Referee::firstOrCreate(
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
            ],
            [
                'external_id' => $data['official_espn_id'] ?? null,
                'external_source' => 'espn',
                'nationality' => $data['nationality'] ?? null,
                'photo_url' => $data['photo_url'] ?? null,
            ],
        );

        // Enrich existing referee if nationality/photo were missing
        if ($referee->wasRecentlyCreated === false) {
            $updates = [];
            if (empty($referee->nationality) && ! empty($data['nationality'])) {
                $updates['nationality'] = $data['nationality'];
            }
            if (empty($referee->photo_url) && ! empty($data['photo_url'])) {
                $updates['photo_url'] = $data['photo_url'];
            }
            if (empty($referee->external_id) && ! empty($data['official_espn_id'])) {
                $updates['external_id'] = $data['official_espn_id'];
            }
            if ($updates && ! $this->dryRun) {
                $referee->update($updates);
            }
        }

        $exists = MatchOfficial::where('match_id', $match->id)
            ->where('referee_id', $referee->id)
            ->where('role', $role)
            ->exists();

        if ($exists) {
            return;
        }

        if (! $this->dryRun) {
            MatchOfficial::create([
                'match_id' => $match->id,
                'referee_id' => $referee->id,
                'role' => $role,
            ]);
        }

        $this->officialsCreated++;
    }

    private function findPlayerByName(string $fullName): ?Player
    {
        $parts = explode(' ', trim($fullName));
        if (count($parts) < 2) {
            return null;
        }

        $firstName = $parts[0];
        $lastName = implode(' ', array_slice($parts, 1));

        // Exact match
        $player = Player::where('first_name', $firstName)
            ->where('last_name', $lastName)
            ->first();

        if ($player) {
            return $player;
        }

        // Try last name only with fuzzy first name
        $candidates = Player::where('last_name', $lastName)->get();
        foreach ($candidates as $candidate) {
            if ($this->namesSimilar($candidate->first_name, $firstName)) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveSeason(string $leagueKey, ?int $year): ?Season
    {
        $cacheKey = "{$leagueKey}_{$year}";
        if (isset($this->seasonCache[$cacheKey])) {
            return $this->seasonCache[$cacheKey];
        }

        $leagueInfo = $this->leagueMap[$leagueKey] ?? null;
        if (! $leagueInfo || ! $year) {
            return null;
        }

        // Find or create competition
        $competition = $this->resolveCompetition($leagueInfo);
        if (! $competition) {
            return null;
        }

        // Season label: "2024-25" for club, "2024" for calendar-year
        $label = "{$year}-" . substr($year + 1, 2);

        $season = Season::where('competition_id', $competition->id)
            ->where('label', $label)
            ->first();

        if (! $season) {
            // Try calendar year label
            $season = Season::where('competition_id', $competition->id)
                ->where('label', (string) $year)
                ->first();
        }

        if (! $season && ! $this->dryRun) {
            $season = Season::create([
                'competition_id' => $competition->id,
                'label' => $label,
                'start_date' => Carbon::create($year, 8, 1),
                'end_date' => Carbon::create($year + 1, 7, 31),
                'is_current' => $year >= (int) date('Y') - 1,
                'external_id' => "{$leagueKey}_{$year}",
                'external_source' => 'espn',
            ]);
        }

        $this->seasonCache[$cacheKey] = $season;
        return $season;
    }

    private function resolveCompetition(array $info): ?Competition
    {
        $code = $info['code'];
        if (isset($this->competitionCache[$code])) {
            return $this->competitionCache[$code];
        }

        // Try exact code match first
        $competition = Competition::where('code', $code)->first();

        // Try canonical code from the competition name (handles code mismatches across sources)
        if (! $competition) {
            $canonicalCode = Competition::canonicalCodeFromName($info['name']);
            if ($canonicalCode !== $code) {
                $competition = Competition::where('code', $canonicalCode)->first();
            }
        }

        // Try name-based match as last resort
        if (! $competition) {
            $competition = Competition::where('name', $info['name'])->first();
        }

        if (! $competition && ! $this->dryRun) {
            $competition = Competition::create([
                'name' => $info['name'],
                'code' => $code,
                'format' => $info['format'],
                'external_source' => 'espn',
            ]);
        }

        $this->competitionCache[$code] = $competition;
        return $competition;
    }

    private function resolveVenue(array $venueData): ?Venue
    {
        $name = $venueData['name'] ?? '';
        if (! $name) {
            return null;
        }

        if (isset($this->venueCache[$name])) {
            return $this->venueCache[$name];
        }

        $venue = Venue::where('name', $name)->first();

        if (! $venue && ! $this->dryRun) {
            $venue = Venue::create([
                'name' => $name,
                'city' => $venueData['city'] ?? '',
                'country' => $venueData['country'] ?? '',
                'external_source' => 'espn',
            ]);
        }

        if ($venue) {
            $this->venueCache[$name] = $venue;
        }
        return $venue;
    }

    private function resolveTeam(string $name, string $shortName = ''): ?Team
    {
        $key = $name ?: $shortName;
        if (! $key) {
            return null;
        }

        if (isset($this->teamCache[$key])) {
            return $this->teamCache[$key];
        }

        $normalizedName = Str::lower(trim($name));
        $normalizedName = preg_replace('/\s+/', ' ', $normalizedName);
        $normalizedName = $this->normalizeEspnTeamAlias($normalizedName);
        $baseName = preg_replace('/\b(rugby|rugby\s+club|club|rc|fc)\b/i', '', $normalizedName);
        $baseName = trim(preg_replace('/\s+/', ' ', $baseName));
        $prefixStrippedName = preg_replace('/^(us|rc|fc|ac|sc)\s+/i', '', $baseName);
        $prefixStrippedName = trim(preg_replace('/\s+/', ' ', $prefixStrippedName));

        $team = Team::whereRaw('LOWER(name) = ?', [$normalizedName])->first();

        if (! $team && $baseName !== '') {
            $team = Team::whereRaw('LOWER(name) = ?', [$baseName])->first();
        }

        if (! $team && $baseName !== '') {
            $team = Team::whereRaw('LOWER(name) like ?', ["%{$baseName}%"])->first();
        }

        if (! $team && $prefixStrippedName !== '' && $prefixStrippedName !== $baseName) {
            $team = Team::whereRaw('LOWER(name) = ?', [$prefixStrippedName])->first();
        }

        if (! $team && $prefixStrippedName !== '' && $prefixStrippedName !== $baseName) {
            $team = Team::whereRaw('LOWER(name) like ?', ["%{$prefixStrippedName}%"])->first();
        }

        if (! $team && $normalizedName !== '') {
            $team = Team::whereRaw('LOWER(name) like ?', ["%{$normalizedName}%"])->first();
        }

        if (! $team && $shortName) {
            $shortNameLower = Str::lower($shortName);
            $shortNameMatch = Team::whereRaw('LOWER(short_name) = ?', [$shortNameLower])->first();
            if ($shortNameMatch && $name) {
                if ($this->teamNameLooksCompatible($shortNameMatch->name, $name)) {
                    $team = $shortNameMatch;
                }
            } else {
                $team = $shortNameMatch;
            }
        }

        if (! $team && $name) {
            $team = Team::where('name', 'like', "%{$name}%")->first();
        }

        if ($team) {
            $this->teamCache[$key] = $team;
        }

        return $team;
    }

    private function teamNameLooksCompatible(string $storedName, string $incomingName): bool
    {
        $normalize = function (string $value): string {
            $value = Str::lower(trim($value));
            $value = preg_replace('/\b(rugby|rugby\s+club|club|rc|fc)\b/i', '', $value);
            $value = preg_replace('/^(us|rc|fc|ac|sc)\s+/i', '', $value);
            $value = trim(preg_replace('/\s+/', ' ', $value));
            return $value;
        };

        $stored = $normalize($storedName);
        $incoming = $normalize($incomingName);

        if ($stored === '' || $incoming === '') {
            return false;
        }

        return Str::contains($stored, $incoming) || Str::contains($incoming, $stored);
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

    private function normalizeEspnTeamAlias(string $name): string
    {
        $aliases = [
            'cardiff blues' => 'cardiff rugby',
            'hong kong' => 'hong kong china',
            'united states of america' => 'united states',
            'usa' => 'united states',
            'benetton treviso' => 'benetton',
            'la rochelle 7s' => 'la rochelle',
            'france 7s' => 'france',
            'ireland 7s' => 'ireland',
            'samoa 7s' => 'samoa',
            'canada 7s' => 'canada',
            'new england free jacks' => 'england',
        ];

        return $aliases[$name] ?? $name;
    }
}
