<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Exceptions\RequestKeyReusedException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\RequestKey;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Models\Website;
use App\Modules\Subscriptions\Enums\ClientNameMode;
use App\Modules\Subscriptions\Services\ClientNaming;
use App\Modules\Users\Models\User;
use Psr\Http\Message\ResponseInterface;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * «نام کاربری سرویس» on the website: `GET /` says the ways a buyer may name their new service (`shop.name_modes`); a
 * purchase names it by the shop's pattern unless `name_mode` asks for a random name or `client_name` — each way only
 * while the shop offers it, a typed name refused under `client_name`. A random name is the key's: the request made
 * again with its key is answered by its order, never refused for a name drawn anew, and two sent with one key at once
 * ask the same.
 */
final class StoreClientNamesTest extends HttpTestCase
{
    private Website $website;
    private User $customer;
    private Server $server;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram();
        $this->withoutQr();
        $this->website = $this->website();
        $this->customer = $this->wallet($this->customer(['username' => 'ali']), '500000');
        $this->server = $this->sellingServer('Berlin');
        $this->plan = $this->plan([], $this->server);
        $this->bearer($this->customerSession($this->customer));
    }

    public function testTheShopSaysTheWaysABuyerMayNameTheirService(): void
    {
        $modes = fn(): array => $this->decode($this->get($this->storeApi($this->website)))['shop']['name_modes'];
        self::assertSame(['sequential'], $modes(), "the shop's pattern alone: ask nothing");

        $this->botSettings('client_names', ['name_random' => true, 'name_custom' => true]);
        self::assertSame(['sequential', 'random', 'custom'], $modes());
    }

    public function testAPurchaseIsNamedByThePatternUnlessItAsksOtherwise(): void
    {
        $this->botSettings('client_names', ['name_random' => true, 'name_custom' => true]);

        self::assertSame('ali_1', $this->bought($this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod())), "nothing asked: the shop's pattern");
        self::assertSame('my-vpn', $this->bought($this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod(), ['name_mode' => 'custom', 'client_name' => 'my-vpn'], 'key-2')));
        self::assertMatchesRegularExpression('/^[23456789a-z]{6}$/', $this->bought($this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod(), ['name_mode' => 'random'], 'key-3')));
        self::assertSame('ali_2', $this->bought($this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod(), ['name_mode' => 'sequential', 'client_name' => 'ignored'], 'key-4')));
        self::assertSame([null, 'my-vpn'], Order::query()->orderBy('id')->limit(2)->pluck('client_name')->all());
    }

    public function testAWayTheShopDoesNotOfferAndANameThatIsNoneAreRefused(): void
    {
        $this->assertRefusedUnder('name_mode', 'نام رندوم برای سرویس در این فروشگاه فعال نیست.', $this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod(), ['name_mode' => 'random']));
        $this->assertRefusedUnder('name_mode', sprintf(ClientNaming::NOT_OFFERED, ClientNameMode::Custom->label()), $this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod(), ['name_mode' => 'custom', 'client_name' => 'my-vpn']));
        $unknown = $this->unchecked()->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod(), ['name_mode' => 'fancy']);
        self::assertSame(422, $unknown->getStatusCode());
        self::assertArrayHasKey('name_mode', $this->decode($unknown)['errors']);

        $this->botSettings('client_names', ['name_custom' => true]);
        $this->subscription($this->customer, $this->plan, $this->server, 'taken');
        $this->assertRefusedUnder('client_name', ClientNaming::NOT_A_NAME, $this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod(), ['name_mode' => 'custom', 'client_name' => 'my vpn']));
        $this->assertRefusedUnder('client_name', ClientNaming::NOT_A_NAME, $this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod(), ['name_mode' => 'custom']), message: 'none typed');
        $this->assertRefusedUnder('client_name', ClientNaming::TAKEN, $this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod(), ['name_mode' => 'custom', 'client_name' => 'taken']));
        self::assertSame(0, Order::query()->count(), 'nothing ordered');
    }

    public function testARandomNameIsDrawnOnceForItsKey(): void
    {
        $this->botSettings('client_names', ['name_random' => true, 'name_custom' => true]);
        $card = $this->cardMethod();

        $first = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $card, ['name_mode' => 'random']))['checkout'];
        $again = $this->placeOrder($this->website, $this->plan, $this->server, $card, ['name_mode' => 'random']);

        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame([$first['order']['id'], $first['transfer']], [$this->decode($again)['checkout']['order']['id'], $this->decode($again)['checkout']['transfer']], 'the same order, awaiting the same transfer');
        self::assertSame(1, Order::query()->count());

        $other = $this->placeOrder($this->website, $this->plan, $this->server, $card, ['name_mode' => 'custom', 'client_name' => 'another']);
        self::assertSame([422, [RequestKeyReusedException::MESSAGE]], [$other->getStatusCode(), $this->decode($other)['errors']['idempotency_key'] ?? null], 'the key asked for another name');
    }

    public function testARandomNameIsTheKeysSoTwoRequestsSentWithItAtOnceAskTheSame(): void
    {
        $this->botSettings('client_names', ['name_random' => true]);
        $card = $this->cardMethod();

        $first = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $card, ['name_mode' => 'random']))['checkout'];
        // The second judged before the first kept its key, as when both are sent at the same moment.
        RequestKey::query()->delete();
        $again = $this->placeOrder($this->website, $this->plan, $this->server, $card, ['name_mode' => 'random']);

        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame($first['order']['id'], $this->decode($again)['checkout']['order']['id'], 'the same name asked: the same order');
        self::assertSame(1, Order::query()->count());
    }

    public function testATypedNameMadeAgainWithItsKeyIsNotRefusedForWhatTookItSince(): void
    {
        $this->botSettings('client_names', ['name_custom' => true]);
        $card = $this->cardMethod();
        $name = ['name_mode' => 'custom', 'client_name' => 'my-vpn'];

        $first = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $card, $name))['checkout'];
        $this->subscription($this->customer(['username' => 'other', 'telegram_id' => 7001]), $this->plan, $this->server, 'my-vpn');
        $again = $this->placeOrder($this->website, $this->plan, $this->server, $card, $name);

        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame($first['order']['id'], $this->decode($again)['checkout']['order']['id'], 'its open order, which the pattern names should it be delivered now');
    }

    public function testATypedNameMadeAgainWithItsKeyIsItsOrderOnceDelivered(): void
    {
        $this->botSettings('client_names', ['name_custom' => true]);
        $name = ['name_mode' => 'custom', 'client_name' => 'my-vpn'];

        $first = $this->decode($this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod(), $name))['checkout'];
        $again = $this->placeOrder($this->website, $this->plan, $this->server, $this->walletMethod(), $name);

        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame($first, $this->decode($again)['checkout'], 'answered by its order, though its service now carries the name');
        self::assertCount(1, FakeProvider::$created);
    }

    /** The name of the service a settled purchase delivered. */
    private function bought(ResponseInterface $response): string
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->decode($response)['checkout']['subscription']['name'];
    }
}
