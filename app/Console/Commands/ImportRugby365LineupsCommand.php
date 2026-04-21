<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\MatchLineup;
use App\Models\MatchTeam;
use App\Models\Player;
use App\Models\RugbyMatch;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ImportRugby365LineupsCommand extends Command
{
    protected $signature = 'rugby:import-rugby365-lineups
                            {--path= : JSON file from rugby365_lineup_scraper.py}
                            {--dry-run}';

    protected $description = 'Import match lineups scraped from rugby365.com';

    public function handle(): int
    {
        $path = $this->option('path');
        if (! $path || ! file_exists($path)) {
            $this->error("File required: --path=/path/to/rugby365_lineups_*.json");
            return self::FAILURE;
        }
        $data = json_decode(file_get_contents($path), true);
        $dryRun = (bool) $this->option('dry-run');

        $compCode = $data['competition'];
        $seasonLabel = (string) $data['season'];
        $competition = Competition::where('code', $compCode)->first();
        if (! $competition) {
            $this->error("Competition not found: {$compCode}");
            return self::FAILURE;
        }

        $season = $competition->seasons()->where('label', $seasonLabel)->first();
        if (! $season) {
            $this->error("Season not found: {$compCode} {$seasonLabel}");
            return self::FAILURE;
        }

        $this->info("Importing lineups: {$compCode} {$seasonLabel} (".count($data['matches'])." matches)");

        $stats = [
            'matches_matched' => 0,
            'matches_skipped' => 0,
            'lineups_created' => 0,
            'lineups_existing' => 0,
            'players_created' => 0,
            'players_unresolved' => 0,
        ];

        foreach ($data['matches'] as $entry) {
            $home = $this->resolveTeam($entry['home']);
            $away = $this->resolveTeam($entry['away']);
            if (! $home || ! $away) {
                $this->warn("  Teams not resolved: {$entry['home']} vs {$entry['away']}");
                $stats['matches_skipped']++;
                continue;
            }

            $matchQuery = RugbyMatch::where('season_id', $season->id)
                ->whereHas('matchTeams', fn ($q) => $q->where('team_id', $home->id))
                ->whereHas('matchTeams', fn ($q) => $q->where('team_id', $away->id));

            if (! empty($entry['date'])) {
                $date = Carbon::parse($entry['date']);
                $matchQuery->whereBetween('kickoff', [$date->copy()->subDays(2), $date->copy()->addDays(2)]);
            }

            $match = $matchQuery->first();

            if (! $match) {
                $this->warn("  Match not found: {$home->name} vs {$away->name}");
                $stats['matches_skipped']++;
                continue;
            }
            $stats['matches_matched']++;

            foreach ([[$home, $entry['home_lineup']], [$away, $entry['away_lineup']]] as [$team, $lineup]) {
                foreach ($lineup as $p) {
                    $player = $this->resolveOrCreatePlayer($p['name'], $team, $dryRun, $stats);
                    if (! $player) {
                        $stats['players_unresolved']++;
                        continue;
                    }

                    $existing = MatchLineup::where('match_id', $match->id)
                        ->where('player_id', $player->id)
                        ->first();
                    if ($existing) {
                        $stats['lineups_existing']++;
                        if (! $dryRun) {
                            $update = [];
                            if ($existing->jersey_number === null) $update['jersey_number'] = $p['number'];
                            if ($existing->team_id === null) $update['team_id'] = $team->id;
                            if ($existing->role === null) {
                                $update['role'] = $p['number'] <= 15 ? 'starter' : 'replacement';
                            }
                            if ($update) $existing->update($update);
                        }
                        continue;
                    }

                    if (! $dryRun) {
                        MatchLineup::create([
                            'match_id' => $match->id,
                            'player_id' => $player->id,
                            'team_id' => $team->id,
                            'jersey_number' => $p['number'],
                            'role' => $p['number'] <= 15 ? 'starter' : 'replacement',
                            'position' => $this->positionForNumber($p['number']),
                        ]);
                    }
                    $stats['lineups_created']++;
                }
            }

            $this->line("  ✓ {$home->name} vs {$away->name}");
        }

        $this->table(['Metric', 'Count'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->all());
        return self::SUCCESS;
    }

    private function positionForNumber(int $n): string
    {
        return match ($n) {
            1 => 'LP', 2 => 'HK', 3 => 'TP',
            4, 5 => 'LK',
            6 => 'BF', 7 => 'OF', 8 => 'N8',
            9 => 'SH', 10 => 'FH',
            11 => 'LW', 12 => 'IC', 13 => 'OC', 14 => 'RW',
            15 => 'FB',
            default => 'REP',
        };
    }

    private function resolveTeam(string $name): ?Team
    {
        $name = trim($name);

        $aliases = [
            'British & Irish Lions' => 'British and Irish Lions',
            'First Nations & Pasifika XV' => 'First Nations and Pasifika XV',
            'AUNZ XV' => 'AUNZ Invitational XV',
            'Force' => 'Western Force',
            'Reds' => 'Queensland Reds',
            'Waratahs' => 'NSW Waratahs',
            'Brumbies' => 'ACT Brumbies',
            'Cheetahs' => 'Free State Cheetahs',
            'Sharks XV' => 'Sharks (Currie Cup)',
            'Kavaliers' => 'Boland Cavaliers',
        ];

        if (isset($aliases[$name])) {
            $team = Team::where('name', $aliases[$name])->first();
            if ($team) return $team;
        }

        $team = Team::where('name', $name)->first();
        if ($team) return $team;

        if (isset($aliases[$name])) {
            $team = Team::where('name', $aliases[$name])->first();
            if ($team) return $team;
        }

        // Reverse alias lookup
        foreach ($aliases as $from => $to) {
            if (strcasecmp($from, $name) === 0) {
                $team = Team::where('name', $to)->first();
                if ($team) return $team;
            }
            if (strcasecmp($to, $name) === 0) {
                $team = Team::where('name', $from)->first();
                if ($team) return $team;
            }
        }

        return Team::where('name', 'like', "%{$name}%")->orWhere('name', 'like', "{$name}%")->first();
    }

    private function resolveOrCreatePlayer(string $name, Team $team, bool $dryRun, array &$stats): ?Player
    {
        $name = trim($name);
        if ($name === '') return null;

        $parts = preg_split('/\s+/', $name, 2);
        if (count($parts) < 2) {
            // Surname-only, scoped to players contracted to this team
            $matches = Player::whereRaw('LOWER(last_name) = ?', [strtolower($name)])
                ->whereHas('contracts', fn ($q) => $q->where('team_id', $team->id))
                ->limit(2)->get();
            return $matches->count() === 1 ? $matches->first() : null;
        }

        [$first, $last] = $parts;
        $player = Player::whereRaw('LOWER(first_name) = ? AND LOWER(last_name) = ?', [strtolower($first), strtolower($last)])->first();
        if ($player) return $player;

        // Try initial-only match (e.g., "J Smith" vs "James Smith")
        if (strlen($first) <= 2) {
            $candidates = Player::whereRaw('LOWER(last_name) = ?', [strtolower($last)])->get();
            foreach ($candidates as $c) {
                if (stripos($c->first_name, $first[0]) === 0) return $c;
            }
        }

        // Try last-name-only when unique
        $byLast = Player::whereRaw('LOWER(last_name) = ?', [strtolower($last)])->limit(2)->get();
        if ($byLast->count() === 1) return $byLast->first();

        if ($dryRun) return null;

        $player = Player::create([
            'first_name' => $first,
            'last_name' => $last,
            'position' => 'unknown',
            'external_source' => 'rugby365',
        ]);
        $stats['players_created']++;
        return $player;
    }
}
