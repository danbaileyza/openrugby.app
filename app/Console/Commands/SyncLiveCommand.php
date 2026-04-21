<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\MatchEvent;
use App\Models\MatchLineup;
use App\Models\MatchOfficial;
use App\Models\Player;
use App\Models\MatchTeam;
use App\Models\Referee;
use App\Models\RugbyMatch;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncLiveCommand extends Command
{
    protected $signature = 'rugby:sync-live
                            {--competition=super_rugby : Competition code (super_rugby, currie_cup, urc, etc.)}
                            {--season= : Season label (defaults to current season)}
                            {--rugby365-season= : Rugby365 season id (e.g. 2026 for 2025-26). Defaults to latest.}
                            {--all : Sync all supported competitions}
                            {--import-missing : Create matches that rugby365 has but DB doesn\'t}
                            {--lineups : Also fetch and import lineups for each match}';

    protected $description = 'Pull live scores, events, and fixtures from rugby365.com';

    /** rugby365 tournament path per competition code */
    private array $paths = [
        'super_rugby' => 'super-rugby',
        'currie_cup' => 'currie-cup',
        'urc' => 'united-rugby-championship',
        'premiership' => 'premiership',
        'top14' => 'top-14',
        'pro_d2' => 'pro-d2',
        'european_rugby_champions_cup' => 'champions-cup',
        'lions_tour' => 'british-irish-lions',
    ];

    private array $eventTypeMap = [
        'try' => 'try',
        'con' => 'conversion',
        'pen' => 'penalty_goal',
        'drop' => 'drop_goal',
        'yc' => 'yellow_card',
        'rc' => 'red_card',
    ];

    public function handle(): int
    {
        if ($this->option('all')) {
            foreach (array_keys($this->paths) as $code) {
                $this->info("=== Syncing {$code} ===");
                $this->call('rugby:sync-live', ['--competition' => $code]);
            }
            return self::SUCCESS;
        }

        $compCode = $this->option('competition');
        $path = $this->paths[$compCode] ?? null;
        if (! $path) {
            $this->error("Unknown competition: {$compCode}. Known: ".implode(', ', array_keys($this->paths)));
            return self::FAILURE;
        }

        $competition = Competition::where('code', $compCode)->first();
        if (! $competition) {
            $this->error("Competition not in DB: {$compCode}");
            return self::FAILURE;
        }

        $seasonLabel = $this->option('season');
        if (! $seasonLabel) {
            $current = $competition->seasons()->where('is_current', true)->first();
            $seasonLabel = $current?->label;
        }
        $season = $seasonLabel ? $competition->seasons()->where('label', $seasonLabel)->first() : null;
        if (! $season) {
            if ($this->option('import-missing')) {
                $seasonLabel = $seasonLabel ?: $this->currentSeasonLabel($compCode);
                $season = $competition->seasons()->create([
                    'label' => $seasonLabel,
                    'is_current' => true,
                    'start_date' => now()->startOfYear(),
                    'end_date' => now()->endOfYear(),
                    'external_source' => 'rugby365',
                ]);
                $this->info("Created season {$seasonLabel}");
            } else {
                $this->error("Season '{$seasonLabel}' not found for {$compCode}. Use --import-missing to create it.");
                return self::FAILURE;
            }
        }

        $rugby365Season = $this->option('rugby365-season') ?? $this->guessRugby365Season($seasonLabel);
        $this->info("Syncing {$compCode} season={$seasonLabel} (rugby365={$rugby365Season})");

        $fixtures = $this->fetchFixtures($path, $rugby365Season);
        $this->info('  Rugby365 returned '.count($fixtures).' fixtures');

        $stats = ['live' => 0, 'completed' => 0, 'events_added' => 0, 'skipped' => 0, 'unmarked_live' => 0];

        // Track match IDs rugby365 reports as currently LIVE — everything else that's
        // marked 'live' in our DB for this season should be reverted to 'scheduled'.
        $rugby365LiveMatchIds = [];

        foreach ($fixtures as $fx) {
            if (! in_array($fx['state'], ['LIVE', 'FT'])) { $stats['skipped']++; continue; }

            $match = $this->resolveMatch($season, $fx);
            if (! $match && $this->option('import-missing')) {
                $match = $this->createMatchFromFixture($season, $fx);
            }
            if (! $match) { $stats['skipped']++; continue; }

            $home = $match->matchTeams->firstWhere('side', 'home');
            $away = $match->matchTeams->firstWhere('side', 'away');
            if (! $home || ! $away) continue;

            // Rugby365's `class="team home"` div tells us the true home team (URL slug is
            // unreliable). Map scores based on which DB team matches rugby365's home name.
            $rugbyHomeName = $fx['home_name'] ?? '';
            $homeTokens = $this->slugifyTokens($home->team->name);
            $rugbyHomeTokens = $this->slugifyTokens($rugbyHomeName);
            $sidesMatch = $rugbyHomeName !== '' && $this->tokensOverlap($homeTokens, $rugbyHomeTokens);

            $homeScore = $sidesMatch ? $fx['home_score'] : $fx['away_score'];
            $awayScore = $sidesMatch ? $fx['away_score'] : $fx['home_score'];

            MatchTeam::where('id', $home->id)->update(['score' => $homeScore]);
            MatchTeam::where('id', $away->id)->update(['score' => $awayScore]);

            $newStatus = $fx['state'] === 'FT' ? 'ft' : 'live';
            $match->update(['status' => $newStatus]);

            if ($newStatus === 'live') { $stats['live']++; $rugby365LiveMatchIds[] = $match->id; }
            else $stats['completed']++;

            // Update is_winner on FT
            if ($fx['state'] === 'FT') {
                $hw = $homeScore > $awayScore;
                MatchTeam::where('id', $home->id)->update(['is_winner' => $hw]);
                MatchTeam::where('id', $away->id)->update(['is_winner' => ! $hw && $homeScore !== $awayScore]);
            }

            // Pull lineups if missing (needed for event attribution)
            if ($match->lineups()->count() === 0) {
                $this->importLineups($match, $fx['url'], $fx['match_id']);
                $match = $match->fresh(['matchTeams.team', 'lineups.player']);
            }

            // Pull events
            $events = $this->fetchEvents($fx['url'], $fx['match_id']);
            if (! empty($events)) {
                $added = $this->importEvents($match, $events);
                $stats['events_added'] += $added;
            }

            // Pull referee
            $refName = $this->fetchReferee($fx['url'], $fx['match_id']);
            if ($refName) {
                $this->linkReferee($match, $refName);
            }

            // Roll up stats from events into match_teams
            $this->rollupStats($match);

            $this->line("  ✓ {$fx['state']} {$home->team->name} {$fx['home_score']}-{$fx['away_score']} {$away->team->name}".
                        (isset($added) && $added > 0 ? " (+{$added} events)" : ''));
            unset($added);
        }

        // Clear stale 'live' status on matches rugby365 no longer reports as live
        $stats['unmarked_live'] = RugbyMatch::where('season_id', $season->id)
            ->where('status', 'live')
            ->whereNotIn('id', $rugby365LiveMatchIds)
            ->update(['status' => 'scheduled']);

        $this->table(['Metric', 'Count'], [
            ['Live matches',    $stats['live']],
            ['Completed',       $stats['completed']],
            ['Events added',    $stats['events_added']],
            ['Cleared stale live', $stats['unmarked_live']],
            ['Skipped',         $stats['skipped']],
        ]);
        return self::SUCCESS;
    }

    private function guessRugby365Season(string $label): string
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $label, $m)) {
            return (string) ($m[1] + 1); // 2025-26 → 2026
        }
        return $label;
    }

    private function fetchFixtures(string $path, string $season): array
    {
        $url = "https://rugby365.com/tournaments/{$path}/fixtures-results/";
        $postBody = http_build_query(['action' => 'get-season', 'season' => $season]);
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nX-Requested-With: XMLHttpRequest\r\nUser-Agent: Mozilla/5.0\r\n",
            'content' => $postBody,
            'timeout' => 20,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) return [];
        $json = json_decode($body, true);
        $items = $json['items'] ?? [];

        $fixtures = [];
        $currentDate = null;
        $months = ['jan'=>'01','feb'=>'02','mar'=>'03','apr'=>'04','may'=>'05','jun'=>'06',
                   'jul'=>'07','aug'=>'08','sep'=>'09','oct'=>'10','nov'=>'11','dec'=>'12'];
        foreach ($items as $item) {
            if (preg_match('/class="date">\s*\w+\s+(\w+)\s+(\d{1,2}),\s+(\d{4})/', $item, $m)) {
                $mon = $months[strtolower(substr($m[1], 0, 3))] ?? '01';
                $currentDate = "{$m[3]}-{$mon}-".str_pad($m[2], 2, '0', STR_PAD_LEFT);
            }

            // Find each ?g=<id> occurrence, then isolate its enclosing game block by bracket walking
            preg_match_all('/\?g=(\d+)/', $item, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as $match) {
                $gid = $match[0];
                $pos = $match[1];

                // Extract game block bounded by nearest preceding <div class="game" and next <div class="game" (or end)
                $startDelim = '<div class="game ';
                $blockStart = strrpos(substr($item, 0, $pos), $startDelim);
                if ($blockStart === false) continue;
                $nextStart = strpos($item, $startDelim, $pos);
                $blockEnd = $nextStart !== false ? $nextStart : strlen($item);
                $game = substr($item, $blockStart, $blockEnd - $blockStart);

                if (! preg_match('/href="(https:\/\/rugby365\.com\/live\/[^"]+\?g=\d+)"/', $game, $um)) continue;
                $url = $um[1];

                $homeScore = preg_match('/class="score home\s*"[^>]*>(\d+)/', $game, $hm) ? (int) $hm[1] : null;
                $awayScore = preg_match('/class="score away\s*"[^>]*>(\d+)/', $game, $am) ? (int) $am[1] : null;

                // Extract the true home/away team names from the card divs.
                // Rugby365's URL slug is NOT a reliable home/away indicator.
                $homeName = $awayName = null;
                if (preg_match('/<div class="team home">\s*<span>.*?<\/span>\s*([^<\n]+?)\s*<\/div>/s', $game, $thm)) {
                    $homeName = trim($thm[1]);
                }
                if (preg_match('/<div class="team away">\s*<span>.*?<\/span>\s*([^<\n]+?)\s*<\/div>/s', $game, $tam)) {
                    $awayName = trim($tam[1]);
                }

                $state = $this->determineFixtureState($game, $homeScore, $awayScore);

                $fixtures[] = [
                    'match_id' => $gid,
                    'url' => $url,
                    'home_name' => $homeName,
                    'away_name' => $awayName,
                    'state' => $state,
                    'home_score' => $homeScore,
                    'away_score' => $awayScore,
                    'date' => $currentDate,
                ];
            }
        }
        return $fixtures;
    }

    /**
     * Infer fixture state from a rugby365 game block.
     */
    private function determineFixtureState(string $gameHtml, ?int $homeScore, ?int $awayScore): string
    {
        // rugby365 classifies the game block: "Fixture" (upcoming), "Result" (played/live).
        if (preg_match('/<div class="game[^"]*Fixture/', $gameHtml)) {
            return 'SCH';
        }

        if (preg_match('/<div class="live-note\s+show[^"]*"[^>]*>\s*LIVE/', $gameHtml)) {
            return 'LIVE';
        }

        if (preg_match('/<div class="game[^"]*Result/', $gameHtml)
            && $homeScore !== null
            && $awayScore !== null) {
            return 'FT';
        }

        return 'SCH';
    }

    private function resolveMatch($season, array $fx): ?RugbyMatch
    {
        if (! $fx['date']) return null;
        $date = Carbon::parse($fx['date']);

        // Match slug e.g. "blues-vs-highlanders" → extract team name slugs
        if (! preg_match('#/live/([^/]+)/#', $fx['url'], $m)) return null;
        [$homeSlug, $awaySlug] = array_pad(explode('-vs-', $m[1]), 2, null);
        if (! $homeSlug || ! $awaySlug) return null;

        return RugbyMatch::where('season_id', $season->id)
            ->whereBetween('kickoff', [$date->copy()->subDay(), $date->copy()->addDay()])
            ->with(['matchTeams.team', 'lineups.player'])
            ->get()
            ->first(fn ($m) => $this->matchSlugs($m, $homeSlug, $awaySlug));
    }

    private function extractSlugs(string $url): array
    {
        if (! preg_match('#/live/([^/]+)/#', $url, $m)) return ['', ''];
        $parts = explode('-vs-', $m[1]);
        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    private function matchSlugs(RugbyMatch $m, string $h, string $a): bool
    {
        $teams = $m->matchTeams->pluck('team.name')->filter()->map(fn ($n) => $this->slugifyTokens($n))->all();
        $hTokens = $this->slugifyTokens($h);
        $aTokens = $this->slugifyTokens($a);
        return collect($teams)->contains(fn ($t) => $this->tokensOverlap($t, $hTokens))
            && collect($teams)->contains(fn ($t) => $this->tokensOverlap($t, $aTokens));
    }

    /** Split a team/slug into lowercase alphanumeric tokens and drop common filler words. */
    private function slugifyTokens(string $s): array
    {
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9 \-]/', '', $s);
        $tokens = preg_split('/[\s\-]+/', $s);
        $drop = ['rugby', 'the', 'xv', 'rfc', 'club', 'fc', 'rc', 'cf'];
        return array_values(array_filter($tokens, fn ($t) => $t !== '' && ! in_array($t, $drop)));
    }

    private function tokensOverlap(array $a, array $b): bool
    {
        // Exact match wins
        if (array_intersect($a, $b)) return true;
        // Fallback: any 4+ char token prefix-shared (toulouse ↔ toulousain)
        foreach ($a as $ta) {
            if (strlen($ta) < 4) continue;
            foreach ($b as $tb) {
                if (strlen($tb) < 4) continue;
                $pref = min(strlen($ta), strlen($tb), 5);
                if (substr($ta, 0, $pref) === substr($tb, 0, $pref)) return true;
            }
        }
        return false;
    }

    private function slugify(string $s): string
    {
        return preg_replace('/[^a-z]/', '', strtolower($s));
    }

    private function fetchEvents(string $matchUrl, string $matchId): array
    {
        $slug = rtrim(explode('?', $matchUrl)[0], '/');
        $url = "{$slug}/scorers/?g={$matchId}";
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'header' => "User-Agent: Mozilla/5.0\r\n"]]);
        $html = @file_get_contents($url, false, $ctx);
        if (! $html) return [];

        preg_match('/key-events-container">(.*?)(?=<\/section>|<footer|\Z)/s', $html, $m);
        if (! $m) return [];

        $events = [];
        $parts = preg_split('/(?=class="(?:key-event|interval))/', $m[1]);
        foreach ($parts as $p) {
            if (! str_starts_with(ltrim($p), 'class="key-event')) continue;
            if (! preg_match('/icon-image (try|con|pen|drop|yc|rc)/', $p, $im)) continue;
            $type = $this->eventTypeMap[$im[1]];

            // Each event has two "side" divs. The one with a non-empty name/time is the scorer.
            // Split the event into home and away sections explicitly.
            $homeSection = '';
            $awaySection = '';
            if (preg_match('/side home">(.*?)<\/div>\s*<div class="(?:icon|side away)/s', $p, $hm)) {
                $homeSection = $hm[1];
            }
            if (preg_match('/side away">(.*?)<\/div>\s*<\/div>\s*(?:<div class="key-event|\Z)/s', $p, $am)) {
                $awaySection = $am[1];
            } elseif (preg_match('/side away">(.*?)\Z/s', $p, $am)) {
                $awaySection = $am[1];
            }

            $name = null; $minute = null; $side = null;
            foreach ([['home', $homeSection], ['away', $awaySection]] as [$sideKey, $section]) {
                if (! $section) continue;
                if (preg_match('/class="name">\s*([^<]+?)\s*</', $section, $nm)
                    && preg_match('/class="time">\s*([^<]+?)\s*</', $section, $tm)) {
                    $n = trim($nm[1]); $t = trim($tm[1]);
                    if ($n !== '' && preg_match('/(\d+)/', $t, $mm)) {
                        $name = $n; $minute = (int) $mm[1]; $side = $sideKey;
                        break;
                    }
                }
            }

            if ($name && $minute !== null) {
                $events[] = ['type' => $type, 'player' => $name, 'minute' => $minute, 'side' => $side];
            }
        }
        return $events;
    }

    private function importEvents(RugbyMatch $match, array $events): int
    {
        $bySurname = [];
        foreach ($match->lineups as $l) {
            if (! $l->player) continue;
            $bySurname[strtolower($l->player->last_name)][] = $l;
        }

        // Fetch existing events grouped by (type, player_id) with their minutes — used for
        // ±2min tolerance dedup since rugby365 sometimes nudges minutes between syncs.
        $existing = MatchEvent::where('match_id', $match->id)
            ->get(['type', 'minute', 'player_id'])
            ->groupBy(fn ($e) => "{$e->type}:{$e->player_id}")
            ->map(fn ($g) => $g->pluck('minute')->all());

        $added = 0;
        foreach ($events as $e) {
            $matches = $bySurname[strtolower($e['player'])] ?? [];
            if (count($matches) !== 1) continue;
            $l = $matches[0];
            $key = "{$e['type']}:{$l->player_id}";
            $existingMinutes = $existing->get($key, []);
            $isDuplicate = false;
            foreach ($existingMinutes as $em) {
                if (abs($em - $e['minute']) <= 2) { $isDuplicate = true; break; }
            }
            if ($isDuplicate) continue;
            MatchEvent::create([
                'match_id' => $match->id,
                'team_id' => $l->team_id,
                'player_id' => $l->player_id,
                'type' => $e['type'],
                'minute' => $e['minute'],
            ]);
            // Register so subsequent events in this run don't re-duplicate.
            $existing[$key] = array_merge($existingMinutes, [$e['minute']]);
            $added++;
        }
        return $added;
    }

    private function currentSeasonLabel(string $compCode): string
    {
        // Northern hemisphere winter comps use YYYY-YY format; others single year
        $split = ['urc','premiership_rugby','top_14','pro_d2','european_rugby_champions_cup'];
        $year = (int) now()->format('Y');
        $month = (int) now()->format('n');
        if (in_array($compCode, $split)) {
            $start = $month >= 8 ? $year : $year - 1;
            return $start.'-'.str_pad((string)(($start + 1) % 100), 2, '0', STR_PAD_LEFT);
        }
        return (string) $year;
    }

    private function createMatchFromFixture($season, array $fx): ?RugbyMatch
    {
        if (! $fx['date']) return null;
        if (! preg_match('#/live/([^/]+)/#', $fx['url'], $m)) return null;
        [$homeSlug, $awaySlug] = array_pad(explode('-vs-', $m[1]), 2, null);
        if (! $homeSlug || ! $awaySlug) return null;

        $home = $this->resolveTeamBySlug($homeSlug);
        $away = $this->resolveTeamBySlug($awaySlug);
        if (! $home || ! $away) return null;

        $match = RugbyMatch::create([
            'season_id' => $season->id,
            'kickoff' => Carbon::parse($fx['date']),
            'status' => 'scheduled',
            'external_source' => 'rugby365',
        ]);
        MatchTeam::create(['match_id' => $match->id, 'team_id' => $home->id, 'side' => 'home']);
        MatchTeam::create(['match_id' => $match->id, 'team_id' => $away->id, 'side' => 'away']);
        return $match->fresh(['matchTeams.team', 'lineups.player']);
    }

    private function resolveTeamBySlug(string $slug): ?\App\Models\Team
    {
        $words = explode('-', $slug);
        $like = '%'.implode('%', $words).'%';
        $team = \App\Models\Team::where('name', 'like', $like)->first();
        if ($team) return $team;
        // Try first word as prefix
        return \App\Models\Team::where('name', 'like', $words[0].'%')->first();
    }

    /**
     * Scrape and import lineups from rugby365 /teams/ endpoint.
     */
    private function importLineups(RugbyMatch $match, string $matchUrl, string $matchId): void
    {
        $slug = rtrim(explode('?', $matchUrl)[0], '/');
        $url = "{$slug}/teams/?g={$matchId}";
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'header' => "User-Agent: Mozilla/5.0\r\n"]]);
        $html = @file_get_contents($url, false, $ctx);
        if (! $html || strpos($html, 'class="player') === false) return;

        preg_match_all('/class="player(?:\s+odd)?\s*"\s*>/', $html, $matches, PREG_OFFSET_CAPTURE);
        $players = [];
        foreach ($matches[0] as $m) {
            $chunk = substr($html, $m[1], 800);
            if (! preg_match('/<div class="num">(\d+)<\/div>/', $chunk, $num)) continue;
            if (! preg_match('/<div class="name">\s*([^<]+?)(?:\s*<|\s*<\/div>)/', $chunk, $name)) continue;
            $players[] = ['number' => (int) $num[1], 'name' => trim($name[1])];
        }

        // Rugby365 order: home 1-15, away 1-15, home 16-23, away 16-23
        $home = [];
        $away = [];
        $seenHome = [];
        foreach ($players as $p) {
            if ($p['number'] <= 15) {
                if (! isset($seenHome[$p['number']])) { $home[] = $p; $seenHome[$p['number']] = true; }
                else $away[] = $p;
            } else {
                $homeHas = count(array_filter($home, fn ($x) => $x['number'] === $p['number']));
                if ($homeHas === 0) $home[] = $p;
                else $away[] = $p;
            }
        }

        $homeTeam = $match->matchTeams->firstWhere('side', 'home');
        $awayTeam = $match->matchTeams->firstWhere('side', 'away');
        if (! $homeTeam || ! $awayTeam) return;

        $positions = [1=>'LP',2=>'HK',3=>'TP',4=>'LK',5=>'LK',6=>'BF',7=>'OF',8=>'N8',9=>'SH',10=>'FH',11=>'LW',12=>'IC',13=>'OC',14=>'RW',15=>'FB'];

        foreach ([[$homeTeam, $home], [$awayTeam, $away]] as [$team, $lineup]) {
            foreach ($lineup as $p) {
                $player = $this->resolvePlayerByName($p['name']);
                if (! $player) continue;
                MatchLineup::firstOrCreate(
                    ['match_id' => $match->id, 'player_id' => $player->id],
                    [
                        'team_id' => $team->team_id,
                        'jersey_number' => $p['number'],
                        'role' => $p['number'] <= 15 ? 'starter' : 'replacement',
                        'position' => $positions[$p['number']] ?? 'REP',
                    ]
                );
            }
        }
    }

    private function resolvePlayerByName(string $name): ?Player
    {
        $name = trim($name);
        if ($name === '') return null;
        if (str_contains($name, ' ')) {
            [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
            $p = Player::whereRaw('LOWER(first_name) = ? AND LOWER(last_name) = ?', [strtolower($first), strtolower($last)])->first();
            if ($p) return $p;
        }
        // Surname only or not found — try surname match
        $players = Player::whereRaw('LOWER(last_name) = ?', [strtolower(explode(' ', $name)[count(explode(' ', $name)) - 1])])->limit(2)->get();
        if ($players->count() === 1) return $players->first();
        // Create
        $parts = explode(' ', $name, 2);
        return Player::create([
            'first_name' => $parts[0],
            'last_name' => $parts[1] ?? '',
            'position' => 'unknown',
            'external_source' => 'rugby365',
        ]);
    }

    /**
     * Fetch the referee name from a rugby365 match page.
     */
    private function fetchReferee(string $matchUrl, string $matchId): ?string
    {
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'header' => "User-Agent: Mozilla/5.0\r\n"]]);
        $html = @file_get_contents($matchUrl, false, $ctx);
        if (! $html) return null;

        if (preg_match('/data-src="[^"]*icon-referee[^"]*"[^>]*>\s*<\/div>\s*<div class="title">\s*<span>([^<]+)<\/span>/', $html, $m)) {
            $name = trim($m[1]);
            return $name !== '' ? $name : null;
        }
        return null;
    }

    /**
     * Create or find referee and link to match as official.
     */
    private function linkReferee(RugbyMatch $match, string $fullName): void
    {
        $parts = explode(' ', $fullName, 2);
        $first = $parts[0] ?? '';
        $last = $parts[1] ?? $parts[0];

        $referee = Referee::whereRaw('LOWER(first_name) = ? AND LOWER(last_name) = ?',
            [strtolower($first), strtolower($last)])->first();
        if (! $referee) {
            $referee = Referee::create([
                'first_name' => $first,
                'last_name' => $last,
                'external_source' => 'rugby365',
            ]);
        }

        MatchOfficial::firstOrCreate([
            'match_id' => $match->id,
            'role' => 'referee',
        ], [
            'referee_id' => $referee->id,
        ]);
    }

    /**
     * Aggregate event counts into match_teams (tries, conversions, penalties, drops, cards).
     */
    private function rollupStats(RugbyMatch $match): void
    {
        $counts = MatchEvent::where('match_id', $match->id)
            ->selectRaw('team_id, type, COUNT(*) as c')
            ->groupBy('team_id', 'type')
            ->get()
            ->groupBy('team_id');

        foreach ($match->matchTeams as $mt) {
            if (! $mt->team_id) continue;
            $teamCounts = $counts->get($mt->team_id, collect())->pluck('c', 'type');
            MatchTeam::where('id', $mt->id)->update([
                'tries' => (int) ($teamCounts['try'] ?? 0),
                'conversions' => (int) ($teamCounts['conversion'] ?? 0),
                'penalties_kicked' => (int) ($teamCounts['penalty_goal'] ?? 0),
                'drop_goals' => (int) ($teamCounts['drop_goal'] ?? 0),
            ]);
        }
    }
}
