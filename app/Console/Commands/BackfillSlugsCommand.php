<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\Player;
use App\Models\Referee;
use App\Models\RugbyMatch;
use App\Models\Team;
use Illuminate\Console\Command;

class BackfillSlugsCommand extends Command
{
    protected $signature = 'rugby:backfill-slugs
                            {--fresh : Regenerate slugs for every row, not just rows where slug is null}';

    protected $description = 'Backfill SEO slugs on competitions, teams, players, matches, and referees.';

    public function handle(): int
    {
        $fresh = $this->option('fresh');

        $this->fill('Competitions', Competition::class, $fresh);
        $this->fill('Teams', Team::class, $fresh);
        $this->fill('Players', Player::class, $fresh);
        $this->fill('Referees', Referee::class, $fresh);
        $this->fillMatches($fresh);

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function fill(string $label, string $class, bool $fresh): void
    {
        $query = $class::query();
        if (! $fresh) {
            $query->whereNull('slug');
        }

        $total = $query->count();
        if ($total === 0) {
            $this->info("{$label}: nothing to do.");

            return;
        }

        $this->info("{$label}: processing {$total}...");
        $bar = $this->output->createProgressBar($total);

        $query->chunkById(200, function ($rows) use ($bar, $fresh) {
            foreach ($rows as $row) {
                if ($fresh) {
                    $row->slug = null; // force regeneration via HasSlug::saving
                }
                $row->save();
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
    }

    private function fillMatches(bool $fresh): void
    {
        $query = RugbyMatch::query();
        if (! $fresh) {
            $query->whereNull('slug');
        }
        $total = $query->count();
        if ($total === 0) {
            $this->info('Matches: nothing to do.');

            return;
        }

        $this->info("Matches: processing {$total}...");
        $bar = $this->output->createProgressBar($total);

        $query->with('matchTeams.team')->chunkById(200, function ($rows) use ($bar, $fresh) {
            foreach ($rows as $row) {
                if ($fresh) {
                    $row->slug = null;
                }
                // Trigger saving event to pick up slug from matchTeams relation
                $row->save();
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
    }
}
