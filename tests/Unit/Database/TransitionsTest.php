<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Core\Database\Transitions;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;

/**
 * The compare-and-swap behind every order and payment transition: one conditional UPDATE, and the
 * loser learns it lost.
 */
final class TransitionsTest extends DatabaseTestCase
{
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->order = $this->topUpOrder($this->customer(), '5.00');
    }

    public function testAMoveFromAnAllowedStateWritesTheRowAndTheAttributesTogether(): void
    {
        $fulfilledAt = now();

        self::assertTrue(Transitions::move($this->order, 'status', [OrderStatus::Pending], OrderStatus::Paid, ['fulfilled_at' => $fulfilledAt, 'notes' => 'x']));

        self::assertSame(OrderStatus::Paid, $this->order->status);
        self::assertFalse($this->order->isDirty(), 'the model matches the row');
        $row = Order::query()->findOrFail($this->order->id);
        self::assertSame(OrderStatus::Paid, $row->status);
        self::assertSame('x', $row->notes);
        self::assertSame($fulfilledAt->toDateTimeString(), $row->fulfilled_at?->toDateTimeString());
    }

    public function testAMoveFromTheWrongStateChangesNothingAndReReadsTheRow(): void
    {
        // Another process got there first.
        Order::query()->whereKey($this->order->id)->update(['status' => OrderStatus::Cancelled->value, 'notes' => 'theirs']);

        self::assertFalse(Transitions::move($this->order, 'status', [OrderStatus::Pending], OrderStatus::Paid, ['notes' => 'mine']));

        self::assertSame(OrderStatus::Cancelled, $this->order->status, 'the caller sees the state that won');
        self::assertSame('theirs', $this->order->notes);
        self::assertSame('theirs', Order::query()->findOrFail($this->order->id)->notes, 'nothing of ours was written');
    }

    public function testOnlyOneOfTwoRacingMovesWins(): void
    {
        $mine = $this->order;
        $theirs = Order::query()->findOrFail($this->order->id);

        self::assertTrue(Transitions::move($mine, 'status', [OrderStatus::Pending], OrderStatus::Paid));
        self::assertFalse(Transitions::move($theirs, 'status', [OrderStatus::Pending], OrderStatus::Paid));
        self::assertSame(OrderStatus::Paid, $theirs->status);
    }

    public function testAOneTimeMarkIsSetByOneOfTwoAndAgainOnceItsWindowPassed(): void
    {
        $theirs = Order::query()->findOrFail($this->order->id);

        self::assertTrue(Transitions::claim($this->order, 'fulfilled_at'));
        self::assertSame(now()->toDateTimeString(), $this->order->fulfilled_at?->toDateTimeString(), 'the model holds the moment');
        self::assertFalse($this->order->isDirty());
        self::assertFalse(Transitions::claim($theirs, 'fulfilled_at'), 'the other process does not do it again');

        Carbon::setTestNow(now()->addDays(3));
        self::assertFalse(Transitions::claim($theirs, 'fulfilled_at', before: now()->subDays(5)), 'still inside its window');
        self::assertTrue(Transitions::claim($theirs, 'fulfilled_at', before: now()->subDay()), 'a mark from a window that has passed');
        self::assertSame(now()->toDateTimeString(), Order::query()->findOrFail($this->order->id)->fulfilled_at?->toDateTimeString());
    }

    public function testAClaimIsReclaimedOnlyOnceItHasGoneIdle(): void
    {
        Transitions::move($this->order, 'status', [OrderStatus::Pending], OrderStatus::Processing);

        self::assertFalse(Transitions::reclaim($this->order, 'status', OrderStatus::Processing, 600), 'a live claim is left alone');

        Carbon::setTestNow(now()->addMinutes(11));
        $other = Order::query()->findOrFail($this->order->id);
        self::assertTrue(Transitions::reclaim($this->order, 'status', OrderStatus::Processing, 600, ['notes' => null]));
        self::assertFalse(Transitions::reclaim($other, 'status', OrderStatus::Processing, 600), 'the fresh timestamp fences a second taker out');
        self::assertSame(now()->toDateTimeString(), $other->updated_at->toDateTimeString());
    }
}
