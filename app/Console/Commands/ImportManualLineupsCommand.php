<?php

namespace App\Console\Commands;

use App\Models\MatchLineup;
use App\Models\Player;
use App\Models\RugbyMatch;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ImportManualLineupsCommand extends Command
{
    protected $signature = 'rugby:import-manual-lineups
                            {--path= : JSON file with manual lineup entries}
                            {--dry-run}';

    protected $description = 'Import manually-curated match lineups from a JSON file';

    public function handle(): int
    {
        $path = $this->option('path');
        if (! $path || ! file_exists($path)) {
            $this->error("File required: --path=/path/to/manual_lineups_*.json");
            return self::FAILURE;
        }
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $dryRun = (bool) $this->option('dry-run');

        $stats = ['matches' => 0, 'lineups_created' => 0, 'players_created' => 0, 'unresolved' => 0];

        foreach ($data['matches'] as $entry) {
            $home = Team::where('name', $entry['home'])->first();
            $away = Team::where('name', $entry['away'])->first();
            if (! $home || ! $away) {
                $this->error("Teams not found: {$entry['home']} vs {$entry['away']}");
                continue;
            }

            $date = Carbon::parse($entry['date']);
            $match = RugbyMatch::whereHas('matchTeams', fn ($q) => $q->where('team_id', $home->id))
                ->whereHas('matchTeams', fn ($q) => $q->where('team_id', $away->id))
                ->whereBetween('kickoff', [$date->copy()->subDays(2), $date->copy()->addDays(2)])
                ->first();
            if (! $match) {
                $this->error("Match not found: {$entry['date']} {$entry['home']} vs {$entry['away']}");
                continue;
            }
            $stats['matches']++;

            foreach ([[$home, $entry['home_lineup']], [$away, $entry['away_lineup']]] as [$team, $lineup]) {
                foreach ($lineup as $p) {
                    $player = $this->resolveOrCreate($p, $dryRun, $stats);
                    if (! $player) {
                        $stats['unresolved']++;
                        $this->warn("  unresolved: {$p['first_name']} {$p['last_name']}");
                        continue;
                    }

                    $existing = MatchLineup::where('match_id', $match->id)
                        ->where('player_id', $player->id)
                        ->first();
                    if ($existing) {
                        if (! $dryRun) {
                            $existing->update([
                                'team_id' => $team->id,
                                'jersey_number' => $p['number'],
                                'role' => $p['number'] <= 15 ? 'starter' : 'replacement',
                                'position' => $this->positionForNumber($p['number']),
                                'captain' => $p['captain'] ?? false,
                            ]);
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
                            'captain' => $p['captain'] ?? false,
                        ]);
                    }
                    $stats['lineups_created']++;
                }
            }
            $this->line("  ✓ {$entry['date']} {$home->name} vs {$away->name}");
        }

        $this->table(['Metric', 'Count'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->all());
        return self::SUCCESS;
    }

    private function resolveOrCreate(array $p, bool $dryRun, array &$stats): ?Player
    {
        $first = trim($p['first_name'] ?? '');
        $last = trim($p['last_name'] ?? '');
        if ($last === '') return null;

        $player = Player::whereRaw('LOWER(first_name) = ? AND LOWER(last_name) = ?', [strtolower($first), strtolower($last)])->first();
        if ($player) return $player;

        if ($dryRun) return null;

        $player = Player::create([
            'first_name' => $first,
            'last_name' => $last,
            'position' => 'unknown',
            'nationality' => $p['nationality'] ?? null,
            'external_source' => 'manual',
        ]);
        $stats['players_created']++;
        return $player;
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
}
