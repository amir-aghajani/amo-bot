<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\TestAccount;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Subscriptions\Services\TestAccounts;
use App\Modules\Users\Models\User;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * The free test accounts' page and a server's test card: a server's terms read and saved by the owner alone (every
 * refusal at once), the shop's tests listed newest first with where each stands and whether its customer bought after
 * it (a purchase counted from when it was delivered), every server's terms with whether it would give a test now — in
 * both panels and in an agent's shop the owner opens, each shop its own tests —, the `test_accounts` settings group, and
 * the subscriptions screen marking and filtering the tests.
 */
final class AdminTestAccountsApiTest extends HttpTestCase
{
    private Server $germany;
    private Server $holland;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->telegram();
        $this->germany = $this->sellingServer('آلمان', ['test_enabled' => true]);
        $this->holland = $this->sellingServer('هلند');
        $this->loginAsAdmin();
    }

    public function testTheOwnerReadsAndSavesAServersTerms(): void
    {
        self::assertSame(
            ['test_account' => ['test_enabled' => false, 'test_traffic_mb' => 500, 'test_duration_hours' => 24, 'test_on_hold' => true]],
            $this->decode($this->get("/api/admin/servers/{$this->holland->id}/test-account")),
            'off, with the default terms',
        );

        $saved = $this->putJson("/api/admin/servers/{$this->holland->id}/test-account", ['test_enabled' => true, 'test_traffic_mb' => '۱٬۰۰۰', 'test_duration_hours' => '6', 'test_on_hold' => false]);

        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertSame(['test_enabled' => true, 'test_traffic_mb' => 1000, 'test_duration_hours' => 6, 'test_on_hold' => false], $this->decode($saved)['test_account']);
        $this->holland->refresh();
        self::assertSame([true, 1000 * Traffic::MEGABYTE, 6, false], [$this->holland->test_enabled, $this->holland->test_traffic_bytes, $this->holland->test_duration_hours, $this->holland->test_on_hold]);
    }

    public function testEveryRefusalOfTheTermsComesAtOnce(): void
    {
        $refused = $this->putJson("/api/admin/servers/{$this->germany->id}/test-account", ['test_enabled' => true, 'test_traffic_mb' => 0, 'test_duration_hours' => 721, 'test_on_hold' => true]);

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['test_traffic_mb', 'test_duration_hours'], array_keys($this->decode($refused)['errors']));

        $unswitched = $this->unchecked()->putJson("/api/admin/servers/{$this->germany->id}/test-account", ['test_traffic_mb' => 500, 'test_duration_hours' => 24]);
        self::assertSame(['test_enabled', 'test_on_hold'], array_keys($this->decode($unswitched)['errors']), 'a missing switch is not read as off');
        self::assertSame(500 * Traffic::MEGABYTE, $this->germany->refresh()->test_traffic_bytes, 'nothing was written');
    }

    public function testAServersTermsAreTheOwnersAlone(): void
    {
        $_SESSION = [];
        $this->loginAsAgent($this->agentBot());

        self::assertSame(401, $this->get("/api/admin/servers/{$this->germany->id}/test-account")->getStatusCode());
        self::assertSame(404, $this->get("/api/agent/servers/{$this->germany->id}/test-account")->getStatusCode(), 'an agent has no such route');
    }

    public function testTheTestsAreListedNewestFirstWithWhereEachStands(): void
    {
        $this->testsOn();
        $plan = $this->plan([], $this->germany);
        $ali = $this->customer(['username' => 'ali', 'first_name' => 'Ali']);
        $sara = $this->customer(['telegram_id' => 5002, 'username' => 'sara', 'first_name' => 'Sara']);
        $nima = $this->customer(['telegram_id' => 5004, 'username' => 'nima', 'first_name' => 'Nima']);
        // Nima bought before his test: that purchase came before it, not after.
        Carbon::setTestNow('2026-10-08 11:00:00');
        $this->purchaseOrder($nima, $plan, $this->germany, ['status' => OrderStatus::Fulfilled]);
        Carbon::setTestNow('2026-10-08 12:00:00');
        $waiting = $this->take($ali);
        $ended = $this->take($sara);
        $ended->forceFill(['status' => SubscriptionStatus::Expired])->save();
        $removed = $this->take($this->customer(['telegram_id' => 5003, 'username' => 'reza', 'first_name' => 'Reza']));
        $this->service(ProvisioningService::class)->delete($removed);
        $this->germany->forceFill(['test_on_hold' => false, 'test_duration_hours' => 3])->save();
        $running = $this->take($nima);
        $making = TestAccount::query()->create(['user_id' => $this->customer(['telegram_id' => 5005, 'username' => 'omid', 'first_name' => 'Omid'])->id, 'server_id' => $this->germany->id, 'traffic_bytes' => 1, 'duration_hours' => 3, 'on_hold' => false]);
        // An hour later Ali bought; Sara was given a plan for free, which is no purchase with a price.
        Carbon::setTestNow('2026-10-08 13:00:00');
        $this->purchaseOrder($ali, $plan, $this->germany, ['status' => OrderStatus::Fulfilled]);
        $this->purchaseOrder($sara, $plan, $this->germany, ['status' => OrderStatus::Fulfilled, 'amount' => '0.00']);

        $page = $this->decode($this->get('/api/admin/tests'));

        self::assertSame(5, $page['meta']['total']);
        self::assertSame(['making', 'running', 'removed', 'ended', 'waiting'], array_column($page['tests'], 'state'));
        self::assertSame($making->id, $page['tests'][0]['id']);
        $first = TestAccount::query()->where('subscription_id', $waiting->id)->sole();
        self::assertSame([
            'id' => $first->id,
            'customer' => ['id' => $ali->id, 'name' => 'Ali', 'username' => 'ali', 'telegram_id' => self::TELEGRAM_ID, 'email' => null],
            'server' => ['id' => $this->germany->id, 'name' => 'آلمان'],
            'traffic_bytes' => 500 * Traffic::MEGABYTE,
            'duration_hours' => 24,
            'on_hold' => true,
            'state' => 'waiting',
            'subscription' => [
                'id' => $waiting->id,
                'name' => 'test_ali_1',
                'status' => 'active',
                'traffic' => ['limit' => 500 * Traffic::MEGABYTE, 'used' => 0],
                'starts_at' => null,
                'expires_at' => null,
            ],
            'bought_after' => true,
            'created_at' => '2026-10-08T12:00:00+00:00',
            'issued_at' => '2026-10-08T12:00:00+00:00',
            'ended_at' => null,
        ], $page['tests'][4]);
        self::assertSame([false, false, false, false, true], array_column($page['tests'], 'bought_after'));
        self::assertSame('2026-10-08T15:00:00+00:00', $page['tests'][1]['subscription']['expires_at'], 'three hours from the moment it was made');
        self::assertSame([null, null], [$page['tests'][0]['subscription'], $page['tests'][2]['subscription']], 'being made, and removed');

        $ids = fn(string $query): array => array_column($this->decode($this->get("/api/admin/tests?{$query}"))['tests'], 'id');
        self::assertSame([$first->id], $ids('status=waiting'));
        self::assertSame([TestAccount::query()->where('subscription_id', $running->id)->value('id')], $ids('status=running'));
        self::assertSame([TestAccount::query()->where('subscription_id', $ended->id)->value('id')], $ids('status=ended'));
        self::assertCount(1, $ids('status=removed'));
        self::assertSame([$making->id], $ids('status=making'));
        self::assertSame([$first->id], $ids('search=ali'));
        self::assertSame([$first->id], $ids("user={$ali->id}"));
        self::assertSame([$making->id], $ids("search=%23{$making->id}"));
    }

    public function testATestPastItsDeadlineHasEndedBeforeASyncSaysSo(): void
    {
        $this->testsOn();
        $this->germany->forceFill(['test_on_hold' => false, 'test_duration_hours' => 2])->save();
        $this->take($this->customer());
        Carbon::setTestNow('2026-10-08 15:00:00');

        self::assertSame(['ended'], array_column($this->decode($this->get('/api/admin/tests'))['tests'], 'state'));
        self::assertSame(1, $this->decode($this->get('/api/admin/tests?status=ended'))['meta']['total']);
        self::assertSame(0, $this->decode($this->get('/api/admin/tests?status=running'))['meta']['total']);
    }

    public function testADisabledTestIsSaidSoAndHasItsOwnTabEvenPastItsDeadline(): void
    {
        $this->testsOn();
        $waiting = $this->take($this->customer());
        $disabled = $this->take($this->customer(['telegram_id' => 5002, 'username' => 'sara', 'first_name' => 'Sara']));
        $disabled->forceFill(['status' => SubscriptionStatus::Disabled, 'disabled_at' => now(), 'expires_at' => now()->subHour()])->save();

        $tests = $this->decode($this->get('/api/admin/tests'))['tests'];
        self::assertSame([['disabled', $disabled->id, 'disabled'], ['waiting', $waiting->id, 'active']], array_map(
            static fn(array $test): array => [$test['state'], $test['subscription']['id'], $test['subscription']['status']],
            $tests,
        ));

        $tab = fn(string $state): array => array_column(array_column($this->decode($this->get("/api/admin/tests?status={$state}"))['tests'], 'subscription'), 'id');
        self::assertSame([$disabled->id], $tab('disabled'));
        self::assertSame([], $tab('ended'), 'switched off by support, not ended');
        self::assertSame([$waiting->id], $tab('waiting'));
    }

    public function testAPurchaseCountsAfterTheTestFromWhenItWasDelivered(): void
    {
        $this->testsOn();
        $plan = $this->plan([], $this->germany);
        $ali = $this->customer(['username' => 'ali', 'first_name' => 'Ali']);
        $sara = $this->customer(['telegram_id' => 5002, 'username' => 'sara', 'first_name' => 'Sara']);
        // Both opened a card order half an hour before their test: Sara's was approved before the test, Ali's after it.
        Carbon::setTestNow('2026-10-08 11:30:00');
        $card = $this->purchaseOrder($ali, $plan, $this->germany, ['status' => OrderStatus::Pending]);
        $this->purchaseOrder($sara, $plan, $this->germany, ['status' => OrderStatus::Fulfilled, 'fulfilled_at' => '2026-10-08 11:45:00']);
        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->take($ali);
        $this->take($sara);
        Carbon::setTestNow('2026-10-08 12:30:00');
        $card->forceFill(['status' => OrderStatus::Fulfilled, 'fulfilled_at' => now()])->save();

        $tests = $this->decode($this->get('/api/admin/tests'))['tests'];

        self::assertSame(['sara' => false, 'ali' => true], array_combine(array_column(array_column($tests, 'customer'), 'username'), array_column($tests, 'bought_after')));
    }

    public function testATestWhoseServerWasDeletedNamesNone(): void
    {
        $this->testsOn();
        $this->service(ProvisioningService::class)->delete($this->take($this->customer()));

        self::assertSame(204, $this->deleteJson("/api/admin/servers/{$this->germany->id}")->getStatusCode());

        $tests = $this->decode($this->get('/api/admin/tests'))['tests'];
        self::assertSame([[null, 'removed']], array_map(static fn(array $test): array => [$test['server'], $test['state']], $tests));
    }

    public function testEveryServerWithItsTermsAndWhetherItGivesATestNow(): void
    {
        $this->germany->forceFill(['test_traffic_bytes' => 2 * Traffic::GIGABYTE, 'test_duration_hours' => 48])->save();
        $this->fakeServer('فرانسه', ['test_enabled' => true, 'is_active' => false]);

        $servers = $this->decode($this->get('/api/admin/tests/servers'))['servers'];

        self::assertSame(['آلمان', 'هلند', 'فرانسه'], array_column($servers, 'name'));
        self::assertSame(
            ['id' => $this->germany->id, 'name' => 'آلمان', 'test_enabled' => true, 'test_traffic_mb' => 2048, 'test_duration_hours' => 48, 'test_on_hold' => true, 'available' => true],
            $servers[0],
        );
        self::assertSame([false, false], [$servers[1]['available'], $servers[2]['available']], 'tests off; switched off');
    }

    public function testAnAgentSeesTheirShopsTestsAndEveryServersTerms(): void
    {
        $this->testsOn();
        $this->take($this->customer(['username' => 'main']));
        $bot = $this->agentBot(traffic: 1);
        CurrentBot::run($bot, function (): void {
            $this->testsOn();
            $this->take($this->customer(['telegram_id' => 6001, 'username' => 'theirs']));
        });
        $this->traffic($bot, 0);
        $_SESSION = [];
        $this->loginAsAgent($bot);

        $tests = $this->decode($this->get('/api/agent/tests'))['tests'];
        self::assertSame(['theirs'], array_column(array_column($tests, 'customer'), 'username'));

        $servers = $this->decode($this->get('/api/agent/tests/servers'))['servers'];
        self::assertSame(['آلمان', 'هلند'], array_column($servers, 'name'));
        self::assertFalse($servers[0]['available'], "the agent's pool cannot cover a test now");
    }

    public function testTheOwnerSeesTheTestsAndServersOfTheAgentsShopTheyOpen(): void
    {
        $this->testsOn();
        $this->take($this->customer(['username' => 'main']));
        $bot = $this->agentBot(traffic: 1);
        CurrentBot::run($bot, function (): void {
            $this->testsOn();
            $this->take($this->customer(['telegram_id' => 6001, 'username' => 'theirs']));
        });
        $this->openShop($bot);
        $available = fn(): array => array_column($this->decode($this->get('/api/admin/tests/servers'))['servers'], 'available', 'name');

        self::assertSame(['theirs'], array_column(array_column($this->decode($this->get('/api/admin/tests'))['tests'], 'customer'), 'username'));
        self::assertSame(['آلمان' => true, 'هلند' => false], $available(), "the agent's pool still covers a test");

        $this->traffic($bot, 0);
        self::assertSame(['آلمان' => false, 'هلند' => false], $available(), 'and no longer');
    }

    public function testTheRulesAreAGroupOfTheBotSettings(): void
    {
        $saved = $this->putJson('/api/admin/bot/settings/test_accounts', [
            'test_enabled' => true,
            'test_per_user' => '۲',
            'test_period_days' => '30',
            'test_daily_limit' => '1,000',
            'test_newcomers_only' => true,
            'test_cleanup' => false,
            'test_cleanup_hours' => 0,
            'test_unused_days' => 0,
        ]);

        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $settings = $this->decode($saved)['settings'];
        self::assertSame([true, 2, 30, 1000, true, false, 0, 0], [
            $settings['test_enabled'],
            $settings['test_per_user'],
            $settings['test_period_days'],
            $settings['test_daily_limit'],
            $settings['test_newcomers_only'],
            $settings['test_cleanup'],
            $settings['test_cleanup_hours'],
            $settings['test_unused_days'],
        ]);

        $refused = $this->putJson('/api/admin/bot/settings/test_accounts', ['test_enabled' => true, 'test_per_user' => 0, 'test_period_days' => 366, 'test_daily_limit' => 0, 'test_newcomers_only' => false, 'test_cleanup' => true, 'test_cleanup_hours' => 721, 'test_unused_days' => 7]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['test_per_user', 'test_period_days', 'test_cleanup_hours'], array_keys($this->decode($refused)['errors']));
    }

    public function testTheSubscriptionsScreenMarksAndFiltersTheTests(): void
    {
        $this->testsOn();
        $ali = $this->customer();
        $test = $this->take($ali);
        $sold = $this->subscription($ali, $this->plan([], $this->germany), $this->germany, 'ali_1');

        $rows = $this->decode($this->get('/api/admin/subscriptions'))['subscriptions'];
        self::assertSame([[$sold->id, false], [$test->id, true]], array_map(static fn(array $row): array => [$row['id'], $row['is_test']], $rows));
        self::assertSame([$test->id], array_column($this->decode($this->get('/api/admin/subscriptions?kind=test'))['subscriptions'], 'id'));
        self::assertSame([$sold->id], array_column($this->decode($this->get('/api/admin/subscriptions?kind=regular'))['subscriptions'], 'id'));
    }

    /** A test taken on Germany by the customer, as the bot takes one. */
    private function take(User $customer): Subscription
    {
        return $this->service(TestAccounts::class)->issue($customer, $this->germany->id);
    }

    /** The shop's test accounts switched on: one test a customer for good, no cap, anyone, cleaned up after a day. */
    private function testsOn(): void
    {
        $this->botSettings('test_accounts', [
            'test_enabled' => true,
            'test_per_user' => 1,
            'test_period_days' => 0,
            'test_daily_limit' => 0,
            'test_newcomers_only' => false,
            'test_cleanup' => true,
            'test_cleanup_hours' => 24,
            'test_unused_days' => 7,
        ]);
    }
}
