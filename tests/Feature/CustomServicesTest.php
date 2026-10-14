<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\ValidationException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Enums\CustomQuantity;
use App\Modules\Catalog\Services\CustomServices;
use App\Modules\Discounts\Enums\DiscountSource;
use App\Modules\Discounts\Exceptions\DiscountCodeRefusedException;
use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\Enums\OrderPricing;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\RenewalUnderWayException;
use App\Modules\Orders\Exceptions\RequestKeyReusedException;
use App\Modules\Orders\Services\OrderDirectory;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\Pricing;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentDirectory;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Settings\Services\Settings;
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletService;
use App\Support\Traffic;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;

/**
 * «سرویس دلخواه»: offered in the main shop alone while switched on, both price lists sell and a server of its own can
 * take a client; each quantity within its bounds (the least never below its first tier) priced whole at its tier, the
 * two added, the customer's discounts off that as for a purchase of no plan; ordered as a purchase without a plan that
 * carries its own GB, days and devices, and delivered on the server picked as a service of exactly that, without a plan
 * — named so in the report group and the panels. A service without a plan renewed with a package is priced as a renewal
 * of no plan on its own server, and ordered so under the renewals' lock.
 */
final class CustomServicesTest extends DatabaseTestCase
{
    private Server $germany;
    private Server $france;
    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        $this->germany = $this->sellingServer('آلمان', ['sort' => 1]);
        $this->france = $this->sellingServer('فرانسه', ['sort' => 2]);
        $this->ali = $this->customer();
    }

    public function testOfferedOnlyWhileSwitchedOnWithBothPriceListsAndAServerThatCanTakeAClient(): void
    {
        self::assertFalse($this->custom()->offered(), 'off by default');

        $this->customServicesOn([$this->germany, $this->france]);
        self::assertTrue($this->custom()->offered());
        self::assertSame([$this->germany->id, $this->france->id], array_map(static fn(Server $server): int => $server->id, $this->custom()->servers()), "in the servers' order");

        $this->customServicesOn([$this->germany, $this->france], ['custom_days_tiers' => []]);
        self::assertFalse($this->custom()->offered(), 'the days not priced');
        self::assertSame([], $this->custom()->servers());

        $this->customServicesOn([$this->germany, $this->france], ['custom_traffic_tiers' => []]);
        self::assertFalse($this->custom()->offered(), 'the GB not priced');

        $this->customServicesOn([$this->germany, $this->france], ['custom_traffic_tiers' => [['from' => 600, 'price' => '3000']], 'custom_traffic_max' => 500]);
        self::assertFalse($this->custom()->offered(), 'no GB within the bounds is priced');

        $this->customServicesOn([]);
        self::assertFalse($this->custom()->offered(), 'no server of its own');

        $this->customServicesOn([$this->germany, $this->france], ['custom_enabled' => false]);
        self::assertFalse($this->custom()->offered(), 'switched off');
    }

    public function testOnlyItsServersThatCanTakeAClientNowAreOffered(): void
    {
        $this->customServicesOn([$this->germany, $this->france]);
        $this->france->forceFill(['is_active' => false])->save();
        $elsewhere = $this->sellingServer('هلند');

        self::assertSame([$this->germany->id], array_map(static fn(Server $server): int => $server->id, $this->custom()->servers()), 'not one switched off, nor one not its own');
        self::assertSame($this->germany->id, $this->custom()->server(null)->id, 'the only one, when none is named');
        self::assertSame('server_id', array_key_first($this->refusedWith(fn() => $this->custom()->server($elsewhere->id))));
        self::assertSame('server_id', array_key_first($this->refusedWith(fn() => $this->custom()->server($this->france->id))));

        $this->france->forceFill(['is_active' => true])->save();
        self::assertSame('server_id', array_key_first($this->refusedWith(fn() => $this->custom()->server(null))), 'one must be picked among several');

        $this->germany->forceFill(['is_active' => false])->save();
        $this->france->forceFill(['is_active' => false])->save();
        self::assertFalse($this->custom()->offered(), 'none can take a client now');
        self::assertSame('status', array_key_first($this->refusedWith(fn() => $this->custom()->server(null))));
    }

    public function testTheMainShopsAlone(): void
    {
        $this->customServicesOn([$this->germany]);

        CurrentBot::run($this->agentBot(), function (): void {
            self::assertArrayNotHasKey('custom_enabled', $this->service(BotSettingsScreen::class)->present(), "an agent's shop has no such group");
            // A value stored for it all the same changes nothing.
            $settings = $this->service(Settings::class);
            $settings->set('custom.enabled', true);
            $settings->set('custom.traffic_tiers', [['from' => 1, 'price' => '3000.00']]);
            $settings->set('custom.days_tiers', [['from' => 1, 'price' => '2000.00']]);
            $settings->set('custom.servers', [$this->germany->id]);

            self::assertFalse($this->custom()->offered());
        });
    }

    public function testEachQuantityIsPricedWholeAtItsTierAndTheTwoAdded(): void
    {
        $this->customServicesOn([$this->germany]);

        $price = $this->custom()->quote(50, 30);
        self::assertSame([50, 30, '125000.00', '45000.00', '170000.00'], [$price->gb, $price->days, $price->traffic, $price->time, $price->total()], '50 GB at 2,500 and 30 days at 1,500');
        self::assertSame(['147000.00', '58000.00'], [$this->custom()->quote(49, 29)->traffic, $this->custom()->quote(49, 29)->time], 'one below each tier: the tier before');
        self::assertSame(['3000.00', '2000.00'], [$this->custom()->quote(1, 1)->traffic, $this->custom()->quote(1, 1)->time], 'the least');
        self::assertSame(['1250000.00', '135000.00'], [$this->custom()->quote(500, 90)->traffic, $this->custom()->quote(500, 90)->time], 'the most');
    }

    public function testAQuantityOutsideItsBoundsIsRefusedSayingThem(): void
    {
        $this->customServicesOn([$this->germany]);

        foreach ([[0, 30, 'gb'], [501, 30, 'gb'], [50, 0, 'days'], [50, 91, 'days']] as [$gb, $days, $field]) {
            try {
                $this->custom()->quote($gb, $days);
                self::fail("{$gb} GB for {$days} days was priced");
            } catch (ValidationException $e) {
                self::assertSame([$field], array_keys($e->errors()));
                self::assertSame($field === 'gb' ? 'حجم را عددی بین ۱ تا ۵۰۰ گیگابایت بنویسید.' : 'مدت را عددی بین ۱ تا ۹۰ روز بنویسید.', $e->getMessage());
            }
        }
    }

    public function testTheBoundsStartAtTheFirstTierAndTheTiersAndButtonsKeepWithinThem(): void
    {
        $this->customServicesOn([$this->germany], [
            'custom_traffic_tiers' => [['from' => 5, 'price' => '3000'], ['from' => 50, 'price' => '2500'], ['from' => 300, 'price' => '2000']],
            'custom_traffic_min' => 1,
            'custom_traffic_max' => 200,
            'custom_traffic_options' => [1, 10, 250],
            'custom_days_tiers' => [['from' => 1, 'price' => '2000'], ['from' => 7, 'price' => '1800'], ['from' => 30, 'price' => '1500']],
            'custom_days_min' => 10,
        ]);

        self::assertSame([5, 200], $this->custom()->bounds(CustomQuantity::Traffic), 'nothing below the first tier is priced');
        self::assertSame([10], $this->custom()->options(CustomQuantity::Traffic), 'the buttons within the bounds');
        self::assertSame([['from' => 5, 'price' => '3000.00'], ['from' => 50, 'price' => '2500.00']], $this->custom()->tiers(CustomQuantity::Traffic)->tiers, 'a tier past the most is left out');
        self::assertSame([['from' => 10, 'price' => '1800.00'], ['from' => 30, 'price' => '1500.00']], $this->custom()->tiers(CustomQuantity::Days)->tiers, 'the tier the least falls in starts there; one below it is left out');
        self::assertFalse($this->custom()->allowed(CustomQuantity::Traffic, 4));
        self::assertTrue($this->custom()->allowed(CustomQuantity::Traffic, 5));
        self::assertFalse($this->custom()->allowed(CustomQuantity::Days, 9));
        self::assertSame(['gb' => ['حجم را عددی بین ۵ تا ۲۰۰ گیگابایت بنویسید.']], $this->refusedWith(fn() => $this->custom()->quote(4, 30)), 'the bounds said');
        self::assertSame('15000.00', $this->custom()->quote(5, 30)->traffic);
    }

    public function testThePriceIsTheTiersLessTheCustomersAutomaticDiscountAsAPurchaseOfNoPlan(): void
    {
        $this->customServicesOn([$this->germany]);
        $price = $this->custom()->quote(50, 30);

        $quote = $this->pricing()->custom($this->ali, $price, $this->germany);
        self::assertSame(['170000.00', OrderPricing::Regular, null, '170000.00'], [$quote->base, $quote->pricing, $quote->automatic, $quote->amount()]);

        $this->ali->forceFill(['discount_percent' => 10])->save();
        self::assertSame([DiscountSource::Personal, '153000.00'], [$this->pricing()->custom($this->ali, $price)->automatic?->source, $this->pricing()->custom($this->ali, $price)->amount()], 'their own percent');

        $this->ali->forceFill(['discount_percent' => 0])->save();
        $plan = $this->plan(on: $this->germany);
        $this->sale('فروش پلن', 30, ['plan_ids' => [$plan->id]]);
        self::assertNull($this->pricing()->custom($this->ali, $price)->automatic, 'a sale of certain plans does not reach it');
        $this->sale('فروش همه', 20);
        self::assertSame('136000.00', $this->pricing()->custom($this->ali, $price)->amount(), 'a sale of every plan does');
        self::assertSame('136000.00', $this->pricing()->custom(null, $price)->amount(), 'a visitor too: a sale is for everyone');
    }

    public function testACodeHoldsForItAsForAPurchaseOfNoPlanOnTheServerPicked(): void
    {
        $this->customServicesOn([$this->germany, $this->france]);
        $price = $this->custom()->quote(50, 30);
        $plan = $this->plan(on: $this->germany);
        $this->discountCode('ALL', 10);
        $this->discountCode('PLANONLY', 10, ['plan_ids' => [$plan->id]]);
        $this->discountCode('GERMANY', 50, ['server_ids' => [$this->germany->id]]);
        $this->discountCode('RENEWALS', 10, ['order_types' => [OrderType::Renewal->value]]);

        $quote = $this->pricing()->custom($this->ali, $price, $this->germany, 'all');
        self::assertSame([DiscountSource::Code, '153000.00'], [$quote->code?->source, $quote->amount()]);
        self::assertSame('85000.00', $this->pricing()->custom($this->ali, $price, $this->germany, 'GERMANY')->amount(), 'on the server it names');
        foreach ([['PLANONLY', $this->germany], ['GERMANY', $this->france], ['RENEWALS', $this->germany]] as [$code, $server]) {
            try {
                $this->pricing()->custom($this->ali, $price, $server, $code);
                self::fail("{$code} held on {$server->name}");
            } catch (DiscountCodeRefusedException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testItIsOrderedAsAPurchaseWithoutAPlanCarryingItsOwnPackage(): void
    {
        $this->customServicesOn([$this->germany]);

        $order = $this->orders()->openCustomPurchase($this->ali, 50, 30, $this->germany);
        self::assertSame(
            [OrderType::Purchase, OrderStatus::Pending, null, $this->germany->id, Traffic::bytesOfGb(50), 30, 2, null, '170000.00'],
            [$order->type, $order->status, $order->plan_id, $order->server_id, $order->traffic_bytes, $order->days, $order->devices, $order->client_name, $order->amount],
        );
        self::assertTrue($order->isBuilt());
        self::assertSame([30 * 24, Traffic::bytesOfGb(50), 2], [$order->package()?->hours, $order->package()?->bytes, $order->package()?->ipLimit]);
        self::assertSame('سرویس دلخواه · ۵۰ گیگ · ۳۰ روز', $order->packageName());

        self::assertSame($order->id, $this->orders()->openCustomPurchase($this->ali, 50, 30, $this->germany)->id, 'the open order of the same is found');
        self::assertNotSame($order->id, $this->orders()->openCustomPurchase($this->ali, 60, 30, $this->germany)->id, 'another amount is another order');

        $keyed = $this->orders()->openCustomPurchase($this->ali, 10, 7, $this->germany, new OrderKey('key-1', $this->walletMethod()->id));
        self::assertSame($keyed->id, $this->orders()->openCustomPurchase($this->ali, 10, 7, $this->germany, new OrderKey('key-1', $this->walletMethod()->id))->id, 'made again with its key');
        $this->expectException(RequestKeyReusedException::class);
        $this->orders()->openCustomPurchase($this->ali, 20, 7, $this->germany, new OrderKey('key-1', $this->walletMethod()->id));
    }

    public function testTheBuildersDevicesAreNoPartOfWhatAnOrderIs(): void
    {
        $this->customServicesOn([$this->germany]);
        $wallet = $this->walletMethod()->id;
        $service = $this->subscription($this->ali, $this->plan(on: $this->germany), $this->germany, 'ali_1', ['plan_id' => null]);
        $open = $this->orders()->openCustomPurchase($this->ali, 50, 30, $this->germany);
        $keyed = $this->orders()->openCustomPurchase($this->ali, 10, 7, $this->germany, new OrderKey('key-1', $wallet));
        $renewal = $this->orders()->openCustomRenewal($this->ali, $service, 10, 7, RenewalMethod::Extend, new OrderKey('key-2', $wallet));
        self::assertSame([2, 2, 2], [$open->devices, $keyed->devices, $renewal->devices]);

        $this->customServicesOn([$this->germany], ['custom_devices' => 3]);
        self::assertSame($keyed->id, $this->orders()->openCustomPurchase($this->ali, 10, 7, $this->germany, new OrderKey('key-1', $wallet))->id, 'made again with its key: the same request, whatever the admin set since');
        self::assertSame($renewal->id, $this->orders()->openCustomRenewal($this->ali, $service, 10, 7, RenewalMethod::Extend, new OrderKey('key-2', $wallet))->id, 'a renewal too');
        $again = $this->orders()->openCustomPurchase($this->ali, 50, 30, $this->germany);
        self::assertSame([$open->id, 3], [$again->id, $open->refresh()->devices], 'the open order of the same is found, with the devices as they are now');
    }

    public function testRenewingWithAPackageIsPricedAsARenewalOfNoPlanOnTheServicesServer(): void
    {
        $this->customServicesOn([$this->germany]);
        $price = $this->custom()->quote(50, 30);
        $plan = $this->plan(['agent_price' => '1000.00'], $this->germany);
        $agent = $this->agent(overrides: ['telegram_id' => 7001]);
        $service = $this->subscription($agent, $plan, $this->france, 'agent_1', ['plan_id' => null]);

        $quote = $this->pricing()->renewal($agent, $service, $price);
        self::assertSame(['170000.00', OrderPricing::Regular, null], [$quote->base, $quote->pricing, $quote->automatic], "no plan's price: the builder's tiers");

        $this->sale('فروش خرید', 30, ['order_types' => [OrderType::Purchase->value]]);
        self::assertNull($this->pricing()->renewal($agent, $service, $price)->automatic, "a purchases' sale does not reach a renewal");
        $this->sale('فروش تمدید', 20, ['order_types' => [OrderType::Renewal->value]]);
        self::assertSame('136000.00', $this->pricing()->renewal($agent, $service, $price)->amount(), "a renewals' sale of every plan does");

        $this->discountCode('RENEWALS', 10, ['order_types' => [OrderType::Renewal->value]]);
        $this->discountCode('FRANCE', 50, ['server_ids' => [$this->france->id]]);
        $this->discountCode('PURCHASES', 10, ['order_types' => [OrderType::Purchase->value]]);
        $this->discountCode('PLANONLY', 10, ['plan_ids' => [$plan->id]]);
        $this->discountCode('GERMANY', 10, ['server_ids' => [$this->germany->id]]);
        self::assertSame('122400.00', $this->pricing()->renewal($agent, $service, $price, 'renewals')->amount(), 'the code on what the sale left');
        self::assertSame('68000.00', $this->pricing()->renewal($agent, $service, $price, 'FRANCE')->amount(), "the service's own server, not the builder's");
        foreach (['PURCHASES', 'PLANONLY', 'GERMANY'] as $code) {
            try {
                $this->pricing()->renewal($agent, $service, $price, $code);
                self::fail("{$code} held for a renewal with a package");
            } catch (DiscountCodeRefusedException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testARenewalWithAPackageIsOrderedWithoutAPlanUnderTheRenewalsLock(): void
    {
        $this->customServicesOn([$this->germany]);
        $plan = $this->plan(on: $this->germany);
        $service = $this->subscription($this->ali, $plan, $this->germany, 'ali_1', ['plan_id' => null]);
        $wallet = $this->walletMethod()->id;

        $order = $this->orders()->openCustomRenewal($this->ali, $service, 50, 30, RenewalMethod::ResetTraffic);
        self::assertSame(
            [OrderType::Renewal, OrderStatus::Pending, null, $service->id, Traffic::bytesOfGb(50), 30, 2, RenewalMethod::ResetTraffic, '170000.00'],
            [$order->type, $order->status, $order->plan_id, $order->subscription_id, $order->traffic_bytes, $order->days, $order->devices, $order->renewal_method, $order->amount],
        );
        self::assertSame([true, 30 * 24, Traffic::bytesOfGb(50), 2, 'سرویس دلخواه · ۵۰ گیگ · ۳۰ روز'], [$order->isBuilt(), $order->package()?->hours, $order->package()?->bytes, $order->package()?->ipLimit, $order->packageName()]);

        self::assertSame($order->id, $this->orders()->openCustomRenewal($this->ali, $service, 50, 30, RenewalMethod::ResetTraffic)->id, 'the open order of the same is found');
        self::assertNotSame($order->id, $this->orders()->openCustomRenewal($this->ali, $service, 50, 30, RenewalMethod::Extend)->id, 'by another method, another order');
        self::assertNotSame($order->id, $this->orders()->openRenewal($this->ali, $service, $plan, RenewalMethod::ResetTraffic)->id, 'on a plan, another order');

        $keyed = $this->orders()->openCustomRenewal($this->ali, $service, 10, 7, RenewalMethod::Extend, new OrderKey('key-1', $wallet));
        self::assertSame($keyed->id, $this->orders()->openCustomRenewal($this->ali, $service, 10, 7, RenewalMethod::Extend, new OrderKey('key-1', $wallet))->id, 'made again with its key');
        foreach (['another package' => fn() => $this->orders()->openCustomRenewal($this->ali, $service, 20, 7, RenewalMethod::Extend, new OrderKey('key-1', $wallet)), 'a plan' => fn() => $this->orders()->openRenewal($this->ali, $service, $plan, RenewalMethod::Extend, new OrderKey('key-1', $wallet))] as $case => $open) {
            try {
                $open();
                self::fail("{$case}: the key's order");
            } catch (RequestKeyReusedException) {
                self::addToAssertionCount(1);
            }
        }

        $keyed->forceFill(['status' => OrderStatus::Paid])->save();
        $this->expectException(RenewalUnderWayException::class);
        $this->orders()->openCustomRenewal($this->ali, $service, 60, 30, RenewalMethod::Extend);
    }

    public function testPaidItIsDeliveredOnTheServerPickedAsAServiceOfExactlyThatWithoutAPlan(): void
    {
        $this->telegram();
        $this->reportGroup();
        $this->customServicesOn([$this->germany, $this->france]);
        $this->inbound($this->france, '2', ['protocol' => 'trojan']);
        $this->service(WalletService::class)->credit($this->ali, '170000', 'test');

        $order = $this->orders()->openCustomPurchase($this->ali, 50, 30, $this->france);
        $this->service(PaymentService::class)->createForOrder($order, $this->walletMethod());

        $order->refresh();
        self::assertSame(OrderStatus::Fulfilled, $order->status, (string) $order->notes);
        $service = $order->subscription ?? self::fail('No service was made.');
        self::assertSame([null, $this->france->id, Traffic::bytesOfGb(50), 30 * 24, 2, false], [$service->plan_id, $service->server_id, $service->traffic_limit_bytes, $service->duration_hours, $service->ip_limit, $service->auto_renew]);
        $created = FakeProvider::$created[0];
        self::assertSame([$this->france->id, ['1', '2'], Traffic::bytesOfGb(50), 2, 30 * 86400], [$created['server'], $created['inbounds'], $created['spec']->totalBytes, $created['spec']->ipLimit, $created['spec']->expiry->pendingSeconds()], 'on every inbound the server sells, its term from the first connection');
        self::assertSame('0.00', $this->ali->balance());

        $report = ReportMessage::query()->where('topic', Topic::Purchases->value)->sole()->text;
        self::assertStringContainsString('📦 سرویس دلخواه · ۵۰ گیگ · ۳۰ روز', $report, 'the report group reads what was built');
        $payment = Payment::query()->where('order_id', $order->id)->sole();
        self::assertSame('سرویس دلخواه · ۵۰ گیگ · ۳۰ روز', $this->service(PaymentDirectory::class)->present($payment)['order']['plan'], 'and the panels');
        $order->load(OrderDirectory::RELATIONS);
        self::assertSame([null, 'سرویس دلخواه · ۵۰ گیگ · ۳۰ روز'], [$this->service(OrderDirectory::class)->present($order)['plan'], $this->service(OrderDirectory::class)->present($order)['package_name']], 'the orders screen');
    }

    private function custom(): CustomServices
    {
        return $this->service(CustomServices::class);
    }

    private function pricing(): Pricing
    {
        return $this->service(Pricing::class);
    }

    private function orders(): OrderService
    {
        return $this->service(OrderService::class);
    }
}
