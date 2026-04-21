<?php

namespace Tests\Unit;

use App\Console\Commands\SyncLiveCommand;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class SyncLiveCommandTest extends TestCase
{
    public function test_it_marks_ft_when_both_scores_are_present_even_if_home_is_zero(): void
    {
        $state = $this->determineState('<div class="score home">0</div><div class="score away">3</div>', 0, 3);

        $this->assertSame('FT', $state);
    }

    public function test_it_marks_live_when_live_marker_is_present(): void
    {
        $state = $this->determineState('<div class="live-note show">LIVE</div>', null, null);

        $this->assertSame('LIVE', $state);
    }

    public function test_it_marks_scheduled_when_scores_are_incomplete(): void
    {
        $state = $this->determineState('<div class="score home">0</div>', 0, null);

        $this->assertSame('SCH', $state);
    }

    private function determineState(string $html, ?int $homeScore, ?int $awayScore): string
    {
        $command = new SyncLiveCommand();
        $method = new ReflectionMethod($command, 'determineFixtureState');
        $method->setAccessible(true);

        /** @var string $state */
        $state = $method->invoke($command, $html, $homeScore, $awayScore);

        return $state;
    }
}
