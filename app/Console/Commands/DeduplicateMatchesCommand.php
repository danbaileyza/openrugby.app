<?php

namespace App\Console\Commands;

use App\Models\MatchEvent;
use App\Models\MatchLineup;
use App\Models\MatchOfficial;
use App\Models\MatchStat;
use App\Models\MatchTeam;
use App\Models\PlayerMatchStat;
use App\Models\RugbyMatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeduplicateMatchesCommand extends Command
{
    protected $signature = 'rugby:deduplicate-matches {--dry-run : Show duplicates without deleting}';

    protected $description = 'Find and remove duplicate matches (same kickoff + teams)';

    public function handle(): int
    {
        $this->info('Scanning for duplicate matches...');

        $matches = RugbyMatch::with('matchTeams.team')
            ->orderBy('kickoff')
            ->get();

        $seen = [];
        $duplicates = collect();

        foreach ($matches as $match) {
            $home = $match->matchTeams->firstWhere('side', 'home');
            $away = $match->matchTeams->firstWhere('side', 'away');

            if (! $home || ! $away) {
                continue;
            }

            // Key: date + home team + away team
            $key = implode('|', [
                $match->kickoff?->format('Y-m-d'),
                $home->team_id,
                $away->team_id,
            ]);

            if (isset($seen[$key])) {
                $existing = $seen[$key];

                // Keep the richer record: more child data wins, then round number, then first inserted
                $keep = $this->pickRicher($existing, $match);
                $remove = $keep->id === $existing->id ? $match : $existing;

                $seen[$key] = $keep;
                $duplicates->push(compact('keep', 'remove'));
            } else {
                $seen[$key] = $match;
            }
        }

        if ($duplicates->isEmpty()) {
            $this->info('No duplicates found.');
            return 0;
        }

        $this->info("Found {$duplicates->count()} duplicate(s):");
        $this->newLine();

        foreach ($duplicates as $dup) {
            $keep = $dup['keep'];
            $remove = $dup['remove'];
            $home = $remove->matchTeams->firstWhere('side', 'home');
            $away = $remove->matchTeams->firstWhere('side', 'away');

            $keepEvents = $keep->events()->count();
            $removeEvents = $remove->events()->count();

            $this->line(sprintf(
                '  %s | %s vs %s | %s-%s',
                $remove->kickoff?->format('Y-m-d'),
                $home?->team->name ?? 'TBD',
                $away?->team->name ?? 'TBD',
                $home?->score ?? '?',
                $away?->score ?? '?',
            ));
            $this->line(sprintf(
                '    Keep: %s (%s, %d events) | Remove: %s (%s, %d events)',
                $keep->id,
                $keep->external_source ?? 'unknown',
                $keepEvents,
                $remove->id,
                $remove->external_source ?? 'unknown',
                $removeEvents,
            ));
        }

        $this->newLine();

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN — no changes made.');
            return 0;
        }

        if (! $this->confirm("Remove {$duplicates->count()} duplicate matches?")) {
            return 0;
        }

        $totalDeleted = 0;
        foreach ($duplicates as $dup) {
            $keep = $dup['keep'];
            $remove = $dup['remove'];

            DB::transaction(function () use ($keep, $remove): void {
                // Copy missing metadata from the removed match
                $this->enrichFromDuplicate($keep, $remove);

                $this->mergeMatchChildren($keep->id, $remove->id);
                MatchTeam::where('match_id', $remove->id)->delete();
                RugbyMatch::where('id', $remove->id)->delete();
            });

            $totalDeleted++;
        }

        $this->info("{$totalDeleted} duplicate matches removed.");
        return 0;
    }

    /**
     * Pick the richer of two duplicate matches to keep.
     * Prefers: more child records > has round number > earlier insertion.
     */
    /**
     * Copy missing metadata fields from the duplicate into the kept match.
     */
    protected function enrichFromDuplicate(RugbyMatch $keep, RugbyMatch $remove): void
    {
        $updates = [];

        foreach (['round', 'stage', 'venue_id', 'attendance'] as $field) {
            if ($keep->{$field} === null && $remove->{$field} !== null) {
                $updates[$field] = $remove->{$field};
            }
        }

        if (! empty($updates)) {
            $keep->update($updates);
        }
    }

    /**
     * Pick the richer of two duplicate matches to keep.
     * Prefers: more child records > has round number > earlier insertion.
     */
    protected function pickRicher(RugbyMatch $a, RugbyMatch $b): RugbyMatch
    {
        $scoreA = $a->events()->count()
            + MatchLineup::where('match_id', $a->id)->count()
            + MatchOfficial::where('match_id', $a->id)->count()
            + MatchStat::where('match_id', $a->id)->count();

        $scoreB = $b->events()->count()
            + MatchLineup::where('match_id', $b->id)->count()
            + MatchOfficial::where('match_id', $b->id)->count()
            + MatchStat::where('match_id', $b->id)->count();

        if ($scoreA !== $scoreB) {
            return $scoreA >= $scoreB ? $a : $b;
        }

        // Prefer the one with a round number
        if ($a->round !== null && $b->round === null) {
            return $a;
        }
        if ($b->round !== null && $a->round === null) {
            return $b;
        }

        // Fall back to first inserted
        return $a;
    }

    protected function mergeMatchChildren(string $keepId, string $removeId): void
    {
        MatchEvent::where('match_id', $removeId)->update(['match_id' => $keepId]);

        $this->mergeConstrainedChildRows(MatchLineup::class, $keepId, $removeId, ['player_id']);
        $this->mergeConstrainedChildRows(MatchOfficial::class, $keepId, $removeId, ['referee_id', 'role']);
        $this->mergeConstrainedChildRows(MatchStat::class, $keepId, $removeId, ['team_id', 'stat_key']);
        $this->mergeConstrainedChildRows(PlayerMatchStat::class, $keepId, $removeId, ['player_id', 'stat_key']);
    }

    /**
     * @param class-string<Model> $modelClass
     * @param array<int, string> $uniqueColumns
     */
    protected function mergeConstrainedChildRows(string $modelClass, string $keepId, string $removeId, array $uniqueColumns): void
    {
        $rows = $modelClass::query()->where('match_id', $removeId)->get();

        foreach ($rows as $row) {
            $existsQuery = $modelClass::query()->where('match_id', $keepId);

            foreach ($uniqueColumns as $column) {
                $existsQuery->where($column, $row->{$column});
            }

            if ($existsQuery->exists()) {
                $row->delete();
                continue;
            }

            $row->match_id = $keepId;
            $row->save();
        }
    }
}
