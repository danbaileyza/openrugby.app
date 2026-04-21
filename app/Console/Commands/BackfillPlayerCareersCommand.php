<?php

namespace App\Console\Commands;

use App\Models\MatchLineup;
use App\Models\Player;
use App\Models\PlayerContract;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillPlayerCareersCommand extends Command
{
    protected $signature = 'rugby:backfill-careers
                            {--player= : Only backfill a specific player UUID}
                            {--min-matches=2 : Ignore team stints with fewer than N matches}
                            {--current-days=180 : A team counts as "current" if the player played within the last N days}
                            {--include-national : Also create contracts for national teams (default: club only)}
                            {--dry-run : Preview without writing}';

    protected $description = 'Generate player career contracts from match lineup history';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $minMatches = (int) $this->option('min-matches');
        $currentDays = (int) $this->option('current-days');
        $includeNational = (bool) $this->option('include-national');

        $playerQuery = Player::query();
        if ($this->option('player')) {
            $playerQuery->where('id', $this->option('player'));
        }

        $total = $playerQuery->count();
        $this->info("Processing {$total} players...");
        if ($dryRun) $this->info('DRY RUN mode.');

        $bar = $this->output->createProgressBar($total);
        $careersCreated = 0;
        $careersUpdated = 0;

        $playerQuery->chunkById(200, function ($players) use (&$careersCreated, &$careersUpdated, $minMatches, $currentDays, $includeNational, $dryRun, $bar) {
            foreach ($players as $player) {
                // Get all lineups for this player, grouped by team, with match dates
                $lineups = MatchLineup::where('player_id', $player->id)
                    ->join('matches', 'matches.id', '=', 'match_lineups.match_id')
                    ->join('teams', 'teams.id', '=', 'match_lineups.team_id')
                    ->select('match_lineups.team_id', 'teams.type as team_type', 'matches.kickoff')
                    ->get();

                if ($lineups->isEmpty()) {
                    $bar->advance();
                    continue;
                }

                // Filter out national teams unless flagged
                if (! $includeNational) {
                    $lineups = $lineups->filter(fn ($l) => $l->team_type !== 'national');
                }

                $byTeam = $lineups->groupBy('team_id');

                foreach ($byTeam as $teamId => $group) {
                    if ($group->count() < $minMatches) {
                        continue;
                    }

                    $fromDate = $group->pluck('kickoff')->filter()->min();
                    $toDate = $group->pluck('kickoff')->filter()->max();
                    $isCurrent = $toDate && now()->diffInDays($toDate, false) >= -$currentDays;

                    $existing = PlayerContract::where('player_id', $player->id)
                        ->where('team_id', $teamId)
                        ->first();

                    if ($existing) {
                        $updates = [];
                        if ($existing->from_date === null || $existing->from_date > $fromDate) {
                            $updates['from_date'] = $fromDate;
                        }
                        // Set to_date if not current; otherwise leave null / update if later
                        if (! $isCurrent) {
                            if ($existing->to_date === null || $existing->to_date < $toDate) {
                                $updates['to_date'] = $toDate;
                            }
                            if ($existing->is_current) {
                                $updates['is_current'] = false;
                            }
                        } else {
                            $updates['to_date'] = null;
                            $updates['is_current'] = true;
                        }

                        if (! empty($updates) && ! $dryRun) {
                            $existing->update($updates);
                            $careersUpdated++;
                        }
                    } else {
                        if (! $dryRun) {
                            PlayerContract::create([
                                'player_id' => $player->id,
                                'team_id' => $teamId,
                                'from_date' => $fromDate,
                                'to_date' => $isCurrent ? null : $toDate,
                                'is_current' => $isCurrent,
                            ]);
                        }
                        $careersCreated++;
                    }
                }

                $bar->advance();
            }
            gc_collect_cycles();
        });

        $bar->finish();
        $this->newLine(2);

        $this->table(['Metric', 'Count'], [
            ['Players processed', $total],
            ['Contracts created', $careersCreated],
            ['Contracts updated', $careersUpdated],
        ]);

        return self::SUCCESS;
    }
}
