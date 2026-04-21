<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\MatchEvent;
use App\Models\MatchLineup;
use App\Models\MatchOfficial;
use App\Models\MatchTeam;
use App\Models\RugbyMatch;
use App\Models\Season;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditSeasonCompletenessCommand extends Command
{
    protected $signature = 'rugby:audit-season
                            {--competition= : Competition code}
                            {--season= : Season label}
                            {--all : Audit all seasons with matches}
                            {--verify-above=95 : Auto-verify seasons scoring at or above this percentage}
                            {--reset-verified : Clear existing is_verified flags}
                            {--dry-run : Show audit results without saving}';

    protected $description = 'Audit each season for data completeness (fixtures, results, lineups, events, officials)';

    public function handle(): int
    {
        $seasons = $this->resolveSeasons();

        if ($seasons->isEmpty()) {
            $this->warn('No seasons found.');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $verifyThreshold = (int) $this->option('verify-above');

        if ($this->option('reset-verified')) {
            Season::where('is_verified', true)->update(['is_verified' => false, 'verified_at' => null]);
            $this->info('Reset verified flags on all seasons.');
        }

        $results = [];

        foreach ($seasons as $season) {
            $season->loadMissing('competition');
            $audit = $this->auditSeason($season);

            $results[] = [
                'competition' => $season->competition->name,
                'season' => $season->label,
                'score' => $audit['score'],
                'matches' => $audit['total_matches'],
                'verified' => $audit['score'] >= $verifyThreshold ? 'Yes' : '',
            ];

            if (! $dryRun) {
                $season->update([
                    'completeness_score' => $audit['score'],
                    'completeness_audit' => $audit['details'],
                    'is_verified' => $audit['score'] >= $verifyThreshold,
                    'verified_at' => $audit['score'] >= $verifyThreshold ? now() : null,
                ]);
            }

            if ($this->option('season') || $this->option('competition')) {
                $this->renderAuditDetail($season, $audit);
            }
        }

        if (count($results) > 1) {
            $this->newLine();
            $this->info('=== Audit Summary ===');
            $this->table(['Competition', 'Season', 'Score %', 'Matches', 'Verified'], $results);
        }

        if ($dryRun) {
            $this->warn('Dry run — no data saved.');
        }

        return self::SUCCESS;
    }

    private function auditSeason(Season $season): array
    {
        $matchIds = RugbyMatch::where('season_id', $season->id)->pluck('id');
        $total = $matchIds->count();

        if ($total === 0) {
            return [
                'score' => 0,
                'total_matches' => 0,
                'details' => ['status' => 'empty_season'],
            ];
        }

        $completed = RugbyMatch::whereIn('id', $matchIds)->where('status', 'ft')->pluck('id');
        $completedCount = $completed->count();

        // Completeness dimensions
        $withTeams = MatchTeam::whereIn('match_id', $matchIds)
            ->whereNotNull('team_id')
            ->select('match_id')
            ->selectRaw('COUNT(*) as cnt')
            ->groupBy('match_id')
            ->havingRaw('COUNT(*) = 2')
            ->count();

        $withScore = MatchTeam::whereIn('match_id', $completed)
            ->whereNotNull('score')
            ->select('match_id')
            ->selectRaw('COUNT(*) as cnt')
            ->groupBy('match_id')
            ->havingRaw('COUNT(*) = 2')
            ->count();

        $withLineups = MatchLineup::whereIn('match_id', $completed)
            ->distinct('match_id')
            ->count('match_id');

        $withEvents = MatchEvent::whereIn('match_id', $completed)
            ->distinct('match_id')
            ->count('match_id');

        $withOfficials = MatchOfficial::whereIn('match_id', $completed)
            ->distinct('match_id')
            ->count('match_id');

        // Expected matches — saved per season, or inferred from typical season size
        $expectedMatches = $season->expected_matches ?? $this->inferExpectedMatches($season);

        // Coverage = how many matches we have vs how many we should have
        $coverage = $expectedMatches > 0 ? min(100, round(($total / $expectedMatches) * 100, 1)) : 100;

        // Calculate dimension scores (0-100 each)
        $dimensions = [
            'coverage' => $coverage,
            'teams' => $total > 0 ? round(($withTeams / $total) * 100, 1) : 0,
            'scores' => $completedCount > 0 ? round(($withScore / $completedCount) * 100, 1) : 100,
            'lineups' => $completedCount > 0 ? round(($withLineups / $completedCount) * 100, 1) : 100,
            'events' => $completedCount > 0 ? round(($withEvents / $completedCount) * 100, 1) : 100,
            'officials' => $completedCount > 0 ? round(($withOfficials / $completedCount) * 100, 1) : 100,
        ];

        $weights = [
            'coverage' => 0.20,  // Match count vs expected
            'teams' => 0.10,
            'scores' => 0.20,
            'lineups' => 0.10,
            'events' => 0.15,
            'officials' => 0.25,
        ];

        $score = 0;
        foreach ($dimensions as $dim => $value) {
            $score += $value * $weights[$dim];
        }
        $score = round($score);

        // Hard caps — any major dimension <50% caps the total
        if ($dimensions['officials'] < 50 && $completedCount > 0) {
            $score = min($score, 75);
        }
        if ($dimensions['coverage'] < 80) {
            $score = min($score, 70);
        }

        return [
            'score' => $score,
            'total_matches' => $total,
            'expected_matches' => $expectedMatches,
            'details' => [
                'total_matches' => $total,
                'expected_matches' => $expectedMatches,
                'completed_matches' => $completedCount,
                'scheduled_matches' => $total - $completedCount,
                'dimensions' => $dimensions,
                'counts' => [
                    'with_both_teams' => $withTeams,
                    'with_scores' => $withScore,
                    'with_lineups' => $withLineups,
                    'with_events' => $withEvents,
                    'with_officials' => $withOfficials,
                ],
                'weights' => $weights,
            ],
        ];
    }

    /**
     * Infer expected total matches for a season based on the competition.
     * Returns null when we can't infer (e.g. test series).
     */
    private function inferExpectedMatches(Season $season): int
    {
        $code = $season->competition->code;

        // Known competition formats — total matches per full season including playoffs
        $expected = [
            'urc' => 144,                  // 16 teams × 18 pool rounds + 8 knockout
            'top14' => 188,                // 14 teams × 26 + 6 finals
            'premiership' => 91,           // 10 teams × 18 + 7 finals
            'champions_cup' => 63,         // pools + KO
            'challenge_cup' => 56,
            'super_rugby' => 96,           // varies but ~96 modern format
            'six_nations' => 15,           // 5 rounds × 3 matches
            'rugby_championship' => 12,
            'world_cup' => 48,
            'pro_d2' => 244,               // 16 teams × 30 + 6 finals
            'premiership_rugby_cup' => 32, // ~32 cup matches per season (pools + knockouts)
            'autumn_internationals' => 18, // ~18 test matches in November window
            'lions_tour' => 10,            // ~10 tour matches
        ];

        return $expected[$code] ?? max(0, $season->matches()->count());
    }

    private function renderAuditDetail(Season $season, array $audit): void
    {
        $this->newLine();
        $this->info("=== {$season->competition->name} {$season->label} ===");
        $this->line("Overall score: <info>{$audit['score']}%</info>");

        if ($audit['total_matches'] === 0) {
            $this->warn('No matches in this season.');
            return;
        }

        $d = $audit['details'];
        $this->line("Matches: {$d['total_matches']} ({$d['completed_matches']} completed, {$d['scheduled_matches']} scheduled)");

        $rows = [];
        foreach ($d['dimensions'] as $dim => $value) {
            $count = $d['counts']["with_{$dim}"] ?? $d['counts']["with_both_{$dim}"] ?? null;
            $rows[] = [ucfirst($dim), "{$value}%", $count, round($d['weights'][$dim] * 100) . '%'];
        }
        $this->table(['Dimension', 'Score', 'Count', 'Weight'], $rows);
    }

    private function resolveSeasons(): \Illuminate\Support\Collection
    {
        $code = $this->option('competition');
        $label = $this->option('season');
        $all = (bool) $this->option('all');

        if ($code) {
            $comp = Competition::where('code', $code)->first();
            if (! $comp) {
                $this->error("Competition not found: {$code}");
                return collect();
            }
            $query = $comp->seasons();
            if ($label) {
                $query->where('label', $label);
            }
            return $query->orderByDesc('label')->get();
        }

        if ($all) {
            return Season::with('competition')->whereHas('matches')->orderBy('competition_id')->orderByDesc('label')->get();
        }

        return Season::with('competition')->where('is_current', true)->get();
    }
}
