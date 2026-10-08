<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database\Page;
use App\Modules\Bots\CurrentBot;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletService;
use Tests\HttpTestCase;

/**
 * The users table: the bot's customers with their counts and balance — the three Telegram identifiers kept apart —,
 * one search box (name, handle, Telegram id, a phone by four digits or more, our own id), the status filter, paging,
 * banning and the bot's admin role in one edit, and the wallet: its ledger and a credit or debit by hand.
 */
final class AdminUsersApiTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
    }

    public function testRowsAreTheBotsCustomersWithTheirCountsNewestFirst(): void
    {
        $ali = $this->wallet($this->customer(['telegram_id' => 1001, 'username' => 'ali_r', 'phone' => '+989120000001']), '50000.00');
        $sara = $this->customer(['telegram_id' => 1002, 'first_name' => 'Sara', 'status' => UserStatus::Banned]);
        $nameless = $this->customer(['telegram_id' => 1003, 'first_name' => null]);
        // One order and two subscriptions (one active, one expired) for the counts.
        $server = $this->fakeServer();
        $plan = $this->plan();
        $this->purchaseOrder($ali, $plan, $server, ['status' => OrderStatus::Fulfilled]);
        $this->subscription($ali, $plan, $server, 'USER_1');
        $this->subscription($ali, $plan, $server, 'USER_2', ['status' => SubscriptionStatus::Expired]);

        $response = $this->get('/api/admin/users');

        self::assertSame(200, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame([$nameless->id, $sara->id, $ali->id], array_column($data['users'], 'id'), 'newest first');
        self::assertSame([null, 'Sara', 'Ali'], array_column($data['users'], 'name'), 'an account that shows no name has none — never its handle or id in its place');
        self::assertSame(['page' => 1, 'per_page' => Page::PER_PAGE, 'total' => 3, 'last_page' => 1, 'sort' => 'joined', 'dir' => 'desc'], $data['meta']);

        $row = $data['users'][2];
        self::assertSame($ali->id, $row['id']);
        self::assertSame('ali_r', $row['username']);
        self::assertSame(1001, $row['telegram_id']);
        self::assertSame('+989120000001', $row['phone']);
        self::assertSame('active', $row['status']);
        self::assertSame('customer', $row['role']);
        self::assertSame('50000.00', $row['balance']);
        self::assertSame(['orders' => 1, 'subscriptions' => 2, 'active_subscriptions' => 1], $row['counts']);
        self::assertNotNull($row['created_at']);

        self::assertSame('banned', $data['users'][1]['status']);
    }

    public function testSearchFindsByNameHandlePhoneAndIds(): void
    {
        $ali = $this->customer(['telegram_id' => 777001, 'first_name' => 'علی', 'last_name' => 'رضایی', 'username' => 'ali_r', 'phone' => '+989120000001']);
        $this->customer(['telegram_id' => 777002, 'first_name' => 'Sara', 'username' => 'sara_k', 'phone' => '+989350000002']);

        $names = fn(string $term): array => array_column($this->decode($this->get('/api/admin/users?search=' . rawurlencode($term)))['users'], 'name');

        self::assertSame(['علی رضایی'], $names('رضا'));
        self::assertSame(['علی رضایی'], $names('@ali'), 'a pasted handle');
        self::assertSame(['Sara'], $names('+98935'), 'a phone prefix');
        self::assertSame(['علی رضایی'], $names('9120'), 'four digits are a piece of a phone number');
        self::assertSame([], $names('912'), 'three are an id, and none is ours');
        self::assertSame(['Sara'], $names('777002'), 'a Telegram id');
        self::assertSame(['علی رضایی'], $names('۷۷۷۰۰۱'), 'Persian digits too');
        self::assertSame(['علی رضایی'], $names((string) $ali->id), 'or our own id');
        self::assertSame([], $names('nobody'));
    }

    public function testAHashAndANumberIsTheCustomerNumberedSoAlone(): void
    {
        $ali = $this->customer(['telegram_id' => 777001, 'first_name' => 'Ali']);
        // Her Telegram id is Ali's number.
        $this->customer(['telegram_id' => $ali->id, 'first_name' => 'Sara']);

        $names = fn(string $term): array => array_column($this->decode($this->get('/api/admin/users?search=' . rawurlencode($term)))['users'], 'name');

        self::assertSame(['Sara', 'Ali'], $names((string) $ali->id), 'a bare number: our id or a Telegram id');
        self::assertSame(['Ali'], $names("#{$ali->id}"), '«#n»: the customer numbered n, as every list takes it');
        self::assertSame([], $names('#999999'));
    }

    public function testTheStatusFilterAndPagingNarrowTheList(): void
    {
        foreach (range(1, 30) as $i) {
            $this->customer(['telegram_id' => 5000 + $i, 'first_name' => "U{$i}", 'status' => $i % 10 === 0 ? UserStatus::Banned : UserStatus::Active]);
        }

        $banned = $this->decode($this->get('/api/admin/users?status=banned'));
        self::assertSame(['U30', 'U20', 'U10'], array_column($banned['users'], 'name'));
        self::assertSame(3, $banned['meta']['total']);

        $page1 = $this->decode($this->get('/api/admin/users'));
        self::assertCount(Page::PER_PAGE, $page1['users']);
        self::assertSame(['page' => 1, 'per_page' => 25, 'total' => 30, 'last_page' => 2, 'sort' => 'joined', 'dir' => 'desc'], $page1['meta']);

        $page2 = $this->decode($this->get('/api/admin/users?page=2'));
        self::assertCount(5, $page2['users']);
        self::assertSame('U1', end($page2['users'])['name'], 'the oldest customer closes the list');

        $beyond = $this->decode($this->get('/api/admin/users?page=9'));
        self::assertSame(2, $beyond['meta']['page'], 'a page past the end lands on the last one');
        self::assertSame(30, $this->decode($this->get('/api/admin/users?status=nonsense'))['meta']['total'], 'an unknown status filters nothing');
    }

    public function testACustomerCanBeBannedAndReinstated(): void
    {
        $ali = $this->customer(['telegram_id' => 1001]);

        $banned = $this->patchJson("/api/admin/users/{$ali->id}", ['status' => 'banned']);
        self::assertSame(200, $banned->getStatusCode(), (string) $banned->getBody());
        $row = $this->decode($banned)['user'];
        self::assertSame('banned', $row['status']);
        self::assertSame(['orders' => 0, 'subscriptions' => 0, 'active_subscriptions' => 0], $row['counts'], 'the row comes back complete');
        self::assertTrue($ali->refresh()->isBanned());

        $active = $this->patchJson("/api/admin/users/{$ali->id}", ['status' => 'active']);
        self::assertSame('active', $this->decode($active)['user']['status']);

        $bad = $this->unchecked()->patchJson("/api/admin/users/{$ali->id}", ['status' => 'frozen']);
        self::assertSame(422, $bad->getStatusCode());
        self::assertArrayHasKey('status', $this->decode($bad)['errors']);

        self::assertSame(404, $this->patchJson('/api/admin/users/999999', ['status' => 'banned'])->getStatusCode());
    }

    public function testTheBotsAdminRoleIsGivenAndTakenFromTheTable(): void
    {
        $ali = $this->customer(['telegram_id' => 1001]);

        $promoted = $this->putJson("/api/admin/users/{$ali->id}/role", ['role' => 'admin']);
        self::assertSame(200, $promoted->getStatusCode(), (string) $promoted->getBody());
        self::assertSame('admin', $this->decode($promoted)['user']['role']);
        self::assertTrue($ali->refresh()->isAdmin());

        $demoted = $this->putJson("/api/admin/users/{$ali->id}/role", ['role' => 'customer']);
        self::assertSame('customer', $this->decode($demoted)['user']['role']);

        $bad = $this->unchecked()->putJson("/api/admin/users/{$ali->id}/role", ['role' => 'owner']);
        self::assertSame(422, $bad->getStatusCode());
        self::assertArrayHasKey('role', $this->decode($bad)['errors']);
        self::assertFalse($ali->refresh()->isAdmin());

        // The role is its own address: a status change carries none, and changes nothing but the status.
        $refused = $this->unchecked()->patchJson("/api/admin/users/{$ali->id}", ['role' => 'admin']);
        self::assertSame(['status'], array_keys($this->decode($refused)['errors']));
        self::assertFalse($ali->refresh()->isAdmin());
        self::assertSame(422, $this->unchecked()->patchJson("/api/admin/users/{$ali->id}", [])->getStatusCode());

        // An agent's panel gives the role in their own shop.
        $bot = $this->agentBot();
        $customer = CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => 1002]));
        $this->loginAsAgent($bot);
        self::assertSame('admin', $this->decode($this->putJson("/api/agent/users/{$customer->id}/role", ['role' => 'admin']))['user']['role']);
        self::assertSame(404, $this->putJson("/api/agent/users/{$ali->id}/role", ['role' => 'admin'])->getStatusCode(), "the main shop's customer is not theirs");
    }

    public function testTheTableNarrowsToTheBotsAdmins(): void
    {
        $this->customer(['telegram_id' => 1001, 'first_name' => 'Ali']);
        $this->admin(['telegram_id' => 1002, 'first_name' => 'Sara']);

        self::assertSame(['Sara'], array_column($this->decode($this->get('/api/admin/users?role=admin'))['users'], 'name'));
        self::assertSame(['Ali'], array_column($this->decode($this->get('/api/admin/users?role=customer'))['users'], 'name'));
        self::assertSame(2, $this->decode($this->unchecked()->get('/api/admin/users?role=owner'))['meta']['total'], 'an unknown role filters nothing');
    }

    public function testTheWalletCanBeReadAndAdjustedFromTheTable(): void
    {
        $ali = $this->customer(['telegram_id' => 1001]);
        $this->service(WalletService::class)->credit($ali, 20000, 'هدیه');

        $wallet = $this->decode($this->get("/api/admin/users/{$ali->id}/wallet"));
        self::assertSame('20000.00', $wallet['user']['balance']);
        self::assertCount(1, $wallet['transactions']);
        self::assertSame(['type' => 'credit', 'amount' => '20000.00', 'balance_after' => '20000.00', 'description' => 'هدیه', 'reviewer' => null], array_intersect_key($wallet['transactions'][0], array_flip(['type', 'amount', 'balance_after', 'description', 'reviewer'])), 'a line the shop wrote itself is nobody\'s');

        $credited = $this->postJson("/api/admin/users/{$ali->id}/wallet", ['type' => 'credit', 'amount' => '۵۰٬۰۰۰', 'description' => 'جبران قطعی']);
        self::assertSame(201, $credited->getStatusCode(), (string) $credited->getBody());
        $data = $this->decode($credited);
        self::assertSame('70000.00', $data['user']['balance']);
        self::assertSame('جبران قطعی', $data['transaction']['description']);
        self::assertSame('70000.00', $data['transaction']['balance_after']);
        self::assertSame(self::ADMIN_USERNAME, $data['transaction']['reviewer'], 'a line written by hand keeps who wrote it');
        self::assertSame(['جبران قطعی', 'هدیه'], array_column($data['transactions'], 'description'), 'the ledger comes back, newest first');

        $debited = $this->postJson("/api/admin/users/{$ali->id}/wallet", ['type' => 'debit', 'amount' => 10000]);
        self::assertSame('60000.00', $this->decode($debited)['user']['balance']);
        self::assertSame(WalletService::LINE_DEBIT_BY_SUPPORT, $this->decode($debited)['transaction']['description'], 'a note is written when none is given');

        $tooMuch = $this->postJson("/api/admin/users/{$ali->id}/wallet", ['type' => 'debit', 'amount' => 999999]);
        self::assertSame(422, $tooMuch->getStatusCode());
        self::assertStringContainsString('۶۰٬۰۰۰ تومان', $this->decode($tooMuch)['errors']['amount'][0]);
        self::assertSame('60000.00', $ali->balance(), 'nothing moved');

        $fraction = $this->postJson("/api/admin/users/{$ali->id}/wallet", ['type' => 'credit', 'amount' => '1000.50']);
        self::assertSame(['amount'], array_keys($this->decode($fraction)['errors'] ?? []), 'Toman has no fraction: the bot would round it away');
        self::assertSame('60000.00', $ali->balance());

        $bad = $this->unchecked()->postJson("/api/admin/users/{$ali->id}/wallet", ['type' => 'gift', 'amount' => '-5', 'description' => str_repeat('x', 200)]);
        self::assertSame(422, $bad->getStatusCode());
        self::assertSame(['type', 'amount', 'description'], array_keys($this->decode($bad)['errors']));

        self::assertSame(404, $this->get('/api/admin/users/999999/wallet')->getStatusCode());
    }
}
