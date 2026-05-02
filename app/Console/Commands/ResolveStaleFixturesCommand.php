<?php

namespace App\Console\Commands;

use App\Models\RugbyMatch;
use Illuminate\Console\Command;

/**
 * School fixtures get rearranged a lot — a planned Pearson vs Drostdy
 * match might not happen because Pearson's bus broke down and they
 * ended up playing Mali XV instead. The scrapers correctly create the
 * NEW match (Pearson vs Mali XV, ft) but leave the PLANNED one
 * (Pearson vs Drostdy, scheduled) orphaned.
 *
 * This command marks an old scheduled fixture as postponed when:
 *   - kickoff was at least N days ago (default 3)
 *   - either team has at least one ft match within ±1 day vs a
 *     different opponent
 *
 * Pretty conservative on purpose — better to leave a fixture pending
 * than to wrongly postpone a real upcoming match.
 */
class ResolveStaleFixturesCommand extends Command
{
    protected $signature = 'rugby:resolve-stale-fixtures
                            {--days=3 : Minimum age in days before considering a match stale}
                            {--competition= : Limit to one competition code}
                            {--dry-run : Report only}';

    protected $description = 'Mark stale scheduled fixtures as postponed when the teams clearly played other opponents';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $query = RugbyMatch::with(['matchTeams.team', 'season.competition'])
            ->where('status', 'scheduled')
            ->where('kickoff', '<', $cutoff);

        if ($this->option('competition')) {
            $query->whereHas('season.competition', fn ($q) => $q->where('code', $this->option('competition')));
        }

        $stale = $query->get();
        $this->info("Stale scheduled fixtures (>{$days}d old): {$stale->count()}");

        $marked = 0;
        $kept = 0;

        foreach ($stale as $match) {
            $teamIds = $match->matchTeams->pluck('team_id')->all();
            if (count($teamIds) < 2) {
                continue;
            }

            // Did either team play a *different* opponent within ±1 day?
            $playedElsewhere = false;
            foreach ($teamIds as $tid) {
                $other = RugbyMatch::where('id', '!=', $match->id)
                    ->where('status', 'ft')
                    ->whereBetween('kickoff', [$match->kickoff->copy()->subDay(), $match->kickoff->copy()->addDay()])
                    ->whereHas('matchTeams', fn ($q) => $q->where('team_id', $tid))
                    ->whereDoesntHave('matchTeams', fn ($q) => $q->whereIn('team_id', array_diff($teamIds, [$tid])))
                    ->first();
                if ($other) {
                    $playedElsewhere = true;
                    break;
                }
            }

            if (! $playedElsewhere) {
                $kept++;
                continue;
            }

            $home = $match->matchTeams->firstWhere('side', 'home');
            $away = $match->matchTeams->firstWhere('side', 'away');
            $this->line(sprintf('  postpone  %s | %-30s vs %-30s', $match->kickoff->toDateString(), $home?->team?->name ?? '?', $away?->team?->name ?? '?'));

            if (! $dryRun) {
                $match->update(['status' => 'postponed']);
            }
            $marked++;
        }

        $this->newLine();
        $this->table(['', 'Count'], [
            ['Marked postponed', $marked],
            ['Left as scheduled', $kept],
        ]);

        if ($dryRun) {
            $this->warn('Dry run — no changes written.');
        }

        return self::SUCCESS;
    }
}
