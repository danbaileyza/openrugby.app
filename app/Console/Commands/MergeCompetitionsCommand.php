<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\RugbyMatch;
use App\Models\Season;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MergeCompetitionsCommand extends Command
{
    protected $signature = 'rugby:merge-competitions
                            {keep : Competition ID to keep}
                            {remove : Competition ID to remove}
                            {--dry-run : Preview changes without writing}';

    protected $description = 'Merge one duplicate competition into another, including seasons and season-linked records';

    public function handle(): int
    {
        $keep = Competition::find($this->argument('keep'));
        $remove = Competition::find($this->argument('remove'));

        if (! $keep || ! $remove) {
            $this->error('Both keep and remove competition IDs must exist.');
            return self::FAILURE;
        }

        if ($keep->id === $remove->id) {
            $this->error('Keep and remove IDs must be different.');
            return self::FAILURE;
        }

        $this->line("Keep:   {$keep->id} | {$keep->name} ({$keep->code})");
        $this->line("Remove: {$remove->id} | {$remove->name} ({$remove->code})");

        $removeSeasonCount = Season::where('competition_id', $remove->id)->count();
        $removeMatchCount = RugbyMatch::whereIn(
            'season_id',
            Season::where('competition_id', $remove->id)->select('id')
        )->count();

        $this->line("Remove competition seasons: {$removeSeasonCount}");
        $this->line("Remove competition matches: {$removeMatchCount}");

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN — no changes made.');
            return self::SUCCESS;
        }

        if (! $this->confirm('Proceed with merge?')) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($keep, $remove): void {
            $removeSeasons = Season::where('competition_id', $remove->id)->get();

            foreach ($removeSeasons as $removeSeason) {
                $keepSeason = Season::where('competition_id', $keep->id)
                    ->where('label', $removeSeason->label)
                    ->first();

                if (! $keepSeason) {
                    $removeSeason->competition_id = $keep->id;
                    $removeSeason->save();
                    continue;
                }

                $this->mergeSeasonChildren($keepSeason->id, $removeSeason->id);
                $removeSeason->delete();
            }

            $remove->delete();
        });

        $this->info('Competition merge complete.');
        return self::SUCCESS;
    }

    protected function mergeSeasonChildren(string $keepSeasonId, string $removeSeasonId): void
    {
        RugbyMatch::where('season_id', $removeSeasonId)->update(['season_id' => $keepSeasonId]);

        $this->mergeConstrainedSeasonRows('team_season', $keepSeasonId, $removeSeasonId, ['team_id']);
        $this->mergeConstrainedSeasonRows('standings', $keepSeasonId, $removeSeasonId, ['team_id', 'pool']);
        $this->mergeConstrainedSeasonRows('player_season_stats', $keepSeasonId, $removeSeasonId, ['player_id', 'stat_key']);
    }

    /**
     * @param array<int, string> $uniqueColumns
     */
    protected function mergeConstrainedSeasonRows(string $table, string $keepSeasonId, string $removeSeasonId, array $uniqueColumns): void
    {
        $rows = DB::table($table)->where('season_id', $removeSeasonId)->get();

        foreach ($rows as $row) {
            $existsQuery = DB::table($table)->where('season_id', $keepSeasonId);

            foreach ($uniqueColumns as $column) {
                $existsQuery->where($column, $row->{$column});
            }

            if ($existsQuery->exists()) {
                DB::table($table)->where('id', $row->id)->delete();
                continue;
            }

            DB::table($table)->where('id', $row->id)->update([
                'season_id' => $keepSeasonId,
                'updated_at' => now(),
            ]);
        }
    }
}
