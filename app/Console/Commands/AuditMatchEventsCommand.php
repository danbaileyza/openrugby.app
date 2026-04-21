<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\MatchEvent;
use App\Models\RugbyMatch;
use Illuminate\Console\Command;

class AuditMatchEventsCommand extends Command
{
    protected $signature = 'rugby:audit-match-events
                            {--competition= : Competition code (default: all)}
                            {--season= : Season label (default: all seasons in competition)}
                            {--current : Only audit the current season}
                            {--limit=20 : Show this many worst matches}
                            {--min-diff=3 : Minimum points delta between score and events to flag}
                            {--fix : Re-run sync-live on the affected competitions to try fix gaps}';

    protected $description = 'Flag matches where score ≠ sum of events (tries×5 + conversions×2 + penalties×3 + drops×3)';

    public function handle(): int
    {
        $compCode = $this->option('competition');
        $minDiff = (int) $this->option('min-diff');

        $query = RugbyMatch::with(['matchTeams.team', 'events', 'season.competition'])
            ->whereIn('status', ['ft', 'live']);

        if ($compCode) {
            $query->whereHas('season.competition', fn ($q) => $q->where('code', $compCode));
        }
        if ($seasonLabel = $this->option('season')) {
            $query->whereHas('season', fn ($q) => $q->where('label', $seasonLabel));
        }
        if ($this->option('current')) {
            $query->whereHas('season', fn ($q) => $q->where('is_current', true));
        }

        $issues = [];
        foreach ($query->lazy() as $match) {
            foreach ($match->matchTeams as $mt) {
                if ($mt->score === null || ! $mt->team_id) continue;
                $teamEvents = $match->events->where('team_id', $mt->team_id);
                $calc = $teamEvents->where('type', 'try')->count() * 5
                      + $teamEvents->where('type', 'conversion')->count() * 2
                      + $teamEvents->where('type', 'penalty_goal')->count() * 3
                      + $teamEvents->where('type', 'drop_goal')->count() * 3;
                $diff = abs($mt->score - $calc);
                if ($diff >= $minDiff) {
                    $issues[] = [
                        'match_id' => $match->id,
                        'kickoff' => $match->kickoff->format('Y-m-d'),
                        'comp' => $match->season->competition->code,
                        'team' => $mt->team->name,
                        'score' => $mt->score,
                        'from_events' => $calc,
                        'diff' => $diff,
                    ];
                }
            }
        }

        usort($issues, fn ($a, $b) => $b['diff'] <=> $a['diff']);
        $issues = array_slice($issues, 0, (int) $this->option('limit'));

        if (empty($issues)) {
            $this->info('No issues found.');
            return self::SUCCESS;
        }

        $this->warn(count($issues).' matches with score/event mismatch:');
        $this->table(
            ['Match ID', 'Date', 'Comp', 'Team', 'Score', 'From events', 'Δ'],
            array_map(fn ($i) => array_values($i), $issues)
        );

        if ($this->option('fix')) {
            $comps = array_unique(array_column($issues, 'comp'));
            $this->info("\nRunning sync-live on ".count($comps).' competition(s) to try fill gaps...');
            foreach ($comps as $comp) {
                $this->call('rugby:sync-live', ['--competition' => $comp]);
            }
        }

        return self::SUCCESS;
    }
}
