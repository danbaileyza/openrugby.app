<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\MatchTeam;
use App\Models\RugbyMatch;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamMatchesEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_match_resources_ordered_by_kickoff_desc(): void
    {
        $competition = Competition::create([
            'name' => 'United Rugby Championship',
            'code' => 'urc',
            'format' => 'union',
        ]);

        $season = Season::create([
            'competition_id' => $competition->id,
            'label' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_current' => true,
        ]);

        $team = Team::create([
            'name' => 'Stormers',
            'country' => 'South Africa',
            'type' => 'club',
        ]);

        $opponent = Team::create([
            'name' => 'Sharks',
            'country' => 'South Africa',
            'type' => 'club',
        ]);

        $olderMatch = RugbyMatch::create([
            'season_id' => $season->id,
            'kickoff' => '2026-04-01 15:00:00',
            'status' => 'ft',
        ]);

        $newerMatch = RugbyMatch::create([
            'season_id' => $season->id,
            'kickoff' => '2026-04-08 15:00:00',
            'status' => 'ft',
        ]);

        foreach ([[$olderMatch, 20, 10], [$newerMatch, 30, 15]] as [$match, $home, $away]) {
            MatchTeam::create([
                'match_id' => $match->id,
                'team_id' => $team->id,
                'side' => 'home',
                'score' => $home,
            ]);
            MatchTeam::create([
                'match_id' => $match->id,
                'team_id' => $opponent->id,
                'side' => 'away',
                'score' => $away,
            ]);
        }

        $response = $this->getJson("/api/teams/{$team->id}/matches");

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $newerMatch->id);
        $response->assertJsonPath('data.1.id', $olderMatch->id);
        $response->assertJsonMissingPath('data.0.match_id');
    }
}
