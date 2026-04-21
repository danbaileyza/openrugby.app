<?php

namespace App\Console\Commands;

use App\Models\MatchEvent;
use App\Models\MatchLineup;
use App\Models\MatchOfficial;
use App\Models\MatchStat;
use App\Models\MatchTeam;
use App\Models\PlayerMatchStat;
use App\Models\RugbyMatch;
use App\Models\Team;
use Illuminate\Console\Command;

class FixProvenceRugbyCommand extends Command
{
    protected $signature = 'rugby:fix-provence {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Remove matches incorrectly linked to Provence Rugby (team matching bug)';

    public function handle(): int
    {
        $provenceTeam = Team::where('name', 'like', '%Provence%')->first();

        if (! $provenceTeam) {
            $this->info('No "Provence Rugby" team found in the database. Nothing to fix.');
            return 0;
        }

        $this->info("Found team: {$provenceTeam->name} (ID: {$provenceTeam->id})");

        // Find all matches where Provence Rugby appears in match_teams
        $matchIds = MatchTeam::where('team_id', $provenceTeam->id)->pluck('match_id')->unique();

        if ($matchIds->isEmpty()) {
            $this->info('No matches linked to Provence Rugby. Nothing to fix.');
            return 0;
        }

        $matches = RugbyMatch::whereIn('id', $matchIds)
            ->with(['matchTeams.team', 'season.competition'])
            ->get();

        $this->info("Found {$matches->count()} matches linked to Provence Rugby:");
        $this->newLine();

        foreach ($matches as $match) {
            $home = $match->matchTeams->firstWhere('side', 'home');
            $away = $match->matchTeams->firstWhere('side', 'away');
            $this->line(sprintf(
                '  %s | R%s | %s vs %s | %s-%s | %s',
                $match->kickoff?->format('Y-m-d'),
                $match->round ?? '?',
                $home?->team->name ?? 'TBD',
                $away?->team->name ?? 'TBD',
                $home?->score ?? '?',
                $away?->score ?? '?',
                $match->external_id ?? 'no-ext-id'
            ));
        }

        $this->newLine();

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN — no changes made. Remove --dry-run to delete these matches.');
            return 0;
        }

        if (! $this->confirm("Delete these {$matches->count()} matches and all related data?")) {
            $this->info('Aborted.');
            return 0;
        }

        $deleted = [
            'events' => MatchEvent::whereIn('match_id', $matchIds)->delete(),
            'lineups' => MatchLineup::whereIn('match_id', $matchIds)->delete(),
            'officials' => MatchOfficial::whereIn('match_id', $matchIds)->delete(),
            'stats' => MatchStat::whereIn('match_id', $matchIds)->delete(),
            'player_stats' => PlayerMatchStat::whereIn('match_id', $matchIds)->delete(),
            'match_teams' => MatchTeam::whereIn('match_id', $matchIds)->delete(),
            'matches' => RugbyMatch::whereIn('id', $matchIds)->delete(),
        ];

        $this->newLine();
        $this->info('=== Cleanup Summary ===');
        foreach ($deleted as $type => $count) {
            $this->line("  {$type}: {$count} deleted");
        }

        $this->newLine();
        $this->info('Now re-import to recreate with correct team links:');
        $this->line('  php artisan rugby:import-urc --matches=202501');
        $this->line('  php artisan rugby:import-urc-match-details --season 202501');

        return 0;
    }
}
