<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\ValidationException;
use App\Modules\Agency\Enums\TrafficTransactionType;
use App\Modules\Agency\Models\TrafficTransaction;
use App\Modules\Agency\Services\TrafficPool;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Payments\Enums\CheckoutOutcome;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\DTO\ExtraOffer;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\CustomerExtras;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Support\LocalTime;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;

/**
 * «حجم و زمان اضافه»: a customer buys extra traffic or time for a service, at the plan's own prices or the shop's, while the
 * shop sells them and the service can take them; delivered from what the panel says then (traffic revives a service whose
 * traffic ran out; days restart a term that ended). In an agent's shop traffic is never offered beyond the agent's pool,
 * is drawn from it like a sale and given back when the delivery fails; days cost nothing.
 */
final class ServiceExtrasTest extends DatabaseTestCase
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
        $this->plan = $this->plan([], $this->server);
        $this->extras = $this->service(CustomerExtras::class);
    }

    public function testNothingIsSoldUntilTheShopSwitchesItOnAndPricesIt(): void
    {
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1'));

        self::assertSame([$service->id => ['traffic' => null, 'time' => null, 'devices' => null]], $this->extras->offersFor([$service]), 'off, as a shop starts');

        $this->extrasOnSale(['extra_traffic_price' => '', 'extra_traffic_options' => [], 'extra_time_price' => '', 'extra_time_options' => []]);
        self::assertSame([$service->id => ['traffic' => null, 'time' => null, 'devices' => null]], $this->extras->offersFor([$service]), 'on, with nothing priced');

        $this->extrasOnSale();
        $traffic = $this->extras->offer($service, ExtraKind::Traffic) ?? self::fail('no traffic on sale');
        self::assertSame([['quantity' => 10, 'price' => '18000.00'], ['quantity' => 50, 'price' => '100000.00']], $traffic->choices(), 'its own price, else the price of a GB fifty times');
        self::assertSame([true, 1, 100], [$traffic->typed(), $traffic->min, $traffic->max]);
        self::assertSame('14000.00', $this->extras->quote($service, ExtraKind::Traffic, 7), 'typed: the price of a GB seven times');
        self::assertSame('25000.00', $this->extras->quote($service, ExtraKind::Time, 30));
        self::assertSame('7000.00', $this->extras->quote($service, ExtraKind::Time, 7));
    }

    public function testTimeIsSoldNoFurtherThanTheLastDayTheShopKeeps(): void
    {
        // Its 30 days end 10 days before the TIMESTAMP columns' last moment (MySQL refuses a later one).
        Carbon::setTestNow(LocalTime::last()->subDays(40));
        $this->extrasOnSale();
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1'));

        $time = $this->extras->offer($service, ExtraKind::Time) ?? self::fail('no time on sale');
        self::assertSame([[['quantity' => 7, 'price' => '7000.00']], 1, 10], [$time->choices(), $time->min, $time->max], 'the 30 days go; up to 10 typed');
        self::assertSame('10000.00', $this->extras->quote($service, ExtraKind::Time, 10));
        try {
            $this->extras->quote($service, ExtraKind::Time, 11);
            self::fail('Eleven days were sold.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('days', $e->errors());
        }
        self::assertNotNull($this->extras->offer($service, ExtraKind::Traffic), 'traffic is sold as ever');

        $atTheEnd = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_2', ['expires_at' => LocalTime::last()]));
        self::assertNull($this->extras->offer($atTheEnd, ExtraKind::Time), 'not a day more');
    }

    public function testAPlansOwnPricesStandInForTheShopsButNotForItsSwitch(): void
    {
        $this->extrasOnSale();
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1'));
        $this->plan->forceFill(['extras' => [
            'traffic_price' => '5000.00', 'traffic_options' => [], 'traffic_min' => 1, 'traffic_max' => 20,
            'time_price' => null, 'time_options' => [], 'time_min' => 1, 'time_max' => 30,
            'devices_price' => null, 'devices_options' => [], 'devices_min' => 1, 'devices_max' => 5,
        ]])->save();
        $service->refresh();

        self::assertSame('50000.00', $this->extras->quote($service, ExtraKind::Traffic, 10), "the plan's 5,000 a GB: the shop's ten-GB button is not the plan's");
        self::assertSame(20, $this->extras->offer($service, ExtraKind::Traffic)?->max);
        self::assertNull($this->extras->offer($service, ExtraKind::Time), 'the plan sells no time, whatever the shop does');
        $other = $this->subscription($this->ali, $this->plan(['name' => 'سه‌ماهه']), $this->server, 'ali_2');
        self::assertSame('18000.00', $this->extras->quote($other, ExtraKind::Traffic, 10), "a plan without prices of its own: the shop's");

        $this->extrasOnSale(['extras_enabled' => false]);
        self::assertSame(['traffic' => null, 'time' => null, 'devices' => null], $this->extras->offersFor([$service])[$service->id], "the shop's switch off: nothing, whatever a plan's prices say");
    }

    public function testWhatAServiceMayTakeIsSaidByItsStateAndItsOtherCount(): void
    {
        $this->extrasOnSale();
        $cases = [
            'running' => [[], null, null],
            'traffic used up' => [['status' => SubscriptionStatus::Expired, 'download_bytes' => 30 * Traffic::GIGABYTE], null, CustomerExtras::TRAFFIC_OVER],
            'time over' => [['status' => SubscriptionStatus::Expired, 'expires_at' => now()->subDay()], CustomerExtras::TIME_OVER, null],
            'both over' => [['status' => SubscriptionStatus::Expired, 'expires_at' => now()->subDay(), 'download_bytes' => 30 * Traffic::GIGABYTE], CustomerExtras::TIME_OVER, CustomerExtras::TRAFFIC_OVER],
            'waiting for its first connection' => [['starts_at' => null, 'expires_at' => null], null, null],
            'unlimited' => [['traffic_limit_bytes' => 0], CustomerExtras::UNLIMITED, null],
            'endless' => [['duration_hours' => 0, 'expires_at' => null], null, CustomerExtras::NO_TERM],
            'switched off by support' => [['status' => SubscriptionStatus::Disabled, 'disabled_at' => now()], CustomerExtras::NOT_RUNNING, CustomerExtras::NOT_RUNNING],
            'gone from its panel' => [['status' => SubscriptionStatus::Deleted], CustomerExtras::NOT_RUNNING, CustomerExtras::NOT_RUNNING],
        ];

        $services = [];
        foreach ($cases as $case => [$overrides, $traffic, $time]) {
            $services[$case] = $service = $this->subscription($this->ali, $this->plan, $this->server, 'ali_' . (count($services) + 1), $overrides);
            self::assertSame([$traffic, $time], [$this->extras->refusal($service, ExtraKind::Traffic), $this->extras->refusal($service, ExtraKind::Time)], $case);
        }

        $offers = $this->extras->offersFor($services);
        foreach ($cases as $case => [, $traffic, $time]) {
            $offer = $offers[$services[$case]->id];
            self::assertSame([$traffic === null, $time === null], [$offer['traffic'] !== null, $offer['time'] !== null], "{$case}: on offer while it can take it");
        }
    }

    public function testAQuoteIsItsPriceOrARefusalUnderItsField(): void
    {
        $this->extrasOnSale(['extra_time_price' => '', 'extra_time_options' => []]);
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1'));

        self::assertSame(['gb' => ['این مقدار فروخته نمی‌شود؛ حجم اضافه بین ۱ تا ۱۰۰ گیگابایت بنویسید، یا یکی از این‌ها را بخرید: ۱۰ گیگابایت (۱۸٬۰۰۰ تومان)، ۵۰ گیگابایت (۱۰۰٬۰۰۰ تومان).']], $this->refusedWith(fn() => $this->extras->quote($service, ExtraKind::Traffic, 101)));
        self::assertSame(['days' => [sprintf(CustomerExtras::NOT_SOLD, 'زمان اضافه')]], $this->refusedWith(fn() => $this->extras->quote($service, ExtraKind::Time, 7)), 'the shop sells no time');

        $unlimited = $this->subscription($this->ali, $this->plan, $this->server, 'ali_2', ['traffic_limit_bytes' => 0]);
        self::assertSame(['status' => [CustomerExtras::UNLIMITED]], $this->refusedWith(fn() => $this->extras->quote($unlimited, ExtraKind::Traffic, 10)), 'what the service cannot take, before what it costs');

        $this->extrasOnSale(['extra_traffic_price' => '', 'extra_traffic_options' => [['quantity' => 10, 'price' => '18000']]]);
        self::assertSame(['gb' => ['این مقدار فروخته نمی‌شود؛ یکی از این‌ها را بخرید: ۱۰ گیگابایت (۱۸٬۰۰۰ تومان).']], $this->refusedWith(fn() => $this->extras->quote($service, ExtraKind::Traffic, 20)), 'no price of a GB: only the amounts priced');
    }

    public function testExtraTrafficFromTheWalletGoesOnTopOfTheServiceAndNothingElseOfIt(): void
    {
        $this->extrasOnSale();
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['download_bytes' => 25 * Traffic::GIGABYTE]));
        $this->wallet($this->ali, '50000');

        $result = $this->buyExtra($service, ExtraKind::Traffic, 10);

        self::assertSame(CheckoutOutcome::Settled, $result->outcome);
        $order = $result->order();
        self::assertSame(
            [OrderType::ExtraTraffic, OrderStatus::Fulfilled, '18000.00', 10 * Traffic::GIGABYTE, null, $service->id, $this->plan->id, null],
            [$order->type, $order->status, $order->amount, $order->traffic_bytes, $order->days, $order->subscription_id, $order->plan_id, $order->server_id],
        );
        $spec = FakeProvider::lastUpdate('ali_1');
        self::assertSame([40 * Traffic::GIGABYTE, '2026-11-09 12:00:00'], [$spec->totalBytes, $spec->expiry->deadline()?->format('Y-m-d H:i:s')], 'ten on top of its thirty; its deadline as it was');
        $service->refresh();
        self::assertSame([SubscriptionStatus::Active, 40 * Traffic::GIGABYTE, 15 * Traffic::GIGABYTE, 30 * 24], [$service->status, $service->traffic_limit_bytes, $service->remainingBytes(), $service->duration_hours]);
        self::assertSame('32000.00', $this->ali->balance());
    }

    public function testAServiceWhoseTrafficRanOutRunsAgain(): void
    {
        $this->extrasOnSale();
        $service = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['status' => SubscriptionStatus::Expired, 'download_bytes' => 30 * Traffic::GIGABYTE]);
        FakeProvider::mirror($service, enabled: false); // the panel switched it off itself, as 3x-ui does a client used up
        $this->wallet($this->ali, '100000');

        self::assertSame(OrderStatus::Fulfilled, $this->buyExtra($service, ExtraKind::Traffic, 5)->order()->status);

        self::assertSame(35 * Traffic::GIGABYTE, FakeProvider::lastUpdate('ali_1')->totalBytes, 'counted from what it used');
        self::assertTrue((FakeProvider::$clients[$this->server->id]['ali_1'] ?? self::fail('gone'))->enabled, 'switched on again');
        self::assertSame([SubscriptionStatus::Active, 5 * Traffic::GIGABYTE], [$service->refresh()->status, $service->remainingBytes()]);
    }

    public function testTimeForAServiceWhoseTimeRanOutStartsItsTermAgainAtItsNextConnection(): void
    {
        $this->extrasOnSale();
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['status' => SubscriptionStatus::Expired, 'starts_at' => now()->subDays(32), 'expires_at' => now()->subDays(2), 'download_bytes' => 12 * Traffic::GIGABYTE]));
        $this->wallet($this->ali, '100000');

        $order = $this->buyExtra($service, ExtraKind::Time, 7)->order();

        self::assertSame([OrderType::ExtraTime, OrderStatus::Fulfilled, '7000.00', 7, null], [$order->type, $order->status, $order->amount, $order->days, $order->traffic_bytes]);
        $spec = FakeProvider::lastUpdate('ali_1');
        self::assertSame([null, 7 * 86400, 30 * Traffic::GIGABYTE], [$spec->expiry->deadline(), $spec->expiry->pendingSeconds(), $spec->totalBytes], 'seven days from its next connection; its traffic as it was');
        $service->refresh();
        self::assertSame([SubscriptionStatus::Active, null, null, 7 * 24, 18 * Traffic::GIGABYTE], [$service->status, $service->starts_at, $service->expires_at, $service->duration_hours, $service->remainingBytes()]);
    }

    public function testTimeForARunningServiceGoesOntoItsDeadline(): void
    {
        $this->extrasOnSale();
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['starts_at' => now()->subDays(25), 'expires_at' => now()->addDays(5)]));
        $this->wallet($this->ali, '100000');

        $this->buyExtra($service, ExtraKind::Time, 30);

        self::assertSame('2026-11-14 12:00:00', FakeProvider::lastUpdate('ali_1')->expiry->deadline()?->format('Y-m-d H:i:s'));
        $service->refresh();
        self::assertSame(['2026-11-14 12:00:00', 60 * 24], [$service->expires_at?->format('Y-m-d H:i:s'), $service->duration_hours]);
        self::assertSame('75000.00', $this->ali->balance(), 'thirty days at their own 25,000');
    }

    public function testACardTransferDeliversOnApprovalAndTheCustomerIsToldWhatTheirServiceGot(): void
    {
        $this->extrasOnSale();
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['download_bytes' => 25 * Traffic::GIGABYTE]));

        $result = $this->buyExtra($service, ExtraKind::Traffic, 10, $this->cardMethod());

        self::assertSame(CheckoutOutcome::Transfer, $result->outcome);
        self::assertSame([], FakeProvider::$updated, 'nothing given before it is paid');
        $payment = $this->receipt($result->payment());
        $this->telegram()->reset();

        $this->service(PaymentActions::class)->approve($payment, $this->panelActor());

        self::assertSame(OrderStatus::Fulfilled, $result->order()->refresh()->status);
        self::assertSame([self::text(BotText::ExtraTrafficAdded, [
            'extra_traffic' => '۱۰ گیگابایت',
            'client' => 'ali_1',
            'remaining' => Messages::bytes(15 * Traffic::GIGABYTE),
            'expires' => Messages::expiry(now()->addDays(30), 30 * 24),
        ])], $this->telegram()->sentTo(self::TELEGRAM_ID));
        $notice = Notification::query()->where('user_id', $this->ali->id)->sole();
        self::assertSame([NoticeType::PaymentSettled, $result->order()->id], [$notice->type, $notice->subject_id], 'kept for their website with its order');
    }

    public function testADeliveryTheServiceCannotTakeAnyMoreFailsWithWhyAndWaitsForSupport(): void
    {
        $this->extrasOnSale();
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1'));
        $result = $this->buyExtra($service, ExtraKind::Traffic, 10, $this->cardMethod());
        $payment = $this->receipt($result->payment());
        // Its time ran out while the receipt waited: the panel says so.
        FakeProvider::put($this->server, new ClientInfo(name: 'ali_1', enabled: false, totalBytes: 30 * Traffic::GIGABYTE, expiry: Expiry::at(now()->subHour()->toDateTimeImmutable()), subscriptionUrl: $service->subscription_url));
        $this->telegram()->reset();

        $this->service(PaymentActions::class)->approve($payment, $this->panelActor());

        $order = $result->order()->refresh();
        self::assertSame([OrderStatus::Failed, CustomerExtras::TIME_OVER], [$order->status, $order->notes]);
        self::assertSame([], FakeProvider::$updated, 'nothing given');
        self::assertSame(SubscriptionStatus::Expired, $service->refresh()->status, 'the row learned it ended');
        self::assertSame([self::text(BotText::ExtraFailed, ['extra' => '۱۰ گیگابایت', 'client' => 'ali_1'])], $this->telegram()->sentTo(self::TELEGRAM_ID), 'paid: support finishes it, nothing to pay again');
    }

    public function testARefundGivesTheMoneyBackAndTakesBackWhatWasDelivered(): void
    {
        $this->extrasOnSale();
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1'));
        $result = $this->buyExtra($service, ExtraKind::Traffic, 10, $this->cardMethod());
        $payment = $this->receipt($result->payment());
        $this->service(PaymentActions::class)->approve($payment, $this->panelActor());

        $this->service(PaymentActions::class)->refund($payment->refresh(), $this->panelActor(), 'درخواست مشتری');

        self::assertSame([PaymentStatus::Refunded, OrderStatus::Refunded], [$payment->refresh()->status, $result->order()->refresh()->status]);
        self::assertSame('18000.00', $this->ali->balance(), 'to the wallet, as a purchase\'s');
        self::assertSame(30 * Traffic::GIGABYTE, $service->refresh()->traffic_limit_bytes, 'its 10 GB taken back off the service');
        self::assertCount(2, FakeProvider::$updated, 'given, then taken back');
    }

    public function testAnAgentsCustomerBuysTrafficOutOfTheAgentsPoolAndTimeCostsItNothing(): void
    {
        $bot = $this->agentBot(traffic: 25);
        [$reza, $service] = $this->agentsService($bot);

        $offer = $this->inShop($bot, fn() => $this->extras->offer($service, ExtraKind::Traffic)) ?? self::fail('no traffic on sale');
        self::assertSame([[10], 25], [array_column($offer->choices(), 'quantity'), $offer->max], 'no more than the pool holds: the 50 GB button gone, 25 the most typed');

        $order = $this->inShop($bot, fn() => $this->buyExtra($service, ExtraKind::Traffic, 20))->order();

        self::assertSame(OrderStatus::Fulfilled, $order->status, (string) $order->notes);
        self::assertSame(Traffic::bytesOfGb(5), $bot->trafficBalance());
        $line = TrafficTransaction::query()->where('bot_id', $bot->id)->latest('id')->firstOrFail();
        self::assertSame(
            [TrafficTransactionType::Sale, -20 * Traffic::GIGABYTE, $order->id, sprintf(TrafficPool::LINE_EXTRA, $order->id)],
            [$line->type, $line->bytes, $line->order_id, $line->description],
        );
        self::assertSame('60000.00', $this->inShop($bot, fn(): string => $reza->balance()), 'twenty GB at 2,000');

        $this->inShop($bot, fn() => $this->buyExtra($service->refresh(), ExtraKind::Time, 7));
        self::assertSame(Traffic::bytesOfGb(5), $bot->trafficBalance(), 'days cost the pool nothing');

        $this->traffic($bot, 0);
        $offers = $this->inShop($bot, fn(): array => $this->extras->offersFor([$service->refresh()])[$service->id]);
        self::assertSame(['traffic' => false, 'time' => true, 'devices' => false], array_map(static fn(?ExtraOffer $offer): bool => $offer !== null, $offers), 'an empty pool sells no traffic (nor does this shop sell devices)');
    }

    public function testAnAgentsTrafficComesBackWhenTheDeliveryFails(): void
    {
        $bot = $this->agentBot(traffic: 25);
        [, $service] = $this->agentsService($bot);
        FakeProvider::$refusing = [$this->server->id => ['updateClient']];

        $order = $this->inShop($bot, fn() => $this->buyExtra($service, ExtraKind::Traffic, 10))->order();

        self::assertSame(OrderStatus::Failed, $order->status);
        self::assertSame(Traffic::bytesOfGb(25), $bot->trafficBalance(), 'all of it back');
        self::assertSame([
            [TrafficTransactionType::Purchase, 25 * Traffic::GIGABYTE],
            [TrafficTransactionType::Sale, -10 * Traffic::GIGABYTE],
            [TrafficTransactionType::Refund, 10 * Traffic::GIGABYTE],
        ], TrafficTransaction::query()->where('bot_id', $bot->id)->orderBy('id')->get()->map(static fn(TrafficTransaction $line): array => [$line->type, $line->bytes])->all());
    }

    /**
     * A customer of the agent's bot with 100,000 Toman in their wallet, and their running service — the agent's plan
     * (30 days, 30 GB) on the shop's server —, the agent's shop selling extras.
     *
     * @return array{User, Subscription}
     */
    private function agentsService(Bot $bot): array
    {
        return $this->inShop($bot, function (): array {
            $this->extrasOnSale();
            $reza = $this->wallet($this->customer(['telegram_id' => 2001, 'username' => 'reza']), '100000');
            $service = $this->mirrored($this->subscription($reza, $this->plan(['name' => 'پلن نماینده']), $this->server, 'reza_1'));

            return [$reza, $service];
        });
    }

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function inShop(Bot $bot, \Closure $work): mixed
    {
        return CurrentBot::run($bot, $work);
    }
}
