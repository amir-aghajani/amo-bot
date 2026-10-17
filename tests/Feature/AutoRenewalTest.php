<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Services\ProviderErrorPresenter;
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\AutoRenewUnavailableException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\AutoRenewal;
use App\Modules\Subscriptions\Tasks\AutoRenewTask;
use App\Modules\Subscriptions\Tasks\SyncSubscriptionsTask;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Handlers\TopUpHandler;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletService;
use App\Support\LocalTime;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;

/**
 * «تمدید خودکار»: the customer's switch on the service screen, and the task that renews a service from the wallet once its
 * deadline is within the admin's days — once, never while a renewal of it is open; a short wallet notified once per window; a
 * panel out of reach or in backoff waited for with nothing charged; a renewal paid but not delivered left to support.
 */
final class AutoRenewalTest extends BotTestCase
{
    private User $ali;
    private Server $server;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-20 12:00:00');
        $this->fakePanel();
        $this->ali = $this->customer(['username' => 'ali']);
        $this->server = $this->fakeServer();
        $this->plan = $this->plan();
    }

    public function testAServiceWithinTheWindowIsRenewedFromTheWalletOnce(): void
    {
        $subscription = $this->due('ali_1');
        $this->wallet($this->ali, '300000');

        $this->renewNow();

        $order = Order::query()->sole();
        self::assertSame([OrderType::Renewal, OrderStatus::Fulfilled, $subscription->id], [$order->type, $order->status, $order->subscription_id]);
        self::assertSame(PaymentStatus::Paid, Payment::query()->where('order_id', $order->id)->sole()->status);
        self::assertSame('180000.00', $this->ali->balance(), 'the plan\'s price, from the wallet');
        $subscription->refresh();
        self::assertSame('2026-10-21 12:00:00', $subscription->expires_at?->format('Y-m-d H:i:s'), 'the plan\'s days on top of the deadline');
        self::assertSame([], FakeProvider::$trafficReset, 'nothing taken away before the paid period ends');
        self::assertSame(60 * Traffic::GIGABYTE, $subscription->traffic_limit_bytes, 'the plan\'s traffic on top');
        self::assertSame(['2026-09-21 12:00:00', 30 * Traffic::GIGABYTE], [$subscription->period_ends_at?->format('Y-m-d H:i:s'), $subscription->next_period_bytes], 'what is left goes then (not carried, by default)');

        self::assertSame(['sendMessage'], $this->calls());
        self::assertSame([$this->autoRenewed($subscription, '180000.00')], $this->said());

        $this->telegram()->reset();
        $this->renewNow();

        self::assertSame(1, Order::query()->count(), 'the new deadline is out of the window: not again');
        self::assertSame([], $this->calls());
    }

    public function testARenewalThatWouldEndPastTheLastDayTheShopKeepsIsNotMadeNorCharged(): void
    {
        // Its deadline tomorrow, 20 days before the TIMESTAMP columns' last moment: the plan's 30 days would pass it.
        Carbon::setTestNow(LocalTime::last()->subDays(20));
        $subscription = $this->due('ali_1');
        $this->wallet($this->ali, '300000');

        $this->renewNow();

        self::assertSame([0, '300000.00'], [Order::query()->count(), $this->ali->balance()], 'nothing ordered, nothing charged');
        self::assertSame([], FakeProvider::$updated, 'no panel changed');
        self::assertEquals(now()->addDay(), $subscription->refresh()->expires_at);

        // Never asked of its panel (out of reach here: no wait is logged), nor a short wallet told.
        FakeProvider::$unreachable = true;
        $this->wallet($this->ali, '0');
        $this->renewNow();
        self::assertSame([], $this->calls(), 'nor told the wallet is short: the reminder of its end tells the customer (ServiceReminders)');
        self::assertSame([], array_filter($this->logs()->getRecords(), static fn(LogRecord $record): bool => str_contains($record->message, 'waits for')));

        $this->send($this->tap(SubscriptionHandler::serviceCallback($subscription->id)));
        self::assertNotContains(SubscriptionHandler::serviceCallback($subscription->id, 'autorenew'), $this->callbacks(0), 'the switch is not offered: it would not renew it');
    }

    public function testAPriceOutsideTheWalletsBoundsIsNotRenewedFromItNorOfferedTheSwitch(): void
    {
        $subscription = $this->due('ali_1');
        $this->wallet($this->ali, '300000');
        $wallet = $this->walletMethod();
        $wallet->forceFill(['max_amount' => '100000'])->save();

        $this->renewNow();

        self::assertSame([0, '300000.00'], [Order::query()->count(), $this->ali->balance()], 'the wallet pays no more than 100,000 Toman, as at checkout: nothing ordered, nothing charged');
        self::assertSame([], $this->calls(), 'nor told the wallet is short: the reminder of its end tells the customer (ServiceReminders)');

        $this->send($this->tap(SubscriptionHandler::serviceCallback($subscription->id)));
        self::assertNotContains(SubscriptionHandler::serviceCallback($subscription->id, 'autorenew'), $this->callbacks(0), 'the switch is not offered: it would not renew it');
        $this->send($this->tap(SubscriptionHandler::serviceCallback($subscription->id, 'autorenew')));
        self::assertSame(self::text(BotText::AutoRenewUnavailable), $this->popup());

        // Its most the price itself, both ends included: renewed by itself again.
        $wallet->forceFill(['max_amount' => '120000'])->save();
        $this->renewNow();

        self::assertSame([OrderStatus::Fulfilled, '180000.00'], [Order::query()->sole()->status, $this->ali->balance()]);
    }

    public function testItRenewsByTheAdminsDefaultMethodWhateverTheCustomersWindow(): void
    {
        // A reset of the traffic by default; the customer could not renew it by hand for days yet.
        $this->botSettings('renewal', ['renew_extend' => true, 'renew_reset_traffic' => true, 'renew_default_method' => 'reset_traffic', 'renew_window_days' => 1]);
        $subscription = $this->due('ali_1', ['download_bytes' => Traffic::bytesOfGb(20)]);
        $this->wallet($this->ali, '300000');

        $this->renewNow();

        $order = Order::query()->sole();
        self::assertSame([OrderStatus::Fulfilled, RenewalMethod::ResetTraffic], [$order->status, $order->renewal_method]);
        $subscription->refresh();
        self::assertSame([30 * Traffic::GIGABYTE, 0, '2026-10-21 12:00:00', null], [$subscription->traffic_limit_bytes, $subscription->usedBytes(), $subscription->expires_at?->format('Y-m-d H:i:s'), $subscription->next_period_bytes], 'the days on top, the traffic counted afresh');
    }

    /** @return iterable<string, array{RenewalMethod, list<string>, string}> */
    public static function twoDayPlanRenewals(): iterable
    {
        // Each renewal at the window's start: the plan's two days on top of the deadline.
        $ahead = [['2026-09-20 12:00:00', '2026-09-21 12:00:00', '2026-09-23 12:00:00'], '2026-09-27 12:00:00'];
        yield 'extend' => [RenewalMethod::Extend, ...$ahead];
        yield 'reset traffic' => [RenewalMethod::ResetTraffic, ...$ahead];
        // The term starts afresh: at the last run before each deadline, half an hour of the paid term lost.
        yield 'reset time' => [RenewalMethod::ResetTime, ['2026-09-21 11:30:00', '2026-09-23 11:00:00'], '2026-09-25 11:00:00'];
    }

    /** @param list<string> $renewedAt */
    #[DataProvider('twoDayPlanRenewals')]
    public function testATermAsLongAsTheWindowIsRenewedOncePerDeadlineByEachMethod(RenewalMethod $method, array $renewedAt, string $deadline): void
    {
        // Two days renewed two days ahead: every deadline a renewal leaves is within the window at once.
        $this->botSettings('renewal', ['renew_extend' => true, 'renew_reset_traffic' => true, 'renew_reset_time' => true, 'renew_default_method' => $method->value]);
        $this->plan->forceFill(['duration_hours' => 48])->save();
        $subscription = $this->due('ali_1');
        $this->wallet($this->ali, '1000000');

        // A run every half hour, four days long.
        for ($run = 0; $run < 4 * 48; $run++) {
            $this->renewNow();
            Carbon::setTestNow(now()->addMinutes(AutoRenewTask::INTERVAL_MINUTES));
        }

        self::assertSame($renewedAt, Order::query()->oldest('id')->get()->map(static fn(Order $order): string => (string) $order->created_at?->format('Y-m-d H:i:s'))->all());
        self::assertSame([OrderStatus::Fulfilled], Order::query()->distinct()->pluck('status')->all());
        self::assertSame($deadline, $subscription->refresh()->expires_at?->format('Y-m-d H:i:s'));
        self::assertSame(Money::subtract('1000000', Money::times('120000', count($renewedAt))), $this->ali->balance(), 'each renewal charged once');
    }

    public function testByResetTimeItWaitsForTheLastRunWithItsPanelUnaskedButAShortWalletIsToldAtTheWindowsStart(): void
    {
        $this->botSettings('renewal', ['renew_extend' => true, 'renew_reset_time' => true, 'renew_default_method' => 'reset_time']);
        $subscription = $this->due('ali_1');
        $this->wallet($this->ali, '50000');
        $asked = 0;
        FakeProvider::$onCall = static function () use (&$asked): void {
            $asked++;
        };

        $this->renewNow();

        self::assertSame([$this->short($subscription->refresh(), '50000')], $this->said(), 'a day ahead, as by any method');
        self::assertSame(1, $asked, 'the panel\'s deadline read first');

        $this->wallet($this->ali, '150000');
        $this->telegram()->reset();
        $asked = 0;
        Carbon::setTestNow('2026-09-21 11:00:00');
        $this->renewNow();

        self::assertSame([0, 0], [Order::query()->count(), $asked], 'an hour before its end: not yet, nothing asked');
        self::assertSame([], $this->calls());

        Carbon::setTestNow('2026-09-21 11:30:00');
        $this->renewNow();

        self::assertSame([OrderStatus::Fulfilled, RenewalMethod::ResetTime], [Order::query()->sole()->status, Order::query()->sole()->renewal_method]);
        self::assertSame('2026-10-21 11:30:00', $subscription->refresh()->expires_at?->format('Y-m-d H:i:s'), 'the plan\'s month from now: half an hour of the old term lost');
        self::assertCount(1, $this->said());
        self::assertStringStartsWith((string) strtok(self::text(BotText::AutoRenewed, ['client' => 'ali_1']), "\n"), $this->said()[0] ?? '', 'told it was renewed');
    }

    /** @return iterable<string, array{RenewalMethod}> */
    public static function methods(): iterable
    {
        foreach (RenewalMethod::cases() as $method) {
            yield $method->value => [$method];
        }
    }

    #[DataProvider('methods')]
    public function testADeadlineThePanelKeptAfterItsRenewalIsNotRenewedAgain(RenewalMethod $method): void
    {
        $this->botSettings('renewal', ['renew_extend' => true, 'renew_reset_traffic' => true, 'renew_reset_time' => true, 'renew_default_method' => $method->value]);
        $subscription = $this->due('ali_1', ['expires_at' => now()->addMinutes(20)]);
        $this->wallet($this->ali, '1000000');
        $before = FakeProvider::$clients[$this->server->id]['ali_1'];

        $this->renewNow();
        self::assertSame(OrderStatus::Fulfilled, Order::query()->sole()->status);

        // The panel answers with the deadline it had — the new one did not hold there —, and the sync brings it.
        FakeProvider::put($this->server, $before);
        Carbon::setTestNow(now()->addMinutes(10));
        $this->service(SyncSubscriptionsTask::class)->run();
        $this->telegram()->reset();
        $this->renewNow();

        self::assertSame('2026-09-20 12:20:00', $subscription->refresh()->expires_at?->format('Y-m-d H:i:s'), 'read from the panel');
        self::assertSame(1, Order::query()->count(), 'renewed once for this deadline');
        self::assertSame('880000.00', $this->ali->balance());
        self::assertSame([], $this->calls());
    }

    public function testAnAgentsOwnServiceRenewsAtThePlansPriceFromTheirCredit(): void
    {
        $agent = $this->agent(credit: '200000', overrides: ['telegram_id' => self::AGENT_TELEGRAM_ID, 'username' => 'reza']);
        $subscription = $this->due('reza_1', [], $agent);

        $this->renewNow();

        $order = Order::query()->sole();
        self::assertSame([OrderStatus::Fulfilled, '120000.00'], [$order->status, $order->amount], "the plan's price: an agent buys at no discount any more");
        self::assertSame('-120000.00', $agent->balance(), 'paid from the credit');
        self::assertSame([$this->autoRenewed($subscription->refresh(), '-120000.00')], $this->telegram()->sentTo(self::AGENT_TELEGRAM_ID));
    }

    public function testOnlyARunningServiceWithTheSwitchOnAndItsDeadlineWithinTheDaysIsRenewed(): void
    {
        $this->wallet($this->ali, '1000000');
        $later = $this->due('ali_1', ['expires_at' => now()->addDays(3)]);
        $this->due('ali_2', ['auto_renew' => false]);
        $this->due('ali_3', ['starts_at' => null, 'expires_at' => null]);
        $this->due('ali_4', ['expires_at' => now()->subHour(), 'status' => SubscriptionStatus::Expired]);

        $this->renewNow();

        self::assertSame(0, Order::query()->count(), 'three days out, switched off, not started, ended');
        self::assertSame([], $this->calls());

        $this->botSettings('auto_renew', ['auto_renew_days' => 3, 'auto_renew_default' => false]);
        $this->renewNow();

        self::assertSame([$later->id], Order::query()->pluck('subscription_id')->all(), 'three days out, once the admin says three');
    }

    public function testAShortWalletIsToldOnceAndTheServiceRenewedOnceItCanPay(): void
    {
        $subscription = $this->due('ali_1');
        $this->wallet($this->ali, '50000');

        $this->renewNow();

        self::assertSame(0, Order::query()->count(), 'nothing to pay with: no order');
        self::assertSame(['sendMessage'], $this->calls());
        self::assertSame([$this->short($subscription, '50000')], $this->said());
        self::assertSame([[['text' => self::text(BotText::WalletTopup), 'callback_data' => TopUpHandler::START, 'style' => 'success']]], $this->inlineKeyboard(0));

        $this->telegram()->reset();
        $this->renewNow();
        self::assertSame([], $this->calls(), 'told once in this window');

        $this->wallet($this->ali, '150000');
        $this->renewNow();

        self::assertSame(OrderStatus::Fulfilled, Order::query()->sole()->status, 'renewed on the next run once the wallet can pay');
        self::assertSame([$this->autoRenewed($subscription->refresh(), '30000.00')], $this->said());
    }

    public function testAShortWalletIsToldAgainInTheNextRenewalWindow(): void
    {
        $subscription = $this->due('ali_1');
        $this->wallet($this->ali, '50000');
        $this->renewNow();

        // Support extends it on the panel by a month and the sync brings the new deadline: a new window comes — and the
        // wallet is still short.
        FakeProvider::put($this->server, new ClientInfo(name: 'ali_1', enabled: true, totalBytes: $subscription->traffic_limit_bytes, expiry: Expiry::at(now()->addDays(31)->toDateTimeImmutable()), subscriptionUrl: $subscription->subscription_url));
        $this->service(SyncSubscriptionsTask::class)->run();
        Carbon::setTestNow(now()->addDays(30));
        $this->telegram()->reset();
        $this->renewNow();

        self::assertSame([$this->short($subscription->refresh(), '50000')], $this->said(), 'told once more, for this window');
        self::assertSame(0, Order::query()->count());
    }

    public function testAPanelOutOfReachWaitsForTheNextRunWithNothingCharged(): void
    {
        $this->due('ali_1');
        $this->wallet($this->ali, '300000');
        FakeProvider::$down = [$this->server->id];

        $this->renewNow();

        self::assertSame(0, Order::query()->count());
        self::assertSame('300000.00', $this->ali->balance());
        self::assertSame([], $this->calls());
    }

    public function testAPanelTheShopLeavesAloneIsNotAskedAndNothingIsCharged(): void
    {
        $this->due('ali_1');
        $this->wallet($this->ali, '300000');
        // The sync found the panel out of reach: it is left alone a while, though it answers again by now.
        FakeProvider::$down = [$this->server->id];
        $this->service(SyncSubscriptionsTask::class)->run();
        FakeProvider::$down = [];
        $asked = 0;
        FakeProvider::$onCall = static function () use (&$asked): void {
            $asked++;
        };

        $this->renewNow();

        self::assertSame([0, 0], [$asked, Order::query()->count()], 'the next run, once the while is up');
        self::assertSame('300000.00', $this->ali->balance());

        Carbon::setTestNow(now()->addMinutes(Server::BACKOFF_MINUTES));
        $this->renewNow();
        self::assertSame(OrderStatus::Fulfilled, Order::query()->sole()->status);
    }

    /** @return iterable<string, array{OrderStatus}> */
    public static function openRenewals(): iterable
    {
        yield 'being paid' => [OrderStatus::Pending];
        yield 'paid' => [OrderStatus::Paid];
        yield 'being delivered' => [OrderStatus::Processing];
    }

    #[DataProvider('openRenewals')]
    public function testAServiceWithARenewalStillOpenIsNotChargedAgain(OrderStatus $status): void
    {
        $subscription = $this->due('ali_1');
        $this->wallet($this->ali, '300000');
        $open = $this->service(OrderService::class)->createRenewal($this->ali, $subscription, $this->plan);
        $open->forceFill(['status' => $status])->save();

        $this->renewNow();

        self::assertSame([$open->id], Order::query()->pluck('id')->all(), 'no second renewal');
        self::assertSame('300000.00', $this->ali->balance());
        self::assertSame([], $this->calls());
    }

    public function testARenewalTheWalletCouldNoLongerPayIsDroppedAndTheCustomerToldShort(): void
    {
        $subscription = $this->due('ali_1');
        $this->wallet($this->ali, '120000');
        // Another payment takes the money in the same moment the renewal is ordered.
        $this->whileListening('eloquent.created: ' . Order::class, function (): void {
            $this->service(WalletService::class)->debit($this->ali, '50000', 'another payment');
        }, $this->renewNow(...));

        self::assertSame(OrderStatus::Cancelled, Order::query()->sole()->status, 'dropped unpaid');
        self::assertSame('70000.00', $this->ali->balance(), 'nothing more was taken');
        self::assertSame([$this->short($subscription, '70000')], $this->said());
    }

    public function testAServiceHandedOnSinceTheRunReadItIsNotRenewedFromThePreviousOwnersWallet(): void
    {
        $subscription = $this->due('ali_1');
        $this->wallet($this->ali, '300000');
        $sara = $this->customer(['telegram_id' => 1002, 'username' => 'sara']);
        $read = Subscription::query()->with(['user', 'plan', 'server'])->findOrFail($subscription->id);
        // Handed to Sara after the run read it, and Sara switched «تمدید خودکار» on at once.
        $subscription->forceFill(['user_id' => $sara->id, 'auto_renew' => true])->save();

        $this->service(AutoRenewal::class)->renew($read);

        self::assertSame([0, '300000.00'], [Order::query()->count(), $this->ali->balance()], 'nothing ordered, nothing charged to Ali');
    }

    public function testTheSwitchOfAServiceHandedOnSinceItWasReadIsNotSetForTheNewOwner(): void
    {
        $subscription = $this->due('ali_1', ['auto_renew' => false]);
        $this->wallet($this->ali, '300000');
        $read = Subscription::query()->with(['user', 'plan', 'server'])->findOrFail($subscription->id);
        $sara = $this->customer(['telegram_id' => 1002, 'username' => 'sara']);
        $subscription->forceFill(['user_id' => $sara->id])->save(); // support hands it to Sara as Ali taps the switch

        try {
            $this->service(AutoRenewal::class)->set($read, true);
            self::fail('the switch was set for a service no longer Ali\'s');
        } catch (AutoRenewUnavailableException) {
            // Refused: not his to commit a wallet for.
        }

        self::assertFalse($subscription->refresh()->auto_renew, 'Sara\'s wallet is not committed by Ali\'s tap');
    }

    public function testARunOutOfTimeLeavesTheRenewalsForTheNextOne(): void
    {
        $this->due('ali_1');
        $this->wallet($this->ali, '300000');

        $this->withTheTurnOver($this->renewNow(...));
        self::assertSame(0, Order::query()->count());

        $this->renewNow();
        self::assertSame(1, Order::query()->count());
    }

    public function testAServiceAnAdminExtendedOnThePanelIsNotRenewed(): void
    {
        $subscription = $this->due('ali_1');
        $this->wallet($this->ali, '300000');
        FakeProvider::put($this->server, new ClientInfo(
            name: 'ali_1',
            enabled: true,
            totalBytes: $subscription->traffic_limit_bytes,
            expiry: Expiry::at(new \DateTimeImmutable('2026-10-10 12:00:00')),
            subscriptionUrl: 'https://fake.test/sub/ali_1',
        ));

        $this->renewNow();

        self::assertSame(0, Order::query()->count(), 'the panel\'s deadline is weeks away');
        self::assertSame('2026-10-10', $subscription->refresh()->expires_at?->format('Y-m-d'), 'and the row learned it');
    }

    public function testARenewalPaidButNotDeliveredIsLeftToSupportAndNeverChargedAgain(): void
    {
        $this->due('ali_1');
        $this->wallet($this->ali, '300000');
        FakeProvider::$refusing = [$this->server->id => ['updateClient']];

        $this->renewNow();

        $order = Order::query()->sole();
        self::assertSame(OrderStatus::Failed, $order->status);
        self::assertSame('180000.00', $this->ali->balance(), 'paid');
        self::assertSame([self::text(BotText::RenewFailed, ['client' => 'ali_1'])], $this->said());

        $this->telegram()->reset();
        $this->renewNow();

        self::assertSame(1, Order::query()->count(), 'the failed renewal waits for the admin\'s retry, not a second charge');
        self::assertSame('180000.00', $this->ali->balance());
        self::assertSame([], $this->calls());

        // The admin retries it from the payments screen: the customer hears the service was renewed.
        FakeProvider::$refusing = [];
        $this->service(OrderService::class)->deliver($order);
        $this->service(CustomerNotifier::class)->paymentSettled(Payment::query()->where('order_id', $order->id)->sole());

        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status);
        self::assertStringStartsWith((string) strtok(self::text(BotText::Renewed, ['client' => 'ali_1']), "\n"), $this->said()[0] ?? '', 'renewed — not «automatically»: support finished it');
    }

    public function testARenewalThatBreaksStopsNoOtherAndItsChargedCustomerIsTold(): void
    {
        $logs = $this->logs();
        $sara = $this->customer(['telegram_id' => 1002, 'username' => 'sara']);
        $this->due('ali_1');
        $this->due('sara_1', ['expires_at' => now()->addDay()->addHour()], $sara);
        $this->wallet($this->ali, '300000');
        $this->wallet($sara, '300000');
        // A fault in the shop itself as each renewal is delivered — no panel's refusal, no rule's.
        FakeProvider::$onCall = static function (int $server, string $call): void {
            if ($call === 'updateClient') {
                throw new \RuntimeException('a fault of the shop\'s own');
            }
        };

        $this->renewNow();

        self::assertSame([OrderStatus::Failed, OrderStatus::Failed], Order::query()->oldest('id')->pluck('status')->all(), 'the second renewal was tried too');
        self::assertSame(OrderService::DELIVERY_BROKEN, Order::query()->value('notes'));
        self::assertSame(['180000.00', '180000.00'], [$this->ali->balance(), $sara->balance()], 'paid, both');
        self::assertSame([self::text(BotText::RenewFailed, ['client' => 'ali_1'])], $this->telegram()->sentTo(self::TELEGRAM_ID), 'told: support finishes it');
        self::assertSame([self::text(BotText::RenewFailed, ['client' => 'sara_1'])], $this->telegram()->sentTo(1002));
        self::assertCount(2, array_filter($logs->getRecords(), static fn(LogRecord $record): bool => str_contains($record->message, 'broke') && ($record->context['exception'] ?? null) instanceof \RuntimeException), 'each fault logged with its trace');
    }

    public function testARenewalRetriedAfterItsClientLeftThePanelFailsWithThatReason(): void
    {
        $subscription = $this->due('ali_1');
        $this->wallet($this->ali, '300000');
        FakeProvider::$refusing = [$this->server->id => ['updateClient']];
        $this->renewNow();
        $order = Order::query()->sole(); // paid, not delivered

        FakeProvider::$refusing = [];
        unset(FakeProvider::$clients[$this->server->id]['ali_1']); // removed on the panel by hand meanwhile
        $this->service(OrderService::class)->deliver($order);

        self::assertSame(OrderStatus::Failed, $order->refresh()->status, 'nothing to renew on the panel');
        self::assertSame(ProviderErrorPresenter::summary(new NotFoundException('')), $order->notes, 'why, in a word anyone of the shop may read');
        self::assertStringStartsWith(ProviderErrorPresenter::explain(new NotFoundException(''))['message'], (string) $order->diagnosis, 'the owner reads the diagnosis');
        self::assertSame(SubscriptionStatus::Deleted, $subscription->refresh()->status, 'and the service says what its panel did');
    }

    public function testABannedCustomersServiceIsNotRenewed(): void
    {
        $this->due('ali_1');
        $this->wallet($this->ali, '300000');
        $this->ali->forceFill(['status' => UserStatus::Banned])->save();

        $this->renewNow();

        self::assertSame(0, Order::query()->count());
        self::assertSame([], $this->calls());
    }

    public function testWithTheWalletSwitchedOffNothingIsRenewed(): void
    {
        $this->due('ali_1');
        $this->wallet($this->ali, '300000');
        $this->walletMethod()->forceFill(['enabled' => false])->save();

        $this->renewNow();

        self::assertSame(0, Order::query()->count(), 'the wallet pays automatic renewals; switched off, nothing does');
        self::assertSame([], $this->calls());
    }

    public function testByAResetOfTheTimeTheSwitchSaysItRenewsShortlyBeforeTheEnd(): void
    {
        $this->botSettings('renewal', ['renew_extend' => true, 'renew_reset_time' => true, 'renew_default_method' => 'reset_time']);
        $subscription = $this->due('ali_1', ['auto_renew' => false]);

        $this->send($this->tap(SubscriptionHandler::serviceCallback($subscription->id, 'autorenew')));

        self::assertStringContainsString('کمی پیش از پایان سرویس', $this->popup(), 'not days ahead: a reset throws the term left away');
    }

    public function testTheCustomerTurnsTheSwitchOnAndOffFromTheServiceScreen(): void
    {
        $subscription = $this->due('ali_1', ['auto_renew' => false]);
        $switch = SubscriptionHandler::serviceCallback($subscription->id, 'autorenew');

        $this->send($this->tap($switch));

        self::assertTrue($subscription->refresh()->auto_renew);
        self::assertSame(['answerCallbackQuery', 'editMessageReplyMarkup'], $this->calls(), 'only the buttons are redrawn');
        self::assertSame(self::text(BotText::AutoRenewEnabled, ['renews' => Messages::autoRenews(RenewalMethod::Extend, 2), 'days' => Persian::digits(2), 'amount' => Money::format('120000')]), $this->popup(), 'when, and what the wallet pays');
        self::assertStringContainsString('۲ روز پیش از پایان سرویس', $this->popup());
        self::assertSame('true', $this->params(0)['show_alert']);
        self::assertContains(['text' => self::text(BotText::ServiceAutoRenewOn), 'callback_data' => $switch, 'style' => 'success'], array_merge(...$this->inlineKeyboard(1)));

        $this->send($this->tap($switch));

        self::assertFalse($subscription->refresh()->auto_renew);
        self::assertSame(self::text(BotText::AutoRenewDisabled), $this->popup());
        self::assertNotSame('true', $this->params(0)['show_alert'] ?? null, 'a toast');
        self::assertContains(['text' => self::text(BotText::ServiceAutoRenewOff), 'callback_data' => $switch], array_merge(...$this->inlineKeyboard(1)));
    }

    public function testTheSwitchIsNotOfferedForAServiceThatNeverEnds(): void
    {
        $forever = $this->plan(['name' => 'دائمی', 'duration_hours' => 0]);
        $subscription = $this->subscription($this->ali, $forever, $this->server, 'ali_1');
        FakeProvider::mirror($subscription);

        $this->send($this->tap(SubscriptionHandler::serviceCallback($subscription->id)));
        self::assertNotContains(SubscriptionHandler::serviceCallback($subscription->id, 'autorenew'), $this->callbacks(0));

        $this->send($this->tap(SubscriptionHandler::serviceCallback($subscription->id, 'autorenew')));
        self::assertSame(self::text(BotText::AutoRenewUnavailable), $this->popup());
        self::assertFalse($subscription->refresh()->auto_renew);
    }

    public function testANewServiceStartsWithTheSwitchAsTheAdminSet(): void
    {
        $this->inbound($this->server, '1');
        $this->planEntry($this->plan, $this->server);

        $first = $this->buy($this->ali, $this->plan, $this->server)->subscription;
        self::assertFalse($first?->auto_renew, 'off unless the admin says otherwise');

        $this->botSettings('auto_renew', ['auto_renew_days' => 2, 'auto_renew_default' => true]);
        $second = $this->buy($this->ali, $this->plan, $this->server)->subscription;
        self::assertTrue($second?->auto_renew);
    }

    /**
     * A service of Ali's (or `$owner`'s) with the switch on and its deadline tomorrow — the panel's client says the same.
     *
     * @param array<string, mixed> $overrides
     */
    private function due(string $name, array $overrides = [], ?User $owner = null): Subscription
    {
        $subscription = $this->subscription($owner ?? $this->ali, $this->plan, $this->server, $name, $overrides + ['auto_renew' => true, 'expires_at' => now()->addDay()]);
        FakeProvider::mirror($subscription);

        return $subscription;
    }

    /** What the customer reads once the wallet renewed the service: the row as the renewal left it, `$balance` left. */
    private function autoRenewed(Subscription $subscription, string $balance): string
    {
        return self::text(BotText::AutoRenewed, [
            'client' => $subscription->remote_name,
            'amount' => Money::format($this->plan->price),
            'balance' => Messages::balance($balance),
            'plan' => $this->plan->name,
            'expires' => Messages::expiry($subscription->expires_at, $subscription->duration_hours),
            'remaining' => Messages::bytes((int) $subscription->remainingBytes()),
            'leftover' => self::text(BotText::RenewalLeftoverUntil, [
                'expiring' => Messages::bytes($subscription->expiringBytes()),
                'date' => Persian::date(Carbon::parse((string) $subscription->period_ends_at)),
                'next' => Messages::bytes((int) $subscription->next_period_bytes),
            ]),
        ]);
    }

    /** What the customer reads when the wallet cannot pay the renewal: the price, `$balance`, the deadline. */
    private function short(Subscription $subscription, string $balance): string
    {
        return self::text(BotText::AutoRenewShort, [
            'client' => $subscription->remote_name,
            'amount' => Money::format($this->plan->price),
            'balance' => Messages::balance($balance),
            'expires' => Messages::expiry($subscription->expires_at, $subscription->duration_hours),
        ]);
    }

    private function renewNow(): void
    {
        $this->service(AutoRenewTask::class)->run();
    }
}
