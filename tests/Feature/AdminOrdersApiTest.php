<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Notifications\ServiceCard;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Support\Input;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * The orders screen: every order with its customer, what it was for and its payments — tabs by status and the stuck
 * queue (paid and not delivered, counted: the dashboard and the sidebar link there), a type filter, the shop's days,
 * one search box, and what the list sold — and what an order itself allows: delivering a paid order again once its
 * delivery failed, dropping an order nobody paid, with its unpaid payments. The customer hears as from the payments
 * screen, as a reply to their receipt.
 */
final class AdminOrdersApiTest extends HttpTestCase
{
    private User $ali;
    private PaymentMethod $card;
    private Server $server;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-20 12:00:00');
        $this->withoutQr();
        $this->telegram();
        $this->loginAsAdmin();

        $this->ali = $this->customer(['telegram_id' => 1001, 'username' => 'ali_r']);
        $this->card = $this->cardMethod();
        $this->server = $this->sellingServer('Berlin');
        $this->plan = $this->plan(['name' => 'Basic', 'price' => '50000.00'], $this->server);
    }

    public function testTheListFiltersSearchesAndCountsTheStuckOnes(): void
    {
        $sara = $this->customer(['telegram_id' => 1002, 'first_name' => 'Sara']);
        $bought = $this->buy($this->ali, $this->plan, $this->server);
        $renewal = $this->paid($this->purchaseOrder($this->ali, $this->plan, $this->server, ['type' => OrderType::Renewal, 'server_id' => null, 'subscription_id' => $bought->subscription_id, 'status' => OrderStatus::Failed, 'notes' => 'پنل جواب نداد.']));
        $topUp = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '20000.00'), $this->card))->order;
        $walkedAway = $this->purchaseOrder($sara, $this->plan, $this->server);

        $data = $this->decode($this->get('/api/admin/orders'));
        self::assertSame([$walkedAway->id, $topUp?->id, $renewal->id, $bought->id], array_column($data['orders'], 'id'), 'newest first');
        self::assertSame(4, $data['meta']['total']);
        self::assertSame(1, $data['meta']['stuck'], "what the dashboard's attention card and the sidebar count");
        self::assertSame([1, '50000.00'], [$data['meta']['sold'], $data['meta']['sold_amount']], 'what the list sold');

        $row = $data['orders'][3];
        self::assertSame(['purchase', 'fulfilled', '50000.00'], [$row['type'], $row['status'], $row['amount']]);
        self::assertSame(['id' => $this->ali->id, 'name' => 'Ali', 'username' => 'ali_r', 'telegram_id' => 1001, 'email' => null], $row['user']);
        self::assertSame(['id' => $this->plan->id, 'name' => 'Basic'], $row['plan']);
        self::assertSame(['id' => $this->server->id, 'name' => 'Berlin'], $row['server']);
        self::assertSame(['id' => $bought->subscription_id, 'name' => 'ali_r_1', 'status' => 'active'], $row['subscription']);
        self::assertSame([['paid', 'wallet', $this->walletMethod()->label]], array_map(static fn(array $payment): array => [$payment['status'], $payment['gateway'], $payment['method']], $row['payments']));
        self::assertNotNull($row['paid_at']);
        self::assertNotNull($row['fulfilled_at']);
        self::assertSame(['retry' => false, 'cancel' => false], $row['actions']);

        $renewed = $data['orders'][2];
        self::assertSame(['id' => $this->server->id, 'name' => 'Berlin'], $renewed['server'], 'a renewal is where its service is');
        self::assertSame('پنل جواب نداد.', $renewed['notes']);
        self::assertSame(['retry' => true, 'cancel' => false], $renewed['actions'], 'stuck: delivered again from here');
        self::assertSame(['retry' => false, 'cancel' => true], $data['orders'][0]['actions']);
        self::assertSame([], $data['orders'][0]['payments'], 'a checkout the customer walked away from');

        $ids = fn(string $query): array => array_column($this->decode($this->get("/api/admin/orders?{$query}"))['orders'], 'id');
        self::assertSame([$renewal->id], $ids('status=failed'));
        self::assertSame([$renewal->id], $ids('status=stuck'));
        self::assertSame([$walkedAway->id, $topUp?->id], $ids('status=pending'));
        self::assertSame([$topUp?->id], $ids('type=wallet_topup'));
        self::assertSame([$walkedAway->id, $bought->id], $ids('type=purchase'));
        self::assertSame([$bought->id], $ids('search=' . urlencode('#' . $bought->id)));
        self::assertSame([$walkedAway->id], $ids('search=Sara'), 'by the customer');
        self::assertSame([$renewal->id, $bought->id], $ids('search=ali_r_1'), 'by the service\'s name on the panel');
        self::assertSame([$walkedAway->id, $renewal->id, $bought->id], $ids('search=Basic'), 'by the plan');
    }

    public function testTheStuckTabHoldsWhatWasPaidAndNotDelivered(): void
    {
        $failed = $this->paid($this->purchaseOrder($this->ali, $this->plan, $this->server, ['status' => OrderStatus::Failed, 'notes' => 'پنل جواب نداد.']));
        $neverStarted = $this->paid($this->topUpOrder($this->ali, '50000.00', ['status' => OrderStatus::Paid]));
        $claimed = $this->paid($this->purchaseOrder($this->ali, $this->plan, $this->server, ['status' => OrderStatus::Processing]));
        $this->paid($this->purchaseOrder($this->ali, $this->plan, $this->server, ['status' => OrderStatus::Fulfilled]));
        $this->purchaseOrder($this->ali, $this->plan, $this->server);
        $stuck = function (): array {
            $data = $this->decode($this->get('/api/admin/orders?status=stuck'));

            return [array_column($data['orders'], 'id'), $data['meta']['stuck']];
        };

        self::assertSame([[$failed->id], 1], $stuck(), 'the others may still be on their way');

        Carbon::setTestNow(now()->addMinutes(OrderService::STALE_PROCESSING_MINUTES));
        self::assertSame([[$claimed->id, $neverStarted->id, $failed->id], 3], $stuck(), 'nobody is delivering them');
        self::assertSame([$neverStarted->id], array_column($this->decode($this->get('/api/admin/orders?status=stuck&type=wallet_topup'))['orders'], 'id'), 'narrowed like any tab');
    }

    public function testAnOrderWhoseMoneyWentBackIsStuckNowhere(): void
    {
        // As an older release left one: its delivery failed and the payment that paid it was refunded, the order left failed.
        $givenBack = $this->purchaseOrder($this->ali, $this->plan, $this->server, ['status' => OrderStatus::Failed, 'notes' => 'پنل جواب نداد.']);
        $this->cardPayment($givenBack, $this->card, ['status' => PaymentStatus::Refunded, 'paid_at' => now()]);
        $owed = $this->paid($this->purchaseOrder($this->ali, $this->plan, $this->server, ['status' => OrderStatus::Failed, 'notes' => 'پنل جواب نداد.']));

        $tab = $this->decode($this->get('/api/admin/orders?status=stuck'));
        self::assertSame([[$owed->id], 1], [array_column($tab['orders'], 'id'), $tab['meta']['stuck']], 'nothing is owed for what was given back');
        self::assertSame(1, $this->decode($this->get('/api/admin/queues'))['queues']['stuck_orders'], "the sidebar's count");
        self::assertSame(1, $this->decode($this->get('/api/admin/dashboard'))['attention']['stuck_orders'], "the dashboard's");

        $actions = array_column($this->decode($this->get('/api/admin/orders'))['orders'], 'actions', 'id');
        self::assertSame(['retry' => false, 'cancel' => false], $actions[$givenBack->id]);
        self::assertSame(['retry' => true, 'cancel' => false], $actions[$owed->id], 'what is counted is what support can act on');

        $refused = $this->postJson("/api/admin/orders/{$givenBack->id}/retry");
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['پرداخت این سفارش بازپرداخت شده است؛ چیزی برای تحویل نمانده.'], $this->decode($refused)['errors']['status']);
    }

    public function testTheListNarrowsToTheShopsDaysAndSaysWhatItSold(): void
    {
        LocalTime::use('Asia/Tehran');
        try {
            // 20:00 UTC on the 5th is 23:30 on the 5th in Tehran; 21:00 UTC is already 00:30 on the 6th there.
            Carbon::setTestNow(Carbon::parse('2026-10-05 20:00:00', 'UTC'));
            $fifth = $this->purchaseOrder($this->ali, $this->plan, $this->server, ['status' => OrderStatus::Fulfilled]);
            Carbon::setTestNow(Carbon::parse('2026-10-05 21:00:00', 'UTC'));
            $bought = $this->purchaseOrder($this->ali, $this->plan, $this->server, ['status' => OrderStatus::Fulfilled]);
            $charged = $this->topUpOrder($this->ali, '20000.00', ['status' => OrderStatus::Fulfilled]);
            $unpaid = $this->purchaseOrder($this->ali, $this->plan, $this->server);
            $dropped = $this->topUpOrder($this->ali, '5000.00', ['status' => OrderStatus::Cancelled]);
            Carbon::setTestNow(Carbon::parse('2026-10-06 21:00:00', 'UTC'));
            $seventh = $this->topUpOrder($this->ali, '7000.00', ['status' => OrderStatus::Fulfilled]);
            Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00', 'UTC'));
            $list = fn(string $query): array => $this->decode($this->get("/api/admin/orders?{$query}"));
            $ids = static fn(array $data): array => array_column($data['orders'], 'id');

            $sixth = $list('from=2026-10-06&to=2026-10-06');
            self::assertSame([$dropped->id, $unpaid->id, $charged->id, $bought->id], $ids($sixth), "the shop's day, not UTC's");
            self::assertSame([2, '70000.00'], [$sixth['meta']['sold'], $sixth['meta']['sold_amount']], 'its sales alone: not the unpaid, not the cancelled');
            self::assertSame([1, '20000.00'], [($topUps = $list('from=2026-10-06&to=2026-10-06&type=wallet_topup'))['meta']['sold'], $topUps['meta']['sold_amount']], 'of what the list shows');

            self::assertSame([$seventh->id, $dropped->id, $unpaid->id, $charged->id, $bought->id], $ids($list('from=2026-10-06')), 'from a day on');
            self::assertSame([$fifth->id], $ids($list('to=2026-10-05')), 'up to a day');
            $this->unchecked(); // bounds no panel sends: its date picker gives dates
            $all = $list('from=2026-02-31&to=never');
            self::assertCount(6, $all['orders'], 'a bound that is no date is no bound');
            self::assertSame([4, '127000.00'], [$all['meta']['sold'], $all['meta']['sold_amount']]);
        } finally {
            LocalTime::use('UTC');
        }
    }

    public function testOneOrderIsReadAsTheListShowsIt(): void
    {
        $bought = $this->buy($this->ali, $this->plan, $this->server);

        $listed = $this->decode($this->get('/api/admin/orders'))['orders'][0];
        self::assertSame(['order' => $listed], $this->decode($this->get("/api/admin/orders/{$bought->id}")));
        self::assertSame(404, $this->get('/api/admin/orders/999999')->getStatusCode());
    }

    public function testAFailedDeliveryIsRetriedAndTheCustomerHearsOnlyWhenItWorked(): void
    {
        $this->server->forceFill(['is_active' => false])->save();
        $payment = $this->receipt($this->cardPayment($this->purchaseOrder($this->ali, $this->plan, $this->server), $this->card));
        $this->postJson("/api/admin/payments/{$payment->id}/approve");
        $order = $this->decode($this->get('/api/admin/orders'))['orders'][0];
        self::assertSame('failed', $order['status']);
        self::assertNotNull($order['notes'], 'the admin sees why');
        self::assertTrue($order['actions']['retry']);

        // Still off: it fails again — the admin's problem, not news for the customer.
        $this->telegram()->reset();
        $retried = $this->decode($this->postJson("/api/admin/orders/{$order['id']}/retry"))['order'];
        self::assertSame('failed', $retried['status']);
        self::assertSame([], $this->telegram()->calls(), 'no second "it failed"');

        // Back on: delivered, and the service goes out as a reply to the receipt.
        $this->server->forceFill(['is_active' => true])->save();
        $response = $this->postJson("/api/admin/orders/{$order['id']}/retry");
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $retried = $this->decode($response)['order'];
        self::assertSame('fulfilled', $retried['status']);
        self::assertSame('ali_r_1', $retried['subscription']['name'] ?? null);
        self::assertNull($retried['notes']);
        self::assertCount(1, FakeProvider::$created);
        self::assertSame([self::text(BotText::PaySuccess, $this->service(ServiceCard::class)->values(Subscription::query()->sole()))], $this->telegram()->sentTo(1001));
        self::assertSame(self::RECEIPT_MESSAGE, $this->telegram()->replyTarget(0));

        self::assertSame(422, $this->postJson("/api/admin/orders/{$order['id']}/retry")->getStatusCode(), 'delivered: nothing to retry');
    }

    public function testAFailedDeliveryIsSaidAsItsReaderMayReadIt(): void
    {
        $bot = $this->agentBot();
        $order = CurrentBot::run($bot, fn(): Order => $this->purchaseOrder($this->customer(['telegram_id' => 1009]), $this->plan([], $this->server), $this->server, [
            'status' => OrderStatus::Failed,
            'notes' => 'سرور در دسترس نبود.',
            'diagnosis' => 'پنل جواب نداد (تایم‌اوت).',
        ]));

        $this->openShop($bot);
        self::assertSame('پنل جواب نداد (تایم‌اوت).', $this->decode($this->get("/api/admin/orders/{$order->id}"))['order']['notes'], "the owner, who runs the servers, reads the diagnosis — in an agent's shop too");

        $this->loginAsAgent($bot);
        self::assertSame('سرور در دسترس نبود.', $this->decode($this->get("/api/agent/orders/{$order->id}"))['order']['notes'], 'the agent reads the word: nothing of the panel');
    }

    public function testAnOrderPaidButNeverDeliveredCanBeRetriedOnceNobodyIsOnIt(): void
    {
        // The money is in, and the process that was to deliver died before it started.
        $order = $this->topUpOrder($this->ali, '50000.00', ['status' => OrderStatus::Paid]);
        $this->cardPayment($order, $this->card, ['status' => PaymentStatus::Paid, 'paid_at' => now()]);

        self::assertFalse($this->decode($this->get('/api/admin/orders'))['orders'][0]['actions']['retry'], 'a delivery may still be on its way');
        self::assertSame(422, $this->postJson("/api/admin/orders/{$order->id}/retry")->getStatusCode());

        Carbon::setTestNow(now()->addMinutes(OrderService::STALE_PROCESSING_MINUTES + 1));
        self::assertTrue($this->decode($this->get('/api/admin/orders'))['orders'][0]['actions']['retry']);
        $retried = $this->decode($this->postJson("/api/admin/orders/{$order->id}/retry"))['order'];

        self::assertSame('fulfilled', $retried['status']);
        self::assertSame('50000.00', $this->ali->balance(), 'delivered once');
        self::assertSame([self::text(BotText::WalletCharged, ['balance' => Messages::balance('50000.00')])], $this->telegram()->sentTo(1001));
    }

    public function testAnUnpaidOrderIsCancelledWithItsPaymentsAndTheCustomerIsTold(): void
    {
        $order = $this->purchaseOrder($this->ali, $this->plan, $this->server);
        $older = $this->cardPayment($order, $this->card);
        $receipt = $this->receipt($this->cardPayment($order, $this->card));

        $response = $this->postJson("/api/admin/orders/{$order->id}/cancel", ['note' => 'سفارش تکراری بود']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $row = $this->decode($response)['order'];
        self::assertSame(['cancelled', 'سفارش تکراری بود'], [$row['status'], $row['notes']]);
        self::assertSame(['cancelled', 'cancelled'], array_column($row['payments'], 'status'));
        self::assertSame([$receipt->id, $older->id], array_column($row['payments'], 'id'));
        self::assertSame(self::ADMIN_USERNAME, $receipt->refresh()->reviewer);
        self::assertSame([self::text(BotText::OrderCancelledByAdmin, [
            'order' => $order->id,
            'note' => self::text(BotText::AdminNote, ['comment' => 'سفارش تکراری بود']),
        ])], $this->telegram()->sentTo(1001), 'told once, about the newest payment');
        self::assertSame(self::RECEIPT_MESSAGE, $this->telegram()->replyTarget(0), 'a reply to its receipt');
    }

    /** The orders screen's cancel drops every open payment, a receipt in review too — whichever payment is the newest. */
    public function testCancellingTheOrderDropsAReceiptInReviewToo(): void
    {
        $order = $this->purchaseOrder($this->ali, $this->plan, $this->server);
        $receipt = $this->receipt($this->cardPayment($order, $this->card));
        $newer = $this->cardPayment($order, $this->cardMethod('کارت دوم'));

        $row = $this->decode($this->postJson("/api/admin/orders/{$order->id}/cancel", []))['order'];

        self::assertSame('cancelled', $row['status']);
        self::assertSame([PaymentStatus::Cancelled, PaymentStatus::Cancelled], [$receipt->refresh()->status, $newer->refresh()->status]);
    }

    public function testAnOrderNobodyStartedPayingIsCancelledQuietly(): void
    {
        $order = $this->purchaseOrder($this->ali, $this->plan, $this->server);

        $row = $this->decode($this->postJson("/api/admin/orders/{$order->id}/cancel", []))['order'];

        self::assertSame(['cancelled', OrderService::NOTE_CANCELLED_BY_SUPPORT], [$row['status'], $row['notes']]);
        self::assertSame([], $this->telegram()->calls());
    }

    public function testOnlyAnUnpaidOrderIsCancelledAndTheNoteIsCheckedFirst(): void
    {
        $bought = $this->buy($this->ali, $this->plan, $this->server);
        $response = $this->postJson("/api/admin/orders/{$bought->id}/cancel", []);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['status' => ['این سفارش پرداخت شده است و لغو نمی‌شود؛ برای برگرداندن مبلغ، پرداختش را بازپرداخت کنید.']], $this->decode($response)['errors']);

        $open = $this->purchaseOrder($this->ali, $this->plan, $this->server);
        $payment = $this->cardPayment($open, $this->card);
        $response = $this->postJson("/api/admin/orders/{$open->id}/cancel", ['note' => str_repeat('ا', Input::NOTE_MAX + 1)]);
        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('note', $this->decode($response)['errors']);
        self::assertSame([OrderStatus::Pending, PaymentStatus::Pending], [$open->refresh()->status, $payment->refresh()->status], 'nothing changed');

        self::assertSame(404, $this->postJson('/api/admin/orders/999/cancel', [])->getStatusCode());
        self::assertSame(OrderStatus::Fulfilled, Order::query()->findOrFail($bought->id)->status);
    }

    public function testARefusalSaysWhatTheOrderCameTo(): void
    {
        $refusal = function (Order $order, string $action): string {
            $response = $this->postJson("/api/admin/orders/{$order->id}/{$action}", $action === 'cancel' ? [] : null);
            self::assertSame(422, $response->getStatusCode(), $action);

            return $this->decode($response)['errors']['status'][0];
        };
        // Bought first: a purchase takes up the customer's open order of the same plan.
        $delivered = $this->buy($this->ali, $this->plan, $this->server);
        $open = $this->purchaseOrder($this->ali, $this->plan, $this->server);
        $cancelled = $this->purchaseOrder($this->ali, $this->plan, $this->server, ['status' => OrderStatus::Cancelled]);
        $refunded = $this->purchaseOrder($this->ali, $this->plan, $this->server, ['status' => OrderStatus::Refunded]);
        $delivering = $this->paid($this->purchaseOrder($this->ali, $this->plan, $this->server, ['status' => OrderStatus::Processing]));

        // Cancelled elsewhere a moment ago — another tab, the bot's checkout expiring —: said so, not the rule.
        self::assertSame('این سفارش لغو شده است.', $refusal($cancelled, 'cancel'));
        self::assertSame('مبلغ این سفارش بازپرداخت شده است.', $refusal($refunded, 'cancel'));
        self::assertSame('این سفارش هنوز پرداخت نشده است.', $refusal($open, 'retry'));
        self::assertSame('این سفارش لغو شده است.', $refusal($cancelled, 'retry'));
        self::assertSame('این سفارش تحویل شده است.', $refusal($delivered, 'retry'));
        self::assertSame('تحویل این سفارش در جریان است؛ اگر تا چند دقیقه دیگر تمام نشد، دوباره امتحان کنید.', $refusal($delivering, 'retry'));
    }

    /** The order's money in: a card payment of it, paid. */
    private function paid(Order $order): Order
    {
        $this->cardPayment($order, $this->card, ['status' => PaymentStatus::Paid, 'paid_at' => now()]);

        return $order;
    }
}
