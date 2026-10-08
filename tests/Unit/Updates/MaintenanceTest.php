<?php

declare(strict_types=1);

namespace Tests\Unit\Updates;

use App\Modules\Updates\Maintenance;
use Tests\TestCase;

/**
 * The flag that holds the shop while an update installs — public/index.php's check (Maintenance::retryAfter()), made
 * before the app is loaded: every request waits a moment while the install says it is alive, and none once the flag is
 * gone, older than its window (the install died), or holds no time of the shop's.
 */
final class MaintenanceTest extends TestCase
{
    private string $flag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flag = $this->scratchDir() . '/updating.flag';
    }

    public function testAHeldShopTellsEveryRequestToComeBackInAMoment(): void
    {
        $maintenance = new Maintenance($this->flag);
        self::assertNull(Maintenance::retryAfter($this->flag, time()), 'no update installs');

        $maintenance->hold();
        // Measured from the moment the flag holds, never a second read of the clock: one that ticked between the two
        // would leave four seconds of five.
        $heldAt = (int) file_get_contents($this->flag);

        self::assertSame(10, Maintenance::retryAfter($this->flag, $heldAt), 'a few seconds: an install takes seconds');
        self::assertSame(5, Maintenance::retryAfter($this->flag, $heldAt + Maintenance::SECONDS - 5), 'no longer than the flag holds');

        $maintenance->release();
        self::assertNull(Maintenance::retryAfter($this->flag, time()));
    }

    public function testAFlagWhoseInstallDiedHoldsNothingForGood(): void
    {
        file_put_contents($this->flag, (string) (time() - Maintenance::SECONDS));

        self::assertNull(Maintenance::retryAfter($this->flag, time()), 'its window is over: the shop answers again');
    }

    public function testAFlagThatHoldsNoTimeOfTheShopsHoldsNothing(): void
    {
        foreach (['', 'garbage', '-5', (string) (time() + 3600)] as $content) {
            file_put_contents($this->flag, $content);

            self::assertNull(Maintenance::retryAfter($this->flag, time()), "a flag of «{$content}»");
        }
    }
}
