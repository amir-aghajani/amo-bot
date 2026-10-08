<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use Tests\HttpTestCase;

/**
 * A customer's page in either panel (GET /users/{id}): their row as the users table has it, the referral program's facts
 * about them, and an agent's agency as the agents list shows it — the shop's own customer alone, another shop's is not
 * found —; and the lists the page links to, each narrowed to the customer (`user=`): their orders, their payments, their
 * services.
 */
final class CustomerPageApiTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram();
    }

    public function testACustomersPageIsTheirRowAndTheirReferralFacts(): void
    {
        $this->loginAsAdmin();
        $this->referralProgram();
        $reza = $this->customer(['telegram_id' => 900_001, 'first_name' => 'Reza', 'username' => 'reza']);
        $ali = $this->wallet($this->customer(['telegram_id' => 900_002, 'username' => 'ali_r', 'phone' => '+989120000001', 'role' => UserRole::Admin, 'referred_by' => $reza->id]), '25000.00');
        $sara = $this->customer(['telegram_id' => 900_003, 'first_name' => 'Sara', 'referred_by' => $ali->id]);
        $this->customer(['telegram_id' => 900_004, 'first_name' => 'Nima', 'referred_by' => $ali->id]);
        $this->paidByCard($this->topUpOrder($sara, '100000'));
        $this->customerGroup('VIP', [$ali]);

        $response = $this->get("/api/admin/users/{$ali->id}");

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $page = $this->decode($response);
        self::assertSame($this->decode($this->get('/api/admin/users?search=ali_r'))['users'][0], $page['user'], 'their row, as the users table has it');
        self::assertSame(['35000.00', 'admin', '+989120000001', ['VIP']], [$page['user']['balance'], $page['user']['role'], $page['user']['phone'], array_column($page['user']['groups'], 'name')]);
        self::assertSame(['id' => $reza->id, 'name' => 'Reza', 'username' => 'reza', 'telegram_id' => 900_001, 'email' => null], $page['referral']['referrer'], 'whose link brought them');
        self::assertSame([2, '10000.00'], [$page['referral']['referrals'], $page['referral']['earned']], 'how many theirs brought, and what those earned them');
        self::assertNull($page['agency'], 'a customer who is no agent has no agency');

        $referrers = $this->decode($this->get("/api/admin/users/{$reza->id}"))['referral'];
        self::assertSame(['referrer' => null, 'referrals' => 1, 'earned' => '0.00'], $referrers, 'one link brought them, and nobody brought them');
        self::assertSame(404, $this->get('/api/admin/users/999999')->getStatusCode());
    }

    public function testAnAgentsPageCarriesTheirAgencyAsTheAgentsListShowsIt(): void
    {
        $this->loginAsAdmin();
        $bot = $this->agentBot(traffic: 50);
        $agent = $bot->agent ?? self::fail('The bot has no agent.');

        $agency = $this->decode($this->get("/api/admin/users/{$agent->id}"))['agency'];

        self::assertSame($this->decode($this->get('/api/admin/agency/agents'))['agents'][0], $agency);
        self::assertSame([$bot->id, 'agent_shop_bot'], [$agency['bot']['id'], $agency['bot']['username']]);
    }

    public function testAShopsPageIsOfItsOwnCustomersAloneInEitherPanel(): void
    {
        $bot = $this->agentBot();
        $theirs = CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => 700_001, 'username' => 'agents_customer']));
        $main = $this->customer(['telegram_id' => 700_002, 'username' => 'main_customer']);

        $this->loginAsAgent($bot);
        $page = $this->decode($this->get("/api/agent/users/{$theirs->id}"));
        self::assertSame(['agents_customer', 'customer', null, 0, null], [$page['user']['username'], $page['user']['role'], $page['referral']['referrer'], $page['referral']['referrals'], $page['agency']]);
        self::assertSame(404, $this->get("/api/agent/users/{$main->id}")->getStatusCode(), "the main bot's customer is another shop's");

        $this->loginAsAdmin();
        self::assertSame(404, $this->get("/api/admin/users/{$theirs->id}")->getStatusCode(), "the agent's customer is not the main bot's");
        $this->openShop($bot);
        self::assertSame('agents_customer', $this->decode($this->get("/api/admin/users/{$theirs->id}"))['user']['username'], 'the owner sees them in that shop');
    }

    public function testTheOrdersPaymentsAndServicesNarrowToOneCustomer(): void
    {
        $this->loginAsAdmin();
        $server = $this->fakeServer();
        $plan = $this->plan([], $server);
        $method = $this->cardMethod();
        $ali = $this->customer(['telegram_id' => 800_001, 'username' => 'ali']);
        $sara = $this->customer(['telegram_id' => 800_002, 'username' => 'sara']);
        $order = $this->purchaseOrder($ali, $plan, $server);
        $payment = $this->cardPayment($order, $method);
        $this->cardPayment($this->purchaseOrder($sara, $plan, $server), $method);
        $service = $this->subscription($ali, $plan, $server, 'ali_1');
        $this->subscription($sara, $plan, $server, 'sara_1');

        $ids = fn(string $list, string $query): array => array_column($this->decode($this->get("/api/admin/{$list}?{$query}"))[$list], 'id');

        self::assertSame([$order->id], $ids('orders', "user={$ali->id}"));
        self::assertSame([$payment->id], $ids('payments', "user={$ali->id}"), "a payment is its order's customer's");
        self::assertSame([$service->id], $ids('subscriptions', "user={$ali->id}"));
        self::assertSame([], $ids('orders', 'user=999999'), "an id that is nobody's narrows to nothing");
        $this->unchecked();
        self::assertCount(2, $ids('subscriptions', 'user=ali'), 'what is no id (no panel sends one) is no filter');
        self::assertSame([], $ids('payments', "user={$ali->id}&status=paid"), 'with the other filters');
    }
}
