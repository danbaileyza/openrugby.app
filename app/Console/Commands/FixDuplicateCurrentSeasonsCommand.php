<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\Season;
use Illuminate\Console\Command;

/**
 * Some competitions have more than one season flagged is_current=true —
 * typically an empty orphan (e.g. Challenge Cup "2025") alongside the
 * real in-progress season ("2025-26"). This confuses anything that
 * resolves "the current season", most visibly the dashboard tiles.
 *
 * For each affected competition, keep the season that best represents
 * "current": most matches → highest completeness_score → highest label.
 * Everything else gets is_current=false.
 */
class FixDuplicateCurrentSeasonsCommand extends Command
{
    protected $signature = 'rugby:fix-duplicate-current-seasons
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Deduplicate seasons flagged is_current=true within a competition';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $dupedCompetitionIds = Season::query()
            ->where('is_current', true)
            ->selectRaw('competition_id, COUNT(*) as c')
            ->groupBy('competition_id')
            ->having('c', '>', 1)
            ->pluck('competition_id');

        if ($dupedCompetitionIds->isEmpty()) {
            $this->info('No competitions with duplicate is_current seasons.');

            return self::SUCCESS;
        }

        $this->line(sprintf('Found %d competition(s) with duplicate current seasons.', $dupedCompetitionIds->count()));

        $touched = 0;

        foreach ($dupedCompetitionIds as $competitionId) {
            $competition = Competition::find($competitionId);
            $this->line("\n<info>{$competition?->name}</info>");

            $currents = Season::where('competition_id', $competitionId)
                ->where('is_current', true)
                ->withCount('matches')
                ->get()
                ->sortByDesc(fn ($s) => [
                    $s->matches_count,
                    (int) ($s->completeness_score ?? 0),
                    $s->label,
                ])
                ->values();

            $winner = $currents->shift();
            $this->line(sprintf(
                '  keep   %-10s matches=%d score=%s',
                $winner->label,
                $winner->matches_count,
                $winner->completeness_score ?? '—'
            ));

            foreach ($currents as $loser) {
                $this->line(sprintf(
                    '  unflag %-10s matches=%d score=%s',
                    $loser->label,
                    $loser->matches_count,
                    $loser->completeness_score ?? '—'
                ));

                if (! $dryRun) {
                    // Use query builder to bypass the saving hook, which
                    // would otherwise re-unflag the winner we just picked.
                    Season::where('id', $loser->id)->update(['is_current' => false]);
                    $touched++;
                }
            }
        }

        if ($dryRun) {
            $this->warn("\nDry run — no changes written. Re-run without --dry-run to apply.");
        } else {
            $this->info("\nUpdated {$touched} season row(s).");
        }

        return self::SUCCESS;
    }
}
