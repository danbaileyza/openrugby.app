<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\Season;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * API-Sports sync creates single-year season labels (e.g. "2025") that
 * sit alongside the real span-year ones we import elsewhere ("2025-26").
 * The orphans never get matches, standings, or team rosters attached,
 * so they pollute pickers like the dashboard's featuredSeason.
 *
 * "Empty" means: no matches, no standings, no team-seasons, no player
 * season stats. The four-table check is paranoid on purpose — we only
 * want to drop seasons that nothing references.
 */
class CleanupEmptySeasonsCommand extends Command
{
    protected $signature = 'rugby:cleanup-empty-seasons
                            {--force : Actually delete (default is dry-run)}
                            {--competition= : Limit to one competition code}';

    protected $description = 'Delete seasons that have no matches, standings, team-seasons, or player stats';

    public function handle(): int
    {
        $apply = (bool) $this->option('force');
        $compCode = $this->option('competition');

        $query = Season::query()
            ->doesntHave('matches')
            ->doesntHave('standings')
            ->doesntHave('teams')
            ->doesntHave('playerSeasonStats')
            ->with('competition');

        if ($compCode) {
            $query->whereHas('competition', fn ($q) => $q->where('code', $compCode));
        }

        $empties = $query->get();

        if ($empties->isEmpty()) {
            $this->info('No empty seasons found.');

            return self::SUCCESS;
        }

        // Group per competition for readable output.
        $byComp = $empties->groupBy(fn ($s) => $s->competition?->name ?? '(orphaned)')
            ->sortKeys();

        $this->line(sprintf(
            "Found <info>%d</info> empty season(s) across <info>%d</info> competition(s).\n",
            $empties->count(),
            $byComp->count(),
        ));

        foreach ($byComp as $compName => $seasons) {
            $this->line("<info>{$compName}</info> — ".$seasons->count());
            $labels = $seasons->pluck('label')->sort()->values()->all();
            $this->line('  '.implode(', ', $labels));
        }

        $this->newLine();

        if (! $apply) {
            $this->warn('Dry run — no changes written. Re-run with --force to delete.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Delete {$empties->count()} season(s)?", false)) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        $deleted = DB::transaction(fn () => Season::whereIn('id', $empties->pluck('id'))->delete());
        $this->info("Deleted {$deleted} season row(s).");

        return self::SUCCESS;
    }
}
