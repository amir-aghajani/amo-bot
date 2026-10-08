<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Database\Lease;
use App\Core\Http\ErrorHandler;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\DTO\Capabilities;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Models\Website;
use App\Modules\Store\Services\CustomerSubscriptions;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\AutoRenewUnavailableException;
use App\Modules\Subscriptions\Exceptions\ServiceBusyException;
use App\Modules\Subscriptions\Exceptions\ServiceNotReadException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Users\Models\User;
use App\Support\Traffic;
use App\Support\Validation;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * A customer's services on the shop's website — their own alone, another's a 404 by the query itself — as the shop last
 * saw them on their panels, with what they may do with each now by the bot's own rules: read one from its panel now
 * (with whether it is connected; a panel out of reach, or one the shop leaves alone a while, is a 502 and the row stays
 * as it was), switch its «تمدید خودکار» while it is offered, give it a new link — refused for a service that does not run
 * or a panel that cannot, a panel's failure in the customer's words, a service another change holds busy; and the bot
 * says nothing of what the customer did on the site.
 */
final class StoreSubscriptionsTest extends HttpTestCase
{
    private Website $website;

    private User $customer;

    private Server $server;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->telegram();
        $this->website = $this->website();
        $this->customer = $this->customer(['username' => 'ali']);
        $this->server = $this->sellingServer('Berlin');
        $this->plan = $this->plan([], $this->server);
        $this->bearer($this->customerSession($this->customer));
    }

    public function testTheyReadTheirOwnServicesNewestFirstAndNoOneElses(): void
    {
        $first = $this->subscription($this->customer, $this->plan, $this->server, 'ali_1');
        $second = $this->subscription($this->customer, $this->plan, $this->server, 'ali_2', ['status' => SubscriptionStatus::Expired]);
        $reza = $this->subscription($this->customer(['telegram_id' => 7272]), $this->plan, $this->server, 'reza_1');
        FakeProvider::mirror($reza);

        $answer = $this->decode($this->get($this->storeApi($this->website, '/subscriptions')));

        self::assertSame([$second->id, $first->id], array_column($answer['subscriptions'], 'id'));
        self::assertSame(['page' => 1, 'per_page' => 25, 'total' => 2, 'last_page' => 1], $answer['meta']);

        $theirs = $this->storeApi($this->website, "/subscriptions/{$reza->id}");
        foreach ([$this->get($theirs), $this->postJson("{$theirs}/refresh"), $this->patchJson($theirs, ['auto_renew' => true]), $this->postJson("{$theirs}/rotate-link")] as $i => $response) {
            self::assertSame([404, ErrorHandler::NOT_FOUND], [$response->getStatusCode(), $this->decode($response)['message']], "request {$i}");
        }
        self::assertSame([], FakeProvider::$rotated, 'nothing was done to it');
        self::assertFalse($reza->refresh()->auto_renew);
    }

    public function testAServiceIsWhatTheShopLastSawOfIt(): void
    {
        $subscription = $this->subscription($this->customer, $this->plan, $this->server, 'ali_1', [
            'upload_bytes' => Traffic::GIGABYTE,
            'download_bytes' => 2 * Traffic::GIGABYTE,
            'last_synced_at' => now()->subMinutes(3),
            'auto_renew' => true,
        ]);

        self::assertSame(['subscription' => [
            'id' => $subscription->id,
            'name' => 'ali_1',
            'status' => 'active',
            'plan' => ['id' => $this->plan->id, 'name' => 'یک‌ماهه'],
            'server' => ['id' => $this->server->id, 'name' => 'Berlin'],
            'link' => 'https://fake.test/sub/ali_1',
            'traffic' => ['limit_bytes' => 30 * Traffic::GIGABYTE, 'used_bytes' => 3 * Traffic::GIGABYTE, 'remaining_bytes' => 27 * Traffic::GIGABYTE],
            'term' => ['duration_days' => 30, 'starts_at' => '2026-10-07T12:00:00+00:00', 'expires_at' => '2026-11-06T12:00:00+00:00', 'awaits_first_use' => false],
            'auto_renew' => ['on' => true, 'offered' => true, 'days_before' => 2],
            'renewable' => true,
            'link_rotation' => true,
            'next_period' => null,
            'presence' => null,
            'synced_at' => '2026-10-07T11:57:00+00:00',
            'created_at' => '2026-10-07T12:00:00+00:00',
        ]], $this->decode($this->get($this->storeApi($this->website, "/subscriptions/{$subscription->id}"))));
    }

    public function testATermWaitingForTheFirstConnectionAnUnlimitedOneAndAQueuedPeriod(): void
    {
        $waiting = $this->subscription($this->customer, $this->plan, $this->server, 'ali_1', ['starts_at' => null, 'expires_at' => null]);
        $forever = $this->plan(['name' => 'نامحدود', 'traffic_gb' => 0, 'duration_days' => 0], $this->server);
        $unlimited = $this->subscription($this->customer, $forever, $this->server, 'ali_2');
        $renewed = $this->subscription($this->customer, $this->plan, $this->server, 'ali_3', [
            'period_ends_at' => now()->addDays(5),
            'next_period_bytes' => 30 * Traffic::GIGABYTE,
        ]);

        $one = fn(Subscription $subscription): array => $this->decode($this->get($this->storeApi($this->website, "/subscriptions/{$subscription->id}")))['subscription'];

        self::assertSame(['duration_days' => 30, 'starts_at' => null, 'expires_at' => null, 'awaits_first_use' => true], $one($waiting)['term'], 'its clock starts at the first connection');

        $row = $one($unlimited);
        self::assertSame(['limit_bytes' => 0, 'used_bytes' => 0, 'remaining_bytes' => null], $row['traffic']);
        self::assertSame(['duration_days' => 0, 'starts_at' => '2026-10-07T12:00:00+00:00', 'expires_at' => null, 'awaits_first_use' => false], $row['term']);
        self::assertSame([['on' => false, 'offered' => false, 'days_before' => 2], false], [$row['auto_renew'], $row['renewable']], 'it never ends, and its plan renews nothing');

        self::assertSame(['ends_at' => '2026-10-12T12:00:00+00:00', 'bytes' => 30 * Traffic::GIGABYTE], $one($renewed)['next_period']);
    }

    public function testWhatTheyMayDoIsTheBotsRules(): void
    {
        $expired = $this->subscription($this->customer, $this->plan, $this->server, 'ali_1', ['status' => SubscriptionStatus::Expired]);
        $disabled = $this->subscription($this->customer, $this->plan, $this->server, 'ali_2', ['status' => SubscriptionStatus::Disabled]);
        $deleted = $this->subscription($this->customer, $this->plan, $this->server, 'ali_3', ['status' => SubscriptionStatus::Deleted]);
        $planless = $this->subscription($this->customer, $this->plan, $this->server, 'ali_4', ['plan_id' => null]);

        $rows = array_column($this->decode($this->get($this->storeApi($this->website, '/subscriptions')))['subscriptions'], null, 'name');
        $judged = static fn(array $row): array => [$row['auto_renew']['offered'], $row['renewable'], $row['link_rotation']];

        self::assertSame([false, true, false], $judged($rows['ali_1']), 'an ended service is renewed by hand; its link stays as it is');
        self::assertSame([false, false, false], $judged($rows['ali_2']), "support's switch: a renewal would turn it back on");
        self::assertSame([false, false, false], $judged($rows['ali_3']), 'gone from its panel');
        self::assertNull($rows['ali_3']['link'], 'the link of a client the panel no longer has works no more');
        self::assertSame('https://fake.test/sub/ali_1', $rows['ali_1']['link']);
        self::assertSame([null, false, false, true], [$rows['ali_4']['plan'], $rows['ali_4']['auto_renew']['offered'], $rows['ali_4']['renewable'], $rows['ali_4']['link_rotation']], 'its plan deleted: nothing to renew on, its link still its own');
        self::assertSame([$planless->id, $deleted->id, $disabled->id, $expired->id], array_column($rows, 'id'));
    }

    public function testTheListIsNarrowedToAStateAndPaged(): void
    {
        foreach (range(1, 26) as $n) {
            $this->subscription($this->customer, $this->plan, $this->server, "ali_{$n}");
        }
        $ended = $this->subscription($this->customer, $this->plan, $this->server, 'ali_ended', ['status' => SubscriptionStatus::Expired]);

        $expired = $this->decode($this->get($this->storeApi($this->website, '/subscriptions?status=expired')));
        self::assertSame([[$ended->id], 1], [array_column($expired['subscriptions'], 'id'), $expired['meta']['total']]);

        $anything = $this->decode($this->unchecked()->get($this->storeApi($this->website, '/subscriptions?status=lost')));
        self::assertSame(27, $anything['meta']['total'], 'no such state: no filter');

        $second = $this->decode($this->get($this->storeApi($this->website, '/subscriptions?page=2')));
        self::assertSame(['page' => 2, 'per_page' => 25, 'total' => 27, 'last_page' => 2], $second['meta']);
        self::assertSame(['ali_2', 'ali_1'], array_column($second['subscriptions'], 'name'), 'the oldest last');
    }

    public function testRefreshReadsThePanelNowWithWhetherItIsConnected(): void
    {
        $subscription = $this->subscription($this->customer, $this->plan, $this->server, 'ali_1', ['last_synced_at' => now()->subHour()]);
        FakeProvider::put($this->server, new ClientInfo(
            name: 'ali_1',
            enabled: true,
            uploadBytes: Traffic::GIGABYTE,
            downloadBytes: 4 * Traffic::GIGABYTE,
            totalBytes: 30 * Traffic::GIGABYTE,
            expiry: Expiry::at(new \DateTimeImmutable('2026-11-06 12:00:00', new \DateTimeZone('UTC'))),
            subscriptionUrl: 'https://fake.test/sub/moved',
            lastOnlineAt: new \DateTimeImmutable('2026-10-07 11:50:00', new \DateTimeZone('UTC')),
        ));
        FakeProvider::$online = ['ali_1'];

        $row = $this->decode($this->postJson($this->storeApi($this->website, "/subscriptions/{$subscription->id}/refresh")))['subscription'];

        self::assertSame(['limit_bytes' => 30 * Traffic::GIGABYTE, 'used_bytes' => 5 * Traffic::GIGABYTE, 'remaining_bytes' => 25 * Traffic::GIGABYTE], $row['traffic']);
        self::assertSame(['online' => true, 'last_online_at' => '2026-10-07T11:50:00+00:00'], $row['presence']);
        self::assertSame(['https://fake.test/sub/moved', '2026-10-07T12:00:00+00:00'], [$row['link'], $row['synced_at']]);
        self::assertSame(5 * Traffic::GIGABYTE, $subscription->refresh()->usedBytes(), 'the shop\'s copy brought up to date');

        $again = $this->decode($this->get($this->storeApi($this->website, "/subscriptions/{$subscription->id}")))['subscription'];
        self::assertSame([null, 5 * Traffic::GIGABYTE], [$again['presence'], $again['traffic']['used_bytes']], 'read later: the copy, and nothing said of a connection');
        self::assertSame([], $this->telegram()->calls());
    }

    public function testRefreshWithoutThePanelIsA502AndTheShopsCopyStays(): void
    {
        $subscription = $this->subscription($this->customer, $this->plan, $this->server, 'ali_1', ['download_bytes' => 2 * Traffic::GIGABYTE, 'last_synced_at' => now()->subHour()]);
        FakeProvider::mirror($subscription);
        $refresh = fn() => $this->postJson($this->storeApi($this->website, "/subscriptions/{$subscription->id}/refresh"));
        FakeProvider::$unreachable = true;

        $response = $refresh();

        self::assertSame([502, (new ServiceNotReadException())->getMessage()], [$response->getStatusCode(), $this->decode($response)['message']]);
        $subscription->refresh();
        self::assertSame([2 * Traffic::GIGABYTE, '2026-10-07T11:00:00+00:00'], [$subscription->usedBytes(), $subscription->last_synced_at?->toIso8601String()], 'the row as it was: never passed off as fresh');

        // The panel failed: the shop leaves it alone a while — a customer does not wait out its timeout for every refresh.
        FakeProvider::$unreachable = false;
        $asked = [];
        FakeProvider::$onCall = static function (int $server, string $call) use (&$asked): void {
            $asked[] = $call;
        };
        self::assertSame(502, $refresh()->getStatusCode());
        self::assertSame([], $asked, 'the panel was not asked');

        Carbon::setTestNow(now()->addMinutes(Server::BACKOFF_MINUTES));
        self::assertSame(200, $refresh()->getStatusCode(), 'asked again once the while is up');
        self::assertSame(['findClient'], $asked);
    }

    public function testRefreshFindsAClientThePanelNoLongerHasDeleted(): void
    {
        $subscription = $this->subscription($this->customer, $this->plan, $this->server, 'ali_1');

        $row = $this->decode($this->postJson($this->storeApi($this->website, "/subscriptions/{$subscription->id}/refresh")))['subscription'];

        self::assertSame(['deleted', null, null], [$row['status'], $row['link'], $row['presence']]);
        self::assertSame(SubscriptionStatus::Deleted, $subscription->refresh()->status);
    }

    public function testAutoRenewIsSwitchedWhileItIsOffered(): void
    {
        $subscription = $this->subscription($this->customer, $this->plan, $this->server, 'ali_1');
        $path = $this->storeApi($this->website, "/subscriptions/{$subscription->id}");

        self::assertSame(['on' => true, 'offered' => true, 'days_before' => 2], $this->decode($this->patchJson($path, ['auto_renew' => true]))['subscription']['auto_renew']);
        self::assertTrue($subscription->refresh()->auto_renew);
        self::assertSame(['on' => false, 'offered' => true, 'days_before' => 2], $this->decode($this->patchJson($path, ['auto_renew' => false]))['subscription']['auto_renew']);
        self::assertFalse($subscription->refresh()->auto_renew);
        self::assertSame([], $this->telegram()->calls(), 'the bot says nothing of it');
    }

    public function testAutoRenewSaysHowManyDaysBeforeItsEndItRenews(): void
    {
        $subscription = $this->subscription($this->customer, $this->plan, $this->server, 'ali_1');
        $this->botSettings('auto_renew', ['auto_renew_days' => 5, 'auto_renew_default' => false]);

        $row = $this->decode($this->get($this->storeApi($this->website, "/subscriptions/{$subscription->id}")))['subscription'];

        self::assertSame(['on' => false, 'offered' => true, 'days_before' => 5], $row['auto_renew'], "the shop's rule, as AutoRenewal renews by it");
    }

    public function testACustomerReadsTenServicesAMinuteFromTheirPanelsAtMost(): void
    {
        $subscription = $this->subscription($this->customer, $this->plan, $this->server, 'ali_1');
        FakeProvider::mirror($subscription);
        $refresh = fn(): ResponseInterface => $this->postJson($this->storeApi($this->website, "/subscriptions/{$subscription->id}/refresh"));
        foreach (range(1, CustomerSubscriptions::REFRESHES) as $n) {
            self::assertSame(200, $refresh()->getStatusCode(), "read {$n}");
        }
        $asked = 0;
        FakeProvider::$onCall = static function () use (&$asked): void {
            $asked++;
        };

        $response = $refresh();

        self::assertSame([429, '60'], [$response->getStatusCode(), $response->getHeaderLine('Retry-After')]);
        self::assertSame('اطلاعات سرویس را زیاد به‌روز کرده‌اید؛ ۱ دقیقه دیگر دوباره امتحان کنید.', $this->decode($response)['message']);
        self::assertSame(0, $asked, 'its panel not asked');

        Carbon::setTestNow(now()->addMinute());
        self::assertSame(200, $refresh()->getStatusCode(), 'the minute over');
    }

    public function testACustomerAsksForFiveNewLinksAnHourAtMost(): void
    {
        $subscription = $this->subscription($this->customer, $this->plan, $this->server, 'ali_1');
        FakeProvider::mirror($subscription);
        $rotate = fn(): ResponseInterface => $this->postJson($this->storeApi($this->website, "/subscriptions/{$subscription->id}/rotate-link"));
        foreach (range(1, CustomerSubscriptions::ROTATIONS) as $n) {
            self::assertSame(200, $rotate()->getStatusCode(), "link {$n}");
        }

        $response = $rotate();

        self::assertSame([429, '3600'], [$response->getStatusCode(), $response->getHeaderLine('Retry-After')]);
        self::assertSame('لینک سرویس را زیاد عوض کرده‌اید؛ ۶۰ دقیقه دیگر دوباره امتحان کنید.', $this->decode($response)['message']);
        self::assertCount(CustomerSubscriptions::ROTATIONS, FakeProvider::$rotated, 'no sixth one');
    }

    public function testAutoRenewIsRefusedWhileItIsNotOffered(): void
    {
        $forever = $this->subscription($this->customer, $this->plan(['duration_days' => 0], $this->server), $this->server, 'ali_1');
        $refused = new AutoRenewUnavailableException();

        $response = $this->patchJson($this->storeApi($this->website, "/subscriptions/{$forever->id}"), ['auto_renew' => true]);
        self::assertSame([422, ['auto_renew' => [$refused->getMessage()]]], [$response->getStatusCode(), $this->decode($response)['errors']], 'it never ends: nothing to renew before');
        self::assertFalse($forever->refresh()->auto_renew);

        // The wallet pays every automatic renewal: switched off, no service is offered the switch.
        $this->walletMethod()->forceFill(['enabled' => false])->save();
        $running = $this->subscription($this->customer, $this->plan, $this->server, 'ali_2', ['auto_renew' => true]);
        $path = $this->storeApi($this->website, "/subscriptions/{$running->id}");
        self::assertSame(['on' => true, 'offered' => false, 'days_before' => 2], $this->decode($this->get($path))['subscription']['auto_renew']);
        self::assertSame(422, $this->patchJson($path, ['auto_renew' => false])->getStatusCode());
        self::assertTrue($running->refresh()->auto_renew);

        foreach ([['auto_renew' => 'yes'], ['auto_renew' => null], []] as $body) {
            $response = $this->unchecked()->patchJson($path, $body);
            self::assertSame([422, ['auto_renew' => [Validation::NOT_A_SWITCH]]], [$response->getStatusCode(), $this->decode($response)['errors']], json_encode($body) ?: '');
        }
    }

    public function testANewLinkReplacesTheOldAndTheBotSaysNothing(): void
    {
        $subscription = $this->subscription($this->customer, $this->plan, $this->server, 'ali_1');
        FakeProvider::mirror($subscription);

        $row = $this->decode($this->postJson($this->storeApi($this->website, "/subscriptions/{$subscription->id}/rotate-link")))['subscription'];

        self::assertSame(['ali_1'], FakeProvider::$rotated);
        self::assertSame('https://fake.test/sub/sub-rotated-1', $row['link']);
        self::assertSame($row['link'], $subscription->refresh()->subscription_url);
        self::assertSame([], $this->telegram()->calls(), 'the customer did it on the site');
    }

    public function testANewLinkIsRefusedToAServiceThatDoesNotRunOrAPanelThatCannot(): void
    {
        $disabled = $this->subscription($this->customer, $this->plan, $this->server, 'ali_1', ['status' => SubscriptionStatus::Disabled]);
        $running = $this->subscription($this->customer, $this->plan, $this->server, 'ali_2');
        FakeProvider::mirror($disabled);
        FakeProvider::mirror($running);

        $response = $this->postJson($this->storeApi($this->website, "/subscriptions/{$disabled->id}/rotate-link"));
        self::assertSame([422, ['status' => [ProvisioningService::ROTATE_INACTIVE]]], [$response->getStatusCode(), $this->decode($response)['errors']]);

        FakeProvider::$capabilities = new Capabilities(inbounds: true, linkRotation: false);
        $path = $this->storeApi($this->website, "/subscriptions/{$running->id}");
        self::assertFalse($this->decode($this->get($path))['subscription']['link_rotation']);
        $response = $this->postJson("{$path}/rotate-link");
        self::assertSame([422, ['status' => [ProvisioningService::ROTATE_UNSUPPORTED]]], [$response->getStatusCode(), $this->decode($response)['errors']]);

        self::assertSame([], FakeProvider::$rotated);
    }

    public function testAPanelThatFailsTheNewLinkIsA502InTheCustomersWords(): void
    {
        $subscription = $this->subscription($this->customer, $this->plan, $this->server, 'ali_1');
        FakeProvider::mirror($subscription);
        FakeProvider::$down = [$this->server->id];

        $response = $this->postJson($this->storeApi($this->website, "/subscriptions/{$subscription->id}/rotate-link"));

        $refusal = CustomerSubscriptions::NOT_ROTATED . 'سرور در دسترس نبود.';
        self::assertSame([502, $refusal, ['panel' => [$refusal]]], [$response->getStatusCode(), $this->decode($response)['message'], $this->decode($response)['errors']], 'what happened in a word: never the owner\'s diagnosis of the panel');
        self::assertSame('https://fake.test/sub/ali_1', $subscription->refresh()->subscription_url);
    }

    public function testAServiceAnotherChangeHoldsIsBusy(): void
    {
        $subscription = $this->subscription($this->customer, $this->plan, $this->server, 'ali_1');
        FakeProvider::mirror($subscription);
        self::assertNotNull(Lease::take($subscription, 60), 'a renewal, a grant or a move is working on it');

        $response = $this->postJson($this->storeApi($this->website, "/subscriptions/{$subscription->id}/rotate-link"));

        self::assertSame([409, (new ServiceBusyException())->getMessage()], [$response->getStatusCode(), $this->decode($response)['message']]);
        self::assertSame([], FakeProvider::$rotated);
    }

    public function testInAnAgentsShopWhatTheirTrafficCoversIsRenewable(): void
    {
        $bot = $this->agentBot(traffic: 20);
        [$website, $token, $small, $big] = CurrentBot::run($bot, function (): array {
            $customer = $this->customer(['telegram_id' => 8181]);
            $small = $this->plan(['name' => 'small', 'traffic_gb' => 10], $this->server);
            $big = $this->plan(['name' => 'big', 'traffic_gb' => 50], $this->server);

            return [$this->website(), $this->customerSession($customer), $this->subscription($customer, $small, $this->server, 'sara_1'), $this->subscription($customer, $big, $this->server, 'sara_2')];
        });
        $this->bearer($token);

        $rows = array_column($this->decode($this->get($this->storeApi($website, '/subscriptions')))['subscriptions'], 'renewable', 'id');

        self::assertSame([$big->id => false, $small->id => true], $rows, 'the 50 GB plan is more than their 20 GB covers');
    }
}
