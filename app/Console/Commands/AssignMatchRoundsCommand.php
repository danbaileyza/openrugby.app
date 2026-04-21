<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\RugbyMatch;
use App\Models\Season;
use Illuminate\Console\Command;

class AssignMatchRoundsCommand extends Command
{
    protected $signature = 'rugby:assign-rounds
                            {--competition= : Competition code (required)}
                            {--season= : Season label (defaults to current)}
                            {--pool-rounds=4 : Number of pool stage rounds}
                            {--gap-days=5 : Minimum days between rounds to split them}
                            {--dry-run : Preview without writing}';

    protected $description = 'Assign round numbers to matches by date grouping (for competitions where source data lacks rounds)';

    public function handle(): int
    {
        $code = $this->option('competition');
        if (! $code) {
            $this->error('--competition is required.');
            return self::FAILURE;
        }

        $competition = Competition::where('code', $code)->first();
        if (! $competition) {
            $this->error("Competition not found: {$code}");
            return self::FAILURE;
        }

        $seasonLabel = $this->option('season');
        $season = $seasonLabel
            ? $competition->seasons()->where('label', $seasonLabel)->first()
            : ($competition->currentSeason() ?? $competition->seasons()->orderByDesc('label')->first());

        if (! $season) {
            $this->error('Season not found.');
            return self::FAILURE;
        }

        $poolRounds = (int) $this->option('pool-rounds');
        $gapDays = (int) $this->option('gap-days');
        $dryRun = (bool) $this->option('dry-run');

        $this->info("{$competition->name} {$season->label}");
        if ($dryRun) {
            $this->info('DRY RUN mode.');
        }

        // Load all matches ordered by date
        $matches = RugbyMatch::where('season_id', $season->id)
            ->whereNotNull('kickoff')
            ->with('matchTeams.team')
            ->orderBy('kickoff')
            ->get();

        if ($matches->isEmpty()) {
            $this->warn('No matches found.');
            return self::SUCCESS;
        }

        // Group matches into rounds by date proximity
        $rounds = [];
        $currentRound = [$matches->first()];

        for ($i = 1; $i < $matches->count(); $i++) {
            $prev = $matches[$i - 1];
            $curr = $matches[$i];
            $daysBetween = $prev->kickoff->diffInDays($curr->kickoff);

            if ($daysBetween >= $gapDays) {
                $rounds[] = $currentRound;
                $currentRound = [];
            }
            $currentRound[] = $curr;
        }
        $rounds[] = $currentRound;

        $this->info('Detected ' . count($rounds) . " round(s) (gap threshold: {$gapDays} days)");
        $this->newLine();

        $updated = 0;

        foreach ($rounds as $i => $roundMatches) {
            $roundNum = $i + 1;
            $isPool = $roundNum <= $poolRounds;

            // Determine stage for knockout rounds
            $knockoutCount = count($roundMatches);
            $stage = match (true) {
                $isPool => 'pool',
                $knockoutCount >= 7 => 'round_of_16',
                $knockoutCount >= 4 => 'quarter_finals',
                $knockoutCount >= 2 && $knockoutCount <= 3 => 'semi_finals',
                $knockoutCount === 1 => 'final',
                default => null,
            };

            $assignRound = $isPool ? $roundNum : null;
            $dateRange = $roundMatches[0]->kickoff->format('M d') . ' - ' . end($roundMatches)->kickoff->format('M d');

            $label = $isPool ? "Round {$roundNum}" : ucwords(str_replace('_', ' ', $stage ?? 'unknown'));
            $this->line("  {$label}: {$knockoutCount} matches ({$dateRange})");

            foreach ($roundMatches as $match) {
                $home = $match->matchTeams->firstWhere('side', 'home');
                $away = $match->matchTeams->firstWhere('side', 'away');
                $existing = $match->round ? " (was Rd {$match->round})" : '';

                $changes = [];
                if ($match->round === null && $assignRound !== null) {
                    $changes['round'] = $assignRound;
                }
                if ($match->stage === null && $stage !== null) {
                    $changes['stage'] = $stage;
                }

                if (! empty($changes)) {
                    if (! $dryRun) {
                        $match->update($changes);
                    }
                    $updated++;
                    $changeStr = collect($changes)->map(fn ($v, $k) => "{$k}={$v}")->implode(', ');
                    $this->line("    {$home?->team->name} vs {$away?->team->name} → {$changeStr}{$existing}");
                }
            }
        }

        $this->newLine();
        $this->info($dryRun ? "{$updated} matches would be updated." : "{$updated} matches updated.");

        return self::SUCCESS;
    }
}
