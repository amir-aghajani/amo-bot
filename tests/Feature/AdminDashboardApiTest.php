<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Application;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Subscriptions\Services\SubscriptionDirectory;
use App\Modules\Telegram\BotState;
use App\Support\LocalTime;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * The shop's dashboard (both panels, each its shop's): the period's figures against the one before, the chart's days —
 * the shop's own —, the queues that need a human, the latest arrivals — and, the owner's alone, whether the machinery
 * behind the shop is alive: the version, PHP's, how the open shop's bot gets its updates, and the main bot's — the way
 * every bot of the installation gets them.
 */
final class AdminDashboardApiTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
    }

    public function testEmptyShopStillProducesAFullPayload(): void
    {
        $response = $this->get('/api/admin/dashboard?range=7');
        $data = $this->decode($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(7, $data['range']['days']);
        self::assertCount(7, $data['series']);
        self::assertSame(['value' => '0.00', 'previous' => '0.00'], $data['kpis']['revenue']);
        self::assertSame([0, 0], [$data['kpis']['users_total'], $data['kpis']['active_subscriptions']]);
        self::assertSame([], $data['recent_orders']);
        self::assertSame(SubscriptionDirectory::EXPIRING_DAYS, $data['expiring_days']);
    }

    public function testARangeIsSevenThirtyOrNinetyDaysElseThirty(): void
    {
        foreach ([7 => 7, 30 => 30, 90 => 90, 12 => 30] as $asked => $days) {
            if ($asked !== $days) {
                $this->unchecked(); // no range the panel offers
            }
            $data = $this->decode($this->get("/api/admin/dashboard?range={$asked}"));

            self::assertSame([$days, $days], [$data['range']['days'], count($data['series'])], "range={$asked}");
        }
    }

    public function testTheDaysAreTheShopsOwn(): void
    {
        LocalTime::use('Asia/Tehran');
        try {
            // 22:00 UTC on the 5th is 01:30 on the 6th in Tehran: today's order there, yesterday's in UTC.
            Carbon::setTestNow(Carbon::parse('2026-10-05 22:00:00', 'UTC'));
            $this->purchaseOrder($this->customer(), $this->plan(), $this->fakeServer());
            Carbon::setTestNow(Carbon::parse('2026-10-06 08:00:00', 'UTC'));

            $series = array_column($this->decode($this->get('/api/admin/dashboard?range=7'))['series'], 'orders', 'date');

            self::assertSame(1, $series['2026-10-06']);
            self::assertSame(0, $series['2026-10-05']);
        } finally {
            LocalTime::use('UTC');
        }
    }

    public function testActivityShowsUpInKpisSeriesAndLists(): void
    {
        $plan = $this->plan(['name' => 'Pro', 'price' => '7.00']);
        $server = $this->panelServer(overrides: ['api_token' => null, 'username' => 'a', 'password' => 'b', 'last_error' => 'login failed']);
        // Last period's customer and order.
        Carbon::setTestNow(now()->subDays(10));
        $this->purchaseOrder($this->customer(['telegram_id' => 5000]), $plan, $server, ['status' => OrderStatus::Cancelled]);
        Carbon::setTestNow();

        $customer = $this->customer(['telegram_id' => 5001, 'first_name' => 'Sara']);
        $paidOrder = $this->purchaseOrder($customer, $plan, $server, ['status' => OrderStatus::Fulfilled]);
        $this->cardPayment($paidOrder, $this->cardMethod(), ['status' => PaymentStatus::Paid, 'paid_at' => now()]);
        $reviewOrder = $this->purchaseOrder($customer, $plan, $server);
        $this->cardPayment($reviewOrder, $this->cardMethod('کارت دوم'), ['status' => PaymentStatus::AwaitingReview]);
        // Paid from the wallet — spending, no revenue —, its delivery failed: stuck.
        $this->cardPayment($this->purchaseOrder($customer, $plan, $server, ['status' => OrderStatus::Failed]), $this->walletMethod(), ['status' => PaymentStatus::Paid, 'paid_at' => now()]);

        $data = $this->decode($this->get('/api/admin/dashboard?range=7'));

        self::assertSame(2, $data['kpis']['users_total']);
        self::assertSame('7.00', $data['kpis']['revenue']['value']);
        self::assertSame([['value' => 3, 'previous' => 1], ['value' => 1, 'previous' => 1]], [$data['kpis']['orders'], $data['kpis']['new_users']], 'each figure against the period before');
        self::assertSame(3, array_sum(array_column($data['series'], 'orders')), 'series and KPI must count the same orders');
        self::assertEqualsWithDelta(7.0, array_sum(array_map(static fn(array $p): float => (float) $p['revenue'], $data['series'])), 0.001);
        self::assertCount(4, $data['recent_orders']);
        self::assertSame(['id' => $customer->id, 'name' => 'Sara', 'username' => null, 'telegram_id' => 5001, 'email' => null], $data['recent_orders'][0]['user'], 'the customer as every screen points at them');
        self::assertSame('purchase', $data['recent_orders'][0]['type'], 'the panel words the type itself');
        self::assertSame(['id', 'name', 'username', 'telegram_id', 'email', 'balance', 'created_at'], array_keys($data['recent_users'][0]));
        self::assertSame(['payments_to_review' => 1, 'stuck_orders' => 1, 'open_tickets' => 0, 'pending_reviews' => 0, 'pending_orders' => 1, 'servers_with_errors' => 1, 'expiring_soon' => 0], $data['attention']);
    }

    public function testRevenueIsMoneyThatCameInNeverWalletSpending(): void
    {
        $customer = $this->customer();
        $plan = $this->plan(['price' => '100.00']);
        $server = $this->fakeServer();

        // A card top-up that stays in the wallet, then a purchase from the wallet: 100 came in, not 200.
        $this->cardPayment($this->topUpOrder($customer, '100.00', ['status' => OrderStatus::Fulfilled]), $this->cardMethod(), ['status' => PaymentStatus::Paid, 'paid_at' => now()]);
        $this->cardPayment($this->purchaseOrder($customer, $plan, $server, ['status' => OrderStatus::Fulfilled]), $this->walletMethod(), ['status' => PaymentStatus::Paid, 'paid_at' => now()]);
        // A refunded purchase paid by card: its money went to the customer's wallet and stays in the shop…
        $this->cardPayment($this->purchaseOrder($customer, $plan, $server, ['status' => OrderStatus::Refunded]), $this->cardMethod('کارت دوم'), ['status' => PaymentStatus::Refunded, 'paid_at' => now()]);
        // …a refunded top-up went back to the customer.
        $this->cardPayment($this->topUpOrder($customer, '50.00', ['status' => OrderStatus::Refunded]), $this->cardMethod('کارت سوم'), ['status' => PaymentStatus::Refunded, 'paid_at' => now()]);
        // Last period's card payment is last period's.
        $this->cardPayment($this->purchaseOrder($customer, $plan, $server, ['status' => OrderStatus::Fulfilled]), $this->cardMethod('کارت چهارم'), ['status' => PaymentStatus::Paid, 'paid_at' => now()->subDays(10)]);

        $data = $this->decode($this->get('/api/admin/dashboard?range=7'));

        self::assertSame(['value' => '200.00', 'previous' => '100.00'], $data['kpis']['revenue']);
        self::assertSame('200.00', number_format(array_sum(array_map(floatval(...), array_column($data['series'], 'revenue'))), 2, '.', ''), 'the chart counts the same money');
    }

    public function testServicesEndingSoonAreCountedInTheOneWindowTheSubscriptionsScreenUses(): void
    {
        $customer = $this->customer();
        $plan = $this->plan();
        $server = $this->fakeServer();
        $this->subscription($customer, $plan, $server, 'soon', ['expires_at' => now()->addDays(SubscriptionDirectory::EXPIRING_DAYS)->subHour()]);
        $this->subscription($customer, $plan, $server, 'later', ['expires_at' => now()->addDays(SubscriptionDirectory::EXPIRING_DAYS + 2)]);

        $data = $this->decode($this->get('/api/admin/dashboard'));

        self::assertSame(2, $data['kpis']['active_subscriptions']);
        self::assertSame(1, $data['attention']['expiring_soon']);
        self::assertSame(SubscriptionDirectory::EXPIRING_DAYS, $data['expiring_days']);
    }

    public function testTheSystemSaysHowTheOpenShopsBotAndTheMainBotGetTheirUpdates(): void
    {
        $system = fn(): array => $this->decode($this->get('/api/admin/system'))['system'];
        self::assertSame([Application::VERSION, PHP_VERSION], [$system()['version'], $system()['php']]);
        $quiet = ['configured' => false, 'enabled' => true, 'username' => null, 'mode' => 'offline', 'last_update_at' => null];
        self::assertSame(['id' => Bot::MAIN] + $quiet, $system()['bot']);
        self::assertSame($quiet, $system()['main_bot'], 'the main shop open: its bot twice');

        $state = $this->service(BotState::class);
        $state->heartbeat();
        self::assertSame('polling', $system()['bot']['mode'], 'a poller that wrote its heartbeat lately');
        Carbon::setTestNow(now()->addMinutes(3));
        self::assertSame('offline', $system()['bot']['mode'], 'one gone quiet');
        $state->webhookSet('https://shop.example.com/webhooks/telegram/secret');
        self::assertSame('webhook', $system()['bot']['mode']);

        $bot = $this->agentBot();
        $this->openShop($bot);
        $open = $system();
        self::assertSame([$bot->id, true, 'agent_shop_bot', 'offline'], [$open['bot']['id'], $open['bot']['configured'], $open['bot']['username'], $open['bot']['mode']], "the open shop's bot, by its id");
        self::assertSame([false, 'webhook'], [$open['main_bot']['configured'], $open['main_bot']['mode']], "how every bot gets its updates is the main bot's way, whichever shop is open");
    }

    public function testAnAgentsDashboardIsTheirShopsAloneAndTellsNothingOfTheInstallation(): void
    {
        $server = $this->fakeServer('آلمان', ['last_error' => 'login failed']);
        $this->purchaseOrder($this->customer(), $this->plan(), $server);
        $bot = $this->agentBot();
        CurrentBot::run($bot, function () use ($server): void {
            $failed = $this->purchaseOrder($this->customer(['telegram_id' => 31]), $this->plan(['traffic_gb' => 30]), $server, ['status' => OrderStatus::Failed]);
            $this->cardPayment($failed, $this->walletMethod(), ['status' => PaymentStatus::Paid, 'paid_at' => now()]);
        });
        $this->loginAsAgent($bot);

        $dashboard = $this->decode($this->get('/api/agent/dashboard'));

        self::assertSame([1, 1], [$dashboard['kpis']['users_total'], $dashboard['kpis']['orders']['value']], "their bot's customers and orders");
        self::assertSame([1, 0, 0], [$dashboard['attention']['stuck_orders'], $dashboard['attention']['pending_orders'], $dashboard['attention']['servers_with_errors']], "the servers are the shop's own");
        self::assertArrayNotHasKey('system', $dashboard);
        self::assertSame(404, $this->get('/api/agent/system')->getStatusCode());
    }

    public function testAnAgentsDashboardSaysWhenTheirTrafficSellsNothing(): void
    {
        $bot = $this->agentBot(traffic: 20);
        CurrentBot::run($bot, function (): void {
            $this->plan(['traffic_gb' => 50]);
            $this->plan(['traffic_gb' => 30]);
            $this->plan(['traffic_gb' => 10, 'is_active' => false]);
        });
        $this->loginAsAgent($bot);
        $shortage = fn(): ?array => $this->decode($this->get('/api/agent/dashboard'))['traffic_shortage'];

        self::assertSame(['balance' => Traffic::bytesOfGb(20), 'smallest_plan' => Traffic::bytesOfGb(30)], $shortage(), 'not even the smallest plan on sale is offered');

        $this->traffic($bot, 30);
        self::assertNull($shortage(), 'the smallest plan sells, the bigger one waits for more');

        $this->traffic($bot, 0);
        CurrentBot::run($bot, static fn() => Plan::query()->update(['is_active' => false]));
        self::assertSame(['balance' => 0, 'smallest_plan' => null], $shortage(), 'used up: nothing will sell once a plan is put on sale');

        self::assertNull($this->decode($this->get('/api/admin/dashboard'))['traffic_shortage'], 'the main bot sells without traffic of its own');
    }
}
