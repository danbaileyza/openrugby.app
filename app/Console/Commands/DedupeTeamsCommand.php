<?php

namespace App\Console\Commands;

use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Schools end up duplicated when the schoolboyrugby importer creates a
 * short-name team ("Pearson") alongside an existing canonical full-name
 * team ("Pearson High School"). Once link-school-ids has set the same
 * external_id on both, this command merges them — moves all references
 * onto the canonical team and deletes the duplicate.
 *
 * "Canonical" is the team with the most match_teams rows; ties broken
 * by longer name (the explicit one is usually the original).
 */
class DedupeTeamsCommand extends Command
{
    protected $signature = 'rugby:dedupe-teams
                            {--type= : Limit to one team type (e.g. school)}
                            {--dry-run : Report only}';

    protected $description = 'Merge teams that share an external_id (e.g. short-name + full-name twins)';

    /** All tables with a team_id we need to repoint. */
    private array $teamRefs = [
        'team_season',
        'player_contracts',
        'match_teams',
        'match_events',
        'match_lineups',
        'match_stats',
        'match_officials',
        'player_match_stats',
        'standings',
        'team_user',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $type = $this->option('type');

        $groups = Team::query()
            ->when($type, fn ($q) => $q->where('type', $type))
            ->whereNotNull('external_id')
            ->whereNotNull('external_source')
            ->selectRaw('external_id, external_source, COUNT(*) as c')
            ->groupBy('external_id', 'external_source')
            ->having('c', '>', 1)
            ->get();

        $this->info("Duplicate external_id groups: {$groups->count()}");
        if ($groups->isEmpty()) {
            return self::SUCCESS;
        }

        $merged = 0;

        foreach ($groups as $group) {
            $teams = Team::where('external_id', $group->external_id)
                ->where('external_source', $group->external_source)
                ->withCount('matchTeams')
                ->get()
                ->sortByDesc(fn ($t) => [$t->match_teams_count, mb_strlen($t->name)])
                ->values();

            $canonical = $teams->shift();

            // Pick the best display name across the whole group: longest first,
            // tiebreak by lexical order. We keep the canonical row (most data),
            // but rename it to the most descriptive name in the group.
            $allNames = collect([$canonical->name])->merge($teams->pluck('name'));
            $bestName = $allNames->sortByDesc(fn ($n) => [mb_strlen($n), $n])->first();

            foreach ($teams as $dupe) {
                $this->line(sprintf(
                    '  merge  %-40s -> %s (external_id=%s)',
                    $dupe->name,
                    $bestName,
                    $group->external_id,
                ));

                if (! $dryRun) {
                    $this->mergeInto($dupe, $canonical);
                }
                $merged++;
            }

            if (! $dryRun && $bestName !== $canonical->name) {
                $canonical->update(['name' => $bestName]);
            }
        }

        $this->newLine();
        $this->table(['', 'Count'], [['Merged', $merged]]);

        if ($dryRun) {
            $this->warn('Dry run — no changes written.');
        }

        return self::SUCCESS;
    }

    /**
     * Repoint every team_id reference from $dupe to $canonical, then delete
     * $dupe. Wrapped in a transaction so a partial failure doesn't strand us.
     */
    private function mergeInto(Team $dupe, Team $canonical): void
    {
        DB::transaction(function () use ($dupe, $canonical) {
            foreach ($this->teamRefs as $table) {
                if (! \Schema::hasTable($table)) {
                    continue;
                }
                // Best-effort UPDATE; pivot tables with composite uniques may
                // throw if the canonical already has a row for the same key —
                // those orphan rows belong to the dupe and can just be dropped.
                try {
                    DB::table($table)->where('team_id', $dupe->id)->update(['team_id' => $canonical->id]);
                } catch (\Throwable $e) {
                    DB::table($table)->where('team_id', $dupe->id)->delete();
                }
            }
            $dupe->delete();
        });
    }
}
