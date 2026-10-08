<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Scheduling\Budget;
use App\Core\Scheduling\Scheduler;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Tasks\ResumeDeliveriesTask;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Notifications\ServiceCard;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;
use Tests\Support\FakeTelegram;

/**
 * A delivery whose process died — the bot restarted after the money was in, before or during the delivery: once nobody
 * has touched the order for OrderService::STALE_PROCESSING_MINUTES, the task (every minute, in every shop) delivers it
 * as support's retry would, and the customer hears it only when it worked, as a reply to their receipt. A failed
 * delivery is never taken up — it waits for support —, a delivery that may still be on its way is left alone, an order
 * that breaks holds up none of the others, and the run stops at its share of time.
 */
final class ResumeDeliveriesTest extends DatabaseTestCase
{
    private User $customer;
    private Server $server;
    private Plan $plan;
    private PaymentMethod $card;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-06 10:00:00');
        $this->withoutQr();
        $this->telegram();
        $this->server = $this->sellingServer();
        $this->plan = $this->plan(['price' => '50000.00'], $this->server);
        $this->customer = $this->customer(['username' => 'ali']);
        $this->card = $this->cardMethod();
    }

    public function testTheTaskLooksEveryMinuteInEveryShop(): void
    {
        self::assertSame(
            ['task' => ResumeDeliveriesTask::class, 'interval' => 60, 'eachBot' => true],
            $this->service(Scheduler::class)->tasks()[ResumeDeliveriesTask::class],
        );
    }

    public function testAPaidOrderNobodyDeliveredIsDeliveredOnceItIsStaleAndTheCustomerHearsIt(): void
    {
        $purchase = $this->paidOrder($this->purchaseOrder($this->customer, $this->plan, $this->server, ['status' => OrderStatus::Paid]));
        $topUp = $this->paidOrder($this->topUpOrder($this->customer, '20000.00', ['status' => OrderStatus::Paid]));

        $this->runAfter(OrderService::STALE_PROCESSING_MINUTES - 1);
        self::assertSame([OrderStatus::Paid, OrderStatus::Paid], [$purchase->refresh()->status, $topUp->refresh()->status], 'its delivery may still be on its way');
        self::assertSame([], $this->telegram()->calls());

        $this->runAfter(OrderService::STALE_PROCESSING_MINUTES);
        self::assertSame([OrderStatus::Fulfilled, OrderStatus::Fulfilled], [$purchase->refresh()->status, $topUp->refresh()->status]);
        self::assertCount(1, FakeProvider::$created, 'the client was made on the panel');
        self::assertSame('20000.00', $this->customer->balance(), 'the wallet charged');
        $service = $purchase->subscription ?? self::fail('nothing delivered');
        self::assertSame([
            self::text(BotText::PaySuccess, $this->service(ServiceCard::class)->values($service)),
            self::text(BotText::WalletCharged, ['balance' => Messages::balance('20000.00')]),
        ], $this->telegram()->sentTo(self::TELEGRAM_ID), 'what each brought, as its own delivery would have said it');
        self::assertSame(self::RECEIPT_MESSAGE, $this->telegram()->replyTarget(0), 'as a reply to the receipt');

        $this->telegram()->reset();
        $this->runAfter(OrderService::STALE_PROCESSING_MINUTES * 3);
        self::assertSame([], $this->telegram()->calls(), 'delivered once');
        self::assertCount(1, FakeProvider::$created);
    }

    public function testAClaimGoneQuietIsTakenOverAndOneStillWorkedOnIsLeftAlone(): void
    {
        $order = $this->paidOrder($this->purchaseOrder($this->customer, $this->plan, $this->server, ['status' => OrderStatus::Processing]));

        $this->runAfter(OrderService::STALE_PROCESSING_MINUTES - 1);
        self::assertSame(OrderStatus::Processing, $order->refresh()->status, 'another process may be delivering it this moment');
        self::assertSame([], FakeProvider::$created);

        $this->runAfter(OrderService::STALE_PROCESSING_MINUTES);
        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status, 'the process holding it died');
        self::assertCount(1, FakeProvider::$created);
    }

    public function testAFailedDeliveryWaitsForSupport(): void
    {
        $order = $this->paidOrder($this->purchaseOrder($this->customer, $this->plan, $this->server, ['status' => OrderStatus::Failed, 'notes' => 'پنل جواب نداد.']));

        $this->runAfter(24 * 60);

        self::assertSame([OrderStatus::Failed, 'پنل جواب نداد.'], [$order->refresh()->status, $order->notes]);
        self::assertSame([], FakeProvider::$created);
        self::assertSame([], $this->telegram()->calls());
    }

    public function testAnOrderThatFailedAfterItWasReadIsNotTakenUp(): void
    {
        $read = $this->paidOrder($this->purchaseOrder($this->customer, $this->plan, $this->server, ['status' => OrderStatus::Paid]));
        Carbon::setTestNow(now()->addMinutes(OrderService::STALE_PROCESSING_MINUTES));
        // Read as paid and stale; another process (support's retry) took it and failed it before this one claimed it.
        Order::query()->whereKey($read->id)->update(['status' => OrderStatus::Failed->value]);

        self::assertFalse($this->service(OrderService::class)->resume($read));
        self::assertSame(OrderStatus::Failed, $read->refresh()->status);
        self::assertSame([], FakeProvider::$created);
    }

    public function testAResumedDeliveryThatFailsIsLeftToSupportAndTheCustomerHearsNothing(): void
    {
        $order = $this->paidOrder($this->purchaseOrder($this->customer, $this->plan, $this->server, ['status' => OrderStatus::Paid]));
        $this->server->forceFill(['is_active' => false])->save();

        $this->runAfter(OrderService::STALE_PROCESSING_MINUTES);
        self::assertSame(OrderStatus::Failed, $order->refresh()->status);
        self::assertNotNull($order->notes, 'support reads why');
        self::assertSame([], $this->telegram()->sentTo(self::TELEGRAM_ID), 'told only when it worked');

        $this->server->forceFill(['is_active' => true])->save();
        $this->runAfter(OrderService::STALE_PROCESSING_MINUTES * 3);
        self::assertSame(OrderStatus::Failed, $order->refresh()->status, 'a failure is never taken up again');
    }

    public function testAnOrderThatBreaksHoldsUpNoneOfTheOthers(): void
    {
        // An agent's traffic for a bot they do not have: a fault of the shop's own, the order fails with the general reason.
        $broken = $this->paidOrder($this->topUpOrder($this->customer, '30000.00', ['type' => OrderType::Traffic, 'status' => OrderStatus::Paid, 'traffic_bytes' => 1]));
        $topUp = $this->paidOrder($this->topUpOrder($this->customer, '20000.00', ['status' => OrderStatus::Paid]));
        $logs = $this->logs();

        $this->runAfter(OrderService::STALE_PROCESSING_MINUTES);

        self::assertSame([OrderStatus::Failed, OrderService::DELIVERY_BROKEN], [$broken->refresh()->status, $broken->notes]);
        self::assertTrue($logs->hasErrorThatContains('Resuming the delivery of order'), 'the fault is the log\'s');
        self::assertSame(OrderStatus::Fulfilled, $topUp->refresh()->status);
    }

    public function testTheRunStopsAtItsShareOfTime(): void
    {
        $order = $this->paidOrder($this->topUpOrder($this->customer, '20000.00', ['status' => OrderStatus::Paid]));
        Carbon::setTestNow(now()->addMinutes(OrderService::STALE_PROCESSING_MINUTES));
        $budget = $this->service(Budget::class);

        $budget->turn(0.0);
        try {
            $this->service(ResumeDeliveriesTask::class)->run();
        } finally {
            $budget->turn(null);
        }

        self::assertSame(OrderStatus::Paid, $order->refresh()->status, 'left for the next run');
    }

    public function testEachShopResumesItsOwn(): void
    {
        $bot = $this->agentBot();
        $order = CurrentBot::run($bot, fn(): Order => $this->paidOrder($this->topUpOrder($this->customer(['telegram_id' => 31]), '20000.00', ['status' => OrderStatus::Paid]), $this->cardMethod()));
        Carbon::setTestNow(now()->addMinutes(OrderService::STALE_PROCESSING_MINUTES));

        $this->service(ResumeDeliveriesTask::class)->run();
        self::assertSame(OrderStatus::Paid, $order->refresh()->status, "the main bot's run is the main bot's shop");

        CurrentBot::run($bot, fn() => $this->service(ResumeDeliveriesTask::class)->run());
        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status);
        self::assertSame(FakeTelegram::AGENT_TOKEN, $this->telegram()->tokenOf(0), "the customer hears it from the agent's bot");
    }

    /** The order paid by a card whose receipt was approved — and then the process delivering it died. */
    private function paidOrder(Order $order, ?PaymentMethod $method = null): Order
    {
        $this->cardPayment($order, $method ?? $this->card, [
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
            'receipt_file_id' => 'receipt-1',
            'receipt_message_id' => self::RECEIPT_MESSAGE,
            'receipt_at' => now(),
        ]);

        return $order;
    }

    /** The task, `$minutes` after the orders were last touched (the test's start). */
    private function runAfter(int $minutes): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:00')->addMinutes($minutes));
        $this->service(ResumeDeliveriesTask::class)->run();
    }
}
