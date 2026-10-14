<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Http\ErrorHandler;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\RequestKeyReusedException;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\DTO\Capabilities;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Models\Website;
use App\Modules\Store\Services\CustomerCheckout;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\CustomerExtras;
use App\Modules\Users\Models\User;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * Extra traffic, time and devices on the website: what a service may buy (plan or shop prices; devices for the days it
 * has left), buying by wallet or card, refusals in the shop's words, repeated requests, and an agent's traffic pool.
 */
final class StoreExtrasTest extends HttpTestCase
{
    private Website $website;

    private User $ali;

    private Server $server;

    private Plan $plan;

    private Subscription $subscription;

    private PaymentMethod $card;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->telegram();
        $this->withoutQr();
        $this->website = $this->website();
        $this->server = $this->sellingServer('Berlin');
        $this->plan = $this->plan([], $this->server);
        $this->ali = $this->wallet($this->customer(['username' => 'ali']), '500000');
        // 18 GB of its 30 used, 12 left; it ends in 30 days.
        $this->subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['download_bytes' => Traffic::bytesOfGb(18)]);
        FakeProvider::mirror($this->subscription);
        $this->card = $this->cardMethod();
        $this->extrasOnSale();
        $this->bearer($this->customerSession($this->ali));
    }

    public function testWhatMayBeBoughtIsTheShopsPriceList(): void
    {
        self::assertSame(['extras' => [
            'traffic' => ['price' => '2000.00', 'options' => [['quantity' => 10, 'price' => '18000.00'], ['quantity' => 50, 'price' => '100000.00']], 'custom' => ['min' => 1, 'max' => 100], 'days' => null],
            'time' => ['price' => '1000.00', 'options' => [['quantity' => 7, 'price' => '7000.00'], ['quantity' => 30, 'price' => '25000.00']], 'custom' => ['min' => 1, 'max' => 60], 'days' => null],
            'devices' => null,
        ]], $this->decode($this->get($this->extrasOf($this->subscription))), 'each amount offered with what it costs — its own price, or the price of a GB or a day times it —, and any amount between the bounds; no devices priced');

        self::assertSame(['traffic' => true, 'time' => true, 'devices' => false], $this->row($this->subscription)['extras']);
        self::assertSame(0, Order::query()->count(), 'nothing ordered for a look');
    }

    public function testAPlansOwnPriceListIsWhatItsServicesBuyAt(): void
    {
        // The plan sells 20 GB at 30,000 Toman, none typed — and no time, whatever the shop's list sells.
        $plan = $this->plan(['name' => 'ویژه', 'extras' => [
            'traffic_price' => null,
            'traffic_options' => [['quantity' => 20, 'price' => '30000.00']],
            'traffic_min' => 1,
            'traffic_max' => 100,
            'time_price' => null,
            'time_options' => [],
            'time_min' => 1,
            'time_max' => 30,
            'devices_price' => '10000.00',
            'devices_options' => [],
            'devices_min' => 1,
            'devices_max' => 2,
        ]], $this->server);
        $own = $this->subscription($this->ali, $plan, $this->server, 'ali_2');
        FakeProvider::mirror($own);

        self::assertSame(['extras' => [
            'traffic' => ['price' => null, 'options' => [['quantity' => 20, 'price' => '30000.00']], 'custom' => null, 'days' => null],
            'time' => null,
            'devices' => ['price' => '10000.00', 'options' => [], 'custom' => ['min' => 1, 'max' => 2], 'days' => 30],
        ]], $this->decode($this->get($this->extrasOf($own))));
        self::assertSame(['traffic' => true, 'time' => false, 'devices' => true], $this->row($own)['extras']);

        $shops = $this->buying($own, ExtraKind::Traffic, 10, $this->walletMethod());
        self::assertSame([422, ['این مقدار فروخته نمی‌شود؛ یکی از این‌ها را بخرید: ۲۰ گیگابایت (۳۰٬۰۰۰ تومان).']], [$shops->getStatusCode(), $this->decode($shops)['errors']['gb'] ?? null], "the shop's 10 GB are not its");
        $time = $this->buying($own, ExtraKind::Time, 7, $this->walletMethod(), 'time-1');
        self::assertSame([422, [sprintf(CustomerExtras::NOT_SOLD, 'زمان اضافه')]], [$time->getStatusCode(), $this->decode($time)['errors']['days'] ?? null]);
        self::assertSame(0, Order::query()->count());

        $bought = $this->decode($this->buying($own, ExtraKind::Traffic, 20, $this->walletMethod(), 'own-1'))['checkout'];
        self::assertSame(['settled', '30000.00', Traffic::bytesOfGb(50)], [$bought['outcome'], $bought['order']['amount'], $bought['subscription']['traffic']['limit_bytes'] ?? null], 'at its own price');
    }

    public function testWhileTheShopSellsNoneNothingIsOfferedNorSold(): void
    {
        $this->extrasOnSale(['extras_enabled' => false]);

        self::assertSame(['extras' => ['traffic' => null, 'time' => null, 'devices' => null]], $this->decode($this->get($this->extrasOf($this->subscription))));
        self::assertSame(['traffic' => false, 'time' => false, 'devices' => false], $this->row($this->subscription)['extras']);
        foreach (ExtraKind::cases() as $kind) {
            $response = $this->buying($this->subscription, $kind, 10, $this->walletMethod(), "off-{$kind->value}");
            self::assertSame([422, [sprintf(CustomerExtras::NOT_SOLD, $kind->label())]], [$response->getStatusCode(), $this->decode($response)['errors'][$kind->field()] ?? null], $kind->value);
        }
        self::assertSame([0, '500000.00'], [Order::query()->count(), $this->ali->balance()]);
    }

    public function testEachKindIsOfferedWhileTheServiceMayTakeIt(): void
    {
        $this->extraDevicesOnSale();
        $this->servicesInEveryState();

        $flags = array_column($this->decode($this->get($this->storeApi($this->website, '/subscriptions')))['subscriptions'], 'extras', 'name');

        self::assertSame([
            'ali_8' => ['traffic' => true, 'time' => true, 'devices' => false],
            'ali_7' => ['traffic' => false, 'time' => false, 'devices' => false],
            'ali_6' => ['traffic' => false, 'time' => false, 'devices' => false],
            'ali_5' => ['traffic' => true, 'time' => false, 'devices' => false],
            'ali_4' => ['traffic' => false, 'time' => true, 'devices' => false],
            'ali_3' => ['traffic' => true, 'time' => false, 'devices' => true],
            'ali_2' => ['traffic' => false, 'time' => true, 'devices' => true],
            'ali_1' => ['traffic' => true, 'time' => true, 'devices' => true],
        ], $flags, 'running: all three; unlimited traffic: no traffic; never ending: no time; its time run out: time to start again, nothing else; its traffic run out: traffic to run again, nothing else; switched off by support, or gone from its panel: nothing; unlimited devices: no devices');
    }

    public function testAServiceThatCannotTakeItIsRefusedInTheShopsWords(): void
    {
        $services = $this->servicesInEveryState();
        $refusals = [
            ['unlimited', ExtraKind::Traffic, CustomerExtras::UNLIMITED],
            ['never ending', ExtraKind::Time, CustomerExtras::NO_TERM],
            ['its time run out', ExtraKind::Traffic, CustomerExtras::TIME_OVER],
            ['its traffic run out', ExtraKind::Time, CustomerExtras::TRAFFIC_OVER],
            ['switched off', ExtraKind::Traffic, CustomerExtras::NOT_RUNNING],
            ['switched off', ExtraKind::Time, CustomerExtras::NOT_RUNNING],
            ['gone from its panel', ExtraKind::Traffic, CustomerExtras::NOT_RUNNING],
            ['unlimited devices', ExtraKind::Devices, CustomerExtras::UNLIMITED_DEVICES],
            ['its time run out', ExtraKind::Devices, CustomerExtras::TIME_OVER],
        ];
        $this->extraDevicesOnSale();

        foreach ($refusals as $i => [$state, $kind, $why]) {
            $response = $this->buying($services[$state], $kind, 10, $this->walletMethod(), "refused-{$i}");
            self::assertSame([422, [$why]], [$response->getStatusCode(), $this->decode($response)['errors']['status'] ?? null], "{$state}: {$kind->value}");
        }
        $unreadable = $this->buying($services['switched off'], ExtraKind::Traffic, 'ده', $this->walletMethod(), 'refused-words');
        self::assertSame([422, [CustomerExtras::NOT_RUNNING]], [$unreadable->getStatusCode(), $this->decode($unreadable)['errors']['status'] ?? null], 'the service said first, as for any amount');
        self::assertSame([0, '500000.00'], [Order::query()->count(), $this->ali->balance()]);
    }

    public function testExtraTrafficFromTheWalletIsAddedAtOnce(): void
    {
        $response = $this->buying($this->subscription, ExtraKind::Traffic, 10, $this->walletMethod());

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $checkout = $this->decode($response)['checkout'];
        $order = Order::query()->sole();
        self::assertSame(['settled', $order->id, 'extra_traffic', 'fulfilled', '18000.00'], [$checkout['outcome'], $checkout['order']['id'], $checkout['order']['type'], $checkout['order']['status'], $checkout['order']['amount']]);
        self::assertSame([Traffic::bytesOfGb(10), null, ['id' => $this->subscription->id, 'name' => 'ali_1']], [$checkout['order']['traffic_bytes'], $checkout['order']['days'], $checkout['order']['subscription']]);
        self::assertSame(
            [$this->subscription->id, ['limit_bytes' => Traffic::bytesOfGb(40), 'used_bytes' => Traffic::bytesOfGb(18), 'remaining_bytes' => Traffic::bytesOfGb(22)]],
            [$checkout['subscription']['id'], $checkout['subscription']['traffic']],
            'its 10 GB on top of its 30: the service as it now stands',
        );
        self::assertSame(Traffic::bytesOfGb(40), FakeProvider::lastUpdate('ali_1')->totalBytes, 'its panel given it');
        self::assertSame('482000.00', $this->ali->balance());
        self::assertSame([], $this->telegram()->sentTo(self::TELEGRAM_ID), 'the website shows it: the bot says nothing');
    }

    public function testAServiceWhoseTrafficRanOutRunsAgainWithIt(): void
    {
        $this->subscription->forceFill(['status' => SubscriptionStatus::Expired, 'download_bytes' => Traffic::bytesOfGb(30)])->save();
        FakeProvider::mirror($this->subscription);

        $service = $this->decode($this->buying($this->subscription, ExtraKind::Traffic, 50, $this->walletMethod()))['checkout']['subscription'];

        self::assertSame(['active', ['limit_bytes' => Traffic::bytesOfGb(80), 'used_bytes' => Traffic::bytesOfGb(30), 'remaining_bytes' => Traffic::bytesOfGb(50)]], [$service['status'], $service['traffic']]);
        self::assertSame('400000.00', $this->ali->balance(), '50 GB at the price of a GB');
    }

    public function testExtraTimeFromTheWalletGoesOntoItsEnd(): void
    {
        $checkout = $this->decode($this->buying($this->subscription, ExtraKind::Time, '۷', $this->walletMethod()))['checkout'];

        self::assertSame(['settled', 'extra_time', 'fulfilled', '7000.00', null, 7], [$checkout['outcome'], $checkout['order']['type'], $checkout['order']['status'], $checkout['order']['amount'], $checkout['order']['traffic_bytes'], $checkout['order']['days']], 'typed in Persian digits');
        self::assertSame(['duration_days' => 37, 'duration_hours' => 888, 'starts_at' => '2026-10-07T12:00:00+00:00', 'expires_at' => '2026-11-13T12:00:00+00:00', 'awaits_first_use' => false], $checkout['subscription']['term'], 'its 7 days onto its end');
        self::assertSame('493000.00', $this->ali->balance());
    }

    public function testAServiceWhoseTimeRanOutStartsAgainFromItsNextConnection(): void
    {
        // Its 30 days ran out ten days ago, 12 GB of its traffic still there.
        $this->subscription->forceFill(['status' => SubscriptionStatus::Expired, 'starts_at' => now()->subDays(40), 'expires_at' => now()->subDays(10)])->save();
        FakeProvider::mirror($this->subscription);

        $service = $this->decode($this->buying($this->subscription, ExtraKind::Time, 30, $this->walletMethod()))['checkout']['subscription'];

        self::assertSame(['active', ['duration_days' => 30, 'duration_hours' => 720, 'starts_at' => null, 'expires_at' => null, 'awaits_first_use' => true]], [$service['status'], $service['term']], 'thirty days from its next connection — never onto an end gone by');
        self::assertSame('475000.00', $this->ali->balance(), '30 days at their own price');
    }

    public function testACardTransferWaitsForItsReceiptAndSupportDeliversIt(): void
    {
        // Any amount between the bounds, at the price of a GB.
        $checkout = $this->decode($this->buying($this->subscription, ExtraKind::Traffic, 25, $this->card))['checkout'];

        $payment = Payment::query()->sole();
        self::assertSame(['transfer', 'pending', Traffic::bytesOfGb(25), null], [$checkout['outcome'], $checkout['order']['status'], $checkout['order']['traffic_bytes'], $checkout['subscription']]);
        self::assertSame(['payment_id' => $payment->id, 'amount' => '50000.00', 'card' => '6037997700001119', 'holder' => 'AmoBot', 'instructions' => null], $checkout['transfer']);
        self::assertSame([], FakeProvider::$updated, 'nothing added before the money is in');

        $this->service(PaymentService::class)->approve($this->receipt($payment), 'admin');

        $order = $this->decode($this->get($this->storeApi($this->website, "/orders/{$checkout['order']['id']}")))['order'];
        self::assertSame(['fulfilled', 'paid'], [$order['status'], $order['payments'][0]['status']]);
        self::assertSame(Traffic::bytesOfGb(55), $this->subscription->refresh()->traffic_limit_bytes, 'its 25 GB on top of its 30, once support approved the receipt');
    }

    public function testARequestMadeAgainIsAnsweredByItsOrderWhateverThePricesSaySince(): void
    {
        $first = $this->decode($this->buying($this->subscription, ExtraKind::Traffic, 10, $this->walletMethod(), 'once'))['checkout'];
        // Since: 10 GB no longer sold — 20 GB alone, none typed.
        $this->extrasOnSale(['extra_traffic_price' => '', 'extra_traffic_options' => [['quantity' => 20, 'price' => '30000']]]);

        $again = $this->buying($this->subscription, ExtraKind::Traffic, 10, $this->walletMethod(), 'once');

        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        $answer = $this->decode($again)['checkout'];
        self::assertSame(['settled', $first['order']['id'], '18000.00'], [$answer['outcome'], $answer['order']['id'], $answer['order']['amount']]);
        self::assertSame([1, '482000.00'], [Order::query()->count(), $this->ali->balance()], 'one order, one charge');
        self::assertCount(1, FakeProvider::$updated, 'added once');

        foreach ([
            'another amount' => $this->buying($this->subscription, ExtraKind::Traffic, 20, $this->walletMethod(), 'once'),
            'the other kind' => $this->buying($this->subscription, ExtraKind::Time, 10, $this->walletMethod(), 'once'),
        ] as $why => $response) {
            self::assertSame([422, [RequestKeyReusedException::MESSAGE]], [$response->getStatusCode(), $this->decode($response)['errors']['idempotency_key'] ?? null], $why);
        }
        $otherWay = $this->buying($this->subscription, ExtraKind::Traffic, 10, $this->card, 'once');
        self::assertSame([422, [RequestKeyReusedException::OTHER_METHOD]], [$otherWay->getStatusCode(), $this->decode($otherWay)['errors']['idempotency_key'] ?? null]);
        self::assertSame(1, Order::query()->count());
    }

    public function testACardsRequestMadeAgainIsItsOwnOrderAtThePriceItWasMadeAt(): void
    {
        $first = $this->decode($this->buying($this->subscription, ExtraKind::Traffic, 50, $this->card, 'by-card'))['checkout'];
        $this->extrasOnSale(['extra_traffic_price' => '3000']);

        $again = $this->decode($this->buying($this->subscription, ExtraKind::Traffic, 50, $this->card, 'by-card'))['checkout'];

        self::assertSame(['transfer', $first['order']['id'], '100000.00', $first['transfer']], [$again['outcome'], $again['order']['id'], $again['order']['amount'], $again['transfer']], 'the same payment to the same card, at the price it was made at');
        self::assertSame([1, 1], [Order::query()->count(), Payment::query()->count()]);

        // Still to be paid, it meets today's rules first: 50 GB no longer sold is a refusal — and its order stays.
        $this->extrasOnSale(['extra_traffic_options' => [['quantity' => 10, 'price' => '18000']], 'extra_traffic_max' => 40]);
        $refused = $this->buying($this->subscription, ExtraKind::Traffic, 50, $this->card, 'by-card');
        self::assertSame(422, $refused->getStatusCode());
        self::assertArrayHasKey('gb', $this->decode($refused)['errors']);
        self::assertSame(OrderStatus::Pending, Order::query()->sole()->status);
    }

    /** The service tells an extra apart, not its plan: a request made again after the service got one is the same request. */
    public function testARequestMadeAgainAfterTheServiceGotAPlanIsStillItsOwnOrder(): void
    {
        $this->subscription->forceFill(['plan_id' => null])->save(); // added by its link, or built
        $first = $this->decode($this->buying($this->subscription, ExtraKind::Traffic, 10, $this->card, 'by-card'))['checkout'];
        $this->subscription->forceFill(['plan_id' => $this->plan->id])->save(); // since: renewed on a plan it picked

        $again = $this->buying($this->subscription, ExtraKind::Traffic, 10, $this->card, 'by-card');

        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame($first['order']['id'], $this->decode($again)['checkout']['order']['id']);
        self::assertSame(1, Order::query()->count());
    }

    public function testAnAmountNotOnOfferIsRefusedUnderItsFieldWithWhatMayBeBought(): void
    {
        $traffic = 'این مقدار فروخته نمی‌شود؛ حجم اضافه بین ۱ تا ۱۰۰ گیگابایت بنویسید، یا یکی از این‌ها را بخرید: ۱۰ گیگابایت (۱۸٬۰۰۰ تومان)، ۵۰ گیگابایت (۱۰۰٬۰۰۰ تومان).';
        $time = 'این مقدار فروخته نمی‌شود؛ زمان اضافه بین ۱ تا ۶۰ روز بنویسید، یا یکی از این‌ها را بخرید: ۷ روز (۷٬۰۰۰ تومان)، ۳۰ روز (۲۵٬۰۰۰ تومان).';
        $refusals = [
            [ExtraKind::Traffic, 101, $traffic],
            [ExtraKind::Traffic, 0, $traffic],
            [ExtraKind::Time, 61, $time],
            [ExtraKind::Traffic, '1.5', sprintf(CustomerCheckout::NOT_A_QUANTITY, 'گیگابایت')],
            [ExtraKind::Traffic, 'ده گیگ', sprintf(CustomerCheckout::NOT_A_QUANTITY, 'گیگابایت')],
            [ExtraKind::Time, '-3', sprintf(CustomerCheckout::NOT_A_QUANTITY, 'روز')],
        ];

        foreach ($refusals as $i => [$kind, $quantity, $why]) {
            $response = $this->buying($this->subscription, $kind, $quantity, $this->walletMethod(), "amount-{$i}");
            self::assertSame([422, [$why]], [$response->getStatusCode(), $this->decode($response)['errors'][$kind->field()] ?? null], "{$kind->value} {$quantity}");
        }

        $off = $this->cardMethod('کارت خاموش', overrides: ['enabled' => false]);
        $response = $this->buying($this->subscription, ExtraKind::Traffic, 10, $off, 'switched-off');
        self::assertSame([422, ['این روش پرداخت برای این سفارش در دسترس نیست.']], [$response->getStatusCode(), $this->decode($response)['errors']['method_id'] ?? null], 'a way to pay that may not pay it');
        self::assertSame([0, '500000.00'], [Order::query()->count(), $this->ali->balance()]);
    }

    public function testTheWaysToPayExtrasAreTheCheckoutsWalletAmongThem(): void
    {
        $both = [['id' => $this->walletMethod()->id, 'label' => 'کیف پول', 'kind' => 'instant'], ['id' => $this->card->id, 'label' => 'کارت به کارت (ملت)', 'kind' => 'manual']];

        foreach (['extra_traffic', 'extra_time', 'extra_devices'] as $for) {
            self::assertSame($both, $this->decode($this->get($this->storeApi($this->website, "/payment-methods?for={$for}")))['methods'], $for);
        }
    }

    public function testAnOrderOfExtrasSaysWhatItBroughtAndToWhichService(): void
    {
        $traffic = $this->paidExtra($this->subscription, ExtraKind::Traffic, 10, '18000.00');
        Carbon::setTestNow(now()->addMinute());
        $time = $this->paidExtra($this->subscription, ExtraKind::Time, 7, '7000.00');

        $rows = array_column($this->decode($this->get($this->storeApi($this->website, '/orders')))['orders'], null, 'id');
        $brought = static fn(array $row): array => [$row['type'], $row['status'], $row['plan'], $row['server'], $row['subscription'], $row['traffic_bytes'], $row['days']];
        $plan = ['id' => $this->plan->id, 'name' => 'یک‌ماهه'];
        $berlin = ['id' => $this->server->id, 'name' => 'Berlin'];
        $service = ['id' => $this->subscription->id, 'name' => 'ali_1'];

        self::assertSame(['extra_traffic', 'fulfilled', $plan, $berlin, $service, Traffic::bytesOfGb(10), null], $brought($rows[$traffic->id]));
        self::assertSame(['extra_time', 'fulfilled', $plan, $berlin, $service, null, 7], $brought($rows[$time->id]), "extras name no server of their own: their service's");
        self::assertSame([$time->id], array_column($this->decode($this->get($this->storeApi($this->website, '/orders?type=extra_time')))['orders'], 'id'), 'a kind of order of its own');
    }

    public function testAServiceOfAnotherCustomerIsNotThere(): void
    {
        $theirs = $this->subscription($this->customer(['telegram_id' => 7272]), $this->plan, $this->server, 'reza_1');

        foreach ([
            $this->get($this->extrasOf($theirs)),
            $this->buying($theirs, ExtraKind::Traffic, 10, $this->walletMethod(), 'theirs-1'),
            $this->buying($theirs, ExtraKind::Time, 7, $this->walletMethod(), 'theirs-2'),
        ] as $i => $response) {
            self::assertSame([404, ErrorHandler::NOT_FOUND], [$response->getStatusCode(), $this->decode($response)['message']], "request {$i}");
        }
        self::assertSame(0, Order::query()->count());
    }

    public function testInAnAgentsShopExtraTrafficIsWhatTheirPoolStillHolds(): void
    {
        $bot = $this->agentBot(traffic: 30);
        [$website, $token, $service, $wallet] = CurrentBot::run($bot, function (): array {
            $this->extrasOnSale();
            $customer = $this->wallet($this->customer(['telegram_id' => 8181]), '500000');
            $service = $this->subscription($customer, $this->plan([], $this->server), $this->server, 'sara_1');
            FakeProvider::mirror($service);

            return [$this->website(), $this->customerSession($customer), $service, $this->walletMethod()];
        });
        $this->bearer($token);
        $traffic = fn(): ?array => $this->decode($this->get($this->storeApi($website, "/subscriptions/{$service->id}/extras")))['extras']['traffic'];

        $offered = $traffic();
        self::assertSame(['price' => '2000.00', 'options' => [['quantity' => 10, 'price' => '18000.00']], 'custom' => ['min' => 1, 'max' => 30], 'days' => null], $offered, 'their 30 GB: no 50 GB, nothing typed past 30');

        $bought = $this->send('POST', $this->storeApi($website, "/subscriptions/{$service->id}/extra-traffic"), ['gb' => 10, 'method_id' => $wallet->id], ['Idempotency-Key' => 'agent-1']);
        self::assertSame('settled', $this->decode($bought)['checkout']['outcome'] ?? null, (string) $bought->getBody());
        self::assertSame(Traffic::bytesOfGb(20), $bot->trafficBalance(), 'drawn from their pool, as a sale is');
        $left = $traffic();
        self::assertSame(['min' => 1, 'max' => 20], $left['custom'] ?? null, 'what their pool still holds');

        $more = $this->send('POST', $this->storeApi($website, "/subscriptions/{$service->id}/extra-traffic"), ['gb' => 25, 'method_id' => $wallet->id], ['Idempotency-Key' => 'agent-2']);
        self::assertSame(422, $more->getStatusCode());
        self::assertArrayHasKey('gb', $this->decode($more)['errors']);
    }

    public function testExtraDevicesArePricedForTheDaysTheServiceHasLeftAndAddedAtOnce(): void
    {
        $this->extraDevicesOnSale();
        // 12 of its 30 days left, two devices at once.
        $this->subscription->forceFill(['ip_limit' => 2, 'starts_at' => now()->subDays(18), 'expires_at' => now()->addDays(12)])->save();
        FakeProvider::mirror($this->subscription);

        self::assertSame(
            ['price' => '12000.00', 'options' => [['quantity' => 1, 'price' => '12000.00'], ['quantity' => 2, 'price' => '20000.00']], 'custom' => ['min' => 1, 'max' => 5], 'days' => 12],
            $this->decode($this->get($this->extrasOf($this->subscription)))['extras']['devices'],
            "12 of 30 days: 30,000 a device and the two's own 50,000, prorated",
        );
        self::assertSame([2, true], [$this->row($this->subscription)['devices'], $this->row($this->subscription)['extras']['devices']]);

        $checkout = $this->decode($this->buying($this->subscription, ExtraKind::Devices, '۳', $this->walletMethod()))['checkout'];

        self::assertSame(['settled', 'extra_devices', 'fulfilled', '36000.00', 3, null, null], [$checkout['outcome'], $checkout['order']['type'], $checkout['order']['status'], $checkout['order']['amount'], $checkout['order']['devices'], $checkout['order']['traffic_bytes'], $checkout['order']['days']], 'typed in Persian digits: three at 12,000');
        self::assertSame(5, $checkout['subscription']['devices'], 'three on top of its two');
        self::assertSame([5, Traffic::bytesOfGb(30)], [FakeProvider::lastUpdate('ali_1')->ipLimit, FakeProvider::lastUpdate('ali_1')->totalBytes], 'its panel given them, nothing else');
        self::assertSame('464000.00', $this->ali->balance());

        $rows = $this->decode($this->get($this->storeApi($this->website, '/orders?type=extra_devices')))['orders'];
        self::assertSame([['extra_devices', 3, ['id' => $this->subscription->id, 'name' => 'ali_1']]], array_map(static fn(array $row): array => [$row['type'], $row['devices'], $row['subscription']], $rows), 'a kind of order of its own, saying how many it brought');
    }

    public function testExtraDevicesAreRefusedWhereTheyCannotBeHad(): void
    {
        $this->extraDevicesOnSale();

        $notOnOffer = $this->buying($this->subscription, ExtraKind::Devices, 6, $this->walletMethod(), 'devices-1');
        self::assertSame([422, ['این مقدار فروخته نمی‌شود؛ کاربر اضافه بین ۱ تا ۵ کاربر بنویسید، یا یکی از این‌ها را بخرید: ۱ کاربر (۳۰٬۰۰۰ تومان)، ۲ کاربر (۵۰٬۰۰۰ تومان).']], [$notOnOffer->getStatusCode(), $this->decode($notOnOffer)['errors']['devices'] ?? null]);
        $words = $this->buying($this->subscription, ExtraKind::Devices, 'دو', $this->walletMethod(), 'devices-2');
        self::assertSame([422, [sprintf(CustomerCheckout::NOT_A_QUANTITY, 'کاربر')]], [$words->getStatusCode(), $this->decode($words)['errors']['devices'] ?? null]);

        FakeProvider::$capabilities = new Capabilities(inbounds: true, linkRotation: true, ipLimit: false);
        self::assertNull($this->decode($this->get($this->extrasOf($this->subscription)))['extras']['devices'], 'a panel without an IP limit: none offered');
        foreach (['devices-3' => 1, 'devices-4' => 'دو'] as $key => $devices) {
            $refused = $this->buying($this->subscription, ExtraKind::Devices, $devices, $this->walletMethod(), $key);
            self::assertSame([422, [CustomerExtras::NO_DEVICE_LIMIT]], [$refused->getStatusCode(), $this->decode($refused)['errors']['status'] ?? null], "the service's panel said first: {$key}");
        }
        self::assertSame([0, '500000.00'], [Order::query()->count(), $this->ali->balance()]);
    }

    public function testADiscountCodeIsCheckedOnExtraDevicesAtTheirProratedPrice(): void
    {
        $this->extraDevicesOnSale();
        $this->subscription->forceFill(['expires_at' => now()->addDays(15)])->save();
        $this->discountCode('MORE', 10);

        $quote = $this->decode($this->postJson($this->storeApi($this->website, '/discount-codes/check'), ['code' => 'more', 'for' => 'extra_devices', 'subscription_id' => $this->subscription->id, 'devices' => 2]))['quote'];

        self::assertSame(['base' => '25000.00', 'amount' => '22500.00'], ['base' => $quote['base'], 'amount' => $quote['amount']], "the two's 50,000 for 15 of 30 days, less 10%");
        $methods = $this->decode($this->get($this->storeApi($this->website, '/payment-methods?for=extra_devices')))['methods'];
        self::assertSame([$this->walletMethod()->id, $this->card->id], array_column($methods, 'id'), 'paid as every extra is');
    }

    /**
     * The customer's services in every state an extra is judged by — beside the running one of setUp() (ali_1), by its
     * state's name: unlimited (ali_2), never ending (ali_3), its time run out (ali_4), its traffic run out (ali_5),
     * switched off by support (ali_6), gone from its panel (ali_7), unlimited devices (ali_8).
     *
     * @return array<string, Subscription>
     */
    private function servicesInEveryState(): array
    {
        return [
            'unlimited' => $this->subscription($this->ali, $this->plan(['name' => 'نامحدود', 'traffic_gb' => 0], $this->server), $this->server, 'ali_2'),
            'never ending' => $this->subscription($this->ali, $this->plan(['name' => 'دائمی', 'duration_hours' => 0], $this->server), $this->server, 'ali_3'),
            // Its 30 days ran out ten days ago, its traffic not.
            'its time run out' => $this->subscription($this->ali, $this->plan, $this->server, 'ali_4', ['status' => SubscriptionStatus::Expired, 'starts_at' => now()->subDays(40), 'expires_at' => now()->subDays(10)]),
            // Its 30 GB used, its days not.
            'its traffic run out' => $this->subscription($this->ali, $this->plan, $this->server, 'ali_5', ['status' => SubscriptionStatus::Expired, 'download_bytes' => Traffic::bytesOfGb(30)]),
            'switched off' => $this->subscription($this->ali, $this->plan, $this->server, 'ali_6', ['status' => SubscriptionStatus::Disabled]),
            'gone from its panel' => $this->subscription($this->ali, $this->plan, $this->server, 'ali_7', ['status' => SubscriptionStatus::Deleted]),
            'unlimited devices' => $this->subscription($this->ali, $this->plan, $this->server, 'ali_8', ['ip_limit' => 0]),
        ];
    }

    /** @return array<string, mixed> The service as the website shows it */
    private function row(Subscription $subscription): array
    {
        return $this->decode($this->get($this->storeApi($this->website, "/subscriptions/{$subscription->id}")))['subscription'];
    }

    private function extrasOf(Subscription $subscription): string
    {
        return $this->storeApi($this->website, "/subscriptions/{$subscription->id}/extras");
    }

    /** `$quantity` of `$kind` for the service, under the kind's own field as the website sends it, paid with `$method`. */
    private function buying(Subscription $subscription, ExtraKind $kind, int|string $quantity, PaymentMethod $method, string $key = 'extra-1'): ResponseInterface
    {
        $path = match ($kind) {
            ExtraKind::Traffic => 'extra-traffic',
            ExtraKind::Time => 'extra-time',
            ExtraKind::Devices => 'extra-devices',
        };

        return $this->send('POST', $this->storeApi($this->website, "/subscriptions/{$subscription->id}/{$path}"), [$kind->field() => $quantity, 'method_id' => $method->id], ['Idempotency-Key' => $key]);
    }
}
