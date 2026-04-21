<?php

namespace App\Console\Commands;

use App\Models\MatchLineup;
use App\Models\PlayerContract;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class BackfillPlayerContractsCommand extends Command
{
    protected $signature = 'rugby:backfill-player-contracts
                            {--player= : Only a specific player id}
                            {--dry-run}';

    protected $description = 'Create player contracts inferred from match lineups (international caps + missing club ties)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $playerFilter = $this->option('player');

        $query = MatchLineup::query()
            ->whereNotNull('player_id')
            ->whereNotNull('team_id');
        if ($playerFilter) $query->where('player_id', $playerFilter);

        $this->info('Scanning lineups…');

        $groups = $query
            ->selectRaw('player_id, team_id, MIN(matches.kickoff) as first_app, MAX(matches.kickoff) as last_app, COUNT(*) as apps')
            ->join('matches', 'matches.id', '=', 'match_lineups.match_id')
            ->groupBy('player_id', 'team_id')
            ->get();

        $this->info("  {$groups->count()} (player, team) pairs found.");

        $created = 0;
        $skipped = 0;
        $recentThreshold = now()->subMonths(18);

        foreach ($groups as $g) {
            $exists = PlayerContract::where('player_id', $g->player_id)
                ->where('team_id', $g->team_id)
                ->exists();
            if ($exists) { $skipped++; continue; }

            $first = Carbon::parse($g->first_app);
            $last = Carbon::parse($g->last_app);
            $isCurrent = $last->gte($recentThreshold);

            if (! $dryRun) {
                PlayerContract::create([
                    'player_id' => $g->player_id,
                    'team_id' => $g->team_id,
                    'from_date' => $first->toDateString(),
                    'to_date' => $isCurrent ? null : $last->toDateString(),
                    'is_current' => $isCurrent,
                    'external_source' => 'inferred_from_lineups',
                ]);
            }
            $created++;
        }

        $this->table(['Metric', 'Count'], [
            ['contracts created', $created],
            ['skipped (already had)', $skipped],
        ]);
        return self::SUCCESS;
    }
}
