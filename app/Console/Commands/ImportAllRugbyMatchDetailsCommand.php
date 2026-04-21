<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\MatchEvent;
use App\Models\MatchLineup;
use App\Models\MatchStat;
use App\Models\MatchTeam;
use App\Models\Player;
use App\Models\RugbyMatch;
use App\Models\Season;
use App\Models\Team;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Imports rich match detail data from all.rugby match pages.
 *
 * This imports:
 *  - Match metadata (date, venue, tournament, round)
 *  - Full lineups (1-23 for both teams with jersey numbers)
 *  - Match events (tries, conversions, penalties, drop goals, cards with minutes)
 *  - Substitutions (player off/on with minute)
 *  - Team stats (pack weight, average ages, etc.)
 *
 * Workflow:
 *   1. Run: python3 scripts/allrugby_scraper.py --matches-from-careers
 *   2. Run: php artisan rugby:import-allrugby-match-details
 */
class ImportAllRugbyMatchDetailsCommand extends Command
{
    use Concerns\ResolvesMatches;

    protected $signature = 'rugby:import-allrugby-match-details
                            {--path= : Custom path to allrugby directory}
                            {--match-id= : Import a single all.rugby match ID}
                            {--dry-run : Preview without writing}
                            {--force : Overwrite existing lineups/events}';

    protected $description = 'Import all.rugby match details: lineups, events, substitutions, stats';

    private string $basePath;
    private bool $dryRun = false;
    private bool $force = false;

    private array $teamCache = [];
    private array $playerCache = [];
    private array $venueCache = [];

    private int $filesProcessed = 0;
    private int $matchesUpdated = 0;
    private int $lineupsCreated = 0;
    private int $eventsCreated = 0;
    private int $statsCreated = 0;
    private int $subsCreated = 0;
    private int $venuesUpdated = 0;
    private int $skipped = 0;
    private int $errors = 0;

    /** Map event types from scraper to match_events.type values */
    private array $eventTypeMap = [
        'try' => 'try',
        'conversion' => 'conversion',
        'penalty' => 'penalty_goal',
        'drop' => 'drop_goal',
        'card' => 'yellow_card', // We'll refine based on context
    ];

    public function handle(): int
    {
        $this->basePath = $this->option('path') ?? storage_path('app/allrugby');
        $this->dryRun = (bool) $this->option('dry-run');
        $this->force = (bool) $this->option('force');

        $matchesDir = $this->basePath . '/matches';
        if (! is_dir($matchesDir)) {
            $this->error("Matches directory not found: {$matchesDir}");
            $this->line('Run: python3 scripts/allrugby_scraper.py --matches-from-careers');
            return 1;
        }

        if ($this->dryRun) {
            $this->info('DRY RUN mode.');
        }

        $matchId = $this->option('match-id');
        if ($matchId) {
            $path = "{$matchesDir}/match_{$matchId}.json";
            if (! file_exists($path)) {
                $this->error("Match file not found: {$path}");
                return 1;
            }
            $this->processFile($path);
        } else {
            $files = glob("{$matchesDir}/match_*.json");
            sort($files);

            if (empty($files)) {
                $this->warn('No match files found.');
                return 1;
            }

            $this->info('Processing ' . count($files) . ' match file(s)...');
            $bar = $this->output->createProgressBar(count($files));

            foreach ($files as $file) {
                $this->processFile($file);
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
        }

        $this->newLine();
        $this->info('=== All.Rugby Match Details Import Summary ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Files processed', $this->filesProcessed],
                ['Matches updated', $this->matchesUpdated],
                ['Lineups created', $this->lineupsCreated],
                ['Events created', $this->eventsCreated],
                ['Substitutions created', $this->subsCreated],
                ['Stats created', $this->statsCreated],
                ['Venues updated', $this->venuesUpdated],
                ['Skipped', $this->skipped],
                ['Errors', $this->errors],
            ]
        );

        return 0;
    }

    private function processFile(string $path): void
    {
        $data = json_decode(file_get_contents($path), true);
        $this->filesProcessed++;

        if (! is_array($data)) {
            $this->skipped++;
            return;
        }

        $allrugbyId = (string) ($data['match_id'] ?? '');
        if ($allrugbyId === '') {
            $this->skipped++;
            return;
        }

        // Find existing match by external_id
        $match = RugbyMatch::where('external_id', $allrugbyId)
            ->where('external_source', 'allrugby')
            ->first();

        if (! $match) {
            // Try to find by date + teams if match was imported from another source
            $match = $this->findMatchByDateAndTeams($data);
        }

        if (! $match) {
            // Fallback for matches missing kickoff: match by teams + final score
            $match = $this->findMatchByTeamsAndScore($data);
        }

        if (! $match) {
            $this->skipped++;
            return;
        }

        $updated = false;

        // Update match metadata
        $updated = $this->updateMatchMetadata($match, $data) || $updated;

        // Import lineups
        $updated = $this->importLineups($match, $data) || $updated;

        // Import events (tries, conversions, penalties, cards)
        $updated = $this->importEvents($match, $data) || $updated;

        // Import substitutions as events
        $updated = $this->importSubstitutions($match, $data) || $updated;

        // Import team stats
        $updated = $this->importTeamStats($match, $data) || $updated;

        if ($updated) {
            $this->matchesUpdated++;
        }
    }

    private function updateMatchMetadata(RugbyMatch $match, array $data): bool
    {
        $updates = [];

        // Update venue
        $venueName = $data['venue'] ?? null;
        if ($venueName && ! $match->venue_id) {
            $venue = $this->resolveVenue($venueName);
            if ($venue) {
                $updates['venue_id'] = $venue->id;
                $this->venuesUpdated++;
            }
        }

        // Update kickoff date/time
        $date = $data['date'] ?? null;
        $kickoff = $data['kickoff'] ?? null;
        if ($date && ! $match->kickoff) {
            try {
                $dateStr = $kickoff ? "{$date} {$kickoff}" : $date;
                $updates['kickoff'] = Carbon::parse($dateStr);
            } catch (\Exception $e) {
                // ignore
            }
        }

        // Update round/stage
        $round = $data['round'] ?? null;
        if ($round && ! $match->round && ! $match->stage) {
            if (preg_match('/(\d+)/', $round, $m)) {
                $updates['round'] = (int) $m[1];
                $updates['stage'] = 'pool';
            } elseif (stripos($round, 'pool') !== false) {
                $updates['stage'] = 'pool';
            } elseif (stripos($round, 'final') !== false || stripos($round, 'semi') !== false) {
                $updates['stage'] = Str::slug($round, '_');
            }
        }

        if (! empty($updates) && ! $this->dryRun) {
            $match->update($updates);
        }

        return ! empty($updates);
    }

    private function importLineups(RugbyMatch $match, array $data): bool
    {
        $lineups = $data['lineups'] ?? [];
        if (empty($lineups['home']) && empty($lineups['away'])) {
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

        $teams = $data['teams'] ?? [];
        $homeTeamSlug = $teams[0]['slug'] ?? null;
        $awayTeamSlug = $teams[1]['slug'] ?? null;

        $created = false;

        foreach (['home', 'away'] as $side) {
            $teamSlug = $side === 'home' ? $homeTeamSlug : $awayTeamSlug;
            $team = $teamSlug ? $this->resolveTeamBySlug($teamSlug) : null;

            // Fallback: get team from match_teams
            if (! $team) {
                $matchTeam = $match->matchTeams()->where('side', $side)->first();
                $team = $matchTeam ? Team::find($matchTeam->team_id) : null;
            }

            if (! $team) {
                continue;
            }

            foreach ($lineups[$side] ?? [] as $entry) {
                $playerSlug = $entry['slug'] ?? '';
                $playerName = $entry['name'] ?? '';
                $jersey = $entry['jersey_number'] ?? null;
                $role = $entry['role'] ?? 'starter';

                if (! $playerSlug || ! $jersey) {
                    continue;
                }

                $player = $this->resolvePlayerBySlug($playerSlug, $playerName);
                if (! $player) {
                    continue;
                }

                if (! $this->dryRun) {
                    MatchLineup::updateOrCreate(
                        [
                            'match_id' => $match->id,
                            'player_id' => $player->id,
                        ],
                        [
                            'team_id' => $team?->id,
                            'jersey_number' => (int) $jersey,
                            'role' => $role,
                            'position' => '',
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
            // Only delete non-substitution events (subs handled separately)
            $match->events()
                ->whereNotIn('type', ['substitution_on', 'substitution_off'])
                ->delete();
        }

        $teams = $data['teams'] ?? [];
        $homeTeamSlug = $teams[0]['slug'] ?? null;
        $awayTeamSlug = $teams[1]['slug'] ?? null;

        $created = false;

        foreach ($events as $event) {
            $type = $this->eventTypeMap[$event['type'] ?? ''] ?? null;
            if (! $type) {
                continue;
            }

            $minute = $event['minute'] ?? null;
            $side = $event['side'] ?? '';
            $playerSlug = $event['player_slug'] ?? '';
            $playerName = $event['player_name'] ?? '';

            $player = $playerSlug ? $this->resolvePlayerBySlug($playerSlug, $playerName) : null;
            $teamSlug = $side === 'home' ? $homeTeamSlug : $awayTeamSlug;
            $team = $teamSlug ? $this->resolveTeamBySlug($teamSlug) : null;

            // Fallback team from match_teams
            if (! $team) {
                $matchTeam = $match->matchTeams()->where('side', $side)->first();
                $team = $matchTeam ? Team::find($matchTeam->team_id) : null;
            }

            // Check for duplicate
            if (! $team) {
                continue;
            }

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
                    'team_id' => $team?->id,
                    'minute' => $minute,
                    'type' => $type,
                ]);
            }
            $this->eventsCreated++;
            $created = true;
        }

        return $created;
    }

    private function importSubstitutions(RugbyMatch $match, array $data): bool
    {
        $substitutions = $data['substitutions'] ?? [];
        if (empty($substitutions['home']) && empty($substitutions['away'])) {
            return false;
        }

        // Check if sub events already exist
        if (! $this->force) {
            $existingSubs = $match->events()
                ->whereIn('type', ['substitution_on', 'substitution_off'])
                ->count();
            if ($existingSubs > 0) {
                return false;
            }
        }

        if ($this->force && ! $this->dryRun) {
            $match->events()
                ->whereIn('type', ['substitution_on', 'substitution_off'])
                ->delete();
        }

        $teams = $data['teams'] ?? [];
        $homeTeamSlug = $teams[0]['slug'] ?? null;
        $awayTeamSlug = $teams[1]['slug'] ?? null;

        $created = false;

        foreach (['home', 'away'] as $side) {
            $teamSlug = $side === 'home' ? $homeTeamSlug : $awayTeamSlug;
            $team = $teamSlug ? $this->resolveTeamBySlug($teamSlug) : null;

            if (! $team) {
                $matchTeam = $match->matchTeams()->where('side', $side)->first();
                $team = $matchTeam ? Team::find($matchTeam->team_id) : null;
            }

            foreach ($substitutions[$side] ?? [] as $sub) {
                $minute = $sub['minute'] ?? null;
                $offName = $sub['off'] ?? '';
                $onName = $sub['on'] ?? '';

                // Find players by last name in the lineup
                $offPlayer = $offName ? $this->findPlayerByLastName($offName, $team) : null;
                $onPlayer = $onName ? $this->findPlayerByLastName($onName, $team) : null;

                // Create sub_off event
                if (($offPlayer || $offName) && $team) {
                    if (! $this->dryRun) {
                        MatchEvent::create([
                            'match_id' => $match->id,
                            'player_id' => $offPlayer?->id,
                            'team_id' => $team?->id,
                            'minute' => $minute,
                            'type' => 'substitution_off',
                            'meta' => $offPlayer ? null : ['player_name' => $offName],
                        ]);
                    }
                    $this->subsCreated++;
                    $created = true;
                }

                // Create sub_on event
                if (($onPlayer || $onName) && $team) {
                    if (! $this->dryRun) {
                        MatchEvent::create([
                            'match_id' => $match->id,
                            'player_id' => $onPlayer?->id,
                            'team_id' => $team?->id,
                            'minute' => $minute,
                            'type' => 'substitution_on',
                            'meta' => $onPlayer ? null : ['player_name' => $onName],
                        ]);
                    }
                    $this->subsCreated++;
                    $created = true;
                }

                // Update minutes_played in lineup if we have both players
                if ($minute && ! $this->dryRun) {
                    // Off player played from start to sub minute
                    if ($offPlayer) {
                        MatchLineup::where('match_id', $match->id)
                            ->where('player_id', $offPlayer->id)
                            ->update(['minutes_played' => $minute]);
                    }
                    // On player played from sub minute to 80
                    if ($onPlayer) {
                        MatchLineup::where('match_id', $match->id)
                            ->where('player_id', $onPlayer->id)
                            ->update(['minutes_played' => 80 - $minute]);
                    }
                }
            }
        }

        return $created;
    }

    private function importTeamStats(RugbyMatch $match, array $data): bool
    {
        $teamStats = $data['team_stats'] ?? [];
        if (empty($teamStats)) {
            return false;
        }

        if (! $this->force && $match->matchStats()->count() > 0) {
            return false;
        }

        if ($this->force && ! $this->dryRun) {
            $match->matchStats()->delete();
        }

        $teams = $data['teams'] ?? [];
        $homeTeamSlug = $teams[0]['slug'] ?? null;
        $awayTeamSlug = $teams[1]['slug'] ?? null;

        $created = false;

        foreach ($teamStats as $stat) {
            $statName = $stat['stat'] ?? '';
            $statKey = Str::slug($statName, '_');

            if (! $statKey) {
                continue;
            }

            foreach (['home', 'away'] as $side) {
                $value = $stat[$side] ?? '';
                if (! $value) {
                    continue;
                }

                $teamSlug = $side === 'home' ? $homeTeamSlug : $awayTeamSlug;
                $team = $teamSlug ? $this->resolveTeamBySlug($teamSlug) : null;

                if (! $team) {
                    $matchTeam = $match->matchTeams()->where('side', $side)->first();
                    $team = $matchTeam ? Team::find($matchTeam->team_id) : null;
                }

                if (! $team) {
                    continue;
                }

                // Extract numeric value if possible
                $numericValue = null;
                if (preg_match('/^([\d.]+)/', $value, $m)) {
                    $numericValue = (float) $m[1];
                }

                if (! $this->dryRun) {
                    MatchStat::updateOrCreate(
                        [
                            'match_id' => $match->id,
                            'team_id' => $team?->id,
                            'stat_key' => $statKey,
                        ],
                        [
                            'stat_value' => $numericValue ?? 0,
                        ]
                    );
                }
                $this->statsCreated++;
                $created = true;
            }
        }

        return $created;
    }

    // ── Resolution helpers ──

    private function findMatchByDateAndTeams(array $data): ?RugbyMatch
    {
        $date = $data['date'] ?? null;
        $teams = $data['teams'] ?? [];

        if (! $date || count($teams) < 2) {
            return null;
        }

        try {
            $matchDate = Carbon::parse($date);
        } catch (\Exception $e) {
            return null;
        }

        // Look for matches on the same date
        $candidates = RugbyMatch::whereDate('kickoff', $matchDate->toDateString())
            ->with('matchTeams.team')
            ->get();

        $homeSlug = $teams[0]['slug'] ?? '';
        $awaySlug = $teams[1]['slug'] ?? '';

        foreach ($candidates as $candidate) {
            $homeTeam = $candidate->matchTeams->firstWhere('side', 'home')?->team;
            $awayTeam = $candidate->matchTeams->firstWhere('side', 'away')?->team;

            if (! $homeTeam || ! $awayTeam) {
                continue;
            }

            $homeMatch = $this->teamMatchesSlug($homeTeam, $homeSlug);
            $awayMatch = $this->teamMatchesSlug($awayTeam, $awaySlug);

            if ($homeMatch && $awayMatch) {
                return $candidate;
            }
        }

        return null;
    }

    private function findMatchByTeamsAndScore(array $data): ?RugbyMatch
    {
        $teams = $data['teams'] ?? [];
        if (count($teams) < 2) {
            return null;
        }

        $homeSlug = $teams[0]['slug'] ?? '';
        $awaySlug = $teams[1]['slug'] ?? '';
        if ($homeSlug === '' || $awaySlug === '') {
            return null;
        }

        $homeScore = isset($data['home_score']) ? (int) $data['home_score'] : null;
        $awayScore = isset($data['away_score']) ? (int) $data['away_score'] : null;

        $homeTeam = $this->resolveTeamBySlug($homeSlug);
        $awayTeam = $this->resolveTeamBySlug($awaySlug);

        if (! $homeTeam || ! $awayTeam) {
            return null;
        }

        $candidates = RugbyMatch::whereHas('matchTeams', function ($query) use ($homeTeam, $awayTeam) {
                $query->whereIn('team_id', [$homeTeam->id, $awayTeam->id]);
            })
            ->with('matchTeams.team')
            ->get();

        foreach ($candidates as $candidate) {
            $homeMatchTeam = $candidate->matchTeams->firstWhere('side', 'home');
            $awayMatchTeam = $candidate->matchTeams->firstWhere('side', 'away');

            if (! $homeMatchTeam || ! $awayMatchTeam) {
                continue;
            }

            $homeModel = $homeMatchTeam->team;
            $awayModel = $awayMatchTeam->team;

            if (! $homeModel || ! $awayModel) {
                continue;
            }

            if (! $this->teamMatchesSlug($homeModel, $homeSlug) || ! $this->teamMatchesSlug($awayModel, $awaySlug)) {
                continue;
            }

            if ($homeScore !== null && $awayScore !== null) {
                $candidateHomeScore = $homeMatchTeam->score;
                $candidateAwayScore = $awayMatchTeam->score;

                if ((int) $candidateHomeScore !== $homeScore || (int) $candidateAwayScore !== $awayScore) {
                    continue;
                }
            }

            return $candidate;
        }

        return null;
    }

    private function teamMatchesSlug(Team $team, string $slug): bool
    {
        $teamName = Str::lower($team->name);
        $teamShort = Str::lower($team->short_name ?? '');
        $slug = Str::lower(str_replace('-', ' ', $slug));

        return Str::contains($teamName, $slug)
            || Str::contains($slug, $teamName)
            || ($teamShort && Str::contains($slug, $teamShort));
    }

    private function resolveTeamBySlug(string $slug): ?Team
    {
        if (isset($this->teamCache[$slug])) {
            return $this->teamCache[$slug];
        }

        $searchName = str_replace('-', ' ', $slug);

        $team = Team::where('name', 'like', "%{$searchName}%")->first();

        if (! $team) {
            $team = Team::where('short_name', 'like', "%{$searchName}%")->first();
        }

        if ($team) {
            $this->teamCache[$slug] = $team;
        }

        return $team;
    }

    private function resolvePlayerBySlug(string $slug, string $fullName = ''): ?Player
    {
        if (isset($this->playerCache[$slug])) {
            return $this->playerCache[$slug];
        }

        // Try external_id first
        $player = Player::where('external_id', $slug)
            ->where('external_source', 'allrugby')
            ->first();

        // Try name matching
        if (! $player && $fullName) {
            $player = $this->findPlayerByFullName($fullName);
        }

        // Try slug-based name matching
        if (! $player) {
            $parts = explode('-', $slug);
            if (count($parts) >= 2) {
                $firstName = ucfirst($parts[0]);
                $lastName = ucfirst(implode(' ', array_slice($parts, 1)));
                $player = Player::whereRaw('LOWER(last_name) = ?', [Str::lower($lastName)])
                    ->get()
                    ->first(function ($p) use ($firstName) {
                        return $this->namesSimilar($p->first_name, $firstName);
                    });
            }
        }

        if ($player) {
            $this->playerCache[$slug] = $player;
        }

        return $player;
    }

    private function findPlayerByFullName(string $fullName): ?Player
    {
        // all.rugby uses "First LAST" format
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

        // Case-insensitive
        $player = Player::whereRaw('LOWER(first_name) = ?', [Str::lower($firstName)])
            ->whereRaw('LOWER(last_name) = ?', [Str::lower($lastName)])
            ->first();

        if ($player) {
            return $player;
        }

        // Try last name only with fuzzy first name
        $candidates = Player::whereRaw('LOWER(last_name) = ?', [Str::lower($lastName)])->get();
        foreach ($candidates as $candidate) {
            if ($this->namesSimilar($candidate->first_name, $firstName)) {
                return $candidate;
            }
        }

        return null;
    }

    private function findPlayerByLastName(string $lastName, ?Team $team): ?Player
    {
        $lastName = trim($lastName);
        if (! $lastName) {
            return null;
        }

        $cacheKey = "ln_{$lastName}_{$team?->id}";
        if (isset($this->playerCache[$cacheKey])) {
            return $this->playerCache[$cacheKey];
        }

        // Search by last name (case-insensitive)
        $query = Player::whereRaw('LOWER(last_name) = ?', [Str::lower($lastName)]);

        $candidates = $query->get();

        if ($candidates->count() === 1) {
            $this->playerCache[$cacheKey] = $candidates->first();
            return $candidates->first();
        }

        // Multiple matches — narrow by team if possible
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

        // Return first match if any
        if ($candidates->isNotEmpty()) {
            $this->playerCache[$cacheKey] = $candidates->first();
            return $candidates->first();
        }

        return null;
    }

    private function resolveVenue(string $name): ?Venue
    {
        if (isset($this->venueCache[$name])) {
            return $this->venueCache[$name];
        }

        $venue = Venue::where('name', $name)->first();

        if (! $venue) {
            $venue = Venue::where('name', 'like', "%{$name}%")->first();
        }

        if (! $venue && ! $this->dryRun) {
            $venue = Venue::create([
                'name' => $name,
                'external_source' => 'allrugby',
            ]);
        }

        if ($venue) {
            $this->venueCache[$name] = $venue;
        }

        return $venue;
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
}
