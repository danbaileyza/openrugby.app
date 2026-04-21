<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\MatchLineup;
use App\Models\MatchTeam;
use App\Models\Player;
use App\Models\PlayerMatchStat;
use App\Models\PlayerSeasonStat;
use App\Models\RugbyMatch;
use App\Models\Season;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Imports player career data from all.rugby scraper.
 *
 * This imports per-match game sheets with:
 *  - Tournament, team, opponent, home/away, result, date
 *  - Jersey number, tries, drop goals, penalties, conversions, points, minutes
 *  - Overall season stats per competition
 *
 * Workflow:
 *   1. Run: python3 scripts/allrugby_scraper.py --squads urc
 *   2. Run: python3 scripts/allrugby_scraper.py --careers
 *   3. Run: php artisan rugby:import-allrugby-careers
 */
class ImportAllRugbyCareersCommand extends Command
{
    protected $signature = 'rugby:import-allrugby-careers
                            {--path= : Custom path to allrugby directory}
                            {--player= : Import only a specific player slug}
                            {--dry-run : Preview without writing}';

    protected $description = 'Import all.rugby player career data: game sheets, season stats, nationality';

    private string $basePath;
    private bool $dryRun = false;
    private array $teamCache = [];
    private array $competitionCache = [];
    private array $seasonCache = [];
    private array $playerCache = [];

    private int $playersProcessed = 0;
    private int $playersEnriched = 0;
    private int $matchesCreated = 0;
    private int $lineupsCreated = 0;
    private int $playerStatsCreated = 0;
    private int $seasonStatsCreated = 0;
    private int $skipped = 0;

    /** Map common all.rugby tournament names to competition codes */
    private array $tournamentMap = [
        'United Rugby Championship' => 'urc',
        'URC' => 'urc',
        'Champions Cup' => 'champions_cup',
        'Challenge Cup' => 'challenge_cup',
        'Super Rugby' => 'super_rugby',
        'Super Rugby Unlocked' => 'super_rugby_unlocked',
        'Top 14' => 'top14',
        'Premiership' => 'premiership',
        'Rugby Championship' => 'rugby_championship',
        'Six Nations' => 'six_nations',
        'Test Matchs' => 'test_matches',
        'Test Matches' => 'test_matches',
        'Autumn Nations Series' => 'autumn_internationals',
        'Summer Nations Series' => 'summer_internationals',
        'Rugby World Cup' => 'world_cup',
        'Rainbow Cup SA' => 'rainbow_cup',
        'Rainbow Cup' => 'rainbow_cup',
        'Currie Cup' => 'currie_cup',
        'Major League Rugby' => 'mlr',
    ];

    public function handle(): int
    {
        $this->basePath = $this->option('path') ?? storage_path('app/allrugby');
        $this->dryRun = (bool) $this->option('dry-run');

        if (! is_dir($this->basePath)) {
            $this->error("Data directory not found: {$this->basePath}");
            $this->line('Run: python3 scripts/allrugby_scraper.py --careers');
            return 1;
        }

        if ($this->dryRun) {
            $this->info('DRY RUN mode.');
        }

        $playerSlug = $this->option('player');

        if ($playerSlug) {
            $this->importSinglePlayer($playerSlug);
        } else {
            $this->importAllCareers();
        }

        $this->newLine();
        $this->info('=== All.Rugby Career Import Summary ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Players processed', $this->playersProcessed],
                ['Players enriched (nationality)', $this->playersEnriched],
                ['Matches created', $this->matchesCreated],
                ['Lineups created', $this->lineupsCreated],
                ['Player match stats', $this->playerStatsCreated],
                ['Season stats', $this->seasonStatsCreated],
                ['Skipped', $this->skipped],
            ]
        );

        return 0;
    }

    private function importSinglePlayer(string $slug): void
    {
        $file = "{$this->basePath}/careers/{$slug}.json";
        if (! file_exists($file)) {
            // Try combined careers.json
            $file = "{$this->basePath}/career_{$slug}.json";
        }

        if (! file_exists($file)) {
            $this->error("Player file not found for slug: {$slug}");
            return;
        }

        $data = json_decode(file_get_contents($file), true);
        if ($data) {
            $this->processPlayerCareer($data);
        }
    }

    private function importAllCareers(): void
    {
        // Try individual career files first
        $careersDir = $this->basePath . '/careers';
        if (is_dir($careersDir)) {
            $files = glob("{$careersDir}/*.json");
            $this->info("Found " . count($files) . " individual career files");

            $bar = $this->output->createProgressBar(count($files));
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data) {
                    $this->processPlayerCareer($data);
                }
                $bar->advance();
            }
            $bar->finish();
            $this->newLine();
            return;
        }

        // Fall back to combined careers.json
        $file = "{$this->basePath}/careers.json";
        if (! file_exists($file)) {
            $this->error('No career data found.');
            $this->line('Run: python3 scripts/allrugby_scraper.py --careers');
            return;
        }

        $careers = json_decode(file_get_contents($file), true);
        if (! $careers) {
            $this->warn('careers.json is empty or invalid.');
            return;
        }

        $this->info("Processing " . count($careers) . " player careers...");
        $bar = $this->output->createProgressBar(count($careers));

        foreach ($careers as $data) {
            $this->processPlayerCareer($data);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    private function processPlayerCareer(array $data): void
    {
        $slug = $data['slug'] ?? '';
        $firstName = trim($data['first_name'] ?? '');
        $lastName = trim($data['last_name'] ?? '');

        if (! $slug && ! $firstName && ! $lastName) {
            $this->skipped++;
            return;
        }

        // Find existing player
        $player = $this->findPlayer($firstName, $lastName, $slug);
        if (! $player) {
            $this->skipped++;
            return;
        }

        $this->playersProcessed++;

        // Enrich nationality
        $nationality = $data['nationality'] ?? null;
        if ($nationality && empty($player->nationality) && ! $this->dryRun) {
            $player->nationality = $nationality;
            $player->save();
            $this->playersEnriched++;
        }

        // Process game sheets (per-match data)
        $gameSheets = $data['game_sheets'] ?? [];
        foreach ($gameSheets as $entry) {
            $this->processGameSheet($player, $entry);
        }

        // Process overall season stats
        $overallStats = $data['overall_stats'] ?? [];
        foreach ($overallStats as $entry) {
            $this->processOverallStat($player, $entry);
        }
    }

    private function processGameSheet(Player $player, array $entry): void
    {
        $tournament = $entry['tournament'] ?? '';
        $teamName = $entry['team'] ?? '';
        $opponent = $entry['opponent'] ?? '';
        $date = $entry['date'] ?? '';
        $matchId = $entry['match_id'] ?? null;
        $matchSlug = $entry['match_slug'] ?? null;

        if (! $opponent || ! $teamName) {
            return;
        }

        // The scraper mixes aggregate summary rows into game_sheets; those rows
        // do not represent a real fixture and should never create matches.
        if (! $this->looksLikeMatchEntry($entry)) {
            $this->skipped++;
            return;
        }

        // Resolve team
        $team = $this->resolveTeam($teamName);
        $opponentTeam = $this->resolveTeam($opponent);

        // Find or create the match
        $match = $this->resolveMatch($entry, $team, $opponentTeam);
        if (! $match) {
            return;
        }

        // Create lineup entry
        $jerseyNumber = $entry['jersey_number'] ?? 0;
        if ($jerseyNumber > 0 && ! $this->dryRun) {
            $exists = MatchLineup::where('match_id', $match->id)
                ->where('player_id', $player->id)
                ->exists();

            if (! $exists) {
                MatchLineup::create([
                    'match_id' => $match->id,
                    'player_id' => $player->id,
                    'team_id' => $team?->id ?? $player->currentContract()?->team_id,
                    'jersey_number' => $jerseyNumber,
                    'role' => $jerseyNumber <= 15 ? 'starter' : 'replacement',
                    'position' => $player->position ?? '',
                    'captain' => false,
                    'minutes_played' => $entry['minutes'] ?? null,
                ]);
                $this->lineupsCreated++;
            }
        }

        // Create player match stats
        if (! $this->dryRun) {
            $statMap = [
                'tries' => $entry['tries'] ?? 0,
                'drop_goals' => $entry['drop_goals'] ?? 0,
                'penalties' => $entry['penalties'] ?? 0,
                'conversions' => $entry['conversions'] ?? 0,
                'points' => $entry['points'] ?? 0,
                'minutes' => $entry['minutes'] ?? 0,
            ];

            foreach ($statMap as $key => $value) {
                if ($value > 0) {
                    PlayerMatchStat::updateOrCreate(
                        [
                            'match_id' => $match->id,
                            'player_id' => $player->id,
                            'stat_key' => $key,
                        ],
                        [
                            'stat_value' => $value,
                        ]
                    );
                    $this->playerStatsCreated++;
                }
            }
        }
    }

    private function processOverallStat(Player $player, array $entry): void
    {
        $seasonLabel = $entry['season'] ?? '';
        $tournament = $entry['tournament'] ?? '';
        $teamName = $entry['team'] ?? '';

        if (! $seasonLabel || ! $tournament) {
            return;
        }

        // Resolve competition and season
        $competition = $this->resolveCompetition($tournament);
        if (! $competition) {
            return;
        }

        // Convert "24/25" to "2024-25"
        $season = $this->resolveSeasonFromLabel($competition, $seasonLabel);
        if (! $season) {
            return;
        }

        if ($this->dryRun) {
            $this->seasonStatsCreated++;
            return;
        }

        // Store aggregated season stats
        $statMap = [
            'matches' => $entry['matches'] ?? 0,
            'starter' => $entry['starter'] ?? 0,
            'wins' => $entry['wins'] ?? 0,
            'draws' => $entry['draws'] ?? 0,
            'losses' => $entry['losses'] ?? 0,
            'tries' => $entry['tries'] ?? 0,
            'drop_goals' => $entry['drop_goals'] ?? 0,
            'penalties' => $entry['penalties'] ?? 0,
            'conversions' => $entry['conversions'] ?? 0,
            'points' => $entry['points'] ?? 0,
            'minutes' => $entry['minutes'] ?? 0,
        ];

        foreach ($statMap as $key => $value) {
            if ($value > 0) {
                PlayerSeasonStat::updateOrCreate(
                    [
                        'player_id' => $player->id,
                        'season_id' => $season->id,
                        'stat_key' => $key,
                    ],
                    [
                        'stat_value' => $value,
                    ]
                );
                $this->seasonStatsCreated++;
            }
        }
    }

    private function resolveMatch(array $entry, ?Team $team, ?Team $opponentTeam): ?RugbyMatch
    {
        $matchId = $entry['match_id'] ?? null;
        $matchSlug = $entry['match_slug'] ?? null;

        // Try to find by all.rugby match ID
        if ($matchId) {
            $match = RugbyMatch::where('external_id', (string) $matchId)
                ->where('external_source', 'allrugby')
                ->first();

            if ($match) {
                return $match;
            }
        }

        if ($this->dryRun) {
            $this->matchesCreated++;
            return null;
        }

        // Resolve competition/season from tournament name and match slug
        $tournament = $entry['tournament'] ?? '';
        $competition = $this->resolveCompetition($tournament);

        $kickoff = $this->parseKickoff($entry, $matchSlug);
        $season = $this->resolveSeasonForMatch($competition, $entry, $matchSlug, $kickoff);

        if (! $season) {
            $this->skipped++;
            return null;
        }

        // Create the match
        $match = RugbyMatch::create([
            'season_id' => $season->id,
            'kickoff' => $kickoff,
            'status' => 'ft',
            'round' => $this->parseRound($tournament),
            'external_id' => $matchId ? (string) $matchId : null,
            'external_source' => $matchId ? 'allrugby' : null,
        ]);
        $this->matchesCreated++;

        // Create match teams
        $place = strtoupper($entry['place'] ?? '');
        $result = strtoupper($entry['result'] ?? '');

        if ($team) {
            $homeSide = $place === 'HOME' ? 'home' : 'away';
            $isWinner = $result === 'W';

            MatchTeam::create([
                'match_id' => $match->id,
                'team_id' => $team->id,
                'side' => $homeSide,
                'is_winner' => $isWinner,
            ]);
        }

        if ($opponentTeam) {
            $oppSide = $place === 'HOME' ? 'away' : 'home';
            $oppWinner = $result === 'L';

            MatchTeam::updateOrCreate(
                ['match_id' => $match->id, 'side' => $oppSide],
                ['team_id' => $opponentTeam->id, 'is_winner' => $oppWinner]
            );
        }

        return $match;
    }

    private function parseRound(string $tournament): ?int
    {
        // "URC - R5" → 5, "Champions Cup - R2" → 2
        if (preg_match('/R(\d+)/', $tournament, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function looksLikeMatchEntry(array $entry): bool
    {
        $matchSlug = trim((string) ($entry['match_slug'] ?? ''));
        $date = trim((string) ($entry['date'] ?? ''));

        return $matchSlug !== '' || preg_match('/^\d{1,2}\/\d{1,2}$/', $date) === 1;
    }

    private function parseKickoff(array $entry, ?string $matchSlug): ?Carbon
    {
        $date = trim((string) ($entry['date'] ?? ''));
        if (! preg_match('/(\d{1,2})\/(\d{1,2})/', $date, $dm)) {
            return null;
        }

        $day = (int) $dm[1];
        $month = (int) $dm[2];
        $year = (int) date('Y');

        if ($matchSlug && preg_match('/(\d{4})-(\d{4})/', $matchSlug, $ym)) {
            // Cross-year competitions run Aug-Jul.
            $year = $month >= 8 ? (int) $ym[1] : (int) $ym[2];
        } elseif ($matchSlug && preg_match('/(?:^|[-_])(\d{4})(?:\/|$)/', $matchSlug, $ym)) {
            $year = (int) $ym[1];
        }

        try {
            return Carbon::create($year, $month, $day);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function resolveSeasonForMatch(
        ?Competition $competition,
        array $entry,
        ?string $matchSlug,
        ?Carbon $kickoff
    ): ?Season {
        if (! $competition) {
            return null;
        }

        $seasonLabel = trim((string) ($entry['season'] ?? ''));
        if ($seasonLabel !== '') {
            $season = $this->resolveSeasonFromLabel($competition, $seasonLabel);
            if ($season) {
                return $season;
            }
        }

        if ($matchSlug && preg_match('/(\d{4})-(\d{4})/', $matchSlug, $m)) {
            $year = (int) $m[1];
            return $this->resolveSeasonFromLabel($competition, $year . '-' . substr($m[2], 2));
        }

        if ($matchSlug && preg_match('/(?:^|[-_])(\d{4})(?:\/|$)/', $matchSlug, $m)) {
            return $this->resolveSeasonFromLabel($competition, $m[1]);
        }

        if (! $kickoff) {
            return null;
        }

        $season = Season::where('competition_id', $competition->id)
            ->whereDate('start_date', '<=', $kickoff->toDateString())
            ->whereDate('end_date', '>=', $kickoff->toDateString())
            ->first();

        if ($season) {
            return $season;
        }

        $usesSplitLabel = Season::where('competition_id', $competition->id)
            ->where('label', 'like', '____-__')
            ->exists();

        if ($usesSplitLabel) {
            $startYear = $kickoff->month >= 8 ? $kickoff->year : $kickoff->year - 1;
            return $this->resolveSeasonFromLabel(
                $competition,
                $startYear . '-' . substr((string) ($startYear + 1), -2)
            );
        }

        return $this->resolveSeasonFromLabel($competition, (string) $kickoff->year);
    }

    private function findPlayer(string $firstName, string $lastName, string $slug): ?Player
    {
        $cacheKey = $slug ?: "{$firstName}_{$lastName}";
        if (isset($this->playerCache[$cacheKey])) {
            return $this->playerCache[$cacheKey];
        }

        // 1. By all.rugby slug
        if ($slug) {
            $player = Player::where('external_source', 'allrugby')
                ->where('external_id', $slug)
                ->first();
            if ($player) {
                $this->playerCache[$cacheKey] = $player;
                return $player;
            }
        }

        // 2. Exact name match
        if ($firstName && $lastName) {
            $player = Player::where('first_name', $firstName)
                ->where('last_name', $lastName)
                ->first();
            if ($player) {
                $this->playerCache[$cacheKey] = $player;
                return $player;
            }
        }

        // 3. Fuzzy last name match
        if ($lastName) {
            $candidates = Player::where('last_name', $lastName)->get();
            foreach ($candidates as $candidate) {
                if ($this->namesSimilar($candidate->first_name, $firstName)) {
                    $this->playerCache[$cacheKey] = $candidate;
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function resolveTeam(string $name): ?Team
    {
        if (! $name) {
            return null;
        }

        if (isset($this->teamCache[$name])) {
            return $this->teamCache[$name];
        }

        $normalized = Str::lower(trim($name));

        $team = Team::whereRaw('LOWER(name) = ?', [$normalized])->first();

        if (! $team) {
            $team = Team::where('name', 'like', "%{$name}%")
                ->orderByRaw('LENGTH(name) ASC')
                ->first();
        }

        if (! $team) {
            // Try short name
            $team = Team::where('short_name', $name)->first();
        }

        if ($team) {
            $this->teamCache[$name] = $team;
        }

        return $team;
    }

    private function resolveCompetition(string $tournamentName): ?Competition
    {
        // Clean tournament name (remove round info like " - R5")
        $clean = preg_replace('/\s*-\s*R\d+.*$/', '', $tournamentName);
        $clean = preg_replace('/\s*-\s*(Round of \d+|Quarter-finals|Semi-finals|Final|Qualifying).*$/i', '', $clean);
        $clean = trim($clean);

        if (isset($this->competitionCache[$clean])) {
            return $this->competitionCache[$clean];
        }

        $code = $this->tournamentMap[$clean] ?? Str::slug($clean, '_');

        $competition = Competition::where('code', $code)->first();

        if (! $competition) {
            $competition = Competition::where('name', 'like', "%{$clean}%")->first();
        }

        if (! $competition && ! $this->dryRun) {
            $competition = Competition::create([
                'name' => $clean,
                'code' => $code,
                'format' => 'union',
                'external_source' => 'allrugby',
            ]);
        }

        if ($competition) {
            $this->competitionCache[$clean] = $competition;
        }

        return $competition;
    }

    private function resolveSeasonFromLabel(Competition $competition, string $label): ?Season
    {
        // Convert "24/25" → "2024-25", "25/26" → "2025-26"
        if (preg_match('/^(\d{2})\/(\d{2})$/', $label, $m)) {
            $startYear = ((int) $m[1] >= 80) ? 1900 + (int) $m[1] : 2000 + (int) $m[1];
            $label = "{$startYear}-{$m[2]}";
        }

        $cacheKey = "{$competition->id}_{$label}";
        if (isset($this->seasonCache[$cacheKey])) {
            return $this->seasonCache[$cacheKey];
        }

        $season = Season::where('competition_id', $competition->id)
            ->where('label', $label)
            ->first();

        if (! $season && ! $this->dryRun) {
            // Parse year from label
            $year = (int) substr($label, 0, 4);
            if (! $year) {
                return null;
            }

            $season = Season::create([
                'competition_id' => $competition->id,
                'label' => $label,
                'start_date' => Carbon::create($year, 1, 1),
                'end_date' => Carbon::create($year + 1, 12, 31),
                'is_current' => $year >= (int) date('Y') - 1,
                'external_source' => 'allrugby',
            ]);
        }

        if ($season) {
            $this->seasonCache[$cacheKey] = $season;
        }

        return $season;
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
