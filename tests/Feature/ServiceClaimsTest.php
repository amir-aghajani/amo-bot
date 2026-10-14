<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\ClientLink;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Models\Server;
use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Settings\Services\Settings;
use App\Modules\Subscriptions\Enums\ClaimRefusal;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\ServiceClaimRefusedException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Subscriptions\Services\ServiceClaims;
use App\Modules\Subscriptions\Services\ServiceCleanup;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Users\Models\User;
use App\Support\Traffic;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Monolog\LogRecord;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;

/**
 * «افزودن سرویس با لینک»: a client support made on a panel by hand, added to the customer's account by its subscription
 * link or a config — as its panel has it (counters, term, status, IP limit), with no plan. The main shop's alone, while
 * switched on; the panels asked in the servers' order, one in backoff or failing passed over; the first to send it gets
 * it; the report group hears of it; its client on the panel then names the customer, as a transferred one does; a
 * customer's tries are limited.
 */
final class ServiceClaimsTest extends DatabaseTestCase
{
    /** The subscription id support's client was given on the panel, and the link the customer sends with it. */
    private const TOKEN = 'AbCdEf123456';
    private const LINK = 'سلام، این لینک سرویسم است: https://fake.test/sub/AbCdEf123456';

    private const UUID = 'b1c0e3f4-1234-4abc-8def-0123456789ab';

    private Server $germany;
    private Server $france;
    private User $ali;

    /** @var list<int> The servers asked for a link, in order */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12 12:00:00');
        $this->fakePanel();
        $this->germany = $this->fakeServer('آلمان', ['sort' => 1]);
        $this->france = $this->fakeServer('فرانسه', ['sort' => 2]);
        $this->ali = $this->customer(['username' => 'ali']);
        FakeProvider::$onCall = function (int $server, string $call): void {
            if ($call === 'findClientByLink') {
                $this->asked[] = $server;
            }
        };
        $this->claimsOn();
    }

    public function testASubscriptionLinkAddsTheClientAsItsPanelHasItWithNoPlan(): void
    {
        $this->client($this->germany, 'manual_1');

        $claimed = $this->claims()->claim($this->ali, self::LINK)->refresh();

        self::assertSame([$this->ali->id, Bot::MAIN, $this->germany->id, 'manual_1'], [$claimed->user_id, $claimed->bot_id, $claimed->server_id, $claimed->remote_name]);
        self::assertSame('https://fake.test/sub/' . self::TOKEN, $claimed->subscription_url);
        self::assertSame(SubscriptionStatus::Active, $claimed->status);
        self::assertNull($claimed->plan_id, 'no plan until a renewal gives it one');
        self::assertFalse($claimed->is_test);
        self::assertSame('2026-10-12 12:00:00', $claimed->claimed_at?->toDateTimeString());
        self::assertSame([Traffic::GIGABYTE, 2 * Traffic::GIGABYTE, 30 * Traffic::GIGABYTE], [$claimed->upload_bytes, $claimed->download_bytes, $claimed->traffic_limit_bytes], 'the panel\'s counters and quota');
        self::assertSame(2, $claimed->ip_limit, 'the panel\'s IP limit');
        self::assertSame('2026-11-01 12:00:00', $claimed->expires_at?->toDateTimeString());
        self::assertNull($claimed->starts_at, 'when it began the panel does not say: unknown, not the claim');
        self::assertSame(480, $claimed->duration_hours, 'its term: the hours left to its deadline');
        self::assertSame('2026-10-12 12:00:00', $claimed->last_synced_at?->toDateTimeString());
        self::assertNull($claimed->ended_at);
        self::assertNull($claimed->disabled_at);
        self::assertFalse($claimed->auto_renew);
        self::assertSame([$this->germany->id], $this->asked);
        self::assertSame([[], [], [], []], [FakeProvider::$created, FakeProvider::$enabled, FakeProvider::$deleted, FakeProvider::$trafficReset], 'nothing made, switched, deleted or reset');
    }

    public function testItsClientOnThePanelNamesTheCustomerAsATransferredOnesDoesItsTermsUnchanged(): void
    {
        $this->client($this->germany, 'manual_1');

        $this->claims()->claim($this->ali, self::LINK);

        self::assertSame(['manual_1'], FakeProvider::updatedNames(), 'the one client, updated once');
        $spec = FakeProvider::lastUpdate('manual_1');
        self::assertSame([self::TELEGRAM_ID, self::TELEGRAM_ID . ' | ali'], [$spec->telegramId, $spec->comment]);
        self::assertSame([30 * Traffic::GIGABYTE, 2, '2026-11-01 12:00:00'], [$spec->totalBytes, $spec->ipLimit, $spec->expiry->deadline()?->format('Y-m-d H:i:s')], 'its quota, devices and deadline as the panel had them');
    }

    public function testAPanelThatFailsToNameTheCustomerIsLoggedAndTheClaimStands(): void
    {
        $logs = $this->logs();
        $this->client($this->germany, 'manual_1');
        FakeProvider::$refusing[$this->germany->id] = ['updateClient'];

        $claimed = $this->claims()->claim($this->ali, self::LINK);

        self::assertSame($this->ali->id, $claimed->fresh()?->user_id);
        self::assertTrue($logs->hasWarningThatPasses(static fn(LogRecord $record): bool => str_contains($record->message, 'does not name them yet')));
    }

    public function testAConfigFindsTheClientByItsCredential(): void
    {
        $this->client($this->france, 'manual_2', token: 'ZzYyXxWw9876');
        FakeProvider::$credentials[$this->france->id]['manual_2'] = [self::UUID];

        $claimed = $this->claims()->claim($this->ali, 'vless://' . strtoupper(self::UUID) . '@fr.example.com:443?type=tcp&security=reality#manual_2');

        self::assertSame([$this->france->id, 'manual_2'], [$claimed->server_id, $claimed->remote_name]);
        self::assertSame('https://fake.test/sub/ZzYyXxWw9876', $claimed->subscription_url, 'the customer gets the subscription link, not the config');
        self::assertSame([$this->germany->id, $this->france->id], $this->asked);
    }

    public function testATermWaitingForTheFirstConnectionStaysATermAndNoIpLimitIsNone(): void
    {
        $this->client($this->germany, 'manual_1', expiry: Expiry::afterFirstUse(30 * 86400 + 1800), ipLimit: null);

        $claimed = $this->claims()->claim($this->ali, self::LINK)->refresh();

        self::assertSame(SubscriptionStatus::Active, $claimed->status);
        self::assertSame(721, $claimed->duration_hours, 'in whole hours, a part of one counting as one');
        self::assertNull($claimed->expires_at);
        self::assertNull($claimed->starts_at);
        self::assertTrue($claimed->awaitsFirstUse());
        self::assertSame(0, $claimed->ip_limit, 'a panel that keeps no IP limit: unlimited');
    }

    public function testAClientWithoutAQuotaOrATermRunsForEver(): void
    {
        $this->client($this->germany, 'manual_1', total: 0, expiry: Expiry::never());

        $claimed = $this->claims()->claim($this->ali, self::LINK)->refresh();

        self::assertSame(SubscriptionStatus::Active, $claimed->status);
        self::assertSame([0, 0], [$claimed->traffic_limit_bytes, $claimed->duration_hours]);
        self::assertNull($claimed->expires_at);
    }

    public function testAClientWhoseTimeRanOutComesInEndedCountingItsRemovalFromTheClaim(): void
    {
        $this->client($this->germany, 'manual_1', expiry: Expiry::at(new \DateTimeImmutable('2026-10-02 08:00:00', new \DateTimeZone('UTC'))));
        $this->botSettings('expired_services', ['expired_cleanup' => true, 'expired_cleanup_hours' => 72]);

        $claimed = $this->claims()->claim($this->ali, self::LINK)->refresh();

        self::assertSame(SubscriptionStatus::Expired, $claimed->status);
        self::assertSame('2026-10-02 08:00:00', $claimed->expires_at?->toDateTimeString());
        self::assertNull($claimed->starts_at, 'never shown as connected first at the claim, after its end');
        self::assertSame('2026-10-12 12:00:00', $claimed->ended_at?->toDateTimeString(), 'from the claim: claimed to be renewed, not removed at once');
        self::assertSame('2026-10-15 12:00:00', $this->service(ServiceCleanup::class)->removesAt($claimed)?->toDateTimeString());
        self::assertSame([], $this->service(ServiceCleanup::class)->due(now())->modelKeys());
    }

    public function testAClientWhoseTrafficRanOutComesInEndedFromNow(): void
    {
        $this->client($this->germany, 'manual_1', upload: 4 * Traffic::GIGABYTE, download: 6 * Traffic::GIGABYTE, total: 10 * Traffic::GIGABYTE);

        $claimed = $this->claims()->claim($this->ali, self::LINK)->refresh();

        self::assertSame(SubscriptionStatus::Expired, $claimed->status);
        self::assertSame('2026-10-12 12:00:00', $claimed->ended_at?->toDateTimeString(), 'when the panel said so');
    }

    public function testAClientSwitchedOffOnThePanelWhileItRunsIsKeptSwitchedOff(): void
    {
        $this->client($this->germany, 'manual_1', enabled: false);

        $claimed = $this->claims()->claim($this->ali, self::LINK)->refresh();

        self::assertSame(SubscriptionStatus::Disabled, $claimed->status, 'support\'s switch');
        self::assertSame('2026-10-12 12:00:00', $claimed->disabled_at?->toDateTimeString());
        self::assertNull($claimed->ended_at);
        self::assertFalse(FakeProvider::lastUpdate('manual_1')->enabled, 'named the customer, kept switched off');
        self::assertSame([], FakeProvider::$enabled, 'never switched on, nor again off');
        self::assertFalse(FakeProvider::$clients[$this->germany->id]['manual_1']->enabled, 'and left off on the panel');
    }

    public function testAClientThePanelSwitchedOffOnceUsedUpComesInEndedToRenew(): void
    {
        // 3x-ui switches a client off by itself once it is used up.
        $this->client($this->germany, 'manual_1', token: 'Tokenmanual_1', enabled: false, expiry: Expiry::at(new \DateTimeImmutable('2026-10-10 08:00:00', new \DateTimeZone('UTC'))));
        $this->client($this->germany, 'manual_2', token: 'Tokenmanual_2', enabled: false, upload: 4 * Traffic::GIGABYTE, download: 6 * Traffic::GIGABYTE, total: 10 * Traffic::GIGABYTE);

        foreach (['its time' => 'manual_1', 'its traffic' => 'manual_2'] as $case => $name) {
            $claimed = $this->claims()->claim($this->ali, 'https://fake.test/sub/Token' . $name)->refresh();

            self::assertSame(SubscriptionStatus::Expired, $claimed->status, $case);
            self::assertNull($claimed->disabled_at, $case);
            self::assertSame('2026-10-12 12:00:00', $claimed->ended_at?->toDateTimeString(), $case);
        }
    }

    public function testOffUntilTheAdminSwitchesItOn(): void
    {
        $this->claimsOn(false);
        $this->client($this->germany, 'manual_1');

        self::assertFalse($this->claims()->offered());
        self::assertSame(ClaimRefusal::Off, $this->refused(fn() => $this->claims()->claim($this->ali, self::LINK))->reason);
        self::assertSame([], $this->asked, 'no panel asked');
        self::assertSame(0, Subscription::query()->count());

        $this->claimsOn();
        self::assertTrue($this->claims()->offered());
    }

    public function testOffByDefault(): void
    {
        $this->service(Settings::class)->forget('claims.enabled');

        self::assertFalse($this->claims()->offered(), 'a shop that never saved the switch');
    }

    public function testAnAgentsShopTakesNoServiceByItsLinkEvenSwitchedOn(): void
    {
        $this->client($this->germany, 'manual_1');
        $bot = $this->agentBot();

        CurrentBot::run($bot, function (): void {
            // An agent's shop has no such switch; a value stored for it all the same changes nothing.
            self::assertArrayNotHasKey('claim_enabled', $this->service(BotSettingsScreen::class)->present());
            $this->service(Settings::class)->set('claims.enabled', true);
            $reza = $this->customer(['telegram_id' => 7003, 'username' => 'reza']);

            self::assertFalse($this->claims()->offered());
            self::assertSame(ClaimRefusal::Off, $this->refused(fn() => $this->claims()->claim($reza, self::LINK))->reason);
        });
        self::assertSame([], $this->asked);
        self::assertSame(0, Subscription::acrossShops()->count());
    }

    public function testWhatHoldsNoLinkIsRefusedWithoutAskingAPanel(): void
    {
        foreach (['سلام، سرویسم وصل نمی‌شود', 'https://fake.test/sub/abc', 'trojan://short@fake.test:443'] as $text) {
            $refused = $this->refused(fn() => $this->claims()->claim($this->ali, $text));
            self::assertSame(ClaimRefusal::NotALink, $refused->reason, $text);
            self::assertSame(422, $refused->status());
            self::assertSame(['link' => [ClaimRefusal::NotALink->message()]], $refused->errors());
        }
        self::assertSame([], $this->asked);
    }

    public function testACustomerHasTenTriesInTenMinutesAndWhatIsNoLinkIsNoTry(): void
    {
        for ($i = 0; $i < ServiceClaims::TRIES; $i++) {
            $this->refused(fn() => $this->claims()->claim($this->ali, 'سلام'));
        }
        for ($i = 0; $i < ServiceClaims::TRIES; $i++) {
            self::assertSame(ClaimRefusal::NotFound, $this->refused(fn() => $this->claims()->claim($this->ali, self::LINK))->reason, "try {$i}");
        }

        try {
            $this->claims()->claim($this->ali, self::LINK);
            self::fail('one too many');
        } catch (TooManyAttemptsException $e) {
            self::assertSame(429, $e->status());
            self::assertSame(ServiceClaims::WINDOW_SECONDS, $e->retryAfter);
        }
        self::assertCount(2 * ServiceClaims::TRIES, $this->asked, 'the refused try asked no panel');

        $reza = $this->customer(['telegram_id' => 7003, 'username' => 'reza']);
        self::assertSame(ClaimRefusal::NotFound, $this->refused(fn() => $this->claims()->claim($reza, self::LINK))->reason, 'each customer has their own');

        Carbon::setTestNow(now()->addSeconds(ServiceClaims::WINDOW_SECONDS + 1));
        $this->client($this->germany, 'manual_1');
        self::assertSame('manual_1', $this->claims()->claim($this->ali, self::LINK)->remote_name, 'a new window');
    }

    public function testThePanelsAreAskedInTheServersOrderAndTheFirstThatHasItWins(): void
    {
        // Made first, sold last: the order is the admin's, not the ids'.
        $netherlands = $this->fakeServer('هلند', ['sort' => 3]);
        $this->germany->forceFill(['sort' => 4])->save();
        $this->client($this->germany, 'de_1');
        $this->client($netherlands, 'nl_1');

        $claimed = $this->claims()->claim($this->ali, self::LINK);

        self::assertSame([$netherlands->id, 'nl_1'], [$claimed->server_id, $claimed->remote_name]);
        self::assertSame([$this->france->id, $netherlands->id], $this->asked, 'none asked after the one that had it');
    }

    public function testNoPanelHasItWhenEveryOneAnswered(): void
    {
        $refused = $this->refused(fn() => $this->claims()->claim($this->ali, self::LINK));

        self::assertSame(ClaimRefusal::NotFound, $refused->reason);
        self::assertSame(422, $refused->status());
        self::assertSame([$this->germany->id, $this->france->id], $this->asked);
    }

    public function testAPanelInBackoffIsNotAskedAndThenNothingFoundIsNoAnswer(): void
    {
        $this->germany->forceFill(['last_error' => 'Connection timed out', 'last_checked_at' => now()->subMinutes(2)])->save();

        $refused = $this->refused(fn() => $this->claims()->claim($this->ali, self::LINK));

        self::assertSame(ClaimRefusal::Unavailable, $refused->reason, 'it might have been there');
        self::assertSame(502, $refused->status());
        self::assertSame([$this->france->id], $this->asked);

        $this->client($this->france, 'manual_1');
        self::assertSame($this->france->id, $this->claims()->claim($this->ali, self::LINK)->server_id, 'found on another, it is claimed');
    }

    public function testAPanelThatFailsIsLoggedAndPassedOver(): void
    {
        $logs = $this->logs();
        FakeProvider::$down = [$this->germany->id];
        $this->client($this->france, 'manual_1');

        $claimed = $this->claims()->claim($this->ali, self::LINK);

        self::assertSame([$this->france->id, 'manual_1'], [$claimed->server_id, $claimed->remote_name], 'found on the second while the first is down');
        self::assertSame([$this->germany->id, $this->france->id], $this->asked);
        self::assertTrue($logs->hasWarningThatPasses(static fn(LogRecord $record): bool => str_starts_with($record->message, 'Could not look for a service sent by its link') && $record->context['server'] === 'آلمان'));
        self::assertNotNull($this->germany->refresh()->last_error, 'the failure is recorded on the server');
    }

    public function testAPanelThatRefusesAndNoneElseHasItIsNoAnswer(): void
    {
        FakeProvider::$refusing[$this->france->id] = ['findClientByLink'];

        self::assertSame(ClaimRefusal::Unavailable, $this->refused(fn() => $this->claims()->claim($this->ali, self::LINK))->reason);
        self::assertSame([$this->germany->id, $this->france->id], $this->asked);
    }

    public function testAServerWhoseConnectorIsGoneIsPassedOver(): void
    {
        // A server left from another installation, before the one that has the client.
        $this->fakeServer('قدیمی', ['sort' => 0, 'driver' => 'gone-connector']);

        self::assertSame(ClaimRefusal::NotFound, $this->refused(fn() => $this->claims()->claim($this->ali, self::LINK))->reason, 'it can never answer: no reason to try later');

        $this->client($this->germany, 'manual_1');
        self::assertSame('manual_1', $this->claims()->claim($this->ali, self::LINK)->remote_name);
    }

    public function testTheirOwnServiceListedAsGoneComesBackClaimedWhenItsPanelHasItAgain(): void
    {
        $plan = $this->plan([], $this->germany);
        $stale = $this->claimedSubscription($this->ali, $this->germany, 'manual_1');
        $stale->forceFill(['status' => SubscriptionStatus::Deleted, 'ended_at' => now()->subDay(), 'plan_id' => $plan->id, 'auto_renew' => true])->save();
        $this->client($this->germany, 'manual_1');

        $claimed = $this->claims()->claim($this->ali, self::LINK)->refresh();

        self::assertNotSame($stale->id, $claimed->id, 'a new record');
        self::assertFalse(Subscription::query()->whereKey($stale->id)->exists(), 'the stale one gone');
        self::assertSame(SubscriptionStatus::Active, $claimed->status);
        self::assertSame([$plan->id, true], [$claimed->plan_id, $claimed->auto_renew], 'what they had of it comes back with it');
        self::assertSame([], FakeProvider::$deleted, 'nothing on the panel touched');
    }

    public function testTheirOwnServiceListedAsGoneStaysWhenTheClaimIsRefusedForWantOfALink(): void
    {
        $stale = $this->claimedSubscription($this->ali, $this->germany, 'manual_1');
        $stale->forceFill(['status' => SubscriptionStatus::Deleted, 'ended_at' => now()->subDay()])->save();
        $this->client($this->germany, 'manual_1', link: false);
        FakeProvider::$credentials[$this->germany->id]['manual_1'] = [self::UUID];

        self::assertSame(ClaimRefusal::NoLink, $this->refused(fn() => $this->claims()->claim($this->ali, 'vless://' . self::UUID . '@de.example.com:443'))->reason);
        self::assertSame(SubscriptionStatus::Deleted, Subscription::query()->find($stale->id)?->status, 'the record kept as it was: nothing comes in its place');
        self::assertSame(1, Subscription::query()->count());
    }

    public function testTheirOwnTestListedAsGoneComesBackATest(): void
    {
        $stale = $this->claimedSubscription($this->ali, $this->germany, 'manual_1');
        $stale->forceFill(['status' => SubscriptionStatus::Deleted, 'ended_at' => now()->subDay(), 'plan_id' => null, 'is_test' => true])->save();
        $this->client($this->germany, 'manual_1');

        $claimed = $this->claims()->claim($this->ali, self::LINK)->refresh();

        self::assertTrue($claimed->is_test, 'its test rules, not a plan to pick');
    }

    public function testAPartOfAnHourLeftCountsAsAWholeOne(): void
    {
        $this->client($this->germany, 'manual_1', expiry: Expiry::at(new \DateTimeImmutable('2026-10-12 14:30:00', new \DateTimeZone('UTC'))));

        self::assertSame(3, $this->claims()->claim($this->ali, self::LINK)->refresh()->duration_hours, 'two and a half hours left');
    }

    public function testSomeoneElsesServiceListedAsGoneIsLeftForSupport(): void
    {
        $reza = $this->customer(['telegram_id' => 7003, 'username' => 'reza']);
        $stale = $this->claimedSubscription($reza, $this->germany, 'manual_1');
        $stale->forceFill(['status' => SubscriptionStatus::Deleted, 'ended_at' => now()->subDay()])->save();
        $this->client($this->germany, 'manual_1');

        self::assertSame(ClaimRefusal::Taken, $this->refused(fn() => $this->claims()->claim($this->ali, self::LINK))->reason);
        self::assertTrue(Subscription::query()->whereKey($stale->id)->exists());
    }

    public function testAClientAnotherCustomerHasIsTaken(): void
    {
        $reza = $this->customer(['telegram_id' => 7003, 'username' => 'reza']);
        $this->claimedSubscription($reza, $this->germany, 'manual_1');
        $this->client($this->germany, 'manual_1');

        $refused = $this->refused(fn() => $this->claims()->claim($this->ali, self::LINK));

        self::assertSame(ClaimRefusal::Taken, $refused->reason);
        self::assertSame(409, $refused->status());
        self::assertNull($refused->service, 'nothing of the other customer\'s is shown');
        self::assertSame(1, Subscription::query()->count());
    }

    public function testAClientAnAgentsCustomerHasIsTaken(): void
    {
        $this->client($this->germany, 'manual_1');
        $bot = $this->agentBot();
        CurrentBot::run($bot, function (): void {
            $this->claimedSubscription($this->customer(['telegram_id' => 7003, 'username' => 'reza']), $this->germany, 'manual_1');
        });

        self::assertSame(ClaimRefusal::Taken, $this->refused(fn() => $this->claims()->claim($this->ali, self::LINK))->reason);
        self::assertSame(1, Subscription::acrossShops()->count());
    }

    public function testAClientTheCustomerHasAlreadyIsTheirs(): void
    {
        $mine = $this->claimedSubscription($this->ali, $this->germany, 'manual_1');
        $this->client($this->germany, 'manual_1');

        $refused = $this->refused(fn() => $this->claims()->claim($this->ali, self::LINK));

        self::assertSame(ClaimRefusal::Yours, $refused->reason);
        self::assertSame(422, $refused->status());
        self::assertSame($mine->id, $refused->service?->id, 'which of their services it is');
        self::assertSame(1, Subscription::query()->count());
    }

    public function testAClientWithoutASubscriptionLinkIsNotAdded(): void
    {
        $this->client($this->germany, 'manual_1', link: false);
        FakeProvider::$credentials[$this->germany->id]['manual_1'] = [self::UUID];

        self::assertSame(ClaimRefusal::NoLink, $this->refused(fn() => $this->claims()->claim($this->ali, 'vless://' . self::UUID . '@de.example.com:443'))->reason);
        self::assertSame(0, Subscription::query()->count());
    }

    public function testOfTwoClaimsAtOnceTheFirstToWriteWins(): void
    {
        $reza = $this->customer(['telegram_id' => 7003, 'username' => 'reza']);
        $this->client($this->germany, 'manual_1');

        // Reza's claim is written between Ali's check and Ali's write.
        $refused = $this->whileTheClientIsClaimedMeanwhile($reza, fn() => $this->refused(fn() => $this->claims()->claim($this->ali, self::LINK)));

        self::assertSame(ClaimRefusal::Taken, $refused->reason);
        self::assertSame($reza->id, Subscription::query()->sole()->user_id);
    }

    public function testASecondTapOfTheSameCustomerFindsItTheirs(): void
    {
        $this->client($this->germany, 'manual_1');

        $refused = $this->whileTheClientIsClaimedMeanwhile($this->ali, fn() => $this->refused(fn() => $this->claims()->claim($this->ali, self::LINK)));

        self::assertSame(ClaimRefusal::Yours, $refused->reason);
        self::assertSame(Subscription::query()->sole()->id, $refused->service?->id);
    }

    public function testTheReportGroupHearsOfEveryClaimInThePurchasesTopic(): void
    {
        $this->reportGroup();
        $this->client($this->germany, 'manual<1>&');
        $this->client($this->france, 'manual_2', token: 'ZzYyXxWw9876');

        $first = $this->claims()->claim($this->ali, 'https://fake.test/sub/' . self::TOKEN);
        $sara = $this->customer(['telegram_id' => 7004, 'first_name' => 'Sara', 'username' => null]);
        $second = $this->claims()->claim($sara, 'https://fake.test/sub/ZzYyXxWw9876');

        self::assertSame([
            implode("\n", [
                "🔗 <b>سرویس با لینک به حساب مشتری اضافه شد</b> · اشتراک #{$first->id}",
                '👤 <a href="tg://user?id=5151">Ali</a> · @ali · <code>5151</code>',
                '🔖 سرویس: <code>manual&lt;1&gt;&amp;</code>',
                '📍 لوکیشن: آلمان',
            ]),
            implode("\n", [
                "🔗 <b>سرویس با لینک به حساب مشتری اضافه شد</b> · اشتراک #{$second->id}",
                '👤 <a href="tg://user?id=7004">Sara</a> · بدون نام کاربری · <code>7004</code>',
                '🔖 سرویس: <code>manual_2</code>',
                '📍 لوکیشن: فرانسه',
            ]),
        ], ReportMessage::query()->where('topic', Topic::Purchases->value)->oldest('id')->pluck('text')->all());
        self::assertSame(2, ReportMessage::query()->count(), 'nothing else');
    }

    public function testWithoutAReportGroupTheClaimStandsAlone(): void
    {
        $this->client($this->germany, 'manual_1');

        $this->claims()->claim($this->ali, self::LINK);

        self::assertSame(1, Subscription::query()->count());
        self::assertSame(0, ReportMessage::query()->count());
    }

    public function testAdoptingWritesTheRowAndRunsItsWorkInOneTransaction(): void
    {
        $client = $this->client($this->germany, 'manual_1');
        $outer = $this->db()->transactionLevel();
        $seen = null;

        $claimed = $this->provisioning()->adopt($this->ali, $this->germany, $client, now(), function (Subscription $row) use (&$seen): void {
            $seen = [$this->db()->transactionLevel(), Subscription::query()->whereKey($row->id)->exists()];
        });

        self::assertSame([$outer + 1, true], $seen, 'the row written, in the transaction');
        self::assertTrue($claimed->exists);

        $other = $this->client($this->germany, 'manual_2', token: 'ZzYyXxWw9876');
        try {
            $this->provisioning()->adopt($this->ali, $this->germany, $other, now(), static function (): void {
                throw new \DomainException('the report could not be written');
            });
            self::fail('the work failed');
        } catch (\DomainException) {
        }
        self::assertFalse(Subscription::query()->where('remote_name', 'manual_2')->exists(), 'no row without its work');
    }

    public function testAdoptingAClientTheShopKeepsAlreadyFailsOnTheRowsKey(): void
    {
        $this->claimedSubscription($this->ali, $this->germany, 'manual_1');
        $ran = false;

        try {
            $this->provisioning()->adopt($this->ali, $this->germany, $this->client($this->germany, 'manual_1'), now(), static function () use (&$ran): void {
                $ran = true;
            });
            self::fail('kept already');
        } catch (UniqueConstraintViolationException) {
        }
        self::assertFalse($ran);
        self::assertSame(1, Subscription::query()->count());
    }

    public function testFindingByLinkRecordsTheContactOnTheServer(): void
    {
        $this->germany->forceFill(['last_error' => 'Connection timed out', 'last_checked_at' => now()->subHour()])->save();
        $this->client($this->germany, 'manual_1');

        $found = $this->provisioning()->findByLink($this->germany, ClientLink::find(self::LINK) ?? self::fail('a link'));

        self::assertSame('manual_1', $found?->name);
        self::assertNull($this->germany->refresh()->last_error, 'it answers again');
    }

    /**
     * A client support made on the server's panel by hand, its link ending in `$token` (none without `$link`): 1 GB up and
     * 2 down of 30, until 1 November, two devices — unless told otherwise.
     */
    private function client(
        Server $server,
        string $name,
        string $token = self::TOKEN,
        bool $enabled = true,
        int $upload = Traffic::GIGABYTE,
        int $download = 2 * Traffic::GIGABYTE,
        int $total = 30 * Traffic::GIGABYTE,
        ?Expiry $expiry = null,
        ?int $ipLimit = 2,
        bool $link = true,
    ): ClientInfo {
        $client = new ClientInfo(
            name: $name,
            enabled: $enabled,
            uploadBytes: $upload,
            downloadBytes: $download,
            totalBytes: $total,
            expiry: $expiry ?? Expiry::at(new \DateTimeImmutable('2026-11-01 12:00:00', new \DateTimeZone('UTC'))),
            subscriptionUrl: $link ? 'https://fake.test/sub/' . $token : null,
            ipLimit: $ipLimit,
        );
        FakeProvider::put($server, $client);

        return $client;
    }

    /**
     * `$work` done while `$user` claims the client in another process: their row is written right after `$work`'s claim
     * found the client nobody's, before it writes its own.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function whileTheClientIsClaimedMeanwhile(User $user, \Closure $work): mixed
    {
        $written = false;

        return $this->whileListening(QueryExecuted::class, function (QueryExecuted $query) use (&$written, $user): void {
            if ($written || !str_starts_with($query->sql, 'select') || !str_contains($query->sql, '"remote_name" = ?')) {
                return;
            }
            $written = true;
            $this->db()->table('subscriptions')->insert([
                'bot_id' => Bot::MAIN,
                'user_id' => $user->id,
                'server_id' => $this->germany->id,
                'remote_name' => 'manual_1',
                'subscription_url' => 'https://fake.test/sub/' . self::TOKEN,
                'status' => SubscriptionStatus::Active->value,
                'claimed_at' => now(),
            ]);
        }, $work);
    }

    /** @param \Closure(): mixed $work */
    private function refused(\Closure $work): ServiceClaimRefusedException
    {
        try {
            $work();
        } catch (ServiceClaimRefusedException $e) {
            return $e;
        }

        self::fail('refused');
    }

    private function claims(): ServiceClaims
    {
        return $this->service(ServiceClaims::class);
    }

    private function provisioning(): ProvisioningService
    {
        return $this->service(ProvisioningService::class);
    }
}
