<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database\Lease;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Enums\ConnectionFailure;
use App\Modules\Providers\Exceptions\ConnectionException;
use App\Modules\Providers\Exceptions\PanelApiException;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Services\ProviderErrorPresenter;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\ServiceBusyException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\SubscriptionActions;
use App\Modules\Subscriptions\Tasks\SyncSubscriptionsTask;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Support\Input;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * The subscriptions screen: the list with its tabs (the "expiring" one the dashboard links to), server
 * filter and search, and the modal's operations — read the panel again, switch the client off or on, move
 * it to another server with what is left of it, delete it (a panel out of reach may be left out of a
 * delete or a move, on the admin's word). The customer hears about a switch, gets the new link after a
 * move, and hears about a delete only when it took away a service they could use. A service another change
 * holds is busy (409); a panel that fails is a 502 in words for whoever reads the screen.
 */
final class AdminSubscriptionsApiTest extends HttpTestCase
{
    private User $ali;
    private Server $server;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-20 12:00:00');
        $this->fakePanel();
        $this->telegram();
        $this->withoutQr();
        $this->loginAsAdmin();

        $this->ali = $this->customer(['telegram_id' => 1001, 'username' => 'ali_r']);
        $this->server = $this->fakeServer();
        $this->plan = $this->plan();
    }

    public function testTheListFiltersSearchesAndCountsTheExpiringOnes(): void
    {
        $sara = $this->customer(['telegram_id' => 1002, 'first_name' => 'Sara']);
        $running = $this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1', ['expires_at' => now()->addDays(20)]);
        $ending = $this->subscription($this->ali, $this->plan, $this->server, 'ali_r_2', ['expires_at' => now()->addDays(2)]);
        $waiting = $this->subscription($sara, $this->plan, $this->server, 'USER_1', ['starts_at' => null, 'expires_at' => null]);
        $expired = $this->subscription($sara, $this->plan, $this->server, 'USER_2', ['status' => SubscriptionStatus::Expired, 'expires_at' => now()->subDay()]);

        $data = $this->decode($this->get('/api/admin/subscriptions'));
        self::assertSame([$expired->id, $waiting->id, $ending->id, $running->id], array_column($data['subscriptions'], 'id'), 'newest first');
        self::assertSame(4, $data['meta']['total']);
        self::assertSame(1, $data['meta']['expiring'], 'active and ending within the dashboard\'s three days');

        $row = $data['subscriptions'][2];
        self::assertSame('ali_r_2', $row['name']);
        self::assertSame('https://fake.test/sub/ali_r_2', $row['link']);
        self::assertSame('active', $row['status']);
        self::assertSame(['id' => $this->ali->id, 'name' => 'Ali', 'username' => 'ali_r', 'telegram_id' => 1001, 'email' => null], $row['user']);
        self::assertSame(['id' => $this->plan->id, 'name' => 'یک‌ماهه'], $row['plan']);
        self::assertSame(['id' => $this->server->id, 'name' => 'آلمان'], $row['server']);
        self::assertSame(['limit' => 30 * Traffic::GIGABYTE, 'used' => 0], $row['traffic']);
        self::assertSame(30, $row['duration_days']);
        self::assertSame(['sync' => true, 'disable' => true, 'enable' => false, 'move' => true, 'delete' => true, 'extend' => true], $row['actions']);
        self::assertNull($data['subscriptions'][1]['expires_at'], 'waiting for the first connection: no deadline yet');
        self::assertSame(['sync' => true, 'disable' => false, 'enable' => false, 'move' => false, 'delete' => true, 'extend' => false], $data['subscriptions'][0]['actions'], 'an expired one needs a renewal, not a switch');

        $ids = fn(string $query): array => array_column($this->decode($this->get("/api/admin/subscriptions?{$query}"))['subscriptions'], 'id');
        self::assertSame([$ending->id], $ids('status=expiring'), 'the one the dashboard\'s card counts');
        self::assertSame([$expired->id], $ids('status=expired'));
        self::assertSame([$waiting->id, $ending->id, $running->id], $ids('status=active'));
        self::assertSame([$ending->id], $ids('search=ali_r_2'), 'by the name on the panel');
        self::assertSame([$ending->id, $running->id], $ids('search=@ali_r'), 'by the customer\'s handle');
        self::assertSame([$expired->id, $waiting->id], $ids('search=1002'), 'by Telegram id');
        self::assertSame([$waiting->id], $ids('search=' . rawurlencode('https://fake.test/sub/USER_1')), 'by a link the customer pasted');
        self::assertSame([$running->id], $ids("search=%23{$running->id}"), 'by "#id"');
    }

    public function testAHashAndANumberIsTheServiceNumberedSoAlone(): void
    {
        $service = $this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1');
        // A customer whose Telegram id is the service's number, with a service of theirs.
        $namesake = $this->subscription($this->customer(['telegram_id' => $service->id]), $this->plan, $this->server, 'USER_1');

        $ids = fn(string $search): array => array_column($this->decode($this->get('/api/admin/subscriptions?search=' . rawurlencode($search)))['subscriptions'], 'id');

        self::assertSame([$namesake->id, $service->id], $ids((string) $service->id), "a bare number: the service's own, or its customer's Telegram id");
        self::assertSame([$service->id], $ids("#{$service->id}"), '«#n»: the service numbered n, as every list takes it');
        self::assertSame([], $ids('#999999'));
    }

    public function testTheListNarrowsToOneServer(): void
    {
        $other = $this->fakeServer('هلند');
        $here = $this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1');
        $there = $this->subscription($this->ali, $this->plan, $other, 'ali_r_2');
        $ended = $this->subscription($this->ali, $this->plan, $other, 'ali_r_3', ['status' => SubscriptionStatus::Expired, 'expires_at' => now()->subDay()]);

        $ids = fn(string $query): array => array_column($this->decode($this->get("/api/admin/subscriptions?{$query}"))['subscriptions'], 'id');
        self::assertSame([$here->id], $ids("server={$this->server->id}"));
        self::assertSame([$there->id], $ids("server={$other->id}&status=active"), 'with the other filters');
        $this->unchecked(); // the panel leaves out a filter it does not narrow by
        self::assertSame([$ended->id, $there->id, $here->id], $ids('server='), 'blank = every server');
    }

    public function testOneServiceIsReadAsTheListShowsIt(): void
    {
        $service = $this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1');

        $listed = $this->decode($this->get('/api/admin/subscriptions'))['subscriptions'][0];
        self::assertSame(['subscription' => $listed], $this->decode($this->get("/api/admin/subscriptions/{$service->id}")));
        self::assertSame(404, $this->get('/api/admin/subscriptions/999999')->getStatusCode());
    }

    public function testTheRowSaysWhatAQueuedPeriodLeavesAndWhetherItRenewsItself(): void
    {
        $queued = $this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1', ['auto_renew' => true, 'period_ends_at' => now()->addDays(5), 'next_period_bytes' => 30 * Traffic::GIGABYTE]);
        $plain = $this->subscription($this->ali, $this->plan, $this->server, 'ali_r_2');

        $row = fn(Subscription $subscription): array => $this->decode($this->get("/api/admin/subscriptions/{$subscription->id}"))['subscription'];

        self::assertSame([true, ['starts_at' => now()->addDays(5)->toIso8601String(), 'traffic' => 30 * Traffic::GIGABYTE]], [$row($queued)['auto_renew'], $row($queued)['next_period']]);
        self::assertSame([false, null], [$row($plain)['auto_renew'], $row($plain)['next_period']]);
    }

    public function testSyncReadsThePanelAndMarksAClientItNoLongerHasDeleted(): void
    {
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1');
        FakeProvider::put($this->server, new ClientInfo(
            name: 'ali_r_1',
            enabled: true,
            uploadBytes: Traffic::GIGABYTE,
            downloadBytes: 2 * Traffic::GIGABYTE,
            totalBytes: 50 * Traffic::GIGABYTE,
            expiry: Expiry::at(new \DateTimeImmutable('2026-11-01 00:00:00')),
            subscriptionUrl: 'https://fake.test/sub/moved',
        ));

        $row = $this->decode($this->postJson("/api/admin/subscriptions/{$subscription->id}/sync"))['subscription'];

        self::assertSame(['limit' => 50 * Traffic::GIGABYTE, 'used' => 3 * Traffic::GIGABYTE], $row['traffic'], 'the panel\'s numbers');
        self::assertSame('https://fake.test/sub/moved', $row['link']);
        self::assertStringStartsWith('2026-11-01', (string) $row['expires_at']);
        self::assertSame([], $this->telegram()->calls(), 'nobody is told about a sync');

        FakeProvider::$clients = [];
        $row = $this->decode($this->postJson("/api/admin/subscriptions/{$subscription->id}/sync"))['subscription'];

        self::assertSame('deleted', $row['status'], 'the panel no longer has it');
        self::assertSame(['sync' => false, 'disable' => false, 'enable' => false, 'move' => false, 'delete' => true, 'extend' => false], $row['actions'], 'only deleting it is left');
    }

    public function testAPanelOutOfReachIsNamedInTheAdminsWordsAndNothingChanges(): void
    {
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1');
        FakeProvider::$unreachable = true;

        foreach (['sync', 'disable', 'delete'] as $action) {
            $response = $this->postJson("/api/admin/subscriptions/{$subscription->id}/{$action}", []);
            $body = $this->decode($response);

            self::assertSame(502, $response->getStatusCode(), $action);
            self::assertStringStartsWith('پنل «آلمان»: ', $body['message'], $action);
            self::assertSame([$body['message']], $body['errors']['panel'], "{$action}: the step that failed");
        }
        self::assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
        self::assertSame([], $this->telegram()->calls());
    }

    public function testAnAgentReadsWhatHappenedButNothingOfThePanel(): void
    {
        $bot = $this->agentBot();
        $subscription = CurrentBot::run($bot, function (): Subscription {
            $customer = $this->customer(['telegram_id' => 2001, 'username' => 'reza']);

            return $this->subscription($customer, $this->plan(['name' => 'پلن نماینده']), $this->server, 'reza_1');
        });
        FakeProvider::$unreachable = true;

        $owners = $this->decode($this->postJson("/api/admin/subscriptions/{$this->mirrored('ali_r_1')->id}/sync"))['message'];
        self::assertSame('پنل «آلمان»: ' . ProviderErrorPresenter::describe(new ConnectionException(ConnectionFailure::Timeout, 'Connection timed out')), $owners, 'the owner, who runs the servers, gets the diagnosis');

        $this->loginAsAgent($bot);
        $response = $this->postJson("/api/agent/subscriptions/{$subscription->id}/sync");

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('پنل «آلمان»: ' . ProviderErrorPresenter::summary(new ConnectionException(ConnectionFailure::Timeout, '')), $this->decode($response)['message'], 'what happened, in a word: no host, no timeout, no panel words');
    }

    public function testTheOwnerReadsAPanelsFailureAsItIsInAnAgentsShopToo(): void
    {
        $bot = $this->agentBot();
        $subscription = CurrentBot::run($bot, fn(): Subscription => $this->subscription($this->customer(['telegram_id' => 2001, 'username' => 'reza']), $this->plan(['name' => 'پلن نماینده']), $this->server, 'reza_1'));
        FakeProvider::$unreachable = true;
        $this->openShop($bot);

        $response = $this->postJson("/api/admin/subscriptions/{$subscription->id}/sync");

        self::assertSame('پنل «آلمان»: ' . ProviderErrorPresenter::describe(new ConnectionException(ConnectionFailure::Timeout, 'Connection timed out')), $this->decode($response)['message'], 'they run the servers: whichever shop they have open');
    }

    public function testAnAgentsShopIsHeldToAPaceOnTheOwnersPanelsAndTheOwnersOwnShopIsNot(): void
    {
        $bot = $this->agentBot();
        $subscription = CurrentBot::run($bot, function (): Subscription {
            $customer = $this->customer(['telegram_id' => 2001, 'username' => 'reza']);

            return $this->subscription($customer, $this->plan(['name' => 'پلن نماینده']), $this->server, 'reza_1');
        });
        FakeProvider::mirror($subscription);
        $asked = 0;
        FakeProvider::$onCall = static function () use (&$asked): void {
            $asked++;
        };
        $this->loginAsAgent($bot);
        $sync = fn(): ResponseInterface => $this->postJson("/api/agent/subscriptions/{$subscription->id}/sync");

        for ($i = 0; $i < SubscriptionActions::PANEL_WORK; $i++) {
            self::assertSame(200, $sync()->getStatusCode());
        }
        $calls = $asked;
        $refused = $sync();

        self::assertSame(429, $refused->getStatusCode());
        self::assertStringStartsWith(SubscriptionActions::TOO_MUCH_PANEL_WORK, $this->decode($refused)['message']);
        self::assertSame((string) SubscriptionActions::PANEL_WORK_WINDOW, $refused->getHeaderLine('Retry-After'));
        self::assertSame($calls, $asked, 'the panel was not asked');

        $mine = $this->mirrored('ali_r_1');
        $actions = $this->service(SubscriptionActions::class);
        for ($i = 0; $i <= SubscriptionActions::PANEL_WORK; $i++) {
            $actions->sync($mine, $this->panelActor());
        }
        self::assertSame($calls + SubscriptionActions::PANEL_WORK + 1, $asked, 'the owner works their own shop as they please');

        Carbon::setTestNow(now()->addSeconds(SubscriptionActions::PANEL_WORK_WINDOW));
        self::assertSame(200, $sync()->getStatusCode(), 'the window over');
    }

    public function testAnEndedServiceThePanelExtendedRunsAgainOnceItIsRead(): void
    {
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1', ['status' => SubscriptionStatus::Expired, 'expires_at' => now()->subDay()]);
        FakeProvider::put($this->server, new ClientInfo(name: 'ali_r_1', enabled: true, totalBytes: 30 * Traffic::GIGABYTE, expiry: Expiry::at(new \DateTimeImmutable('2026-10-20 12:00:00')), subscriptionUrl: 'https://fake.test/sub/ali_r_1'));

        $row = $this->decode($this->postJson("/api/admin/subscriptions/{$subscription->id}/sync"))['subscription'];

        self::assertSame('active', $row['status'], 'extended on the panel, by days: the shop mirrors it');
        self::assertStringStartsWith('2026-10-20', (string) $row['expires_at']);
        self::assertTrue($row['actions']['disable']);
    }

    public function testAServiceSupportSwitchedOffStaysOffWhateverItsPanelSays(): void
    {
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1', ['status' => SubscriptionStatus::Disabled, 'disabled_at' => now()]);
        FakeProvider::mirror($subscription); // switched back on on the panel by hand, time and traffic left

        $row = $this->decode($this->postJson("/api/admin/subscriptions/{$subscription->id}/sync"))['subscription'];

        self::assertSame('disabled', $row['status'], "support's switch is the shop's: a read does not undo it");
    }

    public function testTheScreenStillAsksAPanelTheShopLeavesAloneAWhile(): void
    {
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1');
        FakeProvider::put($this->server, new ClientInfo(name: 'ali_r_1', enabled: true, downloadBytes: 2 * Traffic::GIGABYTE, totalBytes: 30 * Traffic::GIGABYTE, subscriptionUrl: $subscription->subscription_url));
        // The sync found the panel out of reach: the tasks and the customers' screens leave it alone a while.
        FakeProvider::$down = [$this->server->id];
        $this->service(SyncSubscriptionsTask::class)->run();
        FakeProvider::$down = [];
        self::assertTrue($this->server->refresh()->isBackingOff());

        $row = $this->decode($this->postJson("/api/admin/subscriptions/{$subscription->id}/sync"))['subscription'];

        self::assertSame(2 * Traffic::GIGABYTE, $row['traffic']['used'], 'the admin asked: the panel is asked');
        self::assertNull($this->server->refresh()->last_error, 'and it answered');
    }

    public function testAPanelThatRefusesTheOneThingAskedStillCountsAsAnswering(): void
    {
        $subscription = $this->mirrored('ali_r_1');
        FakeProvider::$down = [$this->server->id];
        $this->service(SyncSubscriptionsTask::class)->run(); // the sync found it out of reach
        FakeProvider::$down = [];
        FakeProvider::$refusing = [$this->server->id => ['findClient']];

        $response = $this->postJson("/api/admin/subscriptions/{$subscription->id}/sync");

        self::assertSame(502, $response->getStatusCode(), 'the read itself was refused');
        self::assertNull($this->server->refresh()->last_error, 'but the panel answered: the shop talks to it again');
    }

    public function testAServiceAnotherChangeHoldsIsBusyAndNothingHappens(): void
    {
        $subscription = $this->mirrored('ali_r_1');
        $target = $this->movingTarget();
        self::assertNotNull(Lease::take($subscription, 60), 'a renewal, a grant or a move is working on it');

        foreach (['disable' => ['note' => 'x'], 'move' => ['server_id' => $target->id], 'delete' => []] as $action => $body) {
            $response = $this->postJson("/api/admin/subscriptions/{$subscription->id}/{$action}", $body);

            self::assertSame(409, $response->getStatusCode(), $action);
            self::assertSame((new ServiceBusyException())->getMessage(), $this->decode($response)['message'], $action);
        }
        self::assertSame([[], [], []], [FakeProvider::$enabled, FakeProvider::$created, FakeProvider::$deleted], 'no panel was touched');
        $subscription->refresh();
        self::assertSame([SubscriptionStatus::Active, $this->server->id], [$subscription->status, $subscription->server_id]);
        self::assertSame([], $this->telegram()->calls());
    }

    public function testDisablingSwitchesTheClientOffAndTellsTheCustomerWithTheNote(): void
    {
        $subscription = $this->mirrored('ali_r_1');

        $row = $this->decode($this->postJson("/api/admin/subscriptions/{$subscription->id}/disable", ['note' => 'استفاده خارج از قوانین']))['subscription'];

        self::assertSame([['name' => 'ali_r_1', 'enabled' => false]], FakeProvider::$enabled);
        self::assertSame('disabled', $row['status']);
        self::assertNotNull($row['disabled_at']);
        self::assertSame(['sync' => true, 'disable' => false, 'enable' => true, 'move' => false, 'delete' => true, 'extend' => false], $row['actions']);

        self::assertSame(['sendMessage'], $this->telegram()->calls());
        self::assertSame([self::text(BotText::ServiceDisabledBySupport, ['client' => 'ali_r_1', 'note' => self::text(BotText::AdminNote, ['comment' => 'استفاده خارج از قوانین'])])], $this->telegram()->sentTo(1001));
        self::assertArrayNotHasKey('reply_markup', $this->telegram()->params(0), 'the customer is told, not asked');

        $again = $this->postJson("/api/admin/subscriptions/{$subscription->id}/disable", []);
        self::assertSame(422, $again->getStatusCode(), 'switched off already');
        self::assertSame('این سرویس غیرفعال شده است.', $this->decode($again)['errors']['status'][0], 'what it came to, not the rule');
        $extended = $this->postJson("/api/admin/subscriptions/{$subscription->id}/extend", ['days' => 3, 'traffic_gb' => 0]);
        self::assertSame('این سرویس غیرفعال شده است؛ زمان و حجم فقط به سرویس فعال اضافه می‌شود.', $this->decode($extended)['errors']['status'][0]);
    }

    public function testASwitchAnotherTabMadeAMomentAgoIsNeitherMadeNorToldTwice(): void
    {
        $subscription = $this->mirrored('ali_r_1');

        // Another tab switches it off between this request's read of the service and its change.
        $response = $this->whileListening('eloquent.retrieved: ' . Subscription::class, static function (Subscription $read): void {
            Subscription::query()->whereKey($read->id)->where('status', SubscriptionStatus::Active->value)->update(['status' => SubscriptionStatus::Disabled->value, 'disabled_at' => now()]);
        }, fn() => $this->postJson("/api/admin/subscriptions/{$subscription->id}/disable", ['note' => 'x']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('disabled', $this->decode($response)['subscription']['status']);
        self::assertSame([], FakeProvider::$enabled, 'the panel is not switched again');
        self::assertSame([], $this->telegram()->calls(), 'nor is the customer told again');
    }

    public function testAServiceDeletedFromUnderAChangeIsNotFound(): void
    {
        $subscription = $this->mirrored('ali_r_1');

        // Another tab deletes it between this request's read of the service and its change.
        $response = $this->whileListening('eloquent.retrieved: ' . Subscription::class, static function (Subscription $read): void {
            Subscription::query()->whereKey($read->id)->delete();
        }, fn() => $this->postJson("/api/admin/subscriptions/{$subscription->id}/disable", []));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], FakeProvider::$enabled);
    }

    public function testEnablingSwitchesItBackOnAndSaysSo(): void
    {
        $subscription = $this->mirrored('ali_r_1', ['status' => SubscriptionStatus::Disabled, 'disabled_at' => now()]);

        $row = $this->decode($this->postJson("/api/admin/subscriptions/{$subscription->id}/enable"))['subscription'];

        self::assertSame([['name' => 'ali_r_1', 'enabled' => true]], FakeProvider::$enabled);
        self::assertSame('active', $row['status']);
        self::assertNull($row['disabled_at']);
        self::assertSame([self::text(BotText::ServiceEnabledBySupport, ['client' => 'ali_r_1'])], $this->telegram()->sentTo(1001));
        self::assertSame(['این سرویس فعال است.'], $this->decode($this->postJson("/api/admin/subscriptions/{$subscription->id}/enable"))['errors']['status'], 'switched on already');
    }

    public function testAnExpiredServiceIsNotSwitchedBackOn(): void
    {
        $subscription = $this->mirrored('ali_r_1', ['status' => SubscriptionStatus::Expired, 'expires_at' => now()->subDay()]);

        $response = $this->postJson("/api/admin/subscriptions/{$subscription->id}/enable");

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('این سرویس منقضی شده است و تمدید لازم دارد.', $this->decode($response)['errors']['status'][0]);
        self::assertSame([], FakeProvider::$enabled);
    }

    public function testDeletingAWorkingServiceRemovesItAndTellsTheCustomerAndTidyingAnEndedOneDoesNot(): void
    {
        $working = $this->mirrored('ali_r_1');
        $ended = $this->mirrored('ali_r_2', ['status' => SubscriptionStatus::Expired, 'expires_at' => now()->subDay()]);
        $sale = $this->purchaseOrder($this->ali, $this->plan, $this->server, ['status' => OrderStatus::Fulfilled, 'subscription_id' => $working->id]);

        $response = $this->postJson("/api/admin/subscriptions/{$working->id}/delete", ['note' => 'درخواست خود مشتری']);

        self::assertSame(204, $response->getStatusCode());
        self::assertFalse(Subscription::query()->whereKey($working->id)->exists(), 'gone for good');
        self::assertSame([['server' => $this->server->id, 'name' => 'ali_r_1']], FakeProvider::$deleted);
        self::assertNull($sale->refresh()->subscription_id, 'the sale stays on record, pointing at nothing');
        self::assertSame(OrderStatus::Fulfilled, $sale->status);
        self::assertSame([self::text(BotText::ServiceDeletedBySupport, ['client' => 'ali_r_1', 'note' => self::text(BotText::AdminNote, ['comment' => 'درخواست خود مشتری'])])], $this->telegram()->sentTo(1001));

        $this->telegram()->reset();
        $this->postJson("/api/admin/subscriptions/{$ended->id}/delete", []);

        self::assertSame(['ali_r_1', 'ali_r_2'], array_column(FakeProvider::$deleted, 'name'));
        self::assertFalse(Subscription::query()->whereKey($ended->id)->exists());
        self::assertSame([], $this->telegram()->calls(), 'an ended service is tidied away quietly');
        self::assertSame(404, $this->postJson("/api/admin/subscriptions/{$ended->id}/delete", [])->getStatusCode(), 'nothing left to delete');
    }

    public function testAClientAlreadyGoneFromThePanelIsDeletedAllTheSame(): void
    {
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1');

        self::assertSame(204, $this->postJson("/api/admin/subscriptions/{$subscription->id}/delete", [])->getStatusCode());
        self::assertFalse(Subscription::query()->whereKey($subscription->id)->exists());
        self::assertSame([], FakeProvider::$deleted, 'nothing was there to delete');
    }

    public function testARowWhoseClientThePanelLostIsDeletedWithoutAskingThePanel(): void
    {
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1', ['status' => SubscriptionStatus::Deleted]);
        FakeProvider::$unreachable = true;

        self::assertSame(204, $this->postJson("/api/admin/subscriptions/{$subscription->id}/delete", [])->getStatusCode(), 'the last sync found it gone: nothing to ask');
        self::assertFalse(Subscription::query()->whereKey($subscription->id)->exists());
        self::assertSame([], $this->telegram()->calls(), 'nothing the customer could still use');
    }

    public function testAServiceWhosePanelIsOutOfReachIsDeletedHereWhenThePanelIsLeftOut(): void
    {
        $subscription = $this->mirrored('ali_r_1');
        FakeProvider::$down = [$this->server->id];

        $refused = $this->postJson("/api/admin/subscriptions/{$subscription->id}/delete", ['note' => 'سرور از دست رفت']);

        self::assertSame(502, $refused->getStatusCode());
        self::assertArrayHasKey('panel', $this->decode($refused)['errors'], 'the cue to offer leaving the panel out');
        self::assertSame(SubscriptionStatus::Active, $subscription->refresh()->status, 'nothing changes unless the admin says so');

        $logs = $this->logs();
        $response = $this->postJson("/api/admin/subscriptions/{$subscription->id}/delete", ['note' => 'سرور از دست رفت', 'leave_panel' => true]);

        self::assertSame(204, $response->getStatusCode());
        self::assertFalse(Subscription::query()->whereKey($subscription->id)->exists());
        self::assertSame([], FakeProvider::$deleted, 'the panel was not contacted');
        self::assertArrayHasKey('ali_r_1', FakeProvider::$clients[$this->server->id], 'its client stays there for the admin to remove');
        self::assertTrue($logs->hasInfoThatContains('its client was left on the panel'), 'and the log says so');
        self::assertSame(
            [self::text(BotText::ServiceDeletedBySupport, ['client' => 'ali_r_1', 'note' => self::text(BotText::AdminNote, ['comment' => 'سرور از دست رفت'])])],
            $this->telegram()->sentTo(1001),
            'the customer lost a working service all the same',
        );
    }

    public function testATooLongNoteIsRefusedBeforeThePanelIsTouched(): void
    {
        $subscription = $this->mirrored('ali_r_1');

        $response = $this->postJson("/api/admin/subscriptions/{$subscription->id}/disable", ['note' => str_repeat('ا', Input::NOTE_MAX + 1)]);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('note', $this->decode($response)['errors']);
        self::assertSame([], FakeProvider::$enabled);
        self::assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
    }

    public function testMovingCarriesWhatIsLeftToANewClientThereAndSendsTheNewLink(): void
    {
        $target = $this->movingTarget();
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1');
        FakeProvider::put($this->server, new ClientInfo(
            name: 'ali_r_1',
            enabled: true,
            uploadBytes: 4 * Traffic::GIGABYTE,
            downloadBytes: 8 * Traffic::GIGABYTE,
            totalBytes: 30 * Traffic::GIGABYTE,
            expiry: Expiry::at(new \DateTimeImmutable('2026-10-10 12:00:00')),
            subscriptionUrl: 'https://fake.test/sub/ali_r_1',
        ));

        $row = $this->decode($this->postJson("/api/admin/subscriptions/{$subscription->id}/move", ['server_id' => $target->id]))['subscription'];

        // A client on the target with what the previous panel said was left: 18 of 30 GB, the same deadline.
        self::assertCount(1, FakeProvider::$created);
        ['server' => $on, 'inbounds' => $inbounds, 'spec' => $spec] = FakeProvider::$created[0];
        self::assertSame([$target->id, ['7']], [$on, $inbounds], 'on the inbounds the target sells');
        self::assertSame('ali_r_1', $spec->name, 'free there, so the name stays');
        self::assertSame(18 * Traffic::GIGABYTE, $spec->totalBytes);
        self::assertSame('2026-10-10 12:00:00', $spec->expiry->deadline()?->format('Y-m-d H:i:s'));
        self::assertSame([['server' => $this->server->id, 'name' => 'ali_r_1']], FakeProvider::$deleted, 'and the old client is gone');

        self::assertSame(['id' => $target->id, 'name' => 'هلند'], $row['server']);
        self::assertSame('https://fake.test/sub/sub-1', $row['link']);
        self::assertSame(['limit' => 18 * Traffic::GIGABYTE, 'used' => 0], $row['traffic']);
        self::assertStringStartsWith('2026-10-10', (string) $row['expires_at']);

        self::assertSame(['sendMessage'], $this->telegram()->calls());
        self::assertSame([self::text(BotText::ServiceMoved, [
            'client' => 'ali_r_1',
            'plan' => $this->plan->name,
            'server' => 'هلند',
            'expires' => Messages::expiry(new \DateTimeImmutable('2026-10-10 12:00:00'), 30),
            'traffic' => Messages::traffic(18 * Traffic::GIGABYTE),
            'subscription' => 'https://fake.test/sub/sub-1',
        ])], $this->telegram()->sentTo(1001), 'the new link, with what is left');
    }

    public function testAServiceMovesOntoWhatItsPlanSellsOnTheTarget(): void
    {
        $target = $this->movingTarget();
        $pinned = $this->inbound($target, '8', ['is_selectable' => false]);
        $this->planEntry($this->plan, $target, [$pinned->id]);
        $subscription = $this->mirrored('ali_r_1');

        self::assertSame(200, $this->move($subscription, $target)->getStatusCode());

        self::assertSame(['8'], FakeProvider::$created[0]['inbounds'], "the plan's entry for that server, not every inbound it sells");
    }

    public function testAnUnlimitedServiceStaysUnlimitedOnTheTarget(): void
    {
        $target = $this->movingTarget();
        $subscription = $this->mirrored('ali_r_1', ['traffic_limit_bytes' => 0, 'download_bytes' => 50 * Traffic::GIGABYTE]);

        $row = $this->decode($this->move($subscription, $target))['subscription'];

        self::assertSame(0, FakeProvider::$created[0]['spec']->totalBytes);
        self::assertSame(['limit' => 0, 'used' => 0], $row['traffic']);
    }

    public function testATargetThatGivesNoLinkHasItsClientTakenBackAndTheServiceStays(): void
    {
        $target = $this->movingTarget();
        $subscription = $this->mirrored('ali_r_1');
        FakeProvider::$mintsSubId = false; // the target's panel makes the client but no subscription link for it

        $response = $this->move($subscription, $target);

        self::assertSame(502, $response->getStatusCode());
        self::assertStringStartsWith('سرور مقصد «هلند»: ', $this->decode($response)['errors']['target'][0]);
        self::assertSame([['server' => $target->id, 'name' => 'ali_r_1']], FakeProvider::$deleted, 'the new client is taken back');
        self::assertArrayHasKey('ali_r_1', FakeProvider::$clients[$this->server->id], 'the previous one is untouched');
        self::assertSame($this->server->id, $subscription->refresh()->server_id);
        self::assertSame([], $this->telegram()->calls());
    }

    public function testATargetThatRefusesTheClientIsNamedInTheOwnersWords(): void
    {
        $target = $this->movingTarget();
        $subscription = $this->mirrored('ali_r_1');
        FakeProvider::$refusing = [$target->id => ['createClient']];

        $response = $this->move($subscription, $target);

        self::assertSame(502, $response->getStatusCode());
        self::assertSame(['سرور مقصد «هلند»: ' . ProviderErrorPresenter::describe(self::refusal())], $this->decode($response)['errors']['target']);
        self::assertSame([], FakeProvider::$deleted, 'nothing was made, nothing is taken back');
        self::assertSame($this->server->id, $subscription->refresh()->server_id);
    }

    public function testAnAgentMovingTheirCustomersServiceReadsNothingOfThePanels(): void
    {
        $bot = $this->agentBot();
        $target = $this->movingTarget();
        $subscription = CurrentBot::run($bot, fn(): Subscription => $this->subscription($this->customer(['telegram_id' => 2001, 'username' => 'reza']), $this->plan(['name' => 'پلن نماینده']), $this->server, 'reza_1'));
        FakeProvider::mirror($subscription);
        $this->loginAsAgent($bot);
        $move = fn(): array => $this->decode($this->postJson("/api/agent/subscriptions/{$subscription->id}/move", ['server_id' => $target->id]))['errors'] ?? [];

        FakeProvider::$down = [$this->server->id];
        self::assertSame(['سرور قبلی «آلمان»: ' . ProviderErrorPresenter::summary(new ConnectionException(ConnectionFailure::Timeout, ''))], $move()['previous'] ?? null);

        FakeProvider::$down = [];
        FakeProvider::$refusing = [$target->id => ['createClient']];
        self::assertSame(['سرور مقصد «هلند»: ' . ProviderErrorPresenter::summary(self::refusal())], $move()['target'] ?? null);
    }

    public function testAPreviousClientGoneBeforeItsDeleteCountsAsDeleted(): void
    {
        $target = $this->movingTarget();
        $subscription = $this->mirrored('ali_r_1');
        // Removed on the previous panel by hand while the new client was being made.
        FakeProvider::$onCall = function (int $server, string $call): void {
            if ($call === 'deleteClient' && $server === $this->server->id) {
                unset(FakeProvider::$clients[$server]['ali_r_1']);
            }
        };

        $row = $this->decode($this->move($subscription, $target))['subscription'];

        self::assertSame($target->id, $row['server']['id'], 'moved all the same');
        self::assertSame([], FakeProvider::$deleted, 'nothing was left to delete, and the new client is kept');
    }

    public function testLeavingThePreviousServerOutAfterAFirstTryCarriesWhatThatTryRead(): void
    {
        $target = $this->movingTarget();
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1');
        FakeProvider::put($this->server, new ClientInfo(name: 'ali_r_1', enabled: true, downloadBytes: 8 * Traffic::GIGABYTE, totalBytes: 30 * Traffic::GIGABYTE, expiry: Expiry::at(now()->addDays(30)->toDateTimeImmutable()), subscriptionUrl: $subscription->subscription_url));
        FakeProvider::$refusing = [$this->server->id => ['deleteClient']];
        self::assertSame(502, $this->move($subscription, $target)->getStatusCode(), 'the first try read the panel, then its delete was refused');

        $this->postJson("/api/admin/subscriptions/{$subscription->id}/move", ['server_id' => $target->id, 'leave_previous' => true]);

        self::assertSame(22 * Traffic::GIGABYTE, FakeProvider::$created[1]['spec']->totalBytes, 'what the first try read: 8 of 30 GB used');
        self::assertSame($target->id, $subscription->refresh()->server_id);
    }

    public function testAServiceWaitingForItsFirstConnectionMovesWithItsWholeTerm(): void
    {
        $target = $this->movingTarget();
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1', ['starts_at' => null, 'expires_at' => null]);
        FakeProvider::put($this->server, new ClientInfo(name: 'ali_r_1', enabled: true, totalBytes: 30 * Traffic::GIGABYTE, expiry: Expiry::afterFirstUse(30 * 86400), subscriptionUrl: 'https://fake.test/sub/ali_r_1'));

        $row = $this->decode($this->postJson("/api/admin/subscriptions/{$subscription->id}/move", ['server_id' => $target->id]))['subscription'];

        self::assertSame(30 * 86400, FakeProvider::$created[0]['spec']->expiry->pendingSeconds(), 'the clock still starts at the first connection');
        self::assertNull($row['expires_at']);
        self::assertSame(30, $row['duration_days']);
    }

    public function testANameTakenOnTheTargetGetsTheCustomersNextOne(): void
    {
        $target = $this->movingTarget();
        $this->subscription($this->ali, $this->plan, $target, 'ali_r_1');
        $subscription = $this->mirrored('ali_r_1');

        $row = $this->decode($this->postJson("/api/admin/subscriptions/{$subscription->id}/move", ['server_id' => $target->id]))['subscription'];

        self::assertSame('ali_r_2', $row['name'], 'Ali\'s next number, as a new purchase of his would be called');
        self::assertSame('ali_r_2', FakeProvider::$created[0]['spec']->name);
    }

    public function testAServiceItsPanelSaysHasEndedIsNotMoved(): void
    {
        $target = $this->movingTarget();
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1');
        // The shop still has it running; its panel has it used up.
        FakeProvider::put($this->server, new ClientInfo(name: 'ali_r_1', enabled: true, downloadBytes: 30 * Traffic::GIGABYTE, totalBytes: 30 * Traffic::GIGABYTE, expiry: Expiry::at(now()->addDays(10)->toDateTimeImmutable()), subscriptionUrl: $subscription->subscription_url));

        $response = $this->move($subscription, $target);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('status', $this->decode($response)['errors'], 'about this service: a batch goes on to the next');
        self::assertSame([], FakeProvider::$created, 'nothing is made for what is not left');
        $subscription->refresh();
        self::assertSame([SubscriptionStatus::Expired, $this->server->id], [$subscription->status, $subscription->server_id], 'and the row learned it ended');
    }

    public function testAPreviousServerOutOfReachStopsTheMove(): void
    {
        $target = $this->movingTarget();
        $subscription = $this->mirrored('ali_r_1');
        FakeProvider::$down = [$this->server->id];

        $response = $this->postJson("/api/admin/subscriptions/{$subscription->id}/move", ['server_id' => $target->id]);

        self::assertSame(502, $response->getStatusCode());
        self::assertStringStartsWith('سرور قبلی «آلمان»: ', $this->decode($response)['errors']['previous'][0], 'the cue to offer leaving it out');
        self::assertSame([], FakeProvider::$created, 'nothing was made on the target');
        self::assertSame($this->server->id, $subscription->refresh()->server_id);
    }

    public function testLeavingThePreviousServerOutMovesWhatTheShopLastSaw(): void
    {
        $target = $this->movingTarget();
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1', ['download_bytes' => 2 * Traffic::GIGABYTE]);
        FakeProvider::$down = [$this->server->id];

        $row = $this->decode($this->postJson("/api/admin/subscriptions/{$subscription->id}/move", ['server_id' => $target->id, 'leave_previous' => true]))['subscription'];

        self::assertSame($target->id, $row['server']['id']);
        self::assertSame(28 * Traffic::GIGABYTE, FakeProvider::$created[0]['spec']->totalBytes, 'the row\'s last numbers: 2 of 30 GB used');
        self::assertSame([], FakeProvider::$deleted, 'the previous server was not contacted');
    }

    public function testADeleteThePreviousServerRefusesTakesTheNewClientBack(): void
    {
        $target = $this->movingTarget();
        $subscription = $this->mirrored('ali_r_1');
        FakeProvider::$refusing = [$this->server->id => ['deleteClient']];

        $response = $this->postJson("/api/admin/subscriptions/{$subscription->id}/move", ['server_id' => $target->id]);

        self::assertSame(502, $response->getStatusCode());
        self::assertArrayHasKey('previous', $this->decode($response)['errors']);
        self::assertSame([['server' => $target->id, 'name' => 'ali_r_1']], FakeProvider::$deleted, 'the new client was taken back');
        self::assertSame($this->server->id, $subscription->refresh()->server_id, 'nothing moved');
        self::assertSame([], $this->telegram()->calls(), 'nor was the customer told');
    }

    public function testTheTargetMustBeAnotherServerThatCanTakeIt(): void
    {
        $subscription = $this->mirrored('ali_r_1');
        $error = fn(array $body): array => $this->decode($this->postJson("/api/admin/subscriptions/{$subscription->id}/move", $body))['errors'];

        $this->unchecked(); // no target at all
        self::assertSame(['سرور مقصد را انتخاب کنید.'], $error([])['server_id']);
        self::assertSame(['این سرویس همین حالا روی این سرور است.'], $error(['server_id' => $this->server->id])['status'], 'about this service, not the target');
        self::assertSame(['«هلند»: سرور غیرفعال است.'], $error(['server_id' => $this->movingTarget(['is_active' => false])->id])['server_id'], 'the reason the servers screen shows too');
        self::assertSame(['«فرانسه»: اینباند قابل فروشی ندارد؛ در صفحه سرور اینباندی را برای فروش علامت بزنید.'], $error(['server_id' => $this->fakeServer('فرانسه')->id])['server_id']);
        self::assertSame([], FakeProvider::$created);

        $expired = $this->mirrored('ali_r_2', ['status' => SubscriptionStatus::Expired, 'expires_at' => now()->subDay()]);
        $response = $this->postJson("/api/admin/subscriptions/{$expired->id}/move", ['server_id' => $this->movingTarget()->id]);
        self::assertSame('این سرویس منقضی شده است و تمدید لازم دارد.', $this->decode($response)['errors']['status'][0]);
    }

    public function testAnUnknownSubscriptionIsNotFound(): void
    {
        self::assertSame(404, $this->postJson('/api/admin/subscriptions/999/sync')->getStatusCode());
    }

    /**
     * A second server to move services to, selling one inbound.
     *
     * @param array<string, mixed> $overrides
     */
    private function movingTarget(array $overrides = []): Server
    {
        $target = $this->fakeServer('هلند', $overrides);
        $this->inbound($target, '7');

        return $target;
    }

    /**
     * A subscription of Ali's whose client is on the fake panel as the row has it.
     *
     * @param array<string, mixed> $overrides
     */
    private function mirrored(string $name, array $overrides = []): Subscription
    {
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, $name, $overrides);
        FakeProvider::mirror($subscription);

        return $subscription;
    }

    /** The admin moves the service to the server. */
    private function move(Subscription $subscription, Server $target): ResponseInterface
    {
        return $this->postJson("/api/admin/subscriptions/{$subscription->id}/move", ['server_id' => $target->id]);
    }

    /** What the fake panel says when it refuses a call (FakeProvider::$refusing). */
    private static function refusal(): PanelApiException
    {
        return new PanelApiException('The fake panel refused createClient.', 200, 'refused by the test');
    }
}
