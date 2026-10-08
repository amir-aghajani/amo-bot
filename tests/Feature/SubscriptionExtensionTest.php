<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database\Lease;
use App\Modules\Agency\Enums\TrafficTransactionType;
use App\Modules\Agency\Exceptions\TrafficShortException;
use App\Modules\Agency\Models\TrafficTransaction;
use App\Modules\Agency\Services\TrafficPool;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Exceptions\PanelApiException;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Services\ProviderErrorPresenter;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\ServiceBusyException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Support\Input;
use App\Support\Traffic;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;
use Tests\Support\FakeTelegram;

/**
 * «افزایش زمان و حجم» on the subscriptions screen: support gives one service days and traffic on top of what it has —
 * exactly what a grant gives it, from what its panel says right now —, with a note the customer reads unless the admin
 * keeps it quiet. Only an active service that can take them gets them; another change holding the service is a 409, a
 * panel that fails a 502. In an agent's shop the traffic comes out of the agent's bot's — a ledger line of its own,
 * refused before any panel is asked when it is short, given back when the extension does not go through —, the days
 * cost nothing.
 */
final class SubscriptionExtensionTest extends HttpTestCase
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
        $this->loginAsAdmin();

        $this->ali = $this->customer(['telegram_id' => 1001, 'username' => 'ali']);
        $this->server = $this->fakeServer();
        $this->plan = $this->plan();
    }

    public function testDaysAndTrafficGoOnTopOfARunningServiceAndItsCustomerIsTold(): void
    {
        $subscription = $this->mirrored('ali_1', ['expires_at' => now()->addDays(10), 'download_bytes' => 20 * Traffic::GIGABYTE]);
        $logs = $this->logs();

        $response = $this->extend($subscription, ['days' => '۳', 'traffic_gb' => '10', 'note' => 'جبران قطعی دیروز']);

        self::assertSame(200, $response->getStatusCode());
        $row = $this->decode($response)['subscription'];
        self::assertStringStartsWith('2026-10-03T12:00:00', (string) $row['expires_at'], 'three days after its deadline');
        self::assertSame(33, $row['duration_days']);
        self::assertSame(['limit' => 40 * Traffic::GIGABYTE, 'used' => 20 * Traffic::GIGABYTE], $row['traffic'], 'ten on top of its thirty');
        $spec = FakeProvider::lastUpdate('ali_1');
        self::assertSame([40 * Traffic::GIGABYTE, '2026-10-03 12:00:00'], [$spec->totalBytes, $spec->expiry->deadline()?->format('Y-m-d H:i:s')]);
        self::assertSame(['ali_1'], FakeProvider::updatedNames());

        self::assertSame([self::text(BotText::ServiceGranted, [
            'gift' => Messages::gift(3, 10 * Traffic::GIGABYTE),
            'client' => 'ali_1',
            'note' => self::text(BotText::AdminNote, ['comment' => 'جبران قطعی دیروز']),
            'expires' => Messages::expiry(new \DateTimeImmutable('2026-10-03 12:00:00'), 33),
            'remaining' => Messages::bytes(20 * Traffic::GIGABYTE),
        ])], $this->telegram()->sentTo(1001), 'what it got, why, and where it stands now: the message a grant sends');
        self::assertTrue($logs->hasInfoThatContains('extended by ' . self::ADMIN_USERNAME), 'the log says who gave it');
        self::assertSame(0, TrafficTransaction::query()->count(), "the main bot's shop draws on nobody's traffic");
    }

    public function testAServiceWaitingForItsFirstConnectionGetsALongerTerm(): void
    {
        $subscription = $this->mirrored('ali_1', ['starts_at' => null, 'expires_at' => null]);

        $row = $this->decode($this->extend($subscription, ['days' => 5]))['subscription'];

        self::assertNull($row['expires_at'], 'the clock still starts at the first connection');
        self::assertSame(35, $row['duration_days']);
        $spec = FakeProvider::lastUpdate('ali_1');
        self::assertSame([35 * 86400, 30 * Traffic::GIGABYTE], [$spec->expiry->pendingSeconds(), $spec->totalBytes], 'a longer term, the quota as it was');
        self::assertSame([self::text(BotText::ServiceGranted, [
            'gift' => Messages::gift(5, 0),
            'client' => 'ali_1',
            'note' => '',
            'expires' => Messages::expiry(null, 35),
            'remaining' => Messages::bytes(30 * Traffic::GIGABYTE),
        ])], $this->telegram()->sentTo(1001));
    }

    public function testEachAmountAloneLeavesTheOtherAsItWasAndAQueuedPeriodMovesWithTheDays(): void
    {
        $subscription = $this->mirrored('ali_1', ['expires_at' => now()->addDays(10), 'period_ends_at' => now()->addDays(10), 'next_period_bytes' => 30 * Traffic::GIGABYTE]);

        $row = $this->decode($this->extend($subscription, ['traffic_gb' => '۲٫۵']))['subscription'];

        self::assertSame(Traffic::bytesOfGb('32.5'), $row['traffic']['limit'], 'Persian digits and decimals as typed');
        self::assertStringStartsWith('2026-09-30T12:00:00', (string) $row['expires_at'], 'the deadline as it was');
        self::assertSame(['starts_at' => now()->addDays(10)->toIso8601String(), 'traffic' => Traffic::bytesOfGb('32.5')], $row['next_period'], 'the traffic given outlives the period in use');

        $row = $this->decode($this->extend($subscription, ['days' => 4, 'traffic_gb' => '']))['subscription'];

        self::assertStringStartsWith('2026-10-04T12:00:00', (string) $row['expires_at']);
        self::assertSame(Traffic::bytesOfGb('32.5'), $row['traffic']['limit'], 'the quota as it was');
        self::assertStringStartsWith('2026-10-04T12:00:00', $row['next_period']['starts_at'], 'the renewal queued behind the period moves with its days');
    }

    public function testWhatTheServiceCannotTakeIsRefusedUnderItsField(): void
    {
        $endless = $this->mirrored('ali_1', ['duration_days' => 0, 'expires_at' => null]);
        $unlimited = $this->mirrored('ali_2', ['traffic_limit_bytes' => 0]);
        $neither = $this->mirrored('ali_3', ['duration_days' => 0, 'expires_at' => null, 'traffic_limit_bytes' => 0]);

        self::assertSame(['days' => ['این سرویس تاریخ پایان ندارد؛ روزی به آن اضافه نمی‌شود.']], $this->errors($endless, ['days' => 3, 'traffic_gb' => 5]));
        self::assertSame(['traffic_gb' => ['حجم این سرویس نامحدود است؛ حجمی به آن اضافه نمی‌شود.']], $this->errors($unlimited, ['days' => 3, 'traffic_gb' => 5]));
        self::assertSame(['status' => ['این سرویس نه تاریخ پایان دارد نه سقف حجم؛ چیزی به آن اضافه نمی‌شود.']], $this->errors($neither, ['days' => 3]));
        self::assertSame([true, true, false], [$this->row($endless)['actions']['extend'], $this->row($unlimited)['actions']['extend'], $this->row($neither)['actions']['extend']], 'nothing to give: not offered');

        self::assertSame(200, $this->extend($endless, ['traffic_gb' => 5])->getStatusCode(), 'what it can take, it gets');
        self::assertSame(['ali_1'], FakeProvider::updatedNames());
    }

    public function testWhatTheAdminTypesIsCheckedBeforeThePanelIsTouched(): void
    {
        $subscription = $this->mirrored('ali_1');
        $calls = $this->panelCalls();

        self::assertSame(['grant' => ['زمان یا حجمی برای افزودن وارد کنید.']], $this->errors($subscription, ['days' => '', 'traffic_gb' => '0']));
        self::assertArrayHasKey('days', $this->errors($subscription, ['days' => 400]));
        self::assertArrayHasKey('days', $this->errors($subscription, ['days' => 'سه']));
        self::assertArrayHasKey('traffic_gb', $this->errors($subscription, ['traffic_gb' => '10001']));
        self::assertArrayHasKey('traffic_gb', $this->errors($subscription, ['traffic_gb' => '1.234']));
        self::assertSame(['days', 'note'], array_keys($this->errors($subscription, ['days' => 'سه', 'note' => str_repeat('ا', Input::NOTE_MAX + 1)])), 'every field at once');

        self::assertSame([], $calls->getArrayCopy(), 'no panel was asked');
        self::assertSame([], $this->telegram()->calls());
    }

    public function testOnlyAnActiveServiceIsExtended(): void
    {
        $expired = $this->mirrored('ali_1', ['status' => SubscriptionStatus::Expired, 'expires_at' => now()->subDay()]);
        $disabled = $this->mirrored('ali_2', ['status' => SubscriptionStatus::Disabled, 'disabled_at' => now()]);

        // Each refused in the words of what it came to.
        $refusals = [
            'ali_1' => 'این سرویس منقضی شده است و تمدید لازم دارد.',
            'ali_2' => 'این سرویس غیرفعال شده است؛ زمان و حجم فقط به سرویس فعال اضافه می‌شود.',
        ];
        foreach ([$expired, $disabled] as $subscription) {
            self::assertSame(['status' => [$refusals[$subscription->remote_name]]], $this->errors($subscription, ['days' => 3]), $subscription->remote_name);
            self::assertFalse($this->row($subscription)['actions']['extend']);
        }
        self::assertSame([], FakeProvider::$updated);
    }

    public function testAServiceItsPanelSaysHasEndedTakesNothingAndTheRowLearnsIt(): void
    {
        // The shop still has it running; its panel has it used up.
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1');
        FakeProvider::put($this->server, new ClientInfo(name: 'ali_1', enabled: true, downloadBytes: 30 * Traffic::GIGABYTE, totalBytes: 30 * Traffic::GIGABYTE, expiry: Expiry::at(now()->addDays(10)->toDateTimeImmutable()), subscriptionUrl: $subscription->subscription_url));

        self::assertArrayHasKey('status', $this->errors($subscription, ['days' => 3, 'traffic_gb' => 5]));

        self::assertSame(SubscriptionStatus::Expired, $subscription->refresh()->status, 'and the row learned it ended');
        self::assertSame([], FakeProvider::$updated);
        self::assertSame([], $this->telegram()->calls());
    }

    public function testAClientSwitchedOffOnThePanelItselfIsLeftSo(): void
    {
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1');
        FakeProvider::mirror($subscription, enabled: false);

        self::assertSame(['status' => ['این سرویس روی پنل غیرفعال شده است؛ زمان و حجم فقط به سرویس فعال اضافه می‌شود.']], $this->errors($subscription, ['days' => 3]));

        self::assertSame([[], []], [FakeProvider::$updated, FakeProvider::$enabled], 'a grant would have switched it back on');
    }

    public function testAServiceAnotherChangeHoldsIsBusyAndNothingHappens(): void
    {
        $subscription = $this->mirrored('ali_1');
        self::assertNotNull(Lease::take($subscription, 60), 'a renewal, a grant or a move is working on it');

        $response = $this->extend($subscription, ['days' => 3]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame((new ServiceBusyException())->getMessage(), $this->decode($response)['message']);
        self::assertSame([], FakeProvider::$updated);
        self::assertSame(30, $subscription->refresh()->duration_days);
        self::assertSame([], $this->telegram()->calls());
    }

    public function testAPanelThatFailsIsNamedInTheAdminsWordsAndNothingIsKept(): void
    {
        $subscription = $this->mirrored('ali_1');
        FakeProvider::$refusing = [$this->server->id => ['updateClient']];

        $response = $this->extend($subscription, ['days' => 3, 'traffic_gb' => 5]);
        $body = $this->decode($response);

        self::assertSame(502, $response->getStatusCode());
        self::assertStringStartsWith('پنل «آلمان»: ', $body['message']);
        self::assertSame([$body['message']], $body['errors']['panel']);
        $subscription->refresh();
        self::assertSame([30, 30 * Traffic::GIGABYTE], [$subscription->duration_days, $subscription->traffic_limit_bytes]);
        self::assertSame([], $this->telegram()->calls());

        FakeProvider::$refusing = [];
        FakeProvider::$unreachable = true;
        self::assertSame(502, $this->extend($subscription, ['days' => 3])->getStatusCode(), 'out of reach, too');
    }

    public function testTheCustomerIsNotToldWhenTheAdminKeepsItQuiet(): void
    {
        $subscription = $this->mirrored('ali_1');

        self::assertSame(200, $this->extend($subscription, ['days' => 3, 'note' => 'برای تست', 'notify' => false])->getStatusCode());

        self::assertSame(33, $subscription->refresh()->duration_days);
        self::assertSame([], $this->telegram()->calls());
    }

    public function testAnAgentsExtensionTakesItsTrafficFromTheirBotAndItsDaysCostNothing(): void
    {
        $bot = $this->agentBot(traffic: 20);
        $subscription = $this->agentsService($bot);
        $this->loginAsAgent($bot);
        $this->telegram()->reset();

        $row = $this->decode($this->postJson("/api/agent/subscriptions/{$subscription->id}/extend", ['days' => 3, 'traffic_gb' => 5]))['subscription'];

        self::assertSame(35 * Traffic::GIGABYTE, $row['traffic']['limit']);
        self::assertSame(33, $row['duration_days']);
        self::assertSame(Traffic::bytesOfGb(15), $bot->trafficBalance(), 'its 5 GB out of the 20');
        $line = $this->lines($bot)->last() ?? self::fail('no line');
        self::assertSame(
            [TrafficTransactionType::Extension, -5 * Traffic::GIGABYTE, null, sprintf(TrafficPool::LINE_EXTENSION, 'reza_1'), '@agent_shop_bot'],
            [$line->type, $line->bytes, $line->order_id, $line->description, $line->reviewer],
        );
        $listed = $this->decode($this->get('/api/agent/account/traffic'))['lines'][0];
        self::assertSame(['extension', -5 * Traffic::GIGABYTE, '@agent_shop_bot'], [$listed['type'], $listed['bytes'], $listed['reviewer']], 'on the ledger the agent reads');
        self::assertSame([self::text(BotText::ServiceGranted, [
            'gift' => Messages::gift(3, 5 * Traffic::GIGABYTE),
            'client' => 'reza_1',
            'note' => '',
            'expires' => Messages::expiry(now()->addDays(33), 33),
            'remaining' => Messages::bytes(35 * Traffic::GIGABYTE),
        ])], $this->telegram()->sentTo(2001));
        self::assertSame(FakeTelegram::AGENT_TOKEN, $this->telegram()->tokenOf(0), "the customer hears it from the agent's bot");

        $this->postJson("/api/agent/subscriptions/{$subscription->id}/extend", ['days' => 7]);

        self::assertSame(40, $subscription->refresh()->duration_days);
        self::assertSame(Traffic::bytesOfGb(15), $bot->trafficBalance(), 'days cost the agent nothing');
        self::assertCount(2, $this->lines($bot), 'and write no line');
    }

    public function testTheOwnerExtendingInAnAgentsShopDrawsOnTheAgentsTrafficToo(): void
    {
        $bot = $this->agentBot(traffic: 20);
        $subscription = $this->agentsService($bot);
        $this->openShop($bot);

        self::assertSame(200, $this->extend($subscription, ['traffic_gb' => 5])->getStatusCode());

        self::assertSame(Traffic::bytesOfGb(15), $bot->trafficBalance());
        self::assertSame(self::ADMIN_USERNAME, $this->lines($bot)->last()?->reviewer, 'under the name of whoever gave it');
    }

    public function testAnExtensionTheAgentsTrafficCannotCoverIsRefusedBeforeAnyPanelIsAsked(): void
    {
        $bot = $this->agentBot(traffic: 4);
        $subscription = $this->agentsService($bot);
        $this->loginAsAgent($bot);
        $this->telegram()->reset();
        $calls = $this->panelCalls();

        $response = $this->postJson("/api/agent/subscriptions/{$subscription->id}/extend", ['days' => 3, 'traffic_gb' => 5]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([sprintf(TrafficShortException::EXTENSION, Messages::bytes(5 * Traffic::GIGABYTE), Messages::bytes(4 * Traffic::GIGABYTE))], $this->decode($response)['errors']['traffic_gb']);
        self::assertSame([], $calls->getArrayCopy(), 'no panel was asked');
        self::assertSame(Traffic::bytesOfGb(4), $bot->trafficBalance());
        self::assertCount(1, $this->lines($bot), 'nothing drawn');
        self::assertSame([30, 30 * Traffic::GIGABYTE], [$subscription->refresh()->duration_days, $subscription->traffic_limit_bytes]);
        self::assertSame([], $this->telegram()->calls());
    }

    public function testAnExtensionWhoseHoldRanOutAfterThePanelTookItIsGivenAndPaidForOnce(): void
    {
        $bot = $this->agentBot(traffic: 20);
        $subscription = $this->agentsService($bot);
        $this->loginAsAgent($bot);
        // A slow panel: the hold ran out under the update, and another change took the service meanwhile.
        FakeProvider::$onCall = static function (int $server, string $call) use ($subscription): void {
            if ($call === 'updateClient') {
                Subscription::acrossShops()->whereKey($subscription->id)->update([Lease::TOKEN => str_repeat('x', 32), Lease::UNTIL => now()->addMinute()]);
            }
        };

        $response = $this->postJson("/api/agent/subscriptions/{$subscription->id}/extend", ['traffic_gb' => 5]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertCount(1, FakeProvider::$updated);
        self::assertSame(35 * Traffic::GIGABYTE, $subscription->refresh()->traffic_limit_bytes, 'what the panel answered, on the row');
        self::assertSame(Traffic::bytesOfGb(15), $bot->trafficBalance(), 'given, so paid for: nothing comes back to the pool');
    }

    public function testTheAgentsTrafficComesBackWhenTheExtensionDoesNotGoThrough(): void
    {
        $bot = $this->agentBot(traffic: 20);
        $subscription = $this->agentsService($bot);
        $this->loginAsAgent($bot);
        $extend = fn(): ResponseInterface => $this->postJson("/api/agent/subscriptions/{$subscription->id}/extend", ['traffic_gb' => 5]);

        FakeProvider::$refusing = [$this->server->id => ['updateClient']];
        $response = $extend();
        self::assertSame(502, $response->getStatusCode());
        self::assertSame('پنل «آلمان»: ' . ProviderErrorPresenter::summary(new PanelApiException('The fake panel refused updateClient.', 200, 'refused by the test')), $this->decode($response)['message'], 'in a word: nothing of the panel');
        FakeProvider::$refusing = [];

        $lease = Lease::take($subscription, 60) ?? self::fail('the service is held already');
        self::assertSame(409, $extend()->getStatusCode());
        $lease->release();

        self::assertSame(Traffic::bytesOfGb(20), $bot->trafficBalance(), 'all of it back');
        self::assertSame([
            [TrafficTransactionType::Purchase, 20 * Traffic::GIGABYTE],
            [TrafficTransactionType::Extension, -5 * Traffic::GIGABYTE],
            [TrafficTransactionType::Refund, 5 * Traffic::GIGABYTE],
            [TrafficTransactionType::Extension, -5 * Traffic::GIGABYTE],
            [TrafficTransactionType::Refund, 5 * Traffic::GIGABYTE],
        ], $this->lines($bot)->map(static fn(TrafficTransaction $line): array => [$line->type, $line->bytes])->all());
        self::assertSame(sprintf(TrafficPool::LINE_EXTENSION_GIVEN_BACK, 'reza_1'), $this->lines($bot)->last()?->description);
        self::assertSame([], FakeProvider::$updated, 'and the service got nothing');
    }

    public function testAnotherShopsServiceIsNotFound(): void
    {
        $bot = $this->agentBot(traffic: 20);
        $theirs = $this->agentsService($bot);
        $mine = $this->mirrored('ali_1');
        $this->loginAsAgent($bot);

        self::assertSame(404, $this->postJson("/api/agent/subscriptions/{$mine->id}/extend", ['days' => 3])->getStatusCode());
        self::assertSame(404, $this->extend($theirs, ['days' => 3])->getStatusCode(), "the owner's panel works in the main bot's shop");
        self::assertSame([], FakeProvider::$updated);
        self::assertSame(Traffic::bytesOfGb(20), $bot->trafficBalance());
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

    /** A running service of the agent's bot — its customer, its plan (30 days, 30 GB) — on the shop's server. */
    private function agentsService(Bot $bot): Subscription
    {
        $subscription = CurrentBot::run($bot, fn(): Subscription => $this->subscription($this->customer(['telegram_id' => 2001, 'username' => 'reza']), $this->plan(['name' => 'پلن نماینده']), $this->server, 'reza_1'));
        FakeProvider::mirror($subscription);

        return $subscription;
    }

    /** @param array<string, mixed> $body */
    private function extend(Subscription $subscription, array $body): ResponseInterface
    {
        return $this->postJson("/api/admin/subscriptions/{$subscription->id}/extend", $body);
    }

    /**
     * What the owner's extension was refused with, by field.
     *
     * @param array<string, mixed> $body
     * @return array<string, list<string>>
     */
    private function errors(Subscription $subscription, array $body): array
    {
        $response = $this->extend($subscription, $body);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());

        return $this->decode($response)['errors'];
    }

    /** @return array<string, mixed> The service as the owner's screen shows it. */
    private function row(Subscription $subscription): array
    {
        return $this->decode($this->get("/api/admin/subscriptions/{$subscription->id}"))['subscription'];
    }

    /** @return Collection<int, TrafficTransaction> The bot's traffic lines, oldest first. */
    private function lines(Bot $bot): Collection
    {
        return TrafficTransaction::query()->where('bot_id', $bot->id)->orderBy('id')->get();
    }

    /** @return \ArrayObject<int, string> The calls the fake panels get from now on, in order. */
    private function panelCalls(): \ArrayObject
    {
        $calls = new \ArrayObject();
        FakeProvider::$onCall = static function (int $server, string $call) use ($calls): void {
            $calls->append($call);
        };

        return $calls;
    }
}
