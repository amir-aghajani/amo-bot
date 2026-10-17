<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\ValidationException;
use App\Modules\Accounts\Services\AccountMerger;
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
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Subscriptions\Services\ServiceTransfers;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Enums\WalletTransactionType;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use App\Modules\Users\Services\WalletService;
use App\Support\Money;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
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

        // One moment throughout: a term given and the one taken back are compared to the second.
        Carbon::setTestNow('2026-10-01 12:00:00');
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
        self::assertSame(RefundRefusedException::serviceInUse($renewal->refresh())->getMessage(), $this->decode($refused)['message']);
        self::assertStringContainsString("#{$renewal->id}", $this->decode($refused)['message']);
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        self::assertTrue(Subscription::query()->whereKey($service->id)->exists());

        // Support deleted the service from the services screen: the purchase is refunded with nothing left to remove.
        $this->service(ProvisioningService::class)->delete($service);
        self::assertSame(200, $this->postJson("/api/admin/payments/{$payment->id}/refund", [])->getStatusCode());
        self::assertSame('50000.00', $this->ali->balance());
    }

    /**
     * The refusal says what to do with the other order by its status: one still open holds the service from the services
     * screen's delete too, so only a delivered one is told it may be deleted by hand.
     */
    public function testARefusalForAnotherOrderSaysWhatToDoWithItByItsStatus(): void
    {
        $server = $this->sellingServer('Berlin');
        $plan = $this->plan(['price' => '50000.00'], $server);
        $payment = $this->paidByCard($this->purchaseOrder($this->ali, $plan, $server), $this->card);
        $service = $payment->order->subscription ?? self::fail('nothing delivered');
        $renewal = $this->service(OrderService::class)->createRenewal($this->ali, $service, $plan);
        $refuse = fn(): string => $this->decode($this->postJson("/api/admin/payments/{$payment->id}/refund", []))['message'];

        $deleteTold = 'صفحه اشتراک‌ها حذف کنید';
        foreach ([[OrderStatus::Pending, 'لغو کنید'], [OrderStatus::Processing, 'در حال تحویل'], [OrderStatus::Failed, 'ناموفق']] as [$status, $told]) {
            Order::query()->whereKey($renewal->id)->update(['status' => $status->value]);
            $message = $refuse();
            self::assertSame(RefundRefusedException::serviceInUse($renewal->refresh())->getMessage(), $message);
            self::assertStringContainsString($told, $message, $status->value);
            self::assertStringNotContainsString($deleteTold, $message, "{$status->value}: the services screen refuses to delete it while the order is open");
            self::assertSame(422, $this->postJson("/api/admin/subscriptions/{$service->id}/delete", [])->getStatusCode(), $status->value);
        }
        Order::query()->whereKey($renewal->id)->update(['status' => OrderStatus::Fulfilled->value]);
        self::assertStringContainsString($deleteTold, $refuse(), 'a delivered one leaves the service free to delete');
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status);
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
        self::assertSame($plan->id, $subscription->plan_id, 'a sold service keeps its plan: the renewal did not give it');
        self::assertFalse($renewal->order->gave_plan);
        self::assertNotNull($renewal->order->refresh()->taken_back_at);
        self::assertStringEndsWith(self::text(BotText::PaymentRefundedTakenBack, ['client' => $subscription->remote_name]), $this->telegram()->sentTo(1001)[0]);
    }

    public function testTheRefundOfAResetTakesBackOnlyWhatItAddedToWhatItReplaced(): void
    {
        $server = $this->sellingServer('Berlin');
        $plan = $this->plan(['price' => '50000.00', 'duration_hours' => 720], $server);
        $orders = $this->service(OrderService::class);
        $refunded = function (Payment $payment): void {
            $response = $this->postJson("/api/admin/payments/{$payment->id}/refund", []);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        };
        // Ten days and 10 GB of its 30 left.
        $running = fn(string $name): Subscription => $this->mirrored($this->subscription($this->ali, $plan, $server, $name, ['download_bytes' => 20 * Traffic::GIGABYTE, 'starts_at' => now()->subDays(20), 'expires_at' => now()->addDays(10)]));

        $time = $running('ali_1');
        $payment = $this->paidByCard($orders->createRenewal($this->ali, $time, $plan, RenewalMethod::ResetTime), $this->card);
        self::assertSame([now()->addDays(30)->toIso8601String(), 480], [$time->refresh()->expires_at?->toIso8601String(), $payment->order->refresh()->given_hours], 'thirty days from now: twenty more than it had');
        $refunded($payment);
        self::assertSame([now()->addDays(10)->toIso8601String(), 10 * Traffic::GIGABYTE, SubscriptionStatus::Active], [$time->refresh()->expires_at?->toIso8601String(), $time->remainingBytes(), $time->status], 'its ten days back — not ended at the moment it was renewed');

        $traffic = $running('ali_2');
        $payment = $this->paidByCard($orders->createRenewal($this->ali, $traffic, $plan, RenewalMethod::ResetTraffic), $this->card);
        self::assertSame([30 * Traffic::GIGABYTE, 20 * Traffic::GIGABYTE], [$traffic->refresh()->remainingBytes(), $payment->order->refresh()->given_bytes], 'thirty afresh: twenty more than it had');
        $refunded($payment);
        self::assertSame([10 * Traffic::GIGABYTE, now()->addDays(10)->toIso8601String()], [$traffic->refresh()->remainingBytes(), $traffic->expires_at?->toIso8601String()], 'its 10 GB back — not one byte');

        $waiting = $this->mirrored($this->subscription($this->ali, $plan, $server, 'ali_3', ['starts_at' => null, 'expires_at' => null]));
        $payment = $this->paidByCard($orders->createRenewal($this->ali, $waiting, $plan, RenewalMethod::ResetTime), $this->card);
        self::assertSame(0, $payment->order->refresh()->given_hours, 'its thirty days still waiting replaced by thirty');
        $refunded($payment);
        self::assertSame([720, null, SubscriptionStatus::Active], [$waiting->refresh()->duration_hours, $waiting->expires_at, $waiting->status], 'still its thirty days, waiting for the first connection — not ended');
    }

    public function testNothingIsTakenFromAServiceHandedToAnotherCustomer(): void
    {
        $this->extrasOnSale();
        $server = $this->sellingServer('Berlin');
        $plan = $this->plan(['price' => '50000.00'], $server);
        $sara = $this->customer(['telegram_id' => 7070, 'username' => 'sara']);
        $purchase = $this->paidByCard($this->purchaseOrder($this->ali, $plan, $server), $this->card);
        $service = $purchase->order->subscription ?? self::fail('nothing delivered');
        $renewal = $this->paidByCard($this->service(OrderService::class)->createRenewal($this->ali, $service, $plan), $this->card);
        $extra = $this->paidExtra($service->refresh(), ExtraKind::Traffic, 10, '18000')->payments()->firstOrFail();
        $this->service(ServiceTransfers::class)->transfer($service, $sara, $this->panelActor());
        $kept = static fn(): array => $service->refresh()->only(['user_id', 'traffic_limit_bytes', 'duration_hours', 'ip_limit']);
        $before = [$kept(), count(FakeProvider::$updated)];

        foreach (['the purchase' => $purchase, 'the renewal' => $renewal, 'the extra' => $extra] as $what => $payment) {
            $refused = $this->postJson("/api/admin/payments/{$payment->id}/refund", []);

            self::assertSame(422, $refused->getStatusCode(), $what);
            self::assertSame(RefundRefusedException::serviceChangedHands()->getMessage(), $this->decode($refused)['message'], $what);
            self::assertSame(PaymentStatus::Paid, $payment->refresh()->status, "{$what}: not refunded");
        }
        self::assertSame($before, [$kept(), count(FakeProvider::$updated)], "Sara's service as she got it, its panel untouched");
        self::assertSame('0.00', $this->ali->balance());

        // Support gives it back to Ali, then refunds: taken from his service again.
        $this->service(ServiceTransfers::class)->transfer($service, $this->ali, $this->panelActor());
        self::assertSame(200, $this->postJson("/api/admin/payments/{$extra->id}/refund", [])->getStatusCode());
        self::assertSame(60 * Traffic::GIGABYTE, $service->refresh()->traffic_limit_bytes, 'the purchase\'s and the renewal\'s 30 GB each stand; the extra\'s 10 went');
    }

    public function testAServiceWhoseBuyersAccountsWereMergedIsStillTheirs(): void
    {
        $server = $this->sellingServer('Berlin');
        $web = $this->webCustomer();
        $payment = $this->paidByCard($this->purchaseOrder($web, $this->plan(['price' => '50000.00'], $server), $server), $this->card);
        $service = $payment->order->subscription ?? self::fail('nothing delivered');

        $this->service(AccountMerger::class)->merge($this->ali, $web, 'root');

        $refunded = $this->postJson("/api/admin/payments/{$payment->id}/refund", []);
        self::assertSame(200, $refunded->getStatusCode(), (string) $refunded->getBody());
        self::assertFalse(Subscription::query()->whereKey($service->id)->exists(), 'the order went with its service: the purchase took it back');
    }

    public function testTheRefundOfTheRenewalThatGaveAClaimedServiceItsPlanTakesThePlanBack(): void
    {
        $server = $this->sellingServer('Berlin');
        $plan = $this->plan(['price' => '50000.00', 'duration_hours' => 720], $server);
        $claimed = $this->mirrored($this->claimedSubscription($this->ali, $server, 'manual_1'));
        $first = $this->paidByCard($this->service(OrderService::class)->createRenewal($this->ali, $claimed, $plan), $this->card);
        self::assertSame($plan->id, $claimed->refresh()->plan_id, 'the renewal gave it its plan');
        $claimed->forceFill(['auto_renew' => true])->save();

        $refunded = $this->postJson("/api/admin/payments/{$first->id}/refund", []);

        self::assertSame(200, $refunded->getStatusCode(), (string) $refunded->getBody());
        self::assertSame([null, false], [$claimed->refresh()->plan_id, $claimed->auto_renew], 'refunded, it has no plan again: its next renewal picks one');
    }

    public function testAPlanAClaimedServiceCameBackWithStaysWhenItsRenewalIsRefunded(): void
    {
        $server = $this->sellingServer('Berlin');
        $plan = $this->plan(['price' => '50000.00', 'duration_hours' => 720], $server);
        // Claimed again from a record of the customer's the shop had listed gone, its plan carried over.
        $claimed = $this->mirrored($this->claimedSubscription($this->ali, $server, 'manual_1', ['plan_id' => $plan->id, 'auto_renew' => true]));
        $renewal = $this->paidByCard($this->service(OrderService::class)->createRenewal($this->ali, $claimed, $plan), $this->card);
        self::assertFalse($renewal->order->refresh()->gave_plan, 'it had its plan before');

        self::assertSame(200, $this->postJson("/api/admin/payments/{$renewal->id}/refund", [])->getStatusCode());
        self::assertSame([$plan->id, true], [$claimed->refresh()->plan_id, $claimed->auto_renew]);
    }

    public function testARefundedLaterRenewalLeavesAClaimedServiceOnItsPlan(): void
    {
        $server = $this->sellingServer('Berlin');
        $plan = $this->plan(['price' => '50000.00', 'duration_hours' => 720], $server);
        $claimed = $this->mirrored($this->claimedSubscription($this->ali, $server, 'manual_1'));
        $this->paidByCard($this->service(OrderService::class)->createRenewal($this->ali, $claimed, $plan), $this->card);
        $second = $this->paidByCard($this->service(OrderService::class)->createRenewal($this->ali, $claimed->refresh(), $plan), $this->card);

        self::assertSame(200, $this->postJson("/api/admin/payments/{$second->id}/refund", [])->getStatusCode());
        self::assertSame($plan->id, $claimed->refresh()->plan_id, 'the first renewal still stands on it');
    }

    public function testARefundedRenewalLeavesAServiceThatNeverEndedAndHadNoQuotaSoAgain(): void
    {
        $server = $this->sellingServer('Berlin');
        $plan = $this->plan(['price' => '50000.00', 'duration_hours' => 720], $server);
        $orders = $this->service(OrderService::class);
        $refunded = function (Payment $payment): void {
            $response = $this->postJson("/api/admin/payments/{$payment->id}/refund", []);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        };
        // Made by hand on its panel, without an end or a quota, and claimed by its link.
        $endless = fn(string $name): Subscription => $this->mirrored($this->claimedSubscription($this->ali, $server, $name, ['duration_hours' => 0, 'starts_at' => null, 'expires_at' => null, 'traffic_limit_bytes' => 0]));
        $state = static fn(Subscription $service): array => [$service->refresh()->status, $service->duration_hours, $service->expires_at, $service->traffic_limit_bytes, $service->plan_id, $service->ended_at];

        $service = $endless('hand_1');
        $renewal = $this->paidByCard($orders->createRenewal($this->ali, $service, $plan), $this->card);
        self::assertSame([720, 30 * Traffic::GIGABYTE, $plan->id], [$service->refresh()->duration_hours, $service->traffic_limit_bytes, $plan->id], 'renewed: thirty days and 30 GB, on the plan');
        self::assertSame([true, true, true], [$renewal->order->refresh()->gave_term, $renewal->order->gave_quota, $renewal->order->gave_plan]);
        $refunded($renewal);
        self::assertSame([SubscriptionStatus::Active, 0, null, 0, null, null], $state($service), 'never ending, unlimited and without a plan again — not ended now, nothing for the clean-up to remove');
        $spec = FakeProvider::lastUpdate('hand_1');
        self::assertSame([null, null, 0], [$spec->expiry->deadline(), $spec->expiry->pendingSeconds(), $spec->totalBytes], 'on its panel too');

        foreach (['the first first' => true, 'the second first' => false] as $case => $firstFirst) {
            $service = $endless($firstFirst ? 'hand_2' : 'hand_3');
            $first = $this->paidByCard($orders->createRenewal($this->ali, $service, $plan), $this->card);
            $second = $this->paidByCard($orders->createRenewal($this->ali, $service->refresh(), $plan), $this->card);
            $refunded($firstFirst ? $first : $second);
            self::assertSame([720, 30 * Traffic::GIGABYTE, $plan->id], [$service->refresh()->duration_hours, $service->traffic_limit_bytes, $service->plan_id], "{$case}: the other's thirty days and 30 GB stand, on its plan");
            $refunded($firstFirst ? $second : $first);
            self::assertSame([SubscriptionStatus::Active, 0, null, 0, null, null], $state($service), "{$case}: both refunded, as it was before either");
        }
    }

    public function testAnEarlierOrdersRefundLeavesWhatALaterRenewalStartedAfresh(): void
    {
        $this->extrasOnSale();
        $server = $this->sellingServer('Berlin');
        $plan = $this->plan(['price' => '50000.00', 'duration_hours' => 720], $server);
        $long = $this->plan(['price' => '90000.00', 'duration_hours' => 60 * 24, 'traffic_gb' => 50], $server);
        $orders = $this->service(OrderService::class);
        $refunded = function (Payment $payment): void {
            $response = $this->postJson("/api/admin/payments/{$payment->id}/refund", []);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        };

        foreach (['the earlier first' => true, 'the reset first' => false] as $case => $earlierFirst) {
            Carbon::setTestNow('2026-10-01 12:00:00');
            // Two days and 20 GB of its 30 left; extended by thirty days, then — a day later — its time reset to sixty.
            $time = $this->mirrored($this->subscription($this->ali, $plan, $server, $earlierFirst ? 'ali_1' : 'ali_2', ['download_bytes' => 10 * Traffic::GIGABYTE, 'starts_at' => now()->subDays(28), 'expires_at' => now()->addDays(2)]));
            $extension = $this->paidByCard($orders->createRenewal($this->ali, $time, $plan), $this->card);
            Carbon::setTestNow('2026-10-02 12:00:00');
            $reset = $this->paidByCard($orders->createRenewal($this->ali, $time->refresh(), $long, RenewalMethod::ResetTime), $this->card);
            self::assertSame(['2026-12-01 12:00:00', 696, true], [$time->refresh()->expires_at?->format('Y-m-d H:i:s'), $reset->order->refresh()->given_hours, $reset->order->reset_term], 'sixty days from now in place of the thirty-one left');

            $refunded($earlierFirst ? $extension : $reset);
            self::assertSame([$earlierFirst ? '2026-12-01 12:00:00' : '2026-11-02 12:00:00'], [$time->refresh()->expires_at?->format('Y-m-d H:i:s')], $earlierFirst
                ? "{$case}: the reset replaced the extension's days — none of its sixty taken"
                : "{$case}: the reset's twenty-nine days beyond what it replaced taken back");
            $refunded($earlierFirst ? $reset : $extension);
            self::assertSame(['2026-10-03 12:00:00', 20 * Traffic::GIGABYTE, SubscriptionStatus::Active], [$time->refresh()->expires_at?->format('Y-m-d H:i:s'), $time->remainingBytes(), $time->status], "{$case}: both refunded, the day and the 20 GB it had");

            // 20 GB of its 30 left, 10 GB bought on top, then its traffic reset to 50.
            $traffic = $this->mirrored($this->subscription($this->ali, $plan, $server, $earlierFirst ? 'ali_3' : 'ali_4', ['download_bytes' => 10 * Traffic::GIGABYTE, 'expires_at' => now()->addDays(10)]));
            $extra = $this->paidExtra($traffic, ExtraKind::Traffic, 10, '18000')->payments()->firstOrFail();
            $renewal = $this->paidByCard($orders->createRenewal($this->ali, $traffic->refresh(), $long, RenewalMethod::ResetTraffic), $this->card);
            self::assertSame([50 * Traffic::GIGABYTE, 20 * Traffic::GIGABYTE, true], [$traffic->refresh()->remainingBytes(), $renewal->order->refresh()->given_bytes, $renewal->order->reset_traffic]);

            $refunded($earlierFirst ? $extra : $renewal);
            self::assertSame($earlierFirst ? 50 * Traffic::GIGABYTE : 30 * Traffic::GIGABYTE, $traffic->refresh()->remainingBytes(), $earlierFirst
                ? "{$case}: the reset counted the extra's traffic afresh — none of its 50 GB taken"
                : "{$case}: the reset's 20 GB beyond what it replaced taken back");
            $refunded($earlierFirst ? $renewal : $extra);
            self::assertSame([20 * Traffic::GIGABYTE, '2026-10-12 12:00:00'], [$traffic->refresh()->remainingBytes(), $traffic->expires_at?->format('Y-m-d H:i:s')], "{$case}: both refunded, the 20 GB and the days it had");
        }
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

    public function testAServiceSwitchedOffIsTakenFromAndStaysOff(): void
    {
        $this->extrasOnSale();
        $server = $this->sellingServer('Berlin');
        $subscription = $this->subscription($this->ali, $this->plan(['price' => '50000.00'], $server), $server, 'ali_1', ['expires_at' => now()->addDays(10)]);
        FakeProvider::mirror($subscription);
        $order = $this->paidExtra($subscription, ExtraKind::Traffic, 10, '18000');
        $this->service(ProvisioningService::class)->setEnabled($subscription, false);

        $refunded = $this->postJson("/api/admin/payments/{$order->payments()->firstOrFail()->id}/refund", []);

        self::assertSame(200, $refunded->getStatusCode(), (string) $refunded->getBody());
        self::assertSame([30 * Traffic::GIGABYTE, SubscriptionStatus::Disabled], [$subscription->refresh()->traffic_limit_bytes, $subscription->status], 'its 10 GB back, support\'s switch as it was');
        self::assertFalse(FakeProvider::lastUpdate('ali_1')->enabled, 'the update keeps it switched off');
        self::assertFalse(FakeProvider::$clients[$server->id]['ali_1']->enabled);
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
