<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounts\Http\RecentSignInMiddleware;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Agency\Exceptions\TrafficShortException;
use App\Modules\Agency\Services\AgencyStanding;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Exceptions\SellingSuspendedException;
use App\Modules\Bots\Models\Bot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Services\ServerSelector;
use App\Modules\Discounts\Services\Loyalty;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\RequestKeyReusedException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\ManualOrders;
use App\Modules\Payments\Drivers\Outside\OutsideGateway;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Providers\Models\Server;
use App\Modules\Referrals\Models\ReferralCommission;
use App\Modules\Rewards\Models\Cashback;
use App\Modules\Store\Enums\StaffGrant;
use App\Modules\Store\Http\IdempotencyKey;
use App\Modules\Store\Http\StaffMiddleware;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ClientNaming;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Users\Models\User;
use App\Support\Money;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * «افزودن سفارش» on a customer's page (POST /users/{id}/orders): support makes an order for its customer by hand — a
 * plan, or a service of its own numbers — paid at once as support picks (free, from the wallet, or outside the shop) and
 * delivered, the customer told and the report group's «خریدها» saying who made it. Each way of paying counts as it says:
 * a gift no purchase, the wallet's a purchase without takings (whatever the wallet's least and most amount, which hold a
 * customer's own checkout), an outside payment the shop's takings. Its key answers a
 * retry with the same order; an agent makes one in their shop out of their traffic (given back when the delivery fails),
 * never while its selling is suspended — the owner neither — nor on what the owner keeps from it; the website's admins
 * need the grant, a recent sign-in, and never make one for themselves. The report group never reads the owner's login.
 */
final class ManualOrdersApiTest extends HttpTestCase
{
    private User $ali;
    private Server $server;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-14 12:00:00');
        $this->telegram();
        $this->withoutQr();
        $this->server = $this->sellingServer();
        $this->plan = $this->plan([], $this->server);
        $this->ali = $this->customer(['username' => 'ali_r']);
    }

    public function testAFreeOrderIsAGiftDeliveredAtOnceAndToldToTheCustomer(): void
    {
        $this->loginAsAdmin();
        $this->reportGroup();

        $response = $this->order($this->ali, ['plan_id' => $this->plan->id, 'server_id' => $this->server->id, 'payment' => 'free', 'note' => 'جبران قطعی']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        ['order' => $order, 'subscription' => $service] = $this->decode($response);
        self::assertSame(['fulfilled', '0.00', 'gift'], [$order['status'], $order['amount'], $order['pricing']]);
        self::assertSame(['by' => 'root', 'payment' => 'free', 'note' => 'جبران قطعی'], $order['manual']);
        self::assertSame([$this->plan->id, 'ali_r_1', 30 * Traffic::GIGABYTE], [$service['plan']['id'], $service['name'], $service['traffic']['limit']]);
        self::assertSame('0.00', $this->ali->balance(), 'nothing taken');
        self::assertFalse(Order::boughtBy($this->ali->id)->exists(), 'a gift is no purchase of theirs');
        self::assertSame(0.0, (float) Payment::moneyIn()->sum('amount'), 'no takings');
        self::assertCount(1, $this->telegram()->sentTo(self::TELEGRAM_ID), 'the customer is told, as of any delivered purchase');
        self::assertStringContainsString("🎧 ثبت دستی توسط پشتیبانی · رایگان\n📝 توضیح پشتیبانی: جبران قطعی", $this->purchaseReport());
        self::assertStringNotContainsString('root', $this->purchaseReport(), 'the group never reads the owner\'s login');
    }

    public function testAWalletOrderOfItsOwnNumbersChargesTheWalletAndIsAPurchaseWithoutTakings(): void
    {
        $this->loginAsAdmin();
        $sara = $this->wallet($this->referred(), '200000');

        $response = $this->order($sara, ['traffic_gb' => '۵۰', 'days' => 30, 'devices' => 2, 'server_id' => $this->server->id, 'client_name' => 'sara_vip', 'payment' => 'wallet', 'amount' => '150,000']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        ['order' => $order, 'subscription' => $service] = $this->decode($response);
        self::assertSame(['fulfilled', '150000.00', 'regular', null, 'سرویس دلخواه · ۵۰ گیگ · ۳۰ روز'], [$order['status'], $order['amount'], $order['pricing'], $order['plan'], $order['package_name']]);
        self::assertSame(['by' => 'root', 'payment' => 'wallet', 'note' => null], $order['manual']);
        $row = Subscription::query()->findOrFail($service['id']);
        self::assertSame(['sara_vip', null, 50 * Traffic::GIGABYTE, 720, 2], [$row->remote_name, $row->plan_id, $row->traffic_limit_bytes, $row->duration_hours, $row->ip_limit]);
        self::assertSame('50000.00', $sara->balance());
        self::assertTrue(Order::boughtBy($sara->id)->exists(), 'a purchase of theirs');
        self::assertSame(0.0, (float) Payment::moneyIn()->sum('amount'), 'the wallet\'s money counted when it came in');
        self::assertFalse(ReferralCommission::query()->exists(), 'nor does it earn a commission');
    }

    public function testAnOutsidePaymentIsTheShopsTakingsAndEarnsTheReferrer(): void
    {
        $this->loginAsAdmin();
        $sara = $this->referred();

        $response = $this->order($sara, ['plan_id' => $this->plan->id, 'server_id' => $this->server->id, 'payment' => 'outside', 'amount' => 100000, 'note' => 'نقدی در دفتر']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        ['order' => $order] = $this->decode($response);
        self::assertSame(['fulfilled', '100000.00', 'outside', OutsideGateway::label(), 'root'], [$order['status'], $order['amount'], $order['payments'][0]['gateway'], $order['payments'][0]['method'], Payment::query()->value('reviewer')]);
        self::assertSame(['by' => 'root', 'payment' => 'outside', 'note' => 'نقدی در دفتر'], $order['manual']);
        self::assertSame('0.00', $sara->balance(), 'the wallet untouched');
        self::assertSame(100000.0, (float) Payment::moneyIn()->sum('amount'), 'counted in the takings');
        self::assertSame('10000.00', ReferralCommission::query()->value('commission'), 'money in earns the referrer');
        self::assertTrue(Order::boughtBy($sara->id)->exists());
    }

    public function testCashbackAndLoyaltyCountWhatWasPaidNeverAGift(): void
    {
        $this->loginAsAdmin();
        $this->cashbackOn();
        $this->cashbackRule(10);
        $this->wallet($this->ali, '100000');
        $base = ['plan_id' => $this->plan->id, 'server_id' => $this->server->id];

        foreach ([['payment' => 'free'], ['payment' => 'wallet', 'amount' => 100000], ['payment' => 'outside', 'amount' => 50000]] as $paid) {
            self::assertSame(201, $this->order($this->ali, $base + $paid)->getStatusCode());
        }

        self::assertSame(['10000.00', '5000.00'], Cashback::query()->orderBy('id')->pluck('amount')->all(), 'the wallet\'s and the outside one\'s');
        self::assertSame('15000.00', $this->ali->balance(), 'the wallet spent, then given the cashback');
        self::assertSame('150000.00', $this->service(Loyalty::class)->spent($this->ali), 'spent towards a tier: what was paid');
    }

    public function testServicesOfNoLimitAreMadeWithEveryNumberAtZero(): void
    {
        $this->loginAsAdmin();

        $response = $this->order($this->ali, ['traffic_gb' => 0, 'days' => 0, 'devices' => 0, 'server_id' => $this->server->id, 'payment' => 'free']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        ['order' => $order, 'subscription' => $service] = $this->decode($response);
        self::assertSame('سرویس دلخواه · حجم نامحدود · بدون پایان', $order['package_name']);
        $row = Subscription::query()->findOrFail($service['id']);
        self::assertSame([0, 0, 0], [$row->traffic_limit_bytes, $row->duration_hours, $row->ip_limit]);
    }

    public function testAShortWalletIsRefusedAndNothingIsMade(): void
    {
        $this->loginAsAdmin();
        $this->wallet($this->ali, '10000');

        $this->assertRefusedUnder('amount', sprintf(ManualOrders::WALLET_SHORT, Money::format('10000'), Money::format('140000')), $this->order($this->ali, ['plan_id' => $this->plan->id, 'server_id' => $this->server->id, 'payment' => 'wallet', 'amount' => 150000]));

        self::assertFalse(Order::query()->exists());
        self::assertSame('10000.00', $this->ali->balance());
        self::assertSame([], FakeProvider::$created);
    }

    public function testTheWalletsLeastAndMostHoldTheCustomersCheckoutNotSupportsOrder(): void
    {
        $this->loginAsAdmin();
        $this->wallet($this->ali, '500000');
        $this->walletMethod()->forceFill(['min_amount' => '200000', 'max_amount' => '300000'])->save();

        foreach ([100000, 400000] as $amount) {
            $response = $this->order($this->ali, ['plan_id' => $this->plan->id, 'server_id' => $this->server->id, 'payment' => 'wallet', 'amount' => $amount]);
            self::assertSame(201, $response->getStatusCode(), "{$amount}: " . $response->getBody());
        }

        self::assertSame('0.00', $this->ali->balance(), 'below its least and above its most, both from the wallet: support\'s order is deliberate');
    }

    public function testEachRefusalIsUnderItsField(): void
    {
        $this->loginAsAdmin();
        $elsewhere = $this->sellingServer('هلند');
        $this->subscription($this->ali, $this->plan, $this->server, 'taken_1');

        $form = $this->decode($this->order($this->ali, ['traffic_gb' => '10001', 'days' => 'سی', 'server_id' => 999999, 'payment' => 'wallet']))['errors'];
        self::assertSame(['amount', 'traffic_gb', 'days', 'devices', 'server_id'], array_keys($form), 'every refusal of the form at once');
        self::assertSame([sprintf(ManualOrders::NOT_GB, 10000)], $form['traffic_gb']);
        $this->assertRefusedUnder('payment', ManualOrders::NO_PAYMENT, $this->unchecked()->order($this->ali, ['plan_id' => $this->plan->id, 'server_id' => $this->server->id, 'payment' => 'card']));
        $this->assertRefusedUnder('plan_id', ManualOrders::NO_PLAN, $this->order($this->ali, ['plan_id' => 999999, 'server_id' => $this->server->id, 'payment' => 'free']));
        $this->assertRefusedUnder('server_id', "سرور #{$elsewhere->id} برای پلن «{$this->plan->name}» در دسترس نیست.", $this->order($this->ali, ['plan_id' => $this->plan->id, 'server_id' => $elsewhere->id, 'payment' => 'free']));
        $this->assertRefusedUnder('client_name', ClientNaming::NOT_A_NAME, $this->order($this->ali, ['plan_id' => $this->plan->id, 'server_id' => $this->server->id, 'client_name' => 'ab', 'payment' => 'free']));
        $this->assertRefusedUnder('client_name', ClientNaming::TAKEN, $this->order($this->ali, ['plan_id' => $this->plan->id, 'server_id' => $this->server->id, 'client_name' => 'taken_1', 'payment' => 'free']));
        $this->assertRefusedUnder('idempotency_key', IdempotencyKey::MISSING, $this->unchecked()->json('POST', "/api/admin/users/{$this->ali->id}/orders", ['plan_id' => $this->plan->id, 'server_id' => $this->server->id, 'payment' => 'free']));
        self::assertSame(404, $this->send('POST', '/api/admin/users/999999/orders', ['plan_id' => $this->plan->id, 'server_id' => $this->server->id, 'payment' => 'free'], ['X-Requested-With' => 'XMLHttpRequest', 'Idempotency-Key' => 'key-404'])->getStatusCode());
        $this->wallet($this->ali, '500000');
        $this->walletMethod()->forceFill(['enabled' => false])->save();
        $this->assertRefusedUnder('payment', ManualOrders::WALLET_OFF, $this->order($this->ali, ['plan_id' => $this->plan->id, 'server_id' => $this->server->id, 'payment' => 'wallet', 'amount' => 1000]), message: 'no balance is spent while the wallet is off');
        self::assertFalse(Order::query()->exists(), 'nothing made');

        $gift = $this->order($this->ali, ['plan_id' => $this->plan->id, 'server_id' => $this->server->id, 'payment' => 'free']);
        self::assertSame([201, 'fulfilled'], [$gift->getStatusCode(), $this->decode($gift)['order']['status']], 'a gift takes nothing of the wallet, on or off');
        self::assertSame('500000.00', $this->ali->balance());
    }

    public function testARetryWithItsKeyIsAnsweredByTheSameOrder(): void
    {
        $this->loginAsAdmin();
        $this->wallet($this->ali, '150000');
        $body = ['plan_id' => $this->plan->id, 'server_id' => $this->server->id, 'client_name' => 'ali_vip', 'payment' => 'wallet', 'amount' => 150000];

        $first = $this->order($this->ali, $body, 'key-1');
        self::assertSame(201, $first->getStatusCode(), (string) $first->getBody());
        // Made again: the wallet it emptied and the name it took refuse nothing — it is the same order.
        $again = $this->order($this->ali, $body, 'key-1');
        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame($this->decode($first)['order']['id'], $this->decode($again)['order']['id']);
        self::assertSame(1, Order::query()->count());
        self::assertSame('0.00', $this->ali->balance(), 'charged once');
        self::assertCount(1, FakeProvider::$created, 'delivered once');

        $this->assertRefusedUnder('idempotency_key', RequestKeyReusedException::MESSAGE, $this->order($this->ali, ['payment' => 'wallet', 'amount' => 90000] + $body, 'key-1'));
        $this->assertRefusedUnder('idempotency_key', RequestKeyReusedException::OTHER_METHOD, $this->order($this->ali, ['payment' => 'outside'] + $body, 'key-1'));
    }

    public function testAFailedDeliveryWaitsForItsRetryWhichTellsTheCustomer(): void
    {
        $this->loginAsAdmin();
        FakeProvider::$refusing[$this->server->id] = ['createClient'];

        $response = $this->order($this->ali, ['plan_id' => $this->plan->id, 'server_id' => $this->server->id, 'payment' => 'outside', 'amount' => 50000]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        ['order' => $order, 'subscription' => $service] = $this->decode($response);
        self::assertSame(['failed', true, null], [$order['status'], $order['actions']['retry'], $service]);
        self::assertSame([], $this->telegram()->sentTo(self::TELEGRAM_ID), 'support sees it at once; the customer hears of it delivered');

        FakeProvider::$refusing = [];
        $retried = $this->postJson("/api/admin/orders/{$order['id']}/retry");
        self::assertSame('fulfilled', $this->decode($retried)['order']['status'], (string) $retried->getBody());
        self::assertCount(1, $this->telegram()->sentTo(self::TELEGRAM_ID));
    }

    public function testAnAgentMakesOneInTheirShopOutOfTheirTraffic(): void
    {
        $bot = $this->agentBot(traffic: 100);
        [$reza, $theirs] = CurrentBot::run($bot, fn(): array => [$this->customer(['telegram_id' => 8080, 'username' => 'reza']), $this->plan(['name' => 'نامحدود', 'traffic_gb' => 0], $this->server)]);
        $this->loginAsAgent($bot);
        $order = fn(array $body): ResponseInterface => $this->send('POST', "/api/agent/users/{$reza->id}/orders", $body + ['server_id' => $this->server->id, 'payment' => 'free'], ['X-Requested-With' => 'XMLHttpRequest', 'Idempotency-Key' => bin2hex(random_bytes(8))]);

        // The delivery's own refusals (Bot::trafficRefusal()), asked before anything is made.
        $this->assertRefusedUnder('traffic_gb', TrafficShortException::UNLIMITED, $order(['traffic_gb' => 0, 'days' => 30, 'devices' => 1]));
        $this->assertRefusedUnder('traffic_gb', sprintf(TrafficShortException::SHORT, Messages::bytes(500 * Traffic::GIGABYTE), Messages::bytes(100 * Traffic::GIGABYTE)), $order(['traffic_gb' => 500, 'days' => 30, 'devices' => 1]));
        $this->assertRefusedUnder('plan_id', TrafficShortException::UNLIMITED, $order(['plan_id' => $theirs->id]));
        $this->assertRefusedUnder('plan_id', ManualOrders::NO_PLAN, $order(['plan_id' => $this->plan->id]), message: 'the main shop\'s plan is none of theirs');

        $made = $order(['traffic_gb' => 20, 'days' => 30, 'devices' => 1]);
        self::assertSame(201, $made->getStatusCode(), (string) $made->getBody());
        self::assertSame(['fulfilled', '@agent_shop_bot'], [$this->decode($made)['order']['status'], $this->decode($made)['order']['manual']['by']]);
        self::assertSame(80 * Traffic::GIGABYTE, Bot::query()->findOrFail($bot->id)->trafficBalance(), 'drawn from their pool');
        self::assertSame($bot->id, Order::query()->withoutGlobalScopes()->value('bot_id'));
    }

    public function testAFailedDeliveryInAnAgentsShopGivesTheTrafficBack(): void
    {
        $bot = $this->agentBot(traffic: 100);
        $reza = CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => 8080, 'username' => 'reza']));
        FakeProvider::$refusing[$this->server->id] = ['createClient'];
        $this->loginAsAgent($bot);

        $made = $this->send('POST', "/api/agent/users/{$reza->id}/orders", ['traffic_gb' => 20, 'days' => 30, 'devices' => 1, 'server_id' => $this->server->id, 'payment' => 'outside', 'amount' => 50000], ['X-Requested-With' => 'XMLHttpRequest', 'Idempotency-Key' => 'key-agent']);

        self::assertSame(201, $made->getStatusCode(), (string) $made->getBody());
        $order = $this->decode($made)['order'];
        self::assertSame('failed', $order['status']);
        self::assertSame(100 * Traffic::GIGABYTE, Bot::query()->findOrFail($bot->id)->trafficBalance(), 'drawn, then given back');

        FakeProvider::$refusing = [];
        self::assertSame('fulfilled', $this->decode($this->postJson("/api/agent/orders/{$order['id']}/retry"))['order']['status']);
        self::assertSame(80 * Traffic::GIGABYTE, Bot::query()->findOrFail($bot->id)->trafficBalance(), 'drawn once it is delivered');
    }

    public function testASuspendedAgentsShopGetsNoOrderWhoeverAsks(): void
    {
        $bot = $this->agentBot();
        $reza = CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => 8080, 'username' => 'reza']));
        $body = ['traffic_gb' => 20, 'days' => 30, 'devices' => 1, 'server_id' => $this->server->id, 'payment' => 'free'];
        $this->loginAsAgent($bot);
        $byAgent = fn(string $key): ResponseInterface => $this->send('POST', "/api/agent/users/{$reza->id}/orders", $body, ['X-Requested-With' => 'XMLHttpRequest', 'Idempotency-Key' => $key]);
        self::assertSame(201, $byAgent('key-before')->getStatusCode());

        $this->service(AgencyStanding::class)->suspendByHand($bot, null);

        $this->assertRefusedUnder('status', SellingSuspendedException::STAFF, $byAgent('key-after'));
        self::assertSame(200, $byAgent('key-before')->getStatusCode(), 'an order made before is still the answer to its key');
        $this->loginAsAdmin();
        $this->openShop($bot);
        $this->assertRefusedUnder('status', SellingSuspendedException::OWNER, $this->order($reza, $body), message: 'the owner opens it again first');
        self::assertSame(1, Order::query()->withoutGlobalScopes()->count());
        self::assertSame(80 * Traffic::GIGABYTE, Bot::query()->findOrFail($bot->id)->trafficBalance(), 'nothing drawn since');
    }

    public function testNothingIsMadeOnWhatTheOwnerKeepsFromAnAgentsShop(): void
    {
        $bot = $this->agentBot();
        $hidden = $this->sellingServer('هلند');
        [$reza, $there, $kept] = CurrentBot::run($bot, function () use ($hidden): array {
            $kept = $this->plan(['name' => 'پنهان'], $this->server);
            $kept->forceFill(['hidden_by_owner' => true])->save();

            return [$this->customer(['telegram_id' => 8080, 'username' => 'reza']), $this->plan(['name' => 'هلند'], [$hidden, $this->server]), $kept];
        });
        $bot->hideServers([$hidden->id]);
        $this->loginAsAgent($bot);
        $order = fn(array $body): ResponseInterface => $this->send('POST', "/api/agent/users/{$reza->id}/orders", $body + ['payment' => 'free'], ['X-Requested-With' => 'XMLHttpRequest', 'Idempotency-Key' => bin2hex(random_bytes(8))]);

        $this->assertRefusedUnder('server_id', ServerSelector::HIDDEN_SERVER, $order(['traffic_gb' => 20, 'days' => 30, 'devices' => 1, 'server_id' => $hidden->id]));
        $this->assertRefusedUnder('server_id', ServerSelector::HIDDEN_SERVER, $order(['plan_id' => $there->id, 'server_id' => $hidden->id]));
        $this->assertRefusedUnder('plan_id', ServerSelector::HIDDEN_PLAN, $order(['plan_id' => $kept->id, 'server_id' => $this->server->id]));
        self::assertFalse(Order::query()->withoutGlobalScopes()->exists());
        self::assertSame([], FakeProvider::$created);

        $made = $order(['plan_id' => $there->id, 'server_id' => $this->server->id]);
        self::assertSame(201, $made->getStatusCode(), (string) $made->getBody());
    }

    public function testTheWebsitesAdminsNeedTheGrantARecentSignInAndNeverOrderForThemselves(): void
    {
        $website = $this->website();
        $this->reportGroup();
        $boss = $this->customer(['telegram_id' => 6060, 'username' => 'boss', 'first_name' => 'Boss']);
        $this->loginAsStaff($website, $boss);
        $body = ['plan_id' => $this->plan->id, 'server_id' => $this->server->id, 'payment' => 'free'];
        $order = fn(User $for): ResponseInterface => $this->send('POST', $this->storeApi($website, "/admin/users/{$for->id}/orders"), $body, ['Idempotency-Key' => bin2hex(random_bytes(8))]);

        $refused = $order($this->ali);
        self::assertSame([403, StaffMiddleware::NOT_GRANTED], [$refused->getStatusCode(), $this->decode($refused)['message']]);

        $website->forceFill(['staff_grants' => [StaffGrant::ManualOrders->value]])->save();
        $own = $order($boss);
        self::assertSame([403, ActorRefusedException::OWN_ORDER], [$own->getStatusCode(), $this->decode($own)['message']]);
        $made = $order($this->ali);
        self::assertSame(201, $made->getStatusCode(), (string) $made->getBody());
        self::assertSame('@boss', $this->decode($made)['order']['manual']['by']);
        self::assertStringContainsString('🎧 ثبت دستی توسط پشتیبانی: @boss · رایگان', $this->purchaseReport(), 'an admin by name');

        Carbon::setTestNow(now()->addSeconds(CustomerSessions::RECENT_SECONDS + 1));
        $stale = $order($this->ali);
        self::assertSame([403, RecentSignInMiddleware::SIGN_IN_AGAIN], [$stale->getStatusCode(), $this->decode($stale)['message']]);
        self::assertSame(1, Order::query()->count());
    }

    public function testTheOutsideRowIsNeverOfferedNorListedAmongTheMethods(): void
    {
        $this->loginAsAdmin();

        $methods = array_column($this->decode($this->get('/api/admin/payment-methods'))['methods'], 'driver');
        $drivers = array_column($this->decode($this->get('/api/admin/payment-methods/drivers'))['drivers'], 'key');

        self::assertNotContains(OutsideGateway::key(), $methods);
        self::assertNotContains(OutsideGateway::key(), $drivers);
        self::assertContains('wallet', $methods);
        self::assertSame(1, PaymentMethod::query()->where('driver', OutsideGateway::key())->count(), 'yet the shop has its row');
        // Every checkout picks among these — the bot's, the website's, a renewal, extras, a top-up, an agent's traffic.
        $outside = PaymentMethod::query()->where('driver', OutsideGateway::key())->firstOrFail();
        $offered = $this->service(PaymentMethods::class);
        foreach (OrderType::cases() as $type) {
            self::assertNotContains($outside->id, array_map(static fn(PaymentMethod $method): int => $method->id, $offered->forOrder($type, null)), $type->value);
            self::assertNull($offered->payable($outside->id, $type, '100000.00'), $type->value);
        }
        $bot = $this->agentBot();
        self::assertSame(1, CurrentBot::run($bot, static fn(): int => PaymentMethod::query()->where('driver', OutsideGateway::key())->count()), 'and an agent\'s shop gets one as it opens');
    }

    /**
     * POST /users/{id}/orders from the owner's panel, under `$key` (a fresh one by default).
     *
     * @param array<string, mixed> $body
     */
    private function order(User $customer, array $body, ?string $key = null): ResponseInterface
    {
        return $this->send('POST', "/api/admin/users/{$customer->id}/orders", $body, ['X-Requested-With' => 'XMLHttpRequest', 'Idempotency-Key' => $key ?? bin2hex(random_bytes(8))]);
    }

    /** Sara, whom Ali's link brought — the referral program on at 10% — with an empty wallet. */
    private function referred(): User
    {
        $this->referralProgram(rate: 10);

        return $this->customer(['telegram_id' => 7070, 'username' => 'sara', 'referred_by' => $this->ali->id]);
    }

    /** What the report group's «خریدها» was told of the delivered purchase. */
    private function purchaseReport(): string
    {
        return (string) ReportMessage::query()->where('text', 'like', '%خرید جدید%')->value('text');
    }
}
