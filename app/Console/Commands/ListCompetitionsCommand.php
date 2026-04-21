<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\Season;
use Illuminate\Console\Command;

class ListCompetitionsCommand extends Command
{
    protected $signature = 'rugby:competitions
                            {--current : Only show competitions with a current season}
                            {--search= : Filter by name}';

    protected $description = 'List competitions and their seasons from the database';

    public function handle(): int
    {
        $query = Competition::with(['seasons' => fn ($q) => $q->orderByDesc('start_date')]);

        if ($this->option('current')) {
            $query->whereHas('seasons', fn ($q) => $q->where('is_current', true));
        }

        if ($search = $this->option('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        $competitions = $query->orderBy('name')->get();

        $rows = [];
        foreach ($competitions as $comp) {
            $currentSeason = $comp->seasons->firstWhere('is_current', true);
            $rows[] = [
                $comp->external_id,
                $comp->name,
                $comp->format,
                $comp->country ?? 'International',
                $currentSeason?->label ?? '—',
                $comp->seasons->count(),
            ];
        }

        $this->table(
            ['API ID', 'Name', 'Format', 'Country', 'Current Season', 'Total Seasons'],
            $rows,
        );

        $this->info("Total: {$competitions->count()} competitions, " . Season::count() . " seasons (" . Season::where('is_current', true)->count() . " current)");

        return self::SUCCESS;
    }
}
