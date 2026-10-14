<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Exceptions\NoServerAvailableException;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\DTO\Capabilities;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\ClientNameRule;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\DTO\ClientNamePattern;
use App\Modules\Subscriptions\Enums\ClientNameMode;
use App\Modules\Subscriptions\Services\ClientNameSettings;
use App\Modules\Subscriptions\Services\ClientNaming;
use App\Modules\Subscriptions\Services\TestAccounts;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletService;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;

/**
 * A client's name by the shop's pattern («نام کاربری سرویس»): the defaults name as every shop named before
 * ("<username>_<n>", "USER_<n>", "test_…", their counters going on where they were); each variable as it says, worked
 * out only when the pattern holds it — a counter moves only for a pattern that uses it —, a test's by its own pattern,
 * a name taken on the server skipped, never longer than a panel takes, and a bound on the tries. A buyer's own name
 * (requested()): the pattern, a random one (the same for one seed), or one typed, each way only while the shop offers
 * it; a purchase's service gets it while it is free in the shop and on the panel — even when another delivery takes it
 * at the same moment —, else the pattern's. A name the panel refuses for a client made there by hand — a test's too —
 * gives way to the pattern's next, ten names at most.
 */
final class ClientNamingTest extends DatabaseTestCase
{
    private User $customer;
    private Plan $plan;
    private Server $berlin;
    private Server $paris;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->customer(['username' => 'customer', 'telegram_id' => 42]);
        $this->berlin = $this->sellingServer('Berlin');
        $this->paris = $this->sellingServer('Paris');
        $this->plan = $this->plan(['price' => '50000.00'], [$this->berlin, $this->paris]);
    }

    public function testTheDefaultsNameAsBeforeAndTheCountersGoOnWhereTheyWere(): void
    {
        $settings = $this->service(ClientNameSettings::class);
        self::assertSame(['{username}_{seq}', 'test_{username}_{seq}', [ClientNameMode::Sequential]], [$settings->pattern()->pattern, $settings->testPattern()->pattern, $settings->modes()]);
        $nobody = $this->customer(['telegram_id' => 7001, 'username' => null]);
        $this->db()->table('sequences')->insert([['name' => "clients.user.{$this->customer->id}", 'value' => 6], ['name' => 'clients.anonymous', 'value' => 40]]);

        self::assertSame(
            ['customer_7', 'customer_8', 'USER_41', 'test_customer_9', 'test_USER_42'],
            [$this->next($this->customer), $this->next($this->customer), $this->next($nobody), $this->next($this->customer, test: true), $this->next($nobody, test: true)],
            "the customer's own counter, the one shared by every customer without a username, a test's with test_ before it",
        );
        self::assertSame([], array_diff_key($this->counters(), ["clients.user.{$this->customer->id}" => 0, 'clients.anonymous' => 0]), 'no other counter drawn');
    }

    public function testEachVariableIsWhatItSays(): void
    {
        Carbon::setTestNow('2026-10-13 21:00:00');
        LocalTime::use('Asia/Tehran');
        try {
            $web = $this->webCustomer();
            $this->names('{username}.{seq}.{tg_id}.{user_id}');
            self::assertSame("customer.1.42.{$this->customer->id}", $this->next($this->customer));
            self::assertSame("USER.1.w{$web->id}.{$web->id}", $this->next($web), 'without a username nor Telegram');

            $this->names('{n}-{random}-{date}');
            self::assertMatchesRegularExpression('/^1-[23456789abcdefghjkmnpqrstuvwxyz]{6}-261014$/', $this->next($this->customer), "the shop's counter, six letters and digits, the shop's date (half past midnight in Tehran)");
            self::assertStringStartsWith('2-', $this->next($web), "{n} is the shop's: every customer's");
        } finally {
            LocalTime::use('UTC');
        }
    }

    public function testATestIsNamedByTheTestPattern(): void
    {
        $this->names('vpn-{username}-{seq}', 'trial-{tg_id}-{n}');

        self::assertSame(['vpn-customer-1', 'trial-42-1', 'vpn-customer-2'], [$this->next($this->customer), $this->next($this->customer, test: true), $this->next($this->customer)]);
    }

    public function testACounterMovesOnlyWhenThePatternHoldsIt(): void
    {
        $this->names('{username}_{n}');
        self::assertSame(['customer_1', 'customer_2'], [$this->next($this->customer), $this->next($this->customer)]);

        $this->names('{username}-{random}');
        $this->next($this->customer);
        self::assertSame(["clients.shop.{$this->customer->shop()->id}" => 2], $this->counters(), 'nothing drew {seq}, and a random name draws no counter');

        $this->names('{username}_{seq}');
        self::assertSame('customer_1', $this->next($this->customer), "the customer's own counter starts where it was");
    }

    public function testANameTakenOnTheServerIsSkipped(): void
    {
        $this->names('vip{n}');
        $this->subscription($this->customer, $this->plan, $this->berlin, 'vip1');

        self::assertSame('vip2', $this->next($this->customer));
        self::assertSame('vip3', $this->naming()->next($this->customer, $this->paris), 'the next number on another server');
    }

    public function testNoNameIsLongerThanAPanelTakes(): void
    {
        $long = $this->customer(['telegram_id' => 7002, 'username' => str_repeat('a', 32)]);
        $this->names('{username}-{username}-{seq}');

        $name = $this->next($long);

        self::assertSame(str_repeat('a', 30) . '-' . str_repeat('a', 30) . '-1', $name, 'the username gives way, never the number');
        self::assertLessThanOrEqual(ClientNamePattern::NAME_MAX, strlen($name));
    }

    public function testAPatternWhoseNamesAreAllTakenGivesUp(): void
    {
        $this->names('n{n}');
        for ($i = 1; $i <= 100; $i++) {
            $this->subscription($this->customer, $this->plan, $this->berlin, "n{$i}");
        }

        $this->expectException(NoServerAvailableException::class);
        $this->next($this->customer);
    }

    public function testABuyersOwnNameIsTheirsOnlyWhileTheShopOffersItsWay(): void
    {
        $naming = $this->naming();
        self::assertNull($naming->requested(ClientNameMode::Sequential, 'ignored', $this->berlin), "the shop's pattern");
        foreach ([ClientNameMode::Random, ClientNameMode::Custom] as $mode) {
            self::assertSame(['name_mode' => [sprintf(ClientNaming::NOT_OFFERED, $mode->label())]], $this->refusedWith(fn() => $naming->requested($mode, 'my-vpn', $this->berlin)));
        }

        $this->botSettings('client_names', ['name_random' => true, 'name_custom' => true]);
        self::assertMatchesRegularExpression('/^[23456789abcdefghjkmnpqrstuvwxyz]{6}$/', (string) $naming->requested(ClientNameMode::Random, null, $this->berlin));
        self::assertSame('My-VPN_1', $naming->requested(ClientNameMode::Custom, '  My-VPN_1 ', $this->berlin));
    }

    public function testARandomNameWorkedOutFromASeedIsTheSameForIt(): void
    {
        $this->botSettings('client_names', ['name_random' => true]);
        $naming = $this->naming();
        $first = (string) $naming->requested(ClientNameMode::Random, null, $this->berlin, '7|key-1');

        self::assertMatchesRegularExpression('/^[23456789abcdefghjkmnpqrstuvwxyz]{6}$/', $first);
        self::assertSame($first, $naming->requested(ClientNameMode::Random, null, $this->berlin, '7|key-1'), 'one seed, one name: two requests sent with one key at once ask the same');
        self::assertNotSame($first, $naming->requested(ClientNameMode::Random, null, $this->berlin, '7|key-2'));

        $this->subscription($this->customer, $this->plan, $this->berlin, $first);
        $next = (string) $naming->requested(ClientNameMode::Random, null, $this->berlin, '7|key-1');
        self::assertNotSame($first, $next, "taken there: the seed's next");
        self::assertSame($next, $naming->requested(ClientNameMode::Random, null, $this->berlin, '7|key-1'));
    }

    public function testATypedNameIsRefusedForItsCharactersItsLengthOrWhenTakenThere(): void
    {
        $this->botSettings('client_names', ['name_custom' => true]);
        $this->subscription($this->customer, $this->plan, $this->berlin, 'taken');
        $naming = $this->naming();

        foreach (['', 'ab', str_repeat('a', 33), 'my vpn', 'سرویس', 'vpn@home', 'a.b.c'] as $typed) {
            self::assertSame(['client_name' => [ClientNaming::NOT_A_NAME]], $this->refusedWith(fn() => $naming->requested(ClientNameMode::Custom, $typed, $this->berlin)), $typed);
        }
        self::assertSame(['client_name' => [ClientNaming::TAKEN]], $this->refusedWith(fn() => $naming->requested(ClientNameMode::Custom, 'taken', $this->berlin)));
        self::assertSame('taken', $naming->requested(ClientNameMode::Custom, 'taken', $this->paris), 'free on another server');
        self::assertSame(str_repeat('z', 32), $naming->requested(ClientNameMode::Custom, str_repeat('z', 32), $this->berlin));
    }

    public function testAPurchaseGetsTheNameItsBuyerAskedFor(): void
    {
        $asked = [];
        FakeProvider::$onCall = static function (int $server, string $call) use (&$asked): void {
            if ($call === 'findClient') {
                $asked[] = $server;
            }
        };

        $this->buyNamed(null);
        self::assertSame([], $asked, 'no name asked for: the panel is not asked either');

        $this->buyNamed('my-vpn');
        self::assertSame(['customer_1', 'my-vpn'], $this->created());
        self::assertSame([$this->berlin->id], $asked, 'the panel asked once, right before creating');
        self::assertSame('my-vpn', Order::query()->latest('id')->firstOrFail()->subscription?->remote_name);
    }

    public function testANameTakenSinceInTheShopOrOnThePanelGivesWayToThePattern(): void
    {
        $this->buyNamed('my-vpn');
        $this->buyNamed('my-vpn');

        FakeProvider::put($this->berlin, new ClientInfo(name: 'by-hand', enabled: true, totalBytes: 0, expiry: Expiry::never(), subscriptionUrl: null));
        $this->buyNamed('by-hand');

        self::assertSame(['my-vpn', 'customer_1', 'customer_2'], $this->created(), "the shop's service has it, then a client made by hand on the panel");
    }

    public function testOfTwoOrdersAskingOneNameAtOnceThePanelRefusesTheSecondsWhichTakesThePattern(): void
    {
        // The other order's client is made between this delivery's look at the panel and its own: the panel refuses its
        // name, now a client's there.
        FakeProvider::$onCall = function (int $server, string $call): void {
            if ($call === 'createClient' && !isset(FakeProvider::$clients[$server]['my-vpn'])) {
                FakeProvider::put($this->berlin, new ClientInfo(name: 'my-vpn', enabled: true, totalBytes: 0, expiry: Expiry::never(), subscriptionUrl: null));
            }
        };

        $this->buyNamed('my-vpn');

        self::assertSame(['customer_1'], $this->created());
        self::assertSame('customer_1', Order::query()->sole()->subscription?->remote_name);
        self::assertSame(["clients.user.{$this->customer->id}" => 1], $this->counters(), 'the pattern drawn once');
    }

    public function testAPatternsNameAClientMadeByHandHasOnThePanelGivesWayToTheNext(): void
    {
        // Made on the panel by hand with the names the pattern gives next: the shop keeps no service by them.
        foreach (['customer_1', 'customer_2', 'test_customer_4'] as $name) {
            FakeProvider::put($this->berlin, new ClientInfo(name: $name, enabled: true, totalBytes: 0, expiry: Expiry::never(), subscriptionUrl: null));
        }
        $asked = [];
        FakeProvider::$onCall = static function (int $server, string $call) use (&$asked): void {
            $asked[] = $call;
        };

        $this->buyNamed(null);

        self::assertSame(['customer_3'], $this->created());
        self::assertSame('customer_3', Order::query()->sole()->subscription?->remote_name);
        self::assertSame(["clients.user.{$this->customer->id}" => 3], $this->counters(), 'each number drawn once');
        self::assertSame(2, count(array_keys($asked, 'findClient', true)), 'the panel asked about a name only once it refused it');

        $this->botSettings('test_accounts', ['test_enabled' => true, 'test_per_user' => 1, 'test_period_days' => 0, 'test_daily_limit' => 0, 'test_newcomers_only' => false, 'test_cleanup' => true, 'test_cleanup_hours' => 24, 'test_unused_days' => 7]);
        $this->berlin->forceFill(['test_enabled' => true, 'test_on_hold' => false, 'test_duration_hours' => 2])->save();
        $test = $this->service(TestAccounts::class)->issue($this->customer, $this->berlin->id);
        self::assertSame('test_customer_5', $test->remote_name, "a test's name too");
    }

    public function testAfterTenNamesThePanelHasTheDeliveryFails(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            FakeProvider::put($this->berlin, new ClientInfo(name: "customer_{$i}", enabled: true, totalBytes: 0, expiry: Expiry::never(), subscriptionUrl: null));
        }
        $this->service(WalletService::class)->credit($this->customer, '50000', 'test');
        $order = $this->service(OrderService::class)->openPurchase($this->customer, $this->plan, $this->berlin);
        $this->service(PaymentService::class)->createForOrder($order, $this->walletMethod());

        self::assertSame(OrderStatus::Failed, $order->refresh()->status, 'for support: the pattern meets the clients made by hand');
        self::assertSame(["clients.user.{$this->customer->id}" => 10], $this->counters());
        self::assertSame([], $this->created());
    }

    public function testAPanelRefusingTheNameAskedForAnotherReasonFailsTheDelivery(): void
    {
        FakeProvider::$refusing[$this->berlin->id] = ['createClient'];
        $this->service(WalletService::class)->credit($this->customer, '50000', 'test');
        $order = $this->service(OrderService::class)->openPurchase($this->customer, $this->plan, $this->berlin, clientName: 'my-vpn');
        $this->service(PaymentService::class)->createForOrder($order, $this->walletMethod());

        self::assertSame(OrderStatus::Failed, $order->refresh()->status);
        self::assertSame([], $this->counters(), 'the pattern not tried: no client has the name on the panel');
    }

    public function testEveryNameTheShopMakesIsOneItsPanelTakes(): void
    {
        // A panel like PasarGuard: 3 to 128 characters, never two separators side by side; the fake refuses any other.
        FakeProvider::$capabilities = new Capabilities(inbounds: true, names: new ClientNameRule(min: 3, max: 128, characters: 'A-Za-z0-9_.@-', separatorRuns: false));
        $this->botSettings('client_names', ['name_random' => true, 'name_custom' => true]);

        $this->names('{n}');
        self::assertSame('001', $this->next($this->customer), 'too short: padded');
        $this->names('vpn__{n}');
        self::assertSame('vpn_2', $this->next($this->customer), 'a run cut to its first');
        self::assertMatchesRegularExpression('/^[a-z0-9]{6}$/', (string) $this->naming()->requested(ClientNameMode::Random, null, $this->berlin));

        $this->buyNamed('my__vpn');
        self::assertSame(['vpn_3'], $this->created(), 'a name asked for elsewhere, which this panel would refuse, gives way to the pattern');
    }

    public function testATypedNameThePanelWouldRefuseIsRefusedAtTheCheckoutWithWhy(): void
    {
        FakeProvider::$capabilities = new Capabilities(inbounds: true, names: new ClientNameRule(min: 5, max: 6, characters: 'a-z0-9_-', separatorRuns: false));
        $this->botSettings('client_names', ['name_custom' => true]);
        $naming = $this->naming();

        self::assertSame(['client_name' => [ClientNaming::PANEL_RUNS]], $this->refusedWith(fn() => $naming->requested(ClientNameMode::Custom, 'my_-vp', $this->berlin)));
        self::assertSame(['client_name' => [sprintf(ClientNaming::PANEL_LENGTH, '۵', '۶')]], $this->refusedWith(fn() => $naming->requested(ClientNameMode::Custom, 'vpn', $this->berlin)), 'in Persian digits, as every customer text');
        self::assertSame(['client_name' => [ClientNaming::PANEL_REFUSES]], $this->refusedWith(fn() => $naming->requested(ClientNameMode::Custom, 'MyVpn', $this->berlin)));
        self::assertSame('my-vpn', $naming->requested(ClientNameMode::Custom, 'my-vpn', $this->berlin));
    }

    public function testAServiceKeepsItsNameOnTheServerItMovesToOnlyWhereThatPanelTakesIt(): void
    {
        $pasarGuard = $this->fakeServer('PasarGuard', ['driver' => 'pasarguard']);
        $claimed = $this->subscription($this->customer, $this->plan, $this->berlin, 'Ali Reza');
        $own = $this->subscription($this->customer, $this->plan, $this->berlin, 'ali_9');

        self::assertSame(['customer_1', 'ali_9'], [$this->naming()->nameOn($claimed, $pasarGuard), $this->naming()->nameOn($own, $pasarGuard)]);
        self::assertSame('Ali Reza', $this->naming()->nameOn($claimed, $this->paris), 'a panel that takes any name keeps it');
    }

    /** The customer's next name on Berlin. */
    private function next(User $user, bool $test = false): string
    {
        return $this->naming()->next($user, $this->berlin, $test);
    }

    /** The shop's patterns, saved as the bot settings screen saves them. */
    private function names(string $pattern, string $test = ClientNamePattern::TEST_DEFAULT): void
    {
        $this->botSettings('client_names', ['name_pattern' => $pattern, 'test_name_pattern' => $test]);
    }

    /** A wallet-paid purchase on Berlin with the name its buyer asked for, delivered. */
    private function buyNamed(?string $name): void
    {
        $this->service(WalletService::class)->credit($this->customer, '50000', 'test');
        $order = $this->service(OrderService::class)->openPurchase($this->customer, $this->plan, $this->berlin, clientName: $name);
        self::assertSame($name, $order->client_name);
        $this->service(PaymentService::class)->createForOrder($order, $this->walletMethod());

        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status, (string) $order->notes);
    }

    /** @return list<string> The clients made, in order. */
    private function created(): array
    {
        return array_map(static fn(array $created): string => $created['spec']->name, FakeProvider::$created);
    }

    /** @return array<string, int> The counters drawn, by name. */
    private function counters(): array
    {
        return array_map(intval(...), $this->db()->table('sequences')->where('value', '>', 0)->pluck('value', 'name')->all());
    }

    private function naming(): ClientNaming
    {
        return $this->service(ClientNaming::class);
    }
}
