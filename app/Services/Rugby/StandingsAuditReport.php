<?php

namespace App\Services\Rugby;

use Illuminate\Console\Command;

class StandingsAuditReport
{
    /** @var array{type: string, detail: string}[] */
    public array $warnings = [];

    /** @var array<string, array{team_name: string, matches: array[]}> keyed by team_id */
    public array $teamBreakdowns = [];

    public int $totalMatches = 0;
    public int $matchesWithCompleteData = 0;

    /** @var array{check: string, passed: bool, detail: string}[] */
    public array $crossChecks = [];

    public function dataQualityPercent(): float
    {
        return $this->totalMatches > 0
            ? round(($this->matchesWithCompleteData / $this->totalMatches) * 100, 1)
            : 0.0;
    }

    public function addWarning(string $type, string $detail): void
    {
        $this->warnings[] = ['type' => $type, 'detail' => $detail];
    }

    public function addTeamMatch(string $teamId, string $teamName, array $matchData): void
    {
        if (! isset($this->teamBreakdowns[$teamId])) {
            $this->teamBreakdowns[$teamId] = ['team_name' => $teamName, 'matches' => []];
        }
        $this->teamBreakdowns[$teamId]['matches'][] = $matchData;
    }

    public function addCrossCheck(string $check, bool $passed, string $detail = ''): void
    {
        $this->crossChecks[] = compact('check', 'passed', 'detail');
    }

    public function renderTo(Command $command): void
    {
        $command->newLine();
        $command->info("Data Quality: {$this->dataQualityPercent()}% ({$this->matchesWithCompleteData}/{$this->totalMatches} matches have complete data)");

        // Warnings
        if (empty($this->warnings)) {
            $command->info('No warnings.');
        } else {
            $command->newLine();
            $command->warn('Warnings (' . count($this->warnings) . '):');
            foreach ($this->warnings as $w) {
                $type = str_pad($w['type'], 20);
                $command->line("  <comment>{$type}</comment> {$w['detail']}");
            }
        }

        // Cross-checks
        $command->newLine();
        $command->info('Cross-checks:');
        foreach ($this->crossChecks as $cc) {
            $icon = $cc['passed'] ? '<info>✓</info>' : '<error>✗</error>';
            $detail = $cc['detail'] ? " — {$cc['detail']}" : '';
            $command->line("  {$icon} {$cc['check']}{$detail}");
        }

        // Team breakdowns (show first 3 teams by default for brevity)
        $command->newLine();
        $sorted = collect($this->teamBreakdowns)->sortBy('team_name');
        $command->info('Per-team breakdown (' . $sorted->count() . ' teams):');

        foreach ($sorted as $data) {
            $command->newLine();
            $command->line("  <info>{$data['team_name']}</info>");

            $matches = collect($data['matches'])->sortBy('round');
            foreach ($matches as $m) {
                $round = $m['round'] ? 'Rd ' . str_pad($m['round'], 2) : '     ';
                $side = $m['is_home'] ? 'H' : 'A';
                $result = strtoupper($m['result'][0]); // W/D/L
                $score = "{$m['score']}-{$m['opponent_score']}";
                $tries = "tries: {$m['tries']}";
                $bonus = $m['bonus_points'] > 0 ? ", bonus: +{$m['bonus_points']} ({$m['bonus_reason']})" : '';

                $command->line("    {$round}: vs {$m['opponent']} ({$side}) — {$result} {$score}, {$tries}{$bonus}");
            }
        }
    }
}
