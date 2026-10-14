<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Auth\Actor;
use App\Modules\Bots\CurrentBot;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Models\Website;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ClientNaming;
use App\Modules\Subscriptions\Services\ServiceTransfers;
use App\Modules\Users\Models\User;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * «سرویس دلخواه» on the website: what may be built (anyone may read it, `shop.custom_services` of `GET /` says whether
 * it is offered), the customer's price for an amount — with a code too —, and its checkout, a purchase without a plan
 * paid and delivered as the bot's; every refusal under its field, and a request made again answered by its order.
 */
final class StoreCustomServicesTest extends HttpTestCase
{
    private Website $website;
    private User $ali;
    private Server $berlin;
    private Server $paris;
    private PaymentMethod $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-13 12:00:00');
        $this->telegram();
        $this->withoutQr();
        $this->website = $this->website();
        $this->berlin = $this->sellingServer('Berlin', ['sort' => 1]);
        $this->paris = $this->sellingServer('Paris', ['sort' => 2]);
        $this->ali = $this->customer(['username' => 'ali']);
        $this->wallet = $this->walletMethod();
    }

    public function testAnyoneMayReadWhatMayBeBuiltAndTheShopSaysWhetherItIsOffered(): void
    {
        $off = $this->decode($this->get($this->storeApi($this->website, '/custom-services')))['custom_services'];
        self::assertSame([false, [], 2], [$off['offered'], $off['servers'], $off['devices']], 'off by default: nothing to build on');
        self::assertFalse($this->decode($this->get($this->storeApi($this->website)))['shop']['custom_services']);

        $this->customServicesOn([$this->berlin], ['custom_enabled' => false]);
        $priced = $this->decode($this->get($this->storeApi($this->website, '/custom-services')))['custom_services'];
        self::assertSame([false, [], [], [], []], [$priced['offered'], $priced['traffic']['tiers'], $priced['traffic']['options'], $priced['days']['tiers'], $priced['days']['options']], 'priced but switched off: no prices or buttons shown');

        $this->customServicesOn([$this->berlin, $this->paris]);
        self::assertSame([
            'offered' => true,
            'traffic' => ['tiers' => [['from' => 1, 'price' => '3000.00'], ['from' => 50, 'price' => '2500.00']], 'min' => 1, 'max' => 500, 'options' => [10, 50, 100]],
            'days' => ['tiers' => [['from' => 1, 'price' => '2000.00'], ['from' => 30, 'price' => '1500.00']], 'min' => 1, 'max' => 90, 'options' => [30, 60]],
            'devices' => 2,
            'servers' => [['id' => $this->berlin->id, 'name' => 'Berlin'], ['id' => $this->paris->id, 'name' => 'Paris']],
        ], $this->decode($this->get($this->storeApi($this->website, '/custom-services')))['custom_services']);
        self::assertTrue($this->decode($this->get($this->storeApi($this->website)))['shop']['custom_services']);
    }

    public function testAnAgentsWebsiteOffersNone(): void
    {
        $this->customServicesOn([$this->berlin]);
        $bot = $this->agentBot();
        $website = CurrentBot::run($bot, fn(): Website => $this->website());

        self::assertFalse($this->decode($this->get($this->storeApi($website, '/custom-services')))['custom_services']['offered']);
        self::assertFalse($this->decode($this->get($this->storeApi($website)))['shop']['custom_services']);
    }

    public function testTheQuoteIsTheCustomersPriceWithTheLinesAndACode(): void
    {
        $this->customServicesOn([$this->berlin, $this->paris]);
        $this->bearer($this->customerSession($this->ali));
        $this->ali->forceFill(['discount_percent' => 10])->save();
        $this->discountCode('PARIS', 50, ['server_ids' => [$this->paris->id]]);

        self::assertSame([
            'gb' => 50,
            'days' => 30,
            'devices' => 2,
            'server_id' => null,
            'traffic_price' => '125000.00',
            'days_price' => '45000.00',
            'base' => '170000.00',
            'discounts' => [['source' => 'personal', 'label' => 'تخفیف اختصاصی شما ۱۰٪', 'amount' => '17000.00', 'percent' => 10]],
            'amount' => '153000.00',
        ], $this->quote(['gb' => '۵۰', 'days' => 30]));

        $coded = $this->quote(['gb' => 50, 'days' => 30, 'server_id' => $this->paris->id, 'discount_code' => 'paris']);
        self::assertSame([$this->paris->id, '76500.00'], [$coded['server_id'], $coded['amount']], 'the code on what the automatic discount left');

        $refused = $this->postJson($this->storeApi($this->website, '/custom-services/quote'), ['gb' => 50, 'days' => 30, 'server_id' => $this->berlin->id, 'discount_code' => 'PARIS']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertArrayHasKey('discount_code', $this->decode($refused)['errors'], 'not on that server');
    }

    public function testAnAmountOutsideItsBoundsOrABuilderOffIsRefusedUnderItsField(): void
    {
        $this->bearer($this->customerSession($this->ali));
        self::assertSame(['status' => ['ساخت سرویس دلخواه الان در دسترس نیست.']], $this->refusal(['gb' => 50, 'days' => 30]), 'off');

        $this->customServicesOn([$this->berlin]);
        self::assertSame(['gb' => ['حجم را عددی بین ۱ تا ۵۰۰ گیگابایت بنویسید.']], $this->refusal(['gb' => 501, 'days' => 30]));
        self::assertSame(['days' => ['مدت را عددی بین ۱ تا ۹۰ روز بنویسید.']], $this->refusal(['gb' => 50, 'days' => 0]));
        self::assertSame(['gb' => ['تعداد گیگابایت را با عدد و بدون اعشار بنویسید.']], $this->refusal(['gb' => '1.5', 'days' => 30]));
        self::assertArrayHasKey('server_id', $this->refusal(['gb' => 50, 'days' => 30, 'server_id' => $this->paris->id]), 'not one of its servers');

        $this->bearer(null);
        self::assertSame(401, $this->unchecked()->postJson($this->storeApi($this->website, '/custom-services/quote'), ['gb' => 50, 'days' => 30])->getStatusCode(), 'the customer\'s own price');
    }

    public function testTheWalletPaysForABuiltServiceDeliveredWithoutAPlan(): void
    {
        $this->customServicesOn([$this->berlin, $this->paris]);
        $this->wallet($this->ali, '500000');
        $this->bearer($this->customerSession($this->ali));

        $response = $this->order(['gb' => 50, 'days' => 30, 'server_id' => $this->paris->id, 'method_id' => $this->wallet->id]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $checkout = $this->decode($response)['checkout'];
        $order = Order::query()->sole();
        self::assertSame(['settled', 'fulfilled', null, Traffic::bytesOfGb(50), 30, 2], [$checkout['outcome'], $checkout['order']['status'], $checkout['order']['plan'], $checkout['order']['traffic_bytes'], $checkout['order']['days'], $checkout['order']['devices']]);
        self::assertSame('سرویس دلخواه · ۵۰ گیگ · ۳۰ روز', $checkout['order']['package_name'], 'what it bought, in words');
        self::assertSame([OrderType::Purchase, OrderStatus::Fulfilled, null, $this->paris->id, '170000.00'], [$order->type, $order->status, $order->plan_id, $order->server_id, $order->amount]);
        $service = Subscription::query()->sole();
        self::assertSame([$order->subscription_id, null, $this->paris->id], [$checkout['subscription']['id'], $checkout['subscription']['plan'], $checkout['subscription']['server']['id']], 'the service it delivered, without a plan');
        self::assertSame([Traffic::bytesOfGb(50), 30 * 24, 2], [$service->traffic_limit_bytes, $service->duration_hours, $service->ip_limit]);
        self::assertSame('330000.00', $this->ali->balance());

        $again = $this->order(['gb' => 50, 'days' => 30, 'server_id' => $this->paris->id, 'method_id' => $this->wallet->id]);
        self::assertSame($order->id, $this->decode($again)['checkout']['order']['id'], 'made again with its key: its order');
        self::assertSame([1, '330000.00'], [Order::query()->count(), $this->ali->balance()], 'never a second charge');
        self::assertCount(1, FakeProvider::$created);

        $this->transfersOn();
        $this->service(ServiceTransfers::class)->transfer($service, $this->customer(['telegram_id' => 7070, 'username' => 'sara']), Actor::customer($this->ali));
        $handedOn = $this->order(['gb' => 50, 'days' => 30, 'server_id' => $this->paris->id, 'method_id' => $this->wallet->id]);
        self::assertSame(200, $handedOn->getStatusCode(), (string) $handedOn->getBody());
        self::assertSame([$order->id, null], [$this->decode($handedOn)['checkout']['order']['id'], $this->decode($handedOn)['checkout']['subscription']], 'made again once handed on: its order, the service no longer theirs');
    }

    public function testItsServiceIsNamedAsAPurchaseOfAPlanAsks(): void
    {
        $this->customServicesOn([$this->berlin]);
        $this->wallet($this->ali, '500000');
        $this->bearer($this->customerSession($this->ali));
        $refused = $this->order(['gb' => 10, 'days' => 7, 'method_id' => $this->wallet->id, 'name_mode' => 'custom', 'client_name' => 'my-vpn'], 'k-0');
        self::assertSame([422, ['name_mode']], [$refused->getStatusCode(), array_keys($this->decode($refused)['errors'])], 'a way the shop does not offer');

        $this->botSettings('client_names', ['name_random' => true, 'name_custom' => true]);
        $none = $this->order(['gb' => 10, 'days' => 7, 'method_id' => $this->wallet->id, 'name_mode' => 'custom', 'client_name' => 'my vpn'], 'k-1');
        $this->assertRefusedUnder('client_name', ClientNaming::NOT_A_NAME, $none);
        self::assertSame(0, Order::query()->count());

        $named = $this->order(['gb' => 10, 'days' => 7, 'method_id' => $this->wallet->id, 'name_mode' => 'custom', 'client_name' => 'my-vpn'], 'k-2');
        self::assertSame('my-vpn', $this->decode($named)['checkout']['subscription']['name'], (string) $named->getBody());
        self::assertSame('my-vpn', Order::query()->sole()->client_name);

        $card = $this->cardMethod();
        $drawn = $this->decode($this->order(['gb' => 20, 'days' => 7, 'method_id' => $card->id, 'name_mode' => 'random'], 'k-3'))['checkout']['order']['id'];
        $again = $this->order(['gb' => 20, 'days' => 7, 'method_id' => $card->id, 'name_mode' => 'random'], 'k-3');
        self::assertSame($drawn, $this->decode($again)['checkout']['order']['id'], 'a random name is its key\'s: made again, the same order');
        self::assertMatchesRegularExpression('/^[23456789a-z]{6}$/', (string) Order::query()->findOrFail($drawn)->client_name);
    }

    public function testTheServerMayBeLeftOutWhileThereIsOneAndMustBePickedAmongSeveral(): void
    {
        $this->wallet($this->ali, '500000');
        $this->bearer($this->customerSession($this->ali));

        $this->customServicesOn([$this->berlin, $this->paris]);
        $refused = $this->send('POST', $this->storeApi($this->website, '/custom-services/orders'), ['gb' => 10, 'days' => 7, 'method_id' => $this->wallet->id], ['Idempotency-Key' => 'k-1']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['server_id' => ['سرور را انتخاب کنید.']], $this->decode($refused)['errors']);

        $this->customServicesOn([$this->berlin]);
        $response = $this->order(['gb' => 10, 'days' => 7, 'method_id' => $this->wallet->id], 'k-2');
        self::assertSame('settled', $this->decode($response)['checkout']['outcome'], (string) $response->getBody());
        self::assertSame($this->berlin->id, Order::query()->sole()->server_id);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function quote(array $body): array
    {
        $response = $this->postJson($this->storeApi($this->website, '/custom-services/quote'), $body);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->decode($response)['quote'];
    }

    /**
     * The quote's refusal: a 422, by field.
     *
     * @param array<string, mixed> $body
     * @return array<string, list<string>>
     */
    private function refusal(array $body): array
    {
        $response = $this->postJson($this->storeApi($this->website, '/custom-services/quote'), $body);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());

        return $this->decode($response)['errors'];
    }

    /** @param array<string, mixed> $body */
    private function order(array $body, string $key = 'key-1'): ResponseInterface
    {
        return $this->send('POST', $this->storeApi($this->website, '/custom-services/orders'), $body, ['Idempotency-Key' => $key]);
    }
}
