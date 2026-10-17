<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Users\Models\CustomerGroup;
use App\Modules\Users\Models\User;
use Tests\HttpTestCase;

/**
 * The admin's groups of customers («گروه‌ها» on the users page): made, renamed, put in order and deleted — its customers
 * just leave it —; who is in which set on a customer's row; the users list shows each one's groups and filters by one.
 */
final class AdminCustomerGroupsApiTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
    }

    public function testGroupsAreMadeRenamedOrderedAndDeleted(): void
    {
        $vip = $this->postJson('/api/admin/customer-groups', ['name' => ' VIP ']);
        self::assertSame(201, $vip->getStatusCode());
        $vipId = $this->decode($vip)['group']['id'];
        self::assertSame(['VIP', 0], [$this->decode($vip)['group']['name'], $this->decode($vip)['group']['counts']['users']]);
        $friendsId = $this->decode($this->postJson('/api/admin/customer-groups', ['name' => 'همکاران']))['group']['id'];

        $duplicate = $this->postJson('/api/admin/customer-groups', ['name' => 'VIP']);
        self::assertSame(422, $duplicate->getStatusCode());
        self::assertStringContainsString('وجود دارد', $this->decode($duplicate)['errors']['name'][0]);
        self::assertSame(422, $this->postJson('/api/admin/customer-groups', ['name' => ''])->getStatusCode());
        self::assertSame(422, $this->postJson('/api/admin/customer-groups', ['name' => str_repeat('ا', 33)])->getStatusCode());

        $renamed = $this->putJson("/api/admin/customer-groups/{$vipId}", ['name' => 'ویژه']);
        self::assertSame('ویژه', $this->decode($renamed)['group']['name']);
        self::assertSame(422, $this->putJson("/api/admin/customer-groups/{$friendsId}", ['name' => 'ویژه'])->getStatusCode());

        $ordered = $this->postJson('/api/admin/customer-groups/reorder', ['ids' => [$friendsId, $vipId]]);
        self::assertSame(['همکاران', 'ویژه'], array_column($this->decode($ordered)['groups'], 'name'));
        // An order is read off ids alone: "2abc", a boolean or a list is none — never group #2 or #1 put first.
        foreach ([["{$vipId}abc", $friendsId], [true, $friendsId], [[$vipId], $friendsId]] as $garbled) {
            $refused = $this->unchecked()->postJson('/api/admin/customer-groups/reorder', ['ids' => $garbled]);
            self::assertSame(422, $refused->getStatusCode(), json_encode($garbled, JSON_THROW_ON_ERROR));
            self::assertArrayHasKey('ids', $this->decode($refused)['errors']);
        }
        self::assertSame(['همکاران', 'ویژه'], array_column($this->decode($this->get('/api/admin/customer-groups'))['groups'], 'name'), 'the order as it was');

        $ali = $this->customer(['telegram_id' => 1001]);
        $this->putJson("/api/admin/users/{$ali->id}/groups", ['group_ids' => [$vipId]]);
        self::assertSame([0, 1], array_column(array_column($this->decode($this->get('/api/admin/customer-groups'))['groups'], 'counts'), 'users'));

        self::assertSame(204, $this->deleteJson("/api/admin/customer-groups/{$vipId}")->getStatusCode());
        self::assertSame(1, CustomerGroup::query()->count());
        self::assertSame([], $ali->groups()->get()->all(), 'its customers just leave it');
        self::assertTrue(User::query()->whereKey($ali->id)->exists(), 'and keep their account');
        self::assertSame(404, $this->deleteJson("/api/admin/customer-groups/{$vipId}")->getStatusCode());
    }

    public function testAGroupMayPayThePlansAgentPrices(): void
    {
        $plain = $this->decode($this->postJson('/api/admin/customer-groups', ['name' => 'VIP']))['group'];
        self::assertFalse($plain['agent_pricing'], 'a new group: off');

        $colleagues = $this->decode($this->postJson('/api/admin/customer-groups', ['name' => 'همکاران', 'agent_pricing' => true]))['group'];
        self::assertTrue($colleagues['agent_pricing']);
        self::assertTrue($this->decode($this->putJson("/api/admin/customer-groups/{$colleagues['id']}", ['name' => 'همکاران فروش']))['group']['agent_pricing'], 'renamed without it: kept');
        self::assertFalse($this->decode($this->putJson("/api/admin/customer-groups/{$colleagues['id']}", ['name' => 'همکاران فروش', 'agent_pricing' => false]))['group']['agent_pricing']);

        $refused = $this->unchecked()->postJson('/api/admin/customer-groups', ['name' => '', 'agent_pricing' => 'maybe']);
        self::assertSame(['name', 'agent_pricing'], array_keys($this->decode($refused)['errors'] ?? []), 'every refusal at once');
    }

    public function testANameTakenInTheSameMomentIsRefusedLikeAnyTakenName(): void
    {
        // Another save takes the name between this one's check and its write: the table's unique index decides.
        $response = $this->whileListening(
            'eloquent.creating: ' . CustomerGroup::class,
            static fn(CustomerGroup $group) => CustomerGroup::query()->insert(['bot_id' => 1, 'name' => $group->name, 'sort' => 9, 'created_at' => now(), 'updated_at' => now()]),
            fn() => $this->postJson('/api/admin/customer-groups', ['name' => 'VIP']),
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('وجود دارد', $this->decode($response)['errors']['name'][0]);
        self::assertSame(1, CustomerGroup::query()->where('name', 'VIP')->count());
    }

    public function testACustomersGroupsAreSetOnTheirRowAndTheUsersListFiltersByOne(): void
    {
        $ali = $this->customer(['telegram_id' => 1001, 'first_name' => 'Ali']);
        $sara = $this->customer(['telegram_id' => 1002, 'first_name' => 'Sara']);
        $vip = $this->customerGroup('VIP');
        $friends = $this->customerGroup('همکاران');

        $response = $this->putJson("/api/admin/users/{$ali->id}/groups", ['group_ids' => [$friends->id, $vip->id, $vip->id]]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([['id' => $vip->id, 'name' => 'VIP'], ['id' => $friends->id, 'name' => 'همکاران']], $this->decode($response)['user']['groups'], "in the admin's order");

        $unknown = $this->putJson("/api/admin/users/{$sara->id}/groups", ['group_ids' => [$vip->id, 999]]);
        self::assertSame(422, $unknown->getStatusCode());
        self::assertArrayHasKey('group_ids', $this->decode($unknown)['errors']);
        self::assertSame([], $sara->groups()->get()->all(), 'a refused list changes nothing');
        $garbled = $this->unchecked()->putJson("/api/admin/users/{$sara->id}/groups", ['group_ids' => ["{$vip->id}abc"]]);
        self::assertSame(422, $garbled->getStatusCode(), 'no id is read out of what is no whole number');
        self::assertSame([], $sara->groups()->get()->all());

        $users = $this->decode($this->get("/api/admin/users?group={$vip->id}"))['users'];
        self::assertSame(['Ali'], array_column($users, 'name'));
        $everyone = $this->decode($this->get('/api/admin/users'))['users'];
        self::assertSame([[], ['VIP', 'همکاران']], array_map(static fn(array $user): array => array_column($user['groups'], 'name'), $everyone), 'Sara first: the newest');

        $cleared = $this->putJson("/api/admin/users/{$ali->id}/groups", ['group_ids' => []]);
        self::assertSame([], $this->decode($cleared)['user']['groups']);
        self::assertSame(404, $this->putJson('/api/admin/users/999/groups', ['group_ids' => []])->getStatusCode());
    }

    /** The owner decides any account's groups — a bot's admin's, an agent's —: what the website's admins are refused is the panels'. */
    public function testTheOwnerPutsAnAdminsOrAnAgentsAccountInAGroup(): void
    {
        $colleagues = $this->customerGroup('همکاران', overrides: ['agent_pricing' => true]);
        $admin = $this->admin(['telegram_id' => 1001]);
        $agent = $this->agent(overrides: ['telegram_id' => 1002]);

        foreach ([$admin, $agent] as $user) {
            $response = $this->putJson("/api/admin/users/{$user->id}/groups", ['group_ids' => [$colleagues->id]]);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame([$colleagues->id], $user->groups()->pluck('customer_groups.id')->all());
        }
        self::assertTrue($admin->paysAgentPrices());

        $vip = $this->customerGroup('VIP', [$admin]);
        self::assertTrue($this->decode($this->putJson("/api/admin/customer-groups/{$vip->id}", ['name' => 'VIP', 'agent_pricing' => true]))['group']['agent_pricing'], 'a group with an admin in it switched too');
    }
}
