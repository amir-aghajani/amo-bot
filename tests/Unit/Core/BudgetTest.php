<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Scheduling\Budget;
use PHPUnit\Framework\TestCase;

/**
 * The time the scheduled work running now has: its turn's share of the run — the fraction of a second too, however
 * short the share (a busy run of many shops) —, none once the turn is over, and outside a run a turn of its own.
 */
final class BudgetTest extends TestCase
{
    public function testATurnUnderHalfASecondStillHasItsTime(): void
    {
        $budget = new Budget();
        $budget->turn(0.4);

        $seconds = $budget->seconds();

        self::assertGreaterThan(0.3, $seconds, 'a short share is time to work in — a report or two —, never none');
        self::assertLessThan(0.41, $seconds, 'its share, a clock\'s rounding aside');
    }

    public function testATurnThatIsOverHasNoneAndOutsideARunTheWorkHasATurnOfItsOwn(): void
    {
        $budget = new Budget();
        $budget->turn(0.0);
        self::assertSame(0.0, $budget->seconds());

        $budget->turn(null);
        self::assertEqualsWithDelta(Budget::OUTSIDE_A_RUN, $budget->seconds(), 0.5);
    }
}
