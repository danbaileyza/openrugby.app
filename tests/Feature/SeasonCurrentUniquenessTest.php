<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\RugbyMatch;
use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeasonCurrentUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_hook_unflags_sibling_current_seasons(): void
    {
        $comp = Competition::create([
            'name' => 'Test Cup',
            'code' => 'test_cup',
            'country' => 'Rugbyland',
            'format' => 'union',
            'level' => 'professional',
            'has_standings' => true,
        ]);

        $old = Season::create([
            'competition_id' => $comp->id,
            'label' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'is_current' => true,
        ]);

        $new = Season::create([
            'competition_id' => $comp->id,
            'label' => '2024-25',
            'start_date' => '2024-08-01',
            'end_date' => '2025-06-30',
            'is_current' => true,
        ]);

        $currents = $comp->seasons()->where('is_current', true)->get();

        $this->assertCount(1, $currents, 'exactly one current season');
        $this->assertSame($new->id, $currents->first()->id, 'the latest save wins');

        // Refresh old → flag should now be false in DB
        $old->refresh();
        $this->assertFalse($old->is_current);
    }

    public function test_fix_duplicate_current_seasons_command_keeps_season_with_most_matches(): void
    {
        $comp = Competition::create([
            'name' => 'Legacy Cup',
            'code' => 'legacy_cup',
            'country' => 'Rugbyland',
            'format' => 'union',
            'level' => 'professional',
            'has_standings' => true,
        ]);

        // Force two is_current=true rows by bypassing the model hook.
        // This simulates the pre-hook historical bug we're deduping.
        $empty = Season::create([
            'competition_id' => $comp->id, 'label' => '2025',
            'start_date' => '2025-01-01', 'end_date' => '2025-12-31',
            'is_current' => false,
        ]);
        $real = Season::create([
            'competition_id' => $comp->id, 'label' => '2025-26',
            'start_date' => '2025-08-01', 'end_date' => '2026-06-30',
            'is_current' => false,
        ]);
        Season::where('id', $empty->id)->update(['is_current' => true]);
        Season::where('id', $real->id)->update(['is_current' => true, 'completeness_score' => 75]);

        // Give the "real" season some matches so it wins the tiebreaker
        RugbyMatch::create([
            'season_id' => $real->id,
            'kickoff' => now(),
            'status' => 'scheduled',
        ]);

        $this->assertSame(2, $comp->seasons()->where('is_current', true)->count(),
            'both seasons start flagged current');

        $this->artisan('rugby:fix-duplicate-current-seasons')
            ->expectsOutputToContain('Legacy Cup')
            ->assertExitCode(0);

        $currents = $comp->seasons()->where('is_current', true)->get();
        $this->assertCount(1, $currents, 'exactly one current season after dedup');
        $this->assertSame($real->id, $currents->first()->id, 'season with matches wins');
    }

    public function test_command_is_idempotent_when_no_duplicates_exist(): void
    {
        $this->artisan('rugby:fix-duplicate-current-seasons')
            ->expectsOutputToContain('No competitions with duplicate is_current seasons')
            ->assertExitCode(0);
    }
}
