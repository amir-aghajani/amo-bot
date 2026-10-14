<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\CheckoutOutcome;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\DTO\Capabilities;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\ProviderRegistry;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\CustomerExtras;
use App\Modules\Subscriptions\Services\CustomerRenewal;
use App\Modules\Subscriptions\Tasks\NextPeriodTask;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletService;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;

/**
 * «کاربر اضافه»: a customer buys more devices for a service — its IP limit —, priced for 30 days and charged for the
 * days left of the period in use (rounded up to whole Toman), while it has a limit, time and traffic left and a panel
 * that enforces it. Delivered on top of the limit the service has, they last until that period ends — a renewal brings
 * its plan's limit then, or at once for a service whose period is over —; a refund takes them back while they last, and
 * a renewal's refund puts back the limit it replaced, so the two refunded in either order end alike; in an agent's shop
 * they draw nothing from the pool.
 */
final class ExtraDevicesTest extends DatabaseTestCase
{
    private User $ali;
    private Server $server;
    private Plan $plan;
    private CustomerExtras $extras;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-10 12:00:00');
        $this->telegram();
        $this->withoutQr();
        $this->ali = $this->customer(['username' => 'ali']);
        $this->server = $this->sellingServer();
        $this->plan = $this->plan(['ip_limit' => 1], $this->server);
        $this->extras = $this->service(CustomerExtras::class);
    }

    public function testDevicesArePricedForThirtyDaysAndChargedForTheDaysLeftOfThePeriodInUse(): void
    {
        $this->extraDevicesOnSale();
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['expires_at' => now()->addDays(18)]));

        $offer = $this->extras->offer($service, ExtraKind::Devices) ?? self::fail('no devices on sale');
        self::assertSame(18, $offer->days);
        self::assertSame([['quantity' => 1, 'price' => '18000.00'], ['quantity' => 2, 'price' => '30000.00']], $offer->choices(), '18 of 30 days: 30,000 a device, the two at their own 50,000');
        self::assertSame(['18000.00', '54000.00'], [$offer->unitPrice(), $this->extras->quote($service, ExtraKind::Devices, 3)], 'typed: three at 30,000, for 18 days');

        $cases = [
            'a day and a bit left: two days' => [['expires_at' => now()->addDays(1)->addHour()], 2, '2000.00'],
            'hours left: a whole day' => [['expires_at' => now()->addHours(20)], 1, '1000.00'],
            'a minute left: still a day' => [['expires_at' => now()->addMinute()], 1, '1000.00'],
            'waiting for its first connection: its whole term' => [['starts_at' => null, 'expires_at' => null, 'duration_hours' => 60 * 24], 60, '60000.00'],
            'a term of six hours not started: a day' => [['starts_at' => null, 'expires_at' => null, 'duration_hours' => 6], 1, '1000.00'],
            'never ending: a full 30 days' => [['starts_at' => null, 'expires_at' => null, 'duration_hours' => 0], 30, '30000.00'],
            'renewed early: to the end of the period in use, when they end' => [['expires_at' => now()->addDays(35), 'period_ends_at' => now()->addDays(5), 'next_period_ip_limit' => 1], 5, '5000.00'],
            'a period whose end passed has begun: to the deadline' => [['expires_at' => now()->addDays(35), 'period_ends_at' => now()->subMinute(), 'next_period_ip_limit' => 1], 35, '35000.00'],
        ];
        $this->extraDevicesOnSale(['extra_devices_options' => []]);
        foreach ($cases as $case => [$overrides, $days, $price]) {
            $other = $this->subscription($this->ali, $this->plan, $this->server, 'ali_' . (2 + array_search($case, array_keys($cases), true)), $overrides);
            self::assertSame([$days, $price], [CustomerExtras::deviceDays($other), $this->extras->quote($other, ExtraKind::Devices, 1)], $case);
        }

        $this->extraDevicesOnSale(['extra_devices_price' => '1000', 'extra_devices_options' => []]);
        $week = $this->subscription($this->ali, $this->plan, $this->server, 'ali_19', ['expires_at' => now()->addDays(7)]);
        self::assertSame(['234.00', '467.00'], [$this->extras->quote($week, ExtraKind::Devices, 1), $this->extras->quote($week, ExtraKind::Devices, 2)], '1,000 for 7 of 30 days is 233.33: rounded up to whole Toman, once for the whole amount');
    }

    public function testWhatAServiceMayTakeIsItsLimitItsTimeAndItsPanel(): void
    {
        $this->extraDevicesOnSale();
        $cases = [
            'running' => [[], null],
            'waiting for its first connection' => [['starts_at' => null, 'expires_at' => null], null],
            'never ending' => [['starts_at' => null, 'expires_at' => null, 'duration_hours' => 0], null],
            // A renewal would replace its period at once, and the devices with it.
            'traffic used up' => [['status' => SubscriptionStatus::Expired, 'download_bytes' => 30 * Traffic::GIGABYTE], CustomerExtras::TRAFFIC_OVER],
            'unlimited devices' => [['ip_limit' => 0], CustomerExtras::UNLIMITED_DEVICES],
            'time over' => [['status' => SubscriptionStatus::Expired, 'expires_at' => now()->subDay()], CustomerExtras::TIME_OVER],
            'a free test' => [['is_test' => true], CustomerExtras::TEST],
            'switched off by support' => [['status' => SubscriptionStatus::Disabled, 'disabled_at' => now()], CustomerExtras::NOT_RUNNING],
            'gone from its panel' => [['status' => SubscriptionStatus::Deleted], CustomerExtras::NOT_RUNNING],
        ];

        $services = [];
        foreach ($cases as $case => [$overrides, $problem]) {
            $services[$case] = $service = $this->subscription($this->ali, $this->plan, $this->server, 'ali_' . (count($services) + 1), $overrides);
            self::assertSame($problem, $this->extras->refusal($service, ExtraKind::Devices), $case);
        }
        $offers = $this->extras->offersFor($services);
        foreach ($cases as $case => [, $problem]) {
            self::assertSame($problem === null, $offers[$services[$case]->id]['devices'] !== null, "{$case}: on offer while it can take them");
        }

        FakeProvider::$capabilities = new Capabilities(inbounds: true, linkRotation: true, ipLimit: false);
        $running = $services['running'];
        self::assertSame(CustomerExtras::NO_DEVICE_LIMIT, $this->extras->refusal($running, ExtraKind::Devices), 'a panel that does not hold a client to its IP limit sells none');
        self::assertNull($this->extras->offer($running, ExtraKind::Devices));
        self::assertNull($this->extras->refusal($running, ExtraKind::Traffic), 'traffic is no matter of the panel\'s IP limit');
        self::assertSame(['status' => [CustomerExtras::NO_DEVICE_LIMIT]], $this->refusedWith(fn() => $this->extras->quote($running, ExtraKind::Devices, 2)));

        $panels = $this->service(ProviderRegistry::class);
        self::assertSame([true, false], [$panels->capabilities('3x-ui')->ipLimit, $panels->capabilities('pasarguard')->ipLimit], '3x-ui holds a client to its IP limit; PasarGuard has none');
    }

    public function testAnAmountNotOnOfferIsRefusedUnderItsField(): void
    {
        $this->extraDevicesOnSale();
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['expires_at' => now()->addDays(15)]));

        self::assertSame(['devices' => ['این مقدار فروخته نمی‌شود؛ کاربر اضافه بین ۱ تا ۵ کاربر بنویسید، یا یکی از این‌ها را بخرید: ۱ کاربر (۱۵٬۰۰۰ تومان)، ۲ کاربر (۲۵٬۰۰۰ تومان).']], $this->refusedWith(fn() => $this->extras->quote($service, ExtraKind::Devices, 6)), 'the prices it lists are for the days left');

        $this->extrasOnSale(['extra_devices_price' => '', 'extra_devices_options' => []]);
        self::assertSame(['devices' => [sprintf(CustomerExtras::NOT_SOLD, 'کاربر اضافه')]], $this->refusedWith(fn() => $this->extras->quote($service, ExtraKind::Devices, 1)), 'traffic and time on sale, no devices');
    }

    public function testExtraDevicesFromTheWalletGoOnTopOfTheLimitAndNothingElseOfTheService(): void
    {
        $this->extraDevicesOnSale();
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['ip_limit' => 2, 'expires_at' => now()->addDays(18), 'download_bytes' => 5 * Traffic::GIGABYTE]));
        $this->wallet($this->ali, '50000');

        $result = $this->buyExtra($service, ExtraKind::Devices, 2);

        self::assertSame(CheckoutOutcome::Settled, $result->outcome);
        $order = $result->order();
        self::assertSame(
            [OrderType::ExtraDevices, OrderStatus::Fulfilled, '30000.00', 2, null, null, $service->id, $this->plan->id],
            [$order->type, $order->status, $order->amount, $order->devices, $order->traffic_bytes, $order->days, $order->subscription_id, $order->plan_id],
        );
        $spec = FakeProvider::lastUpdate('ali_1');
        self::assertSame([4, 30 * Traffic::GIGABYTE, '2026-10-28 12:00:00'], [$spec->ipLimit, $spec->totalBytes, $spec->expiry->deadline()?->format('Y-m-d H:i:s')], 'two on top of its two; its quota and deadline as they were');
        $service->refresh();
        self::assertSame([SubscriptionStatus::Active, 4, 25 * Traffic::GIGABYTE], [$service->status, $service->ip_limit, $service->remainingBytes()]);
        self::assertSame('20000.00', $this->ali->balance());
    }

    public function testACardTransferDeliversOnApprovalAndARefundTakesTheDevicesBack(): void
    {
        $this->extraDevicesOnSale();
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['ip_limit' => 2]));
        $result = $this->buyExtra($service, ExtraKind::Devices, 3, $this->cardMethod());
        self::assertSame([CheckoutOutcome::Transfer, []], [$result->outcome, FakeProvider::$updated], 'nothing given before it is paid');
        $payment = $this->receipt($result->payment());
        $this->telegram()->reset();

        $this->service(PaymentActions::class)->approve($payment, $this->panelActor());

        self::assertSame(5, $service->refresh()->ip_limit, 'delivered on approval');
        self::assertSame([self::text(BotText::ExtraDevicesAdded, [
            'extra_devices' => '۳ کاربر',
            'client' => 'ali_1',
            'devices' => '۵ کاربر',
            'expires' => Messages::expiry(now()->addDays(30), 30 * 24),
        ])], $this->telegram()->sentTo(self::TELEGRAM_ID), 'the customer is told what their service got');
        $this->telegram()->reset();

        $this->service(PaymentActions::class)->refund($payment->refresh(), $this->panelActor(), null);

        self::assertSame([PaymentStatus::Refunded, OrderStatus::Refunded], [$payment->refresh()->status, $result->order()->refresh()->status]);
        self::assertSame([2, 2], [$service->refresh()->ip_limit, FakeProvider::lastUpdate('ali_1')->ipLimit], 'its three devices taken back, on the panel too');
        self::assertSame(30 * Traffic::GIGABYTE, $service->traffic_limit_bytes, 'nothing else of it');
        self::assertStringEndsWith(self::text(BotText::PaymentRefundedDevicesTakenBack, ['client' => 'ali_1']), $this->telegram()->sentTo(self::TELEGRAM_ID)[0]);
    }

    public function testDevicesBoughtLastUntilThePeriodInUseEndsThenTheRenewalsLimit(): void
    {
        $this->extraDevicesOnSale();
        $this->botSettings('renewal', ['carry_traffic' => true]);
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['expires_at' => now()->addDays(10)]));
        $this->paidExtra($service, ExtraKind::Devices, 2, '20000');

        $order = $this->renew($service, $this->plan(['ip_limit' => 2], $this->server));

        self::assertSame([3, 2, '2026-10-20 12:00:00'], [$service->ip_limit, $service->next_period_ip_limit, $service->period_ends_at?->format('Y-m-d H:i:s')], "the three paid for last until the period in use ends; the plan's two wait for it");
        self::assertSame(3, FakeProvider::lastUpdate('ali_1')->ipLimit);
        self::assertSame([2, null, true], [$order->refresh()->given_ip_limit, $order->ip_limit_was, $order->ip_limit_queued], 'kept for its refund');
        $this->nextPeriods();
        self::assertSame(3, $service->refresh()->ip_limit, 'not before the period ends');

        Carbon::setTestNow('2026-10-20 12:05:00');
        $this->nextPeriods();

        $service->refresh();
        self::assertSame([2, null, null, '2026-10-20 12:00:00'], [$service->ip_limit, $service->next_period_ip_limit, $service->period_ends_at, $service->ip_limit_reset_at?->format('Y-m-d H:i:s')], "the plan's two from then on");
        self::assertSame([2, []], [FakeProvider::lastUpdate('ali_1')->ipLimit, FakeProvider::$trafficReset], 'on the panel too; its traffic, carried, untouched');
        self::assertSame(60 * Traffic::GIGABYTE, $service->traffic_limit_bytes);
    }

    public function testAPeriodQueuedForItsDevicesAloneAsksNoPanelThatKeepsNoLimit(): void
    {
        $this->botSettings('renewal', ['carry_traffic' => true]);
        FakeProvider::$capabilities = new Capabilities(inbounds: true, linkRotation: true, ipLimit: false);
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['expires_at' => now()->addDays(10)]));
        $this->renew($service, $this->plan(['ip_limit' => 2], $this->server));
        $asked = count(FakeProvider::$updated);

        Carbon::setTestNow('2026-10-20 12:05:00');
        $this->nextPeriods();

        self::assertSame([2, null], [$service->refresh()->ip_limit, $service->period_ends_at], 'the row takes the plan\'s limit');
        self::assertCount($asked, FakeProvider::$updated, 'its panel has no limit to be told');
    }

    public function testARefundTakesBackDevicesOnlyWhileTheirPeriodLasts(): void
    {
        $this->extraDevicesOnSale();
        $this->botSettings('renewal', ['carry_traffic' => true]);
        $twoDevices = $this->plan(['ip_limit' => 2], $this->server);
        $refunded = function (Subscription $service, Order $devices): int {
            $this->service(PaymentActions::class)->refund($devices->payments()->firstOrFail(), $this->panelActor(), null);

            return $service->refresh()->ip_limit;
        };

        $lasting = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['expires_at' => now()->addDays(10)]));
        $devices = $this->paidExtra($lasting, ExtraKind::Devices, 2, '20000');
        $this->renew($lasting, $twoDevices);
        self::assertSame([1, 2], [$refunded($lasting, $devices), $lasting->next_period_ip_limit], "renewed while they last: the period in use loses them, the plan's two still come when it ends");

        $over = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_2', ['expires_at' => now()->addDays(10)]));
        $devices = $this->paidExtra($over, ExtraKind::Devices, 2, '20000');
        $this->renew($over, $twoDevices);
        Carbon::setTestNow('2026-10-20 12:05:00');
        $this->nextPeriods();
        self::assertSame(2, $refunded($over, $devices), "their period over, the plan's two in force: nothing is taken");

        $ended = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_3', ['expires_at' => now()->addDay()]));
        $devices = $this->paidExtra($ended, ExtraKind::Devices, 2, '2000');
        Carbon::setTestNow(now()->addDays(2));
        $this->renew($ended, $twoDevices);
        self::assertSame([2, 2], [$ended->ip_limit, $refunded($ended, $devices)], "ended, then renewed: the plan's two at once, the devices gone with their period");

        Carbon::setTestNow(now()->addHour());
        $again = $this->paidExtra($ended, ExtraKind::Devices, 1, '30000');
        self::assertSame([3, 2], [$ended->ip_limit, $refunded($ended, $again)], 'bought for the new period: those come off');
    }

    public function testAServiceWaitingForItsFirstConnectionKeepsTheDevicesBoughtForItWhenRenewed(): void
    {
        $this->extraDevicesOnSale();
        $waiting = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['starts_at' => null, 'expires_at' => null]));
        $devices = $this->paidExtra($waiting, ExtraKind::Devices, 2, '60000');

        $renewal = $this->renew($waiting, $this->plan(['ip_limit' => 2], $this->server));

        self::assertSame([4, null, 60 * 24], [$waiting->ip_limit, $waiting->next_period_ip_limit, $waiting->duration_hours], "the plan's two at once and the two bought for its term on top, for the whole of it");
        self::assertSame([4, 3, false], [$renewal->refresh()->given_ip_limit, $renewal->ip_limit_was, $renewal->ip_limit_queued]);

        $this->service(PaymentActions::class)->refund($renewal->payments()->firstOrFail(), $this->panelActor(), null);
        self::assertSame([3, 30 * 24], [$waiting->refresh()->ip_limit, $waiting->duration_hours], 'the renewal refunded: the three it had');

        $this->service(PaymentActions::class)->refund($devices->payments()->firstOrFail(), $this->panelActor(), null);
        self::assertSame(1, $waiting->refresh()->ip_limit, 'and the devices: the plan\'s one');

        $other = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_2', ['starts_at' => null, 'expires_at' => null]));
        $devices = $this->paidExtra($other, ExtraKind::Devices, 2, '60000');
        $renewal = $this->renew($other, $this->plan(['ip_limit' => 2], $this->server));
        $this->refund($devices);
        self::assertSame(2, $other->refresh()->ip_limit, 'the devices refunded first: off the four');
        $this->refund($renewal);
        self::assertSame(1, $other->refresh()->ip_limit, 'then the renewal: the plan\'s one, as the other way round');
    }

    public function testARefundedRenewalPutsBackTheLimitItReplacedWithWhatChangedItSinceOnTop(): void
    {
        $this->extraDevicesOnSale();
        $twoDevices = $this->plan(['ip_limit' => 2], $this->server);
        $ended = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['status' => SubscriptionStatus::Expired, 'starts_at' => now()->subDays(31), 'expires_at' => now()->subDay()]));
        $renewal = $this->renew($ended, $twoDevices);
        self::assertSame([2, 1, false], [$renewal->refresh()->given_ip_limit, $renewal->ip_limit_was, $renewal->ip_limit_queued], 'set at once');
        $this->refund($renewal);
        self::assertSame(1, $ended->refresh()->ip_limit, 'refunded: the one it replaced');

        foreach (['the renewal first' => true, 'the device first' => false] as $case => $renewalFirst) {
            $changed = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, $renewalFirst ? 'ali_2' : 'ali_4', ['status' => SubscriptionStatus::Expired, 'starts_at' => now()->subDays(31), 'expires_at' => now()->subDay()]));
            $renewal = $this->renew($changed, $twoDevices);
            $device = $this->paidExtra($changed, ExtraKind::Devices, 1, '30000');
            $this->refund($renewalFirst ? $renewal : $device);
            self::assertSame(2, $changed->refresh()->ip_limit, $renewalFirst ? 'a device bought since stays on top of the one it replaced' : 'the device bought for it taken off the two');
            $this->refund($renewalFirst ? $device : $renewal);
            self::assertSame([1, null], [$changed->refresh()->ip_limit, $changed->ip_limit_reset_at], "{$case}: both refunded, the plan's one as before either");
        }

        $queued = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_3', ['expires_at' => now()->addDays(10)]));
        $first = $this->renew($queued, $twoDevices);
        $second = $this->renew($queued, $this->plan(['ip_limit' => 3], $this->server));
        self::assertSame([2, 3], [$second->refresh()->ip_limit_was, $queued->next_period_ip_limit], 'the second replaced the limit the first queued');
        $this->service(PaymentActions::class)->refund($first->payments()->firstOrFail(), $this->panelActor(), null);
        self::assertSame([3, null], [$queued->refresh()->next_period_ip_limit, $second->refresh()->ip_limit_was], 'the first refunded while the second stands: the second\'s stays, and replaces what the first did');
        $this->service(PaymentActions::class)->refund($second->payments()->firstOrFail(), $this->panelActor(), null);
        self::assertSame([1, null, null], [$queued->refresh()->ip_limit, $queued->next_period_ip_limit, $queued->period_ends_at], 'both refunded: nothing queued');
    }

    public function testALimitChangedOnThePanelIsWhatTheDevicesGoOnTopOf(): void
    {
        $this->extraDevicesOnSale();
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['ip_limit' => 2]));
        $client = FakeProvider::$clients[$this->server->id]['ali_1'];
        // Raised to five by hand on the panel since the shop last read it.
        FakeProvider::put($this->server, new ClientInfo(name: 'ali_1', enabled: true, totalBytes: $client->totalBytes, expiry: $client->expiry, subscriptionUrl: $client->subscriptionUrl, ipLimit: 5));
        $this->wallet($this->ali, '100000');

        $this->buyExtra($service, ExtraKind::Devices, 1);

        self::assertSame([6, 6], [FakeProvider::lastUpdate('ali_1')->ipLimit, $service->refresh()->ip_limit], "one on top of the panel's five, not of the two the shop knew");
    }

    public function testADeliveryTheServiceCannotTakeAnyMoreFailsWithWhy(): void
    {
        $this->extraDevicesOnSale();
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1'));
        $result = $this->buyExtra($service, ExtraKind::Devices, 1, $this->cardMethod());
        $payment = $this->receipt($result->payment());
        // Moved meanwhile to a server whose panel holds no IP limit.
        FakeProvider::$capabilities = new Capabilities(inbounds: true, linkRotation: true, ipLimit: false);

        $this->service(PaymentActions::class)->approve($payment, $this->panelActor());

        self::assertSame([OrderStatus::Failed, CustomerExtras::NO_DEVICE_LIMIT], [$result->order()->refresh()->status, $result->order()->notes]);
        self::assertSame([], FakeProvider::$updated, 'nothing given');
    }

    public function testInAnAgentsShopDevicesDrawNothingFromThePool(): void
    {
        $bot = $this->agentBot(traffic: 25);

        [$order, $limit] = CurrentBot::run($bot, function (): array {
            $this->extraDevicesOnSale();
            $reza = $this->wallet($this->customer(['telegram_id' => 2001, 'username' => 'reza']), '100000');
            $service = $this->mirrored($this->subscription($reza, $this->plan(['name' => 'پلن نماینده', 'ip_limit' => 1]), $this->server, 'reza_1'));

            return [$this->buyExtra($service, ExtraKind::Devices, 2)->order(), $service->refresh()->ip_limit];
        });

        self::assertSame([OrderStatus::Fulfilled, 3], [$order->status, $limit], (string) $order->notes);
        self::assertSame(Traffic::bytesOfGb(25), $bot->trafficBalance(), 'devices cost the pool nothing');
    }

    public function testTheDevicesARenewalPutAtOnceOutOfTheLimitComeBackWithItsRefundAndGoWithTheirOwn(): void
    {
        $this->extraDevicesOnSale();
        $endless = $this->plan(['ip_limit' => 1, 'duration_hours' => 0], $this->server);
        $cases = [
            'reset_time on a running service' => [$this->plan, ['expires_at' => now()->addDays(10)], RenewalMethod::ResetTime],
            'a service that never ends' => [$endless, ['starts_at' => null, 'expires_at' => null, 'duration_hours' => 0], RenewalMethod::Extend],
        ];
        $name = 0;
        foreach ($cases as $case => [$plan, $state, $method]) {
            foreach (['the renewal first' => true, 'the devices first' => false] as $order => $renewalFirst) {
                Carbon::setTestNow('2026-10-10 12:00:00');
                $service = $this->mirrored($this->subscription($this->ali, $plan, $this->server, 'ali_' . ++$name, $state));
                $devices = $this->paidExtra($service, ExtraKind::Devices, 2, '20000');
                Carbon::setTestNow('2026-10-10 13:00:00');
                $renewal = $this->renewBy($service, $plan, $method);
                self::assertSame([1, '2026-10-10 13:00:00', 3], [$service->ip_limit, $service->ip_limit_reset_at?->format('Y-m-d H:i:s'), $renewal->refresh()->ip_limit_was], "{$case}: the plan's one at once, the two bought gone with their period");

                Carbon::setTestNow('2026-10-10 14:00:00');
                $this->refund($renewalFirst ? $renewal : $devices);
                self::assertSame($renewalFirst ? [3, null] : [1, 1], [$service->refresh()->ip_limit, $renewalFirst ? $service->ip_limit_reset_at : Order::query()->findOrFail($renewal->id)->ip_limit_was], $renewalFirst
                    ? "{$case}: the renewal refunded, the three it replaced back — the two bought count again"
                    : "{$case}: the devices refunded, nothing to take from the plan's one; the renewal no longer replaced them");
                // Its refund starts from the order as read before (`$renewal`): what it was handed since counts all the same.
                $this->refund($renewalFirst ? $devices : $renewal);
                self::assertSame([1, 1, null], [$service->refresh()->ip_limit, FakeProvider::lastUpdate($service->remote_name)->ipLimit, $service->ip_limit_reset_at], "{$case}, {$order}: both refunded, the plan's one as before either");
            }
        }
    }

    public function testARenewalJustAfterAQueuedPeriodEndedKeepsTheDevicesBoughtForIt(): void
    {
        $this->extraDevicesOnSale();
        $this->botSettings('renewal', ['carry_traffic' => true]);
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['expires_at' => now()->addDays(10)]));
        $this->renew($service);
        self::assertSame([1, 1, '2026-10-20 12:00:00'], [$service->ip_limit, $service->next_period_ip_limit, $service->period_ends_at?->format('Y-m-d H:i:s')]);

        // The queued period's end passed; NextPeriodTask has not run yet.
        Carbon::setTestNow('2026-10-20 12:03:00');
        $this->paidExtra($service, ExtraKind::Devices, 2, $this->extras->quote($service, ExtraKind::Devices, 2));
        self::assertSame([3, 3], [$service->ip_limit, $service->next_period_ip_limit], 'bought for the period that began: on its limit too');

        $renewal = $this->renew($service);
        self::assertSame([3, 1, '2026-11-19 12:00:00', '2026-10-20 12:00:00'], [$service->ip_limit, $service->next_period_ip_limit, $service->period_ends_at?->format('Y-m-d H:i:s'), $service->ip_limit_reset_at?->format('Y-m-d H:i:s')], 'the period that began goes on with its three to the deadline; the renewal waits behind it');
        self::assertSame([1, null, true], [$renewal->refresh()->given_ip_limit, $renewal->ip_limit_was, $renewal->ip_limit_queued]);
        $this->nextPeriods();
        self::assertSame(3, $service->refresh()->ip_limit, 'the three paid for last until its end');
    }

    public function testTheCheckoutOfAWaitingServicesRenewalShowsTheDevicesItKeeps(): void
    {
        $this->extraDevicesOnSale();
        $twoDevices = $this->plan(['ip_limit' => 2], $this->server);
        $waiting = $this->mirrored($this->subscription($this->ali, $twoDevices, $this->server, 'ali_1', ['starts_at' => null, 'expires_at' => null]));
        $this->paidExtra($waiting, ExtraKind::Devices, 2, '60000');

        $preview = $this->service(CustomerRenewal::class)->preview($waiting) ?? self::fail('no checkout');
        $this->renew($waiting);

        self::assertSame([4, 4], [$preview->after->ip_limit, $waiting->ip_limit], "the plan's two and the two bought for its term, shown as given");
    }

    public function testARefundOfDevicesGoneWithTheirPeriodTakesNothingAndSaysNothingWasTaken(): void
    {
        $this->extraDevicesOnSale();
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['expires_at' => now()->addDay()]));
        $devices = $this->paidExtra($service, ExtraKind::Devices, 2, '2000');
        Carbon::setTestNow(now()->addDays(2));
        $this->renew($service);
        $asked = count(FakeProvider::$updated);
        $this->telegram()->reset();

        $this->refund($devices);

        self::assertSame([1, $asked, OrderStatus::Refunded], [$service->refresh()->ip_limit, count(FakeProvider::$updated), $devices->refresh()->status], 'refunded, nothing taken: the panel not asked');
        self::assertNotNull($devices->taken_back_at, 'once');
        $told = $this->telegram()->sentTo(self::TELEGRAM_ID)[0];
        self::assertStringNotContainsString(self::text(BotText::PaymentRefundedDevicesTakenBack, ['client' => 'ali_1']), $told, 'the money back, and no word of devices taken');
    }

    private function nextPeriods(): void
    {
        $this->service(NextPeriodTask::class)->run();
    }

    private function refund(Order $order): void
    {
        $this->service(PaymentActions::class)->refund($order->payments()->firstOrFail(), $this->panelActor(), null);
    }

    /** A wallet-paid renewal on `$plan` by `$method`, delivered; the service read again. */
    private function renewBy(Subscription $subscription, Plan $plan, RenewalMethod $method): Order
    {
        $this->service(WalletService::class)->credit($subscription->user, $plan->price, 'test');
        $order = $this->service(OrderService::class)->createRenewal($subscription->user, $subscription, $plan, $method);
        $this->service(PaymentService::class)->createForOrder($order, $this->walletMethod());
        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status, (string) $order->notes);
        $subscription->refresh();

        return $order;
    }
}
