<?php

namespace App\Console\Commands;

use App\Models\RugbyMatch;
use Illuminate\Console\Command;

class AuditMatchCompletenessCommand extends Command
{
    protected $signature = 'rugby:audit-match-completeness
                            {--source= : Filter by external source (espn, allrugby, api_sports, urc)}
                            {--only-missing : Show only matches with missing data}
                            {--limit=25 : Limit rows shown in missing match output}';

    protected $description = 'Audit completeness of match details (score, result, teams, lineups, overview, key stats, officials)';

    public function handle(): int
    {
        $source = $this->option('source');
        $onlyMissing = (bool) $this->option('only-missing');
        $limit = max(1, (int) $this->option('limit'));

        $query = RugbyMatch::query()->with([
            'matchTeams.team',
            'lineups',
            'events',
            'matchStats',
            'officials',
        ]);

        if ($source) {
            $query->where('external_source', $source);
        }

        $matches = $query->get();

        if ($matches->isEmpty()) {
            $this->warn('No matches found for the selected filters.');
            return self::SUCCESS;
        }

        $summary = [
            'total' => 0,
            'missing_score' => 0,
            'missing_result' => 0,
            'missing_teams' => 0,
            'missing_lineups' => 0,
            'missing_overview' => 0,
            'missing_key_stats' => 0,
            'missing_officials' => 0,
            'complete' => 0,
        ];

        $bySource = [];
        $missingRows = [];

        foreach ($matches as $match) {
            $summary['total']++;

            $sourceKey = $match->external_source ?: 'unknown';
            if (! isset($bySource[$sourceKey])) {
                $bySource[$sourceKey] = [
                    'total' => 0,
                    'complete' => 0,
                    'missing_score' => 0,
                    'missing_result' => 0,
                    'missing_teams' => 0,
                    'missing_lineups' => 0,
                    'missing_overview' => 0,
                    'missing_key_stats' => 0,
                    'missing_officials' => 0,
                ];
            }
            $bySource[$sourceKey]['total']++;

            $home = $match->matchTeams->firstWhere('side', 'home');
            $away = $match->matchTeams->firstWhere('side', 'away');
            $homeScore = $home?->score;
            $awayScore = $away?->score;
            $homeLineups = $home ? $match->lineups->where('team_id', $home->team_id)->count() : 0;
            $awayLineups = $away ? $match->lineups->where('team_id', $away->team_id)->count() : 0;

            $missing = [];

            if ($homeScore === null || $awayScore === null) {
                $summary['missing_score']++;
                $bySource[$sourceKey]['missing_score']++;
                $missing[] = 'score';
            }

            if (! $this->hasResult($home, $away)) {
                $summary['missing_result']++;
                $bySource[$sourceKey]['missing_result']++;
                $missing[] = 'result';
            }

            if (! $home || ! $away) {
                $summary['missing_teams']++;
                $bySource[$sourceKey]['missing_teams']++;
                $missing[] = 'teams';
            }

            if ($homeLineups === 0 || $awayLineups === 0) {
                $summary['missing_lineups']++;
                $bySource[$sourceKey]['missing_lineups']++;
                $missing[] = 'lineups';
            }

            if ($match->events->count() === 0) {
                $summary['missing_overview']++;
                $bySource[$sourceKey]['missing_overview']++;
                $missing[] = 'overview';
            }

            if ($match->matchStats->count() === 0) {
                $summary['missing_key_stats']++;
                $bySource[$sourceKey]['missing_key_stats']++;
                $missing[] = 'key_stats';
            }

            if ($match->officials->count() === 0) {
                $summary['missing_officials']++;
                $bySource[$sourceKey]['missing_officials']++;
                $missing[] = 'officials';
            }

            if (empty($missing)) {
                $summary['complete']++;
                $bySource[$sourceKey]['complete']++;
                continue;
            }

            if (count($missingRows) < $limit) {
                $missingRows[] = [
                    (string) $match->id,
                    (string) ($match->external_source ?: '-'),
                    (string) ($match->external_id ?: '-'),
                    (string) ($home?->team?->name ?: '-'),
                    (string) ($away?->team?->name ?: '-'),
                    implode(',', $missing),
                ];
            }
        }

        $this->newLine();
        $this->info('Match completeness summary');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total matches', $summary['total']],
                ['Complete matches', $summary['complete']],
                ['Missing score', $summary['missing_score']],
                ['Missing result', $summary['missing_result']],
                ['Missing teams', $summary['missing_teams']],
                ['Missing side lineups', $summary['missing_lineups']],
                ['Missing overview', $summary['missing_overview']],
                ['Missing key stats', $summary['missing_key_stats']],
                ['Missing officials', $summary['missing_officials']],
            ]
        );

        ksort($bySource);
        $sourceRows = [];
        foreach ($bySource as $key => $row) {
            $sourceRows[] = [
                $key,
                $row['total'],
                $row['complete'],
                $row['missing_score'],
                $row['missing_result'],
                $row['missing_teams'],
                $row['missing_lineups'],
                $row['missing_overview'],
                $row['missing_key_stats'],
                $row['missing_officials'],
            ];
        }

        $this->newLine();
        $this->info('By source');
        $this->table(
            ['Source', 'Total', 'Complete', 'Missing Score', 'Missing Result', 'Missing Teams', 'Missing Lineups', 'Missing Overview', 'Missing Key Stats', 'Missing Officials'],
            $sourceRows
        );

        if (! empty($missingRows)) {
            $this->newLine();
            $heading = $onlyMissing
                ? "Sample missing matches (limit {$limit})"
                : "Sample matches with missing fields (limit {$limit})";
            $this->info($heading);
            $this->table(
                ['Match UUID', 'Source', 'External ID', 'Home', 'Away', 'Missing'],
                $missingRows
            );
        }

        return self::SUCCESS;
    }

    private function hasResult($home, $away): bool
    {
        if (! $home || ! $away) {
            return false;
        }

        if ($home->score === null || $away->score === null) {
            return false;
        }

        if ($home->score > $away->score) {
            return $home->is_winner === true;
        }

        if ($away->score > $home->score) {
            return $away->is_winner === true;
        }

        // Draw: neither side should be marked winner
        return $home->is_winner !== true && $away->is_winner !== true;
    }
}
