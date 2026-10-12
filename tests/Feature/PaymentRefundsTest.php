<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\ValidationException;
use App\Modules\Agency\Models\TrafficTransaction;
use App\Modules\Agency\Services\TrafficPool;
use App\Modules\Bots\CurrentBot;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\RefundRefusedException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Enums\WalletTransactionType;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use App\Modules\Users\Services\WalletService;
use App\Support\Money;
use App\Support\Traffic;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * Refunds by what the order bought: a purchase's money to the wallet and its service off its panel and the shop —
 * refused, with nothing refunded, while its panel fails or another order holds the service, and in an agent's shop the
 * sale's traffic back in their pool; a renewal's or an extra's money to the wallet and what it gave its service taken
 * back, once (refused while the service is switched off or its panel fails); an agent's traffic out of their pool
 * (refused once sold); a top-up out of the wallet (refused once spent). Failed deliveries are refundable with nothing
 * taken back, running ones are not, nor one delivered since it was read; the customer is notified; the note fits a
 * ledger line.
 */
final class PaymentRefundsTest extends HttpTestCase
{
    private User $ali;
    private PaymentMethod $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram();
        $this->loginAsAdmin();

        $this->ali = $this->customer(['telegram_id' => 1001, 'username' => 'ali_r']);
        $this->card = $this->cardMethod();
    }

    public function testAPurchaseIsRefundedToTheWalletAndItsServiceGoes(): void
    {
        $server = $this->sellingServer('Berlin');
        $payment = $this->paidByCard($this->purchaseOrder($this->ali, $this->plan(['price' => '50000.00'], $server), $server), $this->card);
        $service = $payment->order->subscription ?? self::fail('nothing delivered');
        $this->telegram()->reset();

        $refunded = $this->postJson("/api/admin/payments/{$payment->id}/refund", ['note' => 'اشتباه واریز شده بود']);

        self::assertSame(200, $refunded->getStatusCode(), (string) $refunded->getBody());
        $row = $this->decode($refunded)['payment'];
        self::assertSame(['refunded', 'refunded', 'اشتباه واریز شده بود'], [$row['status'], $row['order']['status'], $row['refund_note']]);
        self::assertSame('50000.00', $this->ali->balance(), 'the amount went to the wallet');
        self::assertFalse(Subscription::query()->whereKey($service->id)->exists(), 'its service went with it');
        self::assertSame([['server' => $server->id, 'name' => $service->remote_name]], FakeProvider::$deleted, 'off its panel too');
        self::assertSame(WalletService::describeRefund($payment, 'اشتباه واریز شده بود'), $this->lastLine()->description);
        self::assertSame([self::text(BotText::PaymentRefunded, [
            'amount' => Money::format('50000'),
            'order' => $payment->order_id,
            'note' => self::text(BotText::AdminNote, ['comment' => 'اشتباه واریز شده بود']),
            'balance' => Messages::balance('50000.00'),
        ]) . self::text(BotText::PaymentRefundedService, ['client' => $service->remote_name])], $this->telegram()->sentTo(1001), 'one message: the money and the service');
        self::assertSame(self::RECEIPT_MESSAGE, $this->telegram()->replyTarget(0), 'a reply to the receipt');
        self::assertArrayNotHasKey('reply_markup', $this->telegram()->params(0), 'no keyboard on a verdict');

        self::assertSame(422, $this->postJson("/api/admin/payments/{$payment->id}/refund", [])->getStatusCode(), 'once');
        self::assertSame(422, $this->postJson("/api/admin/payments/{$payment->id}/retry")->getStatusCode(), 'nothing failed');
    }

    public function testAPanelThatFailsRefundsNothing(): void
    {
        $server = $this->sellingServer('Berlin');
        $payment = $this->paidByCard($this->purchaseOrder($this->ali, $this->plan(['price' => '50000.00'], $server), $server), $this->card);
        FakeProvider::$unreachable = true;
        $this->telegram()->reset();

        $refused = $this->postJson("/api/admin/payments/{$payment->id}/refund", []);

        self::assertSame(422, $refused->getStatusCode(), (string) $refused->getBody());
        self::assertStringStartsWith('سرویس این سفارش روی پنلش تغییر نکرد و چیزی بازپرداخت نشد', $this->decode($refused)['message']);
        self::assertSame([PaymentStatus::Paid, SubscriptionStatus::Active], [$payment->refresh()->status, $payment->order->subscription?->status], 'nothing moved');
        self::assertSame('0.00', $this->ali->balance());
        self::assertSame([], $this->telegram()->calls());
    }

    public function testAServiceAnotherOrderHoldsIsNotTakenBack(): void
    {
        $server = $this->sellingServer('Berlin');
        $plan = $this->plan(['price' => '50000.00'], $server);
        $payment = $this->paidByCard($this->purchaseOrder($this->ali, $plan, $server), $this->card);
        $service = $payment->order->subscription ?? self::fail('nothing delivered');
        $renewal = $this->renew($service);

        $refused = $this->postJson("/api/admin/payments/{$payment->id}/refund", []);

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(RefundRefusedException::serviceInUse($renewal->id)->getMessage(), $this->decode($refused)['message']);
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        self::assertTrue(Subscription::query()->whereKey($service->id)->exists());

        // Support deleted the service from the services screen: the purchase is refunded with nothing left to remove.
        $this->service(ProvisioningService::class)->delete($service);
        self::assertSame(200, $this->postJson("/api/admin/payments/{$payment->id}/refund", [])->getStatusCode());
        self::assertSame('50000.00', $this->ali->balance());
    }

    public function testAnAgentsRefundedSaleGivesItsTrafficBack(): void
    {
        $server = $this->sellingServer('Berlin');
        $bot = $this->agentBot(traffic: 10);
        CurrentBot::run($bot, function () use ($bot, $server): void {
            $reza = $this->customer(['telegram_id' => 7003, 'username' => 'reza']);
            $order = $this->buy($reza, $this->plan(['price' => '50000.00', 'traffic_gb' => 4], $server), $server);
            self::assertSame(Traffic::bytesOfGb(6), $bot->trafficBalance());

            $this->service(PaymentActions::class)->refund($order->payments()->firstOrFail(), $this->panelActor(), null);

            self::assertSame(Traffic::bytesOfGb(10), $bot->trafficBalance(), 'its 4 GB back');
            self::assertSame(sprintf(TrafficPool::LINE_REFUNDED, $order->id), TrafficTransaction::query()->latest('id')->value('description'));
            self::assertFalse(Subscription::query()->where('user_id', $reza->id)->exists());
        });
    }

    public function testARenewalsRefundTakesBackWhatItGave(): void
    {
        $server = $this->sellingServer('Berlin');
        $plan = $this->plan(['price' => '50000.00', 'duration_hours' => 720], $server);
        $subscription = $this->buy($this->ali, $plan, $server)->subscription ?? self::fail('nothing delivered');
        $renewal = $this->paidByCard($this->service(OrderService::class)->createRenewal($this->ali, $subscription, $plan), $this->card);
        self::assertSame([OrderStatus::Fulfilled, 60 * 24], [$renewal->order->status, $subscription->refresh()->duration_hours], 'renewed: a term not started yet runs longer');
        self::assertSame([720, 30 * Traffic::GIGABYTE], [$renewal->order->refresh()->given_hours, $renewal->order->given_bytes], 'what it gave, kept');
        // The plan changes after the renewal: the refund takes back what the renewal gave, not what the plan gives now.
        $plan->forceFill(['duration_hours' => 24 * 90, 'traffic_gb' => 100])->save();
        $this->telegram()->reset();

        $row = $this->decode($this->postJson("/api/admin/payments/{$renewal->id}/refund", []))['payment'];

        self::assertSame(['refunded', 'refunded'], [$row['status'], $row['order']['status']]);
        self::assertSame('50000.00', $this->ali->balance(), 'the amount went to the wallet');
        $subscription->refresh();
        self::assertSame([SubscriptionStatus::Active, 720, 30 * Traffic::GIGABYTE], [$subscription->status, $subscription->duration_hours, $subscription->traffic_limit_bytes], 'its term and traffic back to what the purchase gave');
        self::assertNotNull($renewal->order->refresh()->taken_back_at);
        self::assertStringEndsWith(self::text(BotText::PaymentRefundedTakenBack, ['client' => $subscription->remote_name]), $this->telegram()->sentTo(1001)[0]);
    }

    public function testARenewalNeverDeliveredHasNothingTakenBack(): void
    {
        $server = $this->sellingServer('Berlin');
        $plan = $this->plan(['price' => '50000.00', 'duration_hours' => 720], $server);
        $subscription = $this->buy($this->ali, $plan, $server)->subscription ?? self::fail('nothing delivered');
        FakeProvider::$refusing[$server->id] = ['updateClient'];
        $renewal = $this->paidByCard($this->service(OrderService::class)->createRenewal($this->ali, $subscription, $plan), $this->card);
        self::assertSame(OrderStatus::Failed, $renewal->order->status);
        FakeProvider::$refusing = [];

        self::assertSame(200, $this->postJson("/api/admin/payments/{$renewal->id}/refund", [])->getStatusCode());

        $subscription->refresh();
        self::assertSame([SubscriptionStatus::Active, 720, 30 * Traffic::GIGABYTE], [$subscription->status, $subscription->duration_hours, $subscription->traffic_limit_bytes], 'it gave nothing, and nothing is taken');
        self::assertNull($renewal->order->refresh()->taken_back_at);
        self::assertSame('50000.00', $this->ali->balance());
    }

    public function testAnOrderDeliveredSinceItWasJudgedIsNotRefunded(): void
    {
        $server = $this->sellingServer('Berlin');
        $plan = $this->plan(['price' => '50000.00', 'duration_hours' => 720], $server);
        $subscription = $this->buy($this->ali, $plan, $server)->subscription ?? self::fail('nothing delivered');
        FakeProvider::$refusing[$server->id] = ['updateClient'];
        $renewal = $this->paidByCard($this->service(OrderService::class)->createRenewal($this->ali, $subscription, $plan), $this->card);
        self::assertSame(OrderStatus::Failed, $renewal->order->status, 'read as failed: nothing to take back');
        FakeProvider::$refusing = [];

        // Support's retry delivers it before the refund takes the order.
        self::assertTrue($this->service(OrderService::class)->deliver(Order::query()->findOrFail($renewal->order_id)));
        try {
            $this->service(PaymentActions::class)->refund($renewal, $this->panelActor(), null);
            self::fail('A renewal delivered meanwhile was refunded with what it gave.');
        } catch (ValidationException $e) {
            self::assertSame([PaymentService::DELIVERY_RUNNING], $e->errors()['status']);
        }
        self::assertSame([PaymentStatus::Paid, OrderStatus::Fulfilled, 60 * 24], [$renewal->refresh()->status, $renewal->order->status, $subscription->refresh()->duration_hours], 'nothing moved');

        // Tried again, it is judged as it is: what it gave goes, then the money.
        $this->service(PaymentActions::class)->refund($renewal, $this->panelActor(), null);
        self::assertSame([720, '50000.00'], [$subscription->refresh()->duration_hours, $this->ali->balance()]);
    }

    public function testAnExtrasRefundTakesBackItsTrafficOrDays(): void
    {
        $this->extrasOnSale();
        $server = $this->sellingServer('Berlin');
        $subscription = $this->subscription($this->ali, $this->plan(['price' => '50000.00'], $server), $server, 'ali_1', ['expires_at' => now()->addDays(10)]);
        FakeProvider::mirror($subscription);
        $traffic = $this->paidExtra($subscription, ExtraKind::Traffic, 10, '18000');
        $time = $this->paidExtra($subscription, ExtraKind::Time, 7, '7000');
        self::assertSame([40 * Traffic::GIGABYTE, now()->addDays(17)->toIso8601String()], [$subscription->traffic_limit_bytes, $subscription->expires_at?->toIso8601String()]);

        $this->service(PaymentActions::class)->refund($traffic->payments()->firstOrFail(), $this->panelActor(), null);
        self::assertSame(30 * Traffic::GIGABYTE, $subscription->refresh()->traffic_limit_bytes, 'its 10 GB back');

        $this->service(PaymentActions::class)->refund($time->payments()->firstOrFail(), $this->panelActor(), null);
        self::assertSame(now()->addDays(10)->toIso8601String(), $subscription->refresh()->expires_at?->toIso8601String(), 'its 7 days back');
    }

    public function testWhatAnOrderGaveIsTakenBackOnce(): void
    {
        $this->extrasOnSale();
        $server = $this->sellingServer('Berlin');
        $subscription = $this->subscription($this->ali, $this->plan(['price' => '50000.00'], $server), $server, 'ali_1', ['expires_at' => now()->addDays(10)]);
        FakeProvider::mirror($subscription);
        $order = $this->paidExtra($subscription, ExtraKind::Traffic, 10, '18000');

        // The panel took it back, then the refund was decided otherwise; the next try takes nothing more.
        self::assertTrue($this->service(ProvisioningService::class)->undo($order, $subscription));
        self::assertTrue($this->service(ProvisioningService::class)->undo($order, $subscription));

        self::assertSame(30 * Traffic::GIGABYTE, $subscription->refresh()->traffic_limit_bytes);
    }

    public function testAServiceSwitchedOffIsNotTakenFrom(): void
    {
        $this->extrasOnSale();
        $server = $this->sellingServer('Berlin');
        $subscription = $this->subscription($this->ali, $this->plan(['price' => '50000.00'], $server), $server, 'ali_1', ['expires_at' => now()->addDays(10)]);
        FakeProvider::mirror($subscription);
        $order = $this->paidExtra($subscription, ExtraKind::Traffic, 10, '18000');
        $subscription->forceFill(['status' => SubscriptionStatus::Disabled])->save();

        $refused = $this->postJson("/api/admin/payments/{$order->payments()->firstOrFail()->id}/refund", []);

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(RefundRefusedException::serviceDisabled()->getMessage(), $this->decode($refused)['message']);
        self::assertSame(40 * Traffic::GIGABYTE, $subscription->refresh()->traffic_limit_bytes, 'nothing taken');
        self::assertCount(1, FakeProvider::$updated, 'its panel never asked: an update would switch it on');
    }

    public function testAnExtraWhosePanelFailsWaitsForThePanel(): void
    {
        $this->extrasOnSale();
        $server = $this->sellingServer('Berlin');
        $subscription = $this->subscription($this->ali, $this->plan(['price' => '50000.00'], $server), $server, 'ali_1', ['expires_at' => now()->addDays(10)]);
        FakeProvider::mirror($subscription);
        $order = $this->paidExtra($subscription, ExtraKind::Traffic, 10, '18000');
        FakeProvider::$unreachable = true;

        $refused = $this->postJson("/api/admin/payments/{$order->payments()->firstOrFail()->id}/refund", []);

        self::assertSame(422, $refused->getStatusCode());
        self::assertStringEndsWith('وقتی پنل جواب داد، دوباره بازپرداخت کنید.', $this->decode($refused)['message'], 'no advice to delete the service for its extra');
        self::assertSame([PaymentStatus::Paid, 40 * Traffic::GIGABYTE], [$order->payments()->firstOrFail()->status, $subscription->refresh()->traffic_limit_bytes]);
    }

    public function testAnAgentsRefundedRenewalGivesItsTrafficBack(): void
    {
        $server = $this->sellingServer('Berlin');
        $bot = $this->agentBot(traffic: 10);
        CurrentBot::run($bot, function () use ($bot, $server): void {
            $reza = $this->customer(['telegram_id' => 7003, 'username' => 'reza']);
            $plan = $this->plan(['price' => '50000.00', 'traffic_gb' => 4], $server);
            $service = $this->buy($reza, $plan, $server)->subscription ?? self::fail('nothing delivered');
            $renewal = $this->renew($service);
            self::assertSame(Traffic::bytesOfGb(2), $bot->trafficBalance());

            $this->service(PaymentActions::class)->refund($renewal->payments()->firstOrFail(), $this->panelActor(), null);

            self::assertSame(Traffic::bytesOfGb(6), $bot->trafficBalance(), 'the renewal\'s 4 GB back');
            self::assertSame(4 * Traffic::GIGABYTE, $service->refresh()->traffic_limit_bytes);
        });
    }

    public function testATopUpRefundTakesTheTopUpBackOutOfTheWalletWhileItIsThere(): void
    {
        $payment = $this->paidByCard($this->topUpOrder($this->ali, '50000.00'), $this->card);
        $spent = $this->paidByCard($this->topUpOrder($this->ali, '30000.00'), $this->card);
        $this->service(WalletService::class)->debit($this->ali, '60000', 'خرید');
        $this->telegram()->reset();

        // 20,000 left: the 30,000 top-up comes back out — but not the 50,000 one, spent since.
        $refused = $this->postJson("/api/admin/payments/{$payment->id}/refund", []);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(RefundRefusedException::topUpSpent('20000.00')->getMessage(), $this->decode($refused)['message']);
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status, 'nothing moved');
        self::assertSame([], $this->telegram()->calls());

        $this->service(WalletService::class)->credit($this->ali, '10000', 'هدیه');
        $refunded = $this->postJson("/api/admin/payments/{$spent->id}/refund", ['note' => 'برگشت به کارت']);
        self::assertSame(200, $refunded->getStatusCode(), (string) $refunded->getBody());
        self::assertSame('0.00', $this->ali->balance(), 'the money went back to the card, outside the shop: the wallet gives the top-up back');
        $line = $this->lastLine();
        self::assertSame([WalletTransactionType::Debit, '30000.00', WalletService::describeTopUpRefund($spent, 'برگشت به کارت')], [$line->type, $line->amount, $line->description]);
        self::assertSame([self::text(BotText::TopupRefunded, [
            'amount' => Money::format('30000'),
            'order' => $spent->order_id,
            'note' => self::text(BotText::AdminNote, ['comment' => 'برگشت به کارت']),
            'balance' => Messages::balance('0.00'),
        ])], $this->telegram()->sentTo(1001));
    }

    public function testAnAgentsTopUpIsTakenBackAsFarAsTheirCreditGoes(): void
    {
        $agent = $this->agent(credit: '100000', overrides: ['telegram_id' => 7007]);
        $payment = $this->paidByCard($this->topUpOrder($agent, '50000.00'), $this->card);
        $this->service(WalletService::class)->debit($agent, '80000', 'خرید', $agent->credit());

        $response = $this->postJson("/api/admin/payments/{$payment->id}/refund", []);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('-80000.00', $agent->balance(), 'spent, and the top-up still came back out — into their credit');
    }

    public function testAnAgentsTrafficRefundTakesItsTrafficBackWhileTheBotHasIt(): void
    {
        $agent = $this->agent(overrides: ['telegram_id' => 7007]);
        $bot = $this->agentBot($agent, traffic: 0);
        $order = $this->service(OrderService::class)->openTraffic($agent, 20);
        $payment = $this->paidByCard($order, $this->card);
        self::assertSame(Traffic::bytesOfGb(20), $bot->trafficBalance());
        $this->traffic($bot, 15);

        $sold = $this->postJson("/api/admin/payments/{$payment->id}/refund", []);
        self::assertSame(422, $sold->getStatusCode(), 'some of it was sold since');
        self::assertSame(RefundRefusedException::trafficSold(Traffic::bytesOfGb(15))->getMessage(), $this->decode($sold)['message']);

        $this->traffic($bot, 25);
        self::assertSame(200, $this->postJson("/api/admin/payments/{$payment->id}/refund", [])->getStatusCode());
        self::assertSame(Traffic::bytesOfGb(5), $bot->trafficBalance(), 'its 20 GB came back out');
        self::assertSame($order->amount, $agent->balance(), 'and the money went to their wallet');
    }

    public function testATopUpOrTrafficNeverDeliveredHasNothingToTakeBack(): void
    {
        $topUp = $this->paidUndelivered($this->service(OrderService::class)->openTopUp($this->ali, 50000));

        $row = $this->decode($this->postJson("/api/admin/payments/{$topUp->id}/refund", []))['payment'];
        self::assertSame(['refunded', 'refunded'], [$row['status'], $row['order']['status']]);
        self::assertSame('0.00', $this->ali->balance(), 'nothing landed in the wallet, and nothing comes out of it');
        self::assertSame([self::text(BotText::TopupRefundedUndelivered, [
            'amount' => Money::format('50000'),
            'order' => $topUp->order_id,
            'note' => '',
        ])], $this->telegram()->sentTo(1001), 'and the customer is told so — not that it came out of their wallet');

        $agent = $this->agent(overrides: ['telegram_id' => 7007]);
        $bot = $this->traffic($this->agentBot($agent, traffic: 0), 25);
        $traffic = $this->paidUndelivered($this->service(OrderService::class)->openTraffic($agent, 20));

        self::assertSame(200, $this->postJson("/api/admin/payments/{$traffic->id}/refund", [])->getStatusCode());
        self::assertSame(Traffic::bytesOfGb(25), $bot->trafficBalance(), 'nothing was added to the pool, nothing comes out of it');
        self::assertSame($traffic->amount, $agent->balance(), 'the money goes to their wallet');
    }

    public function testRefundingAFailedDeliveryClosesTheOrderAndOneUnderWayWaits(): void
    {
        $server = $this->sellingServer('Berlin', ['is_active' => false]);
        $payment = $this->paidByCard($this->purchaseOrder($this->ali, $this->plan(['price' => '50000.00'], $server), $server), $this->card);
        self::assertSame(OrderStatus::Failed, $payment->order->status);

        // A retry claimed it for delivery: the money is not given back under it.
        Order::query()->whereKey($payment->order_id)->update(['status' => OrderStatus::Processing->value]);
        self::assertFalse($this->decode($this->get("/api/admin/payments/{$payment->id}"))['payment']['actions']['refund']);
        self::assertSame(422, $this->postJson("/api/admin/payments/{$payment->id}/refund", [])->getStatusCode());
        self::assertSame([PaymentStatus::Paid, '0.00'], [$payment->refresh()->status, $this->ali->balance()]);

        // That delivery failed too.
        Order::query()->whereKey($payment->order_id)->update(['status' => OrderStatus::Failed->value]);
        $stuck = fn(): int => $this->decode($this->get('/api/admin/queues'))['queues']['stuck_orders'];
        self::assertSame(1, $stuck(), 'support may deliver it again');
        $row = $this->decode($this->postJson("/api/admin/payments/{$payment->id}/refund", []))['payment'];
        self::assertSame(['refunded', 'refunded'], [$row['status'], $row['order']['status']], 'the order is closed: its retry goes with the money');
        self::assertFalse($row['actions']['retry']);
        self::assertSame(0, $stuck(), 'nothing is owed any more: out of the queue with it');
        self::assertSame('50000.00', $this->ali->balance());
    }

    public function testARefundsNoteIsHeldToTheWalletLineItIsWrittenOn(): void
    {
        $server = $this->sellingServer('Berlin');
        $payment = $this->paidByCard($this->purchaseOrder($this->ali, $this->plan(['price' => '50000.00'], $server), $server), $this->card);

        $long = $this->postJson("/api/admin/payments/{$payment->id}/refund", ['note' => str_repeat('ی', PaymentService::REFUND_NOTE_MAX + 1)]);
        self::assertSame(422, $long->getStatusCode());
        self::assertArrayHasKey('note', $this->decode($long)['errors']);
        self::assertSame([PaymentStatus::Paid, '0.00'], [$payment->refresh()->status, $this->ali->balance()], 'refused before anything moved');

        $note = str_repeat('ی', PaymentService::REFUND_NOTE_MAX);
        self::assertSame(200, $this->postJson("/api/admin/payments/{$payment->id}/refund", ['note' => $note])->getStatusCode());
        $line = (string) $this->lastLine()->description;
        self::assertSame(WalletService::describeRefund($payment, $note), $line);
        // wallet_transactions.description is a string of 255 (database/schema.php): MySQL refuses a longer one.
        self::assertLessThanOrEqual(255, mb_strlen($line), 'the longest note leaves the line within its column');
    }

    /** The order paid by card, and the process that was to deliver it gone before it started. */
    private function paidUndelivered(Order $order): Payment
    {
        self::assertTrue($this->service(OrderService::class)->markPaid($order));

        return $this->cardPayment($order, $this->card, ['status' => PaymentStatus::Paid, 'paid_at' => now()]);
    }

    private function lastLine(): WalletTransaction
    {
        return WalletTransaction::query()->where('user_id', $this->ali->id)->latest('id')->firstOrFail();
    }
}
