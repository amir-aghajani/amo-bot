<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\ValidationException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Exceptions\NoServerAvailableException;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Services\ServerSelector;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\DTO\Capabilities;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Exceptions\PanelApiException;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Models\ServerInbound;
use App\Modules\Providers\Services\ProviderErrorPresenter;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ClientNaming;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletService;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;

/**
 * Plan → server choice → paid order → one panel client on every inbound of the chosen entry, named after the customer,
 * whose term starts at the first connection — and what a renewal of it sends the panel, a link rotation, a read-back.
 */
final class PurchaseProvisioningTest extends DatabaseTestCase
{
    private User $customer;
    private Plan $plan;
    private Server $berlin;
    private Server $paris;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePanel();

        $this->customer = $this->customer(['username' => 'customer', 'telegram_id' => 42]);
        $this->berlin = $this->fakeServer('Berlin');
        $this->paris = $this->fakeServer('Paris');
        $this->inbound($this->berlin, '1', ['remark' => 'Reality']);
        $this->inbound($this->berlin, '2', ['remark' => 'Trojan']);
        $this->inbound($this->berlin, '3', ['remark' => 'Old', 'is_selectable' => false]);
        $this->inbound($this->paris, '7', ['remark' => 'Paris WS']);
        $this->plan = $this->plan(['name' => 'Multi', 'price' => '50000.00', 'traffic_gb' => 20], [$this->berlin, $this->paris]);
    }

    public function testCustomerSeesEveryAvailableServerByName(): void
    {
        $choices = $this->service(ServerSelector::class)->choices($this->plan);

        self::assertSame(['Berlin', 'Paris'], array_map(static fn(array $c): string => $c['server']->name, $choices));
        self::assertSame(['1', '2'], $choices[0]['inbounds']->pluck('remote_key')->all(), 'whole server = its selectable inbounds only');

        $this->paris->forceFill(['is_active' => false])->save();
        self::assertSame(['Berlin'], array_map(static fn(array $c): string => $c['server']->name, $this->service(ServerSelector::class)->choices($this->plan)));
    }

    public function testAPurchaseOnAWholeServerEntryMakesOneClientOnAllItsInbounds(): void
    {
        $this->service(WalletService::class)->credit($this->customer, '100000', 'test');
        $order = $this->service(OrderService::class)->openPurchase($this->customer, $this->plan, $this->berlin);

        $result = $this->service(PaymentService::class)->createForOrder($order, $this->walletMethod());

        $order->refresh();
        self::assertSame(OrderStatus::Fulfilled, $order->status);
        self::assertCount(1, FakeProvider::$created, 'one client per purchase');
        self::assertSame(['1', '2'], FakeProvider::$created[0]['inbounds'], 'attached to both selectable inbounds, not the disabled/hidden one');
        $spec = FakeProvider::$created[0]['spec'];
        self::assertSame($this->plan->trafficBytes(), $spec->totalBytes);
        self::assertSame(30 * 86400, $spec->expiry->pendingSeconds(), 'the term starts at the first connection: the panel keeps the clock');
        self::assertSame(1, $spec->ipLimit);
        self::assertSame(42, $spec->telegramId);
        self::assertSame('customer_1', $spec->name, 'named after the customer: their username and purchase number');
        self::assertSame('42 | customer', $spec->comment);

        $subscription = $order->subscription;
        self::assertNotNull($subscription);
        self::assertSame('customer_1', $subscription->remote_name);
        self::assertSame('https://fake.test/sub/sub-1', $subscription->subscription_url, 'the link the panel served is stored: no panel call is needed to hand it out again');
        self::assertSame(30, $subscription->duration_days);
        self::assertNull($subscription->expires_at, 'no deadline until the customer connects');
        self::assertNull($subscription->starts_at);
        self::assertTrue($subscription->awaitsFirstUse());
        self::assertSame($this->berlin->id, $subscription->server_id);
        self::assertSame('50000.00', $this->customer->balance());
        self::assertTrue($result['initiation']->isInstant(), 'the wallet settles at once');
    }

    public function testAPanelWithoutInboundsSellsTheWholeServer(): void
    {
        FakeProvider::$capabilities = new Capabilities(inbounds: false);
        ServerInbound::query()->delete();

        $choices = $this->service(ServerSelector::class)->choices($this->plan);
        self::assertSame(['Berlin', 'Paris'], array_map(static fn(array $c): string => $c['server']->name, $choices), 'nothing to pick by, still sellable');
        self::assertTrue($choices[0]['inbounds']->isEmpty());

        $this->buy($this->customer, $this->plan, $this->berlin);

        self::assertSame([], FakeProvider::$created[0]['inbounds'], 'the client is made with no inbound keys');
        self::assertSame('https://fake.test/sub/sub-1', Subscription::query()->firstOrFail()->subscription_url);
    }

    public function testAPlanWithoutATermNeverExpires(): void
    {
        $forever = $this->plan(['name' => 'دائمی', 'duration_days' => 0], $this->berlin);

        $subscription = $this->bought($forever);

        $expiry = FakeProvider::$created[0]['spec']->expiry;
        self::assertSame([null, null], [$expiry->deadline(), $expiry->pendingSeconds()], 'never: no deadline, no term');
        self::assertSame(0, $subscription->duration_days);
        self::assertFalse($subscription->awaitsFirstUse());
    }

    public function testAPanelThatCannotWaitForTheFirstConnectionAnswersWithADeadlineAndTheServiceTakesIt(): void
    {
        Carbon::setTestNow('2026-09-19 12:00:00');
        FakeProvider::$defersClock = false;

        $subscription = $this->bought();

        self::assertSame(30 * 86400, FakeProvider::$created[0]['spec']->expiry->pendingSeconds(), 'asked for a term from the first connection');
        self::assertSame(
            ['2026-10-19 12:00:00', '2026-09-19 12:00:00'],
            [$subscription->expires_at?->format('Y-m-d H:i:s'), $subscription->starts_at?->format('Y-m-d H:i:s')],
            'the deadline the panel set itself, its term before it',
        );
        self::assertFalse($subscription->awaitsFirstUse());
    }

    public function testAServiceWhoseCompletionFailsIsTakenBackOffThePanelAndLeavesNoRow(): void
    {
        // The order's completion runs in the transaction that writes the service: both land, or neither.
        $order = $this->purchaseOrder($this->customer, $this->plan, $this->berlin, ['status' => OrderStatus::Processing]);

        try {
            $this->service(ProvisioningService::class)->provision($order, static fn() => throw new \LogicException('The order left processing meanwhile.'));
            self::fail('a completion that failed must surface');
        } catch (\LogicException) {
        }

        self::assertCount(1, FakeProvider::$created);
        self::assertSame([['server' => $this->berlin->id, 'name' => 'customer_1']], FakeProvider::$deleted, 'the client made for it is taken back, so a retry leaves no stray behind');
        self::assertSame(0, Subscription::query()->count());
        self::assertNull($order->refresh()->subscription_id);
    }

    public function testSyncAdoptsTheDeadlineThePanelSetAtTheFirstConnection(): void
    {
        Carbon::setTestNow('2026-09-19 12:00:00');
        $subscription = $this->bought();
        $provisioning = $this->service(ProvisioningService::class);

        $provisioning->inspect($subscription);
        self::assertTrue($subscription->awaitsFirstUse(), 'still waiting: the panel reports the term, not a date');

        // The customer connected at some point: the panel now has a deadline.
        FakeProvider::put($this->berlin, new ClientInfo(name: 'customer_1', enabled: true, totalBytes: $this->plan->trafficBytes(), expiry: Expiry::at(new \DateTimeImmutable('2026-10-25 09:00:00')), subscriptionUrl: $subscription->subscription_url));
        $provisioning->inspect($subscription);

        self::assertSame('2026-10-25 09:00:00', $subscription->expires_at?->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-25 09:00:00', $subscription->starts_at?->format('Y-m-d H:i:s'), 'the term before the deadline');
        self::assertSame(30, $subscription->duration_days);
    }

    public function testInspectReportsPresenceAndNullOnceTheClientIsGone(): void
    {
        $subscription = $this->bought();
        $provisioning = $this->service(ProvisioningService::class);

        FakeProvider::$online = ['customer_1'];
        $client = $provisioning->inspect($subscription, presence: true);
        self::assertNotNull($client);
        self::assertTrue($client->online);
        self::assertNull($provisioning->inspect($subscription)?->online, 'not asked, not told');
        self::assertSame(SubscriptionStatus::Active, $subscription->status);

        FakeProvider::$clients = [];
        self::assertNull($provisioning->inspect($subscription), 'the panel no longer has it');
        self::assertSame(SubscriptionStatus::Deleted, $subscription->refresh()->status, 'and the row says so');
    }

    public function testRotatingTheLinkStoresWhatThePanelServesNow(): void
    {
        $subscription = $this->bought();

        $this->service(ProvisioningService::class)->rotateLink($subscription);

        self::assertSame(['customer_1'], FakeProvider::$rotated);
        self::assertSame('https://fake.test/sub/sub-rotated-1', $subscription->refresh()->subscription_url, 'the new link, from the panel\'s answer');
    }

    public function testAPanelThatCannotRotateCredentialsKeepsTheLink(): void
    {
        $subscription = $this->bought();
        $link = $subscription->subscription_url;
        FakeProvider::$capabilities = new Capabilities(linkRotation: false);

        try {
            $this->service(ProvisioningService::class)->rotateLink($subscription);
            self::fail('a panel that cannot rotate must say so');
        } catch (ValidationException $e) {
            self::assertSame(['status' => [ProvisioningService::ROTATE_UNSUPPORTED]], $e->errors(), 'refused before the panel is asked');
        }

        self::assertSame([], FakeProvider::$rotated);
        self::assertSame($link, $subscription->refresh()->subscription_url);
    }

    public function testOnlyARunningServiceGetsANewLink(): void
    {
        $subscription = $this->bought();
        $subscription->forceFill(['status' => SubscriptionStatus::Expired])->save();

        try {
            $this->service(ProvisioningService::class)->rotateLink($subscription);
            self::fail('an ended service keeps its link');
        } catch (ValidationException $e) {
            self::assertSame(['status' => [ProvisioningService::ROTATE_INACTIVE]], $e->errors());
        }

        self::assertSame([], FakeProvider::$rotated);
    }

    public function testARenewalOfAServiceNotStartedYetLengthensItsTermStillFromTheFirstConnection(): void
    {
        $subscription = $this->bought();

        $this->renew($subscription);

        $sent = FakeProvider::lastUpdate('customer_1');
        self::assertSame(60 * 86400, $sent->expiry->pendingSeconds(), 'the plan\'s days on the term, the clock still waiting');
        self::assertSame(2 * $this->plan->trafficBytes(), $sent->totalBytes, 'and its traffic on top');
        self::assertSame([], FakeProvider::$trafficReset, 'nothing is taken away while the period has not passed');
        self::assertSame([60, true], [$subscription->duration_days, $subscription->awaitsFirstUse()]);
    }

    public function testARenewalOfARunningServicePutsThePlansDaysOnTopOfTheDeadlineItsPanelHas(): void
    {
        Carbon::setTestNow('2026-09-19 12:00:00');
        $subscription = $this->bought();
        $planBytes = $this->plan->trafficBytes();
        // The panel is the truth: the customer connected ten days ago and it started the month then.
        FakeProvider::put($this->berlin, new ClientInfo(name: 'customer_1', enabled: true, totalBytes: $planBytes, expiry: Expiry::at(now()->addDays(20)->toDateTimeImmutable()), subscriptionUrl: $subscription->subscription_url));

        $this->renew($subscription);

        $sent = FakeProvider::lastUpdate('customer_1');
        self::assertSame('2026-11-08 12:00:00', $sent->expiry->deadline()?->format('Y-m-d H:i:s'));
        self::assertSame(['2026-11-08 12:00:00', 60, 2 * $planBytes], [$subscription->expires_at?->format('Y-m-d H:i:s'), $subscription->duration_days, $subscription->traffic_limit_bytes]);
        self::assertSame(['2026-10-09 12:00:00', $planBytes], [$subscription->period_ends_at?->format('Y-m-d H:i:s'), $subscription->next_period_bytes], 'what the paid period leaves unused goes when it ends (not carried, by default)');
        self::assertSame([42, '42 | customer'], [$sent->telegramId, $sent->comment], 'a renewal re-sends the account the client points back at');
    }

    public function testARenewalOfAnExpiredServiceStartsANewTermAndAFreshCountAtItsNextConnection(): void
    {
        Carbon::setTestNow('2026-09-19 12:00:00');
        $subscription = $this->bought();
        $planBytes = $this->plan->trafficBytes();
        FakeProvider::put($this->berlin, new ClientInfo(name: 'customer_1', enabled: true, downloadBytes: 5, totalBytes: $planBytes, expiry: Expiry::at(now()->subDay()->toDateTimeImmutable()), subscriptionUrl: $subscription->subscription_url));

        $this->renew($subscription);

        self::assertSame(['customer_1'], FakeProvider::$trafficReset);
        $sent = FakeProvider::lastUpdate('customer_1');
        self::assertSame(30 * 86400, $sent->expiry->pendingSeconds());
        self::assertSame($planBytes, $sent->totalBytes, 'the plan\'s traffic, what was left not carried');
        self::assertSame([SubscriptionStatus::Active, true, null, 30, 0], [$subscription->status, $subscription->awaitsFirstUse(), $subscription->starts_at, $subscription->duration_days, $subscription->usedBytes()]);
        self::assertNull($subscription->period_ends_at, 'nothing queued behind a period that is over');
    }

    public function testAClientIsNamedByUsernameAndPurchaseNumberAcrossServers(): void
    {
        $this->buy($this->customer, $this->plan, $this->berlin);
        $this->buy($this->customer, $this->plan, $this->paris);
        $this->buy($this->customer, $this->plan, $this->berlin);

        self::assertSame(['customer_1', 'customer_2', 'customer_3'], $this->names(), 'one count per customer, whichever server');
        self::assertSame(['customer_1', 'customer_2', 'customer_3'], Subscription::query()->orderBy('id')->pluck('remote_name')->all());
    }

    public function testNamelessCustomersShareOneUserCounter(): void
    {
        $nobody = $this->customer(['telegram_id' => 7001]);
        $another = $this->customer(['telegram_id' => 7002, 'username' => '']);

        $this->buy($nobody, $this->plan, $this->berlin);
        $this->buy($this->customer, $this->plan, $this->berlin);
        $this->buy($another, $this->plan, $this->berlin);
        $this->buy($nobody, $this->plan, $this->paris);

        self::assertSame(['USER_1', 'customer_1', 'USER_2', 'USER_3'], $this->names(), 'USER_n counts every nameless purchase, not the customer\'s own');
        self::assertSame('7001 | USER', FakeProvider::$created[0]['spec']->comment, 'the comment still points at the account');
        self::assertSame('7002 | USER', FakeProvider::$created[2]['spec']->comment);
    }

    public function testAUsernameThatMovedToAnotherAccountDoesNotReuseATakenName(): void
    {
        $this->buy($this->customer, $this->plan, $this->berlin);
        $this->customer->forceFill(['username' => 'renamed'])->save();
        $newcomer = $this->customer(['telegram_id' => 43, 'username' => 'customer']);

        $this->buy($newcomer, $this->plan, $this->berlin);
        $this->buy($newcomer, $this->plan, $this->paris);

        self::assertSame(['customer_1', 'customer_2', 'customer_3'], $this->names(), 'their first is customer_2 on Berlin (customer_1 is taken there, its number spent), their second customer_3');
    }

    public function testADeletedServicesNumberIsNeverHandedOutAgain(): void
    {
        $this->buy($this->customer, $this->plan, $this->berlin);
        $second = $this->buy($this->customer, $this->plan, $this->paris)->subscription;
        self::assertNotNull($second);
        $this->service(ProvisioningService::class)->delete($second);

        $this->buy($this->customer, $this->plan, $this->berlin);

        self::assertSame(['customer_1', 'customer_2', 'customer_3'], $this->names(), 'customer_2 is gone, its number is not');
    }

    public function testANameAServiceCarriesOnThatServerIsSkippedThere(): void
    {
        // Services the counter does not know of (made before it, or by hand): each name is skipped where it is taken.
        $this->subscription($this->customer, $this->plan, $this->berlin, 'customer_1');
        $this->subscription($this->customer, $this->plan, $this->paris, 'customer_3');

        $this->buy($this->customer, $this->plan, $this->berlin);
        $this->buy($this->customer, $this->plan, $this->paris);

        self::assertSame(['customer_2', 'customer_4'], $this->names(), 'customer_1 is taken on Berlin, customer_3 on Paris');
    }

    public function testANameAnotherBotsServiceCarriesOnThatPanelIsTakenToo(): void
    {
        // The same @customer, a customer of an agent's bot this time — their own counter, but the panel's names are one.
        $bot = $this->agentBot();
        $this->subscription($this->customer, $this->plan, $this->berlin, 'customer_1');
        $agentsCustomer = CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => 77, 'username' => 'customer']));

        self::assertSame('customer_2', CurrentBot::run($bot, fn(): string => $this->service(ClientNaming::class)->next($agentsCustomer, $this->berlin)), "asked in the agent's shop, the main bot's customer_1 still counts");
    }

    public function testTheCommentIsTheTelegramIdAndTheUsernameOrUserAndTheAgentsBot(): void
    {
        $naming = $this->service(ClientNaming::class);
        self::assertSame('42 | customer', $naming->comment($this->customer));
        self::assertSame('9 | USER', $naming->comment(new User(['telegram_id' => 9])));

        $bot = $this->agentBot();
        $agentsCustomer = CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => 77, 'username' => 'reza']));
        self::assertSame('77 | reza | @agent_shop_bot', $naming->comment($agentsCustomer), "a customer of an agent's bot says whose");
    }

    public function testPinnedEntryUsesExactlyThePinnedInbounds(): void
    {
        $trojan = ServerInbound::query()->where('remote_key', '2')->firstOrFail();
        $pinned = $this->plan(['name' => 'Trojan only']);
        $this->planEntry($pinned, $this->berlin, [$trojan->id]);

        $resolved = $this->service(ServerSelector::class)->resolve($pinned, null);

        self::assertSame('Berlin', $resolved['server']->name, 'a single entry needs no explicit choice');
        self::assertSame(['2'], $resolved['inbounds']->pluck('remote_key')->all());
    }

    public function testChoiceIsRequiredAmongSeveralServersAndMustBeOneOfThem(): void
    {
        $selector = $this->service(ServerSelector::class);

        try {
            $selector->resolve($this->plan, null);
            self::fail('expected NoServerAvailableException');
        } catch (NoServerAvailableException $e) {
            self::assertStringContainsString('چند سرور', $e->getMessage());
        }

        $other = $this->fakeServer('Tokyo');
        try {
            $selector->resolve($this->plan, $other->id);
            self::fail('expected NoServerAvailableException');
        } catch (NoServerAvailableException $e) {
            self::assertStringContainsString("سرور #{$other->id}", $e->getMessage());
        }

        $this->berlin->forceFill(['is_active' => false])->save();
        $this->paris->forceFill(['is_active' => false])->save();
        try {
            $selector->resolve($this->plan, null);
            self::fail('expected NoServerAvailableException');
        } catch (NoServerAvailableException $e) {
            self::assertStringContainsString('«Multi»', $e->getMessage());
            self::assertDoesNotMatchRegularExpression('/[a-zA-Z]/', str_replace('Multi', '', $e->getMessage()), 'Persian, since the order note shows it');
        }
    }

    public function testServersWithoutSubscriptionLinksAreNotOffered(): void
    {
        $this->berlin->forceFill(['serves_subscriptions' => false])->save();
        self::assertSame(['Paris'], array_map(static fn(array $c): string => $c['server']->name, $this->service(ServerSelector::class)->choices($this->plan)));

        $this->berlin->forceFill(['serves_subscriptions' => null])->save();
        self::assertSame(['Paris'], array_map(static fn(array $c): string => $c['server']->name, $this->service(ServerSelector::class)->choices($this->plan)), 'never checked = not sold');
    }

    public function testAPanelThatStoppedServingSubscriptionsSinceTheLastCheckFailsTheOrderInsteadOfDeliveringNothing(): void
    {
        FakeProvider::$subscriptionBase = null;

        $order = $this->checkout($this->customer, $this->plan, $this->berlin);

        self::assertSame(OrderStatus::Failed, $order->status);
        self::assertStringContainsString('سرور اشتراک', (string) $order->notes);
        self::assertStringContainsString('«Berlin»', (string) $order->notes);
        self::assertSame([], FakeProvider::$created, 'no client was made for a link that cannot exist');
        self::assertNull($order->subscription);
    }

    public function testAFailedDeliveryKeepsAWordForEveryoneAndTheOwnersDiagnosisApart(): void
    {
        FakeProvider::$refusing = [$this->berlin->id => ['createClient']];
        $refusal = new PanelApiException('The fake panel refused createClient.', 200, 'refused by the test');
        $bot = $this->agentBot();
        $buy = fn(): Order => $this->checkout($this->customer(['telegram_id' => 77, 'username' => 'reza']), $this->plan(['name' => 'Agent plan', 'traffic_gb' => 20], $this->berlin), $this->berlin);

        foreach (["an agent's shop" => CurrentBot::run($bot, $buy), "the main bot's" => $buy()] as $shop => $order) {
            self::assertSame(OrderStatus::Failed, $order->status, $shop);
            self::assertSame(ProviderErrorPresenter::summary($refusal), $order->notes, "{$shop}: what anyone of the shop reads — nothing of the panel");
            self::assertSame(ProviderErrorPresenter::describe($refusal), $order->diagnosis, "{$shop}: the owner's reading of what it said, apart");
        }
    }

    public function testAClientThePanelGaveNoLinkIsTakenBackAndTheOrderFails(): void
    {
        FakeProvider::$mintsSubId = false;

        $order = $this->checkout($this->customer, $this->plan, $this->berlin);

        self::assertSame(OrderStatus::Failed, $order->status);
        self::assertStringContainsString('لینک اشتراک نساخت', (string) $order->notes);
        self::assertCount(1, FakeProvider::$created, 'the client was created…');
        self::assertSame([['server' => $this->berlin->id, 'name' => 'customer_1']], FakeProvider::$deleted, '…and deleted again, so a retry does not leave a stray behind');
        self::assertSame(0, Subscription::query()->count());
    }

    public function testAClientThatCannotBeTakenBackIsLeftToTheAdminInTheLog(): void
    {
        FakeProvider::$mintsSubId = false;
        FakeProvider::$refusing = [$this->berlin->id => ['deleteClient']];
        $logs = $this->logs();

        $order = $this->checkout($this->customer, $this->plan, $this->berlin);

        self::assertSame(OrderStatus::Failed, $order->status);
        self::assertStringContainsString('لینک اشتراک نساخت', (string) $order->notes, 'the order says why it failed, not why the clean-up did');
        self::assertTrue($logs->hasErrorThatContains('Could not take back client customer_1 on Berlin; delete it by hand'));
    }

    public function testFullServersAreNotOffered(): void
    {
        $this->berlin->forceFill(['capacity' => 0])->save();

        self::assertSame(['Paris'], array_map(static fn(array $c): string => $c['server']->name, $this->service(ServerSelector::class)->choices($this->plan)));
    }

    public function testAServersRoomIsCountedAcrossEveryBotsServices(): void
    {
        $this->berlin->forceFill(['capacity' => 1])->save();
        CurrentBot::run($this->agentBot(), fn(): Subscription => $this->subscription($this->customer(['telegram_id' => 77, 'username' => 'reza']), $this->plan(['name' => 'Agent plan']), $this->berlin, 'reza_1'));

        self::assertSame(['Paris'], array_map(static fn(array $c): string => $c['server']->name, $this->service(ServerSelector::class)->choices($this->plan)), "the agent's bot's service fills Berlin for the main bot too");
    }

    /** The customer's service, bought from the wallet on Berlin — of the plan sold there unless another is given. */
    private function bought(?Plan $plan = null): Subscription
    {
        return $this->buy($this->customer, $plan ?? $this->plan, $this->berlin)->subscription ?? self::fail('The purchase made no service.');
    }

    /** A wallet-paid purchase of the plan on the server, delivered or not: the order as it was left. */
    private function checkout(User $user, Plan $plan, Server $server): Order
    {
        $this->service(WalletService::class)->credit($user, $plan->price, 'test');
        $order = $this->service(OrderService::class)->openPurchase($user, $plan, $server);
        $this->service(PaymentService::class)->createForOrder($order, $this->walletMethod());

        return $order->refresh();
    }

    /** @return list<string> The panel names of every client created so far, in order. */
    private function names(): array
    {
        return array_map(static fn(array $c): string => $c['spec']->name, FakeProvider::$created);
    }
}
