<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\MatchTeam;
use App\Models\RugbyMatch;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ImportWpClubRugbyCommand extends Command
{
    protected $signature = 'rugby:import-wp-club
                            {--path= : JSON file from wpclubrugby_scraper.py}
                            {--first-teams-only : Skip 2nd Team/Colts matches}';

    protected $description = 'Import Western Province Club Rugby results';

    public function handle(): int
    {
        $path = $this->option('path');
        if (! $path || ! file_exists($path)) {
            $this->error('File required: --path=/path/to/wpclubrugby_*.json');
            return self::FAILURE;
        }

        $data = json_decode(file_get_contents($path), true);
        $competition = Competition::firstOrCreate(
            ['code' => 'wp_club'],
            [
                'name' => 'Western Province Club Rugby',
                'format' => 'union',
                'country' => 'South-Africa',
                'has_standings' => true,
                'external_source' => 'wpclubrugby.co.za',
            ]
        );

        $seasonLabel = (string) ($data['year'] ?? now()->year);
        $season = $competition->seasons()->firstOrCreate(
            ['label' => $seasonLabel],
            ['is_current' => true, 'start_date' => "{$seasonLabel}-01-01", 'end_date' => "{$seasonLabel}-12-31", 'external_source' => 'wpclubrugby.co.za']
        );

        $firstOnly = (bool) $this->option('first-teams-only');

        $stats = ['matches_created' => 0, 'matches_existing' => 0, 'teams_created' => 0, 'skipped' => 0];

        foreach ($data['matches'] as $m) {
            if ($firstOnly && stripos($m['team_category'] ?? '', '1st') === false) {
                $stats['skipped']++;
                continue;
            }

            $home = $this->resolveTeam($m['home_team'], $stats);
            $away = $this->resolveTeam($m['away_team'], $stats);
            if (! $home || ! $away) continue;

            $kickoff = Carbon::parse($m['date']);

            $existing = RugbyMatch::where('season_id', $season->id)
                ->whereHas('matchTeams', fn ($q) => $q->where('team_id', $home->id))
                ->whereHas('matchTeams', fn ($q) => $q->where('team_id', $away->id))
                ->whereBetween('kickoff', [$kickoff->copy()->subDay(), $kickoff->copy()->addDay()])
                ->where('round', $m['league'] ?? null)  // use league as identifier
                ->first();

            if ($existing) {
                $stats['matches_existing']++;
                continue;
            }

            $match = RugbyMatch::create([
                'season_id' => $season->id,
                'kickoff' => $kickoff,
                'status' => 'ft',
                'round' => null,  // leave null, use metadata instead
                'stage' => strtolower(str_replace(' ', '_', $m['league'])),
                'external_source' => 'wpclubrugby.co.za',
            ]);

            $isDraw = $m['home_score'] === $m['away_score'];
            MatchTeam::create([
                'match_id' => $match->id, 'team_id' => $home->id, 'side' => 'home',
                'score' => $m['home_score'],
                'is_winner' => ! $isDraw && $m['home_score'] > $m['away_score'],
            ]);
            MatchTeam::create([
                'match_id' => $match->id, 'team_id' => $away->id, 'side' => 'away',
                'score' => $m['away_score'],
                'is_winner' => ! $isDraw && $m['away_score'] > $m['home_score'],
            ]);
            $stats['matches_created']++;
        }

        $this->table(['Metric', 'Count'], [
            ['Matches created', $stats['matches_created']],
            ['Matches existing', $stats['matches_existing']],
            ['Clubs created', $stats['teams_created']],
            ['Skipped (not 1st Team)', $stats['skipped']],
        ]);
        return self::SUCCESS;
    }

    private function resolveTeam(string $name, array &$stats): ?Team
    {
        $name = trim($name);
        if ($name === '') return null;

        $team = Team::where('name', $name)->where('type', 'club')->where('country', 'South-Africa')->first();
        if ($team) return $team;

        $team = Team::create([
            'name' => $name,
            'country' => 'South-Africa',
            'type' => 'club',
            'external_source' => 'wpclubrugby.co.za',
        ]);
        $stats['teams_created']++;
        return $team;
    }
}
