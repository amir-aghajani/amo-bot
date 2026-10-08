<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\GrantAudience;
use App\Modules\Subscriptions\Models\Grant;
use App\Modules\Subscriptions\Models\GrantPart;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Tasks\GrantsTask;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;
use Tests\Support\FakeTelegram;

/**
 * «هدیه همگانی»: days and traffic for the running services on every server at once — or the agents' only (the services
 * their bots sold, while their agency stands), or one server's — given as a server grant per server (each on its server's page too, each worked
 * through on its own, so a panel out of reach holds up its own server only), the customers told with the admin's reason
 * — an agent's customer by the agent's bot; refused while a server it would reach has one under way; stopped, every part
 * still going stops. A gift runs while a part runs, then is cancelled if one was stopped, done otherwise.
 */
final class MassGrantsTest extends HttpTestCase
{
    private Server $germany;
    private Server $finland;
    private Plan $plan;
    private User $ali;
    private User $mina;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-20 12:00:00');
        $this->fakePanel();
        $this->telegram();
        $this->loginAsAdmin();

        $this->germany = $this->fakeServer('آلمان');
        $this->finland = $this->fakeServer('فنلاند');
        $this->fakeServer('هلند'); // no services: no part there
        $this->plan = $this->plan([], [$this->germany, $this->finland]);
        $this->ali = $this->customer(['telegram_id' => 1001, 'username' => 'ali']);
        $sara = $this->customer(['telegram_id' => 1003, 'username' => 'sara']);
        // Mina bought from an agent's bot: hers is an agent's service.
        $bot = $this->agentBot();
        $this->mina = CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => 1002, 'username' => 'mina']));
        $agentsPlan = CurrentBot::run($bot, fn(): Plan => $this->plan(['traffic_gb' => 30], [$this->finland]));

        FakeProvider::mirror($this->subscription($this->ali, $this->plan, $this->germany, 'ali_1', ['expires_at' => now()->addDays(10)]));
        FakeProvider::mirror(CurrentBot::run($bot, fn(): Subscription => $this->subscription($this->mina, $agentsPlan, $this->finland, 'mina_1', ['expires_at' => now()->addDays(5)])));
        FakeProvider::mirror($this->subscription($sara, $this->plan, $this->germany, 'sara_1', ['starts_at' => null, 'expires_at' => null]));
    }

    public function testTheCardSaysWhomAGiftWouldReach(): void
    {
        $audience = $this->decode($this->get('/api/admin/mass-grants'))['audience'];

        self::assertSame(['running' => 2, 'unstarted' => 1], $audience['all']);
        self::assertSame(['running' => 1, 'unstarted' => 0], $audience['agents']);
        self::assertSame(
            [['id' => $this->germany->id, 'name' => 'آلمان', 'running' => 1, 'unstarted' => 1], ['id' => $this->finland->id, 'name' => 'فنلاند', 'running' => 1, 'unstarted' => 0]],
            $audience['servers'],
            'only servers with services',
        );
    }

    public function testAGiftForEveryoneIsAPartOnEachServerAndEveryRunningServiceGetsIt(): void
    {
        $response = $this->postJson('/api/admin/mass-grants', ['days' => '۳', 'traffic_gb' => '5', 'reason' => 'هدیه نوروز', 'audience' => 'all']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $gift = $this->decode($response)['grant'];
        self::assertSame(['running', 'all', 3, 5 * Traffic::GIGABYTE, 3, self::ADMIN_USERNAME], [$gift['status'], $gift['audience'], $gift['days'], $gift['traffic_bytes'], $gift['total'], $gift['reviewer']]);
        self::assertSame(['آلمان', 'فنلاند'], array_column(array_column($gift['parts'], 'server'), 'name'), 'a part on each server with services');

        $gift = $this->decode($this->postJson("/api/admin/mass-grants/{$gift['id']}/run"))['grant'];
        self::assertSame(['done', 2, 1, 0], [$gift['status'], $gift['granted'], $gift['skipped'], $gift['failed']], 'the one waiting for its first connection passed by');
        self::assertNotNull($gift['finished_at']);
        self::assertSame(['ali_1', 'mina_1'], FakeProvider::updatedNames());
        self::assertSame(now()->addDays(13)->getTimestamp(), FakeProvider::lastUpdate('ali_1')->expiry->deadline()?->getTimestamp(), 'three days on its ten');
        self::assertSame(35 * Traffic::GIGABYTE, FakeProvider::lastUpdate('mina_1')->totalBytes);
        self::assertSame(['sendMessage', 'sendMessage'], $this->telegram()->calls(), 'each customer told');
        self::assertStringContainsString(self::text(BotText::AdminNote, ['comment' => 'هدیه نوروز']), $this->telegram()->sentTo(1001)[0] ?? '');
        self::assertSame([FakeTelegram::TOKEN, FakeTelegram::AGENT_TOKEN], [$this->telegram()->tokenOf(0), $this->telegram()->tokenOf(1)], "Mina hears it from the agent's bot, the one she talks to");
        self::assertSame(['1001', '1002'], [$this->telegram()->params(0)['chat_id'], $this->telegram()->params(1)['chat_id']]);

        $parts = $this->decode($this->get("/api/admin/servers/{$this->germany->id}/grants"))['grants'];
        self::assertSame([$gift['id'], false], [$parts[0]['mass_grant_id'], $parts[0]['agents_only']], "on its server's page too");
    }

    public function testAGiftForTheAgentsReachesTheirServicesOnly(): void
    {
        $gift = $this->decode($this->postJson('/api/admin/mass-grants', ['days' => 2, 'audience' => 'agents']))['grant'];

        self::assertSame(['فنلاند'], array_column(array_column($gift['parts'], 'server'), 'name'), 'no agent has a service in Germany');
        self::assertSame(1, $gift['total']);
        $this->decode($this->postJson("/api/admin/mass-grants/{$gift['id']}/run"));
        self::assertSame(['mina_1'], FakeProvider::updatedNames());
        self::assertSame([GrantAudience::Agents, 1], [Grant::query()->sole()->audience, GrantPart::query()->count()]);
    }

    public function testAGiftForTheAgentsLeavesOutTheBotOfAnAgencyThatEnded(): void
    {
        $ended = $this->agent(overrides: ['telegram_id' => 7008, 'username' => 'ex_agent']);
        $bot = $ended->ownBot ?? self::fail('The agent has no shop.');
        CurrentBot::run($bot, function (): void {
            $plan = $this->plan([], [$this->finland]);
            FakeProvider::mirror($this->subscription($this->customer(['telegram_id' => 1004, 'username' => 'neda']), $plan, $this->finland, 'neda_1', ['expires_at' => now()->addDays(7)]));
        });
        // Their agency ends: their bot is off, its customers no agent's to reach.
        $ended->forceFill(['agency_level_id' => null])->save();

        self::assertSame(['running' => 1, 'unstarted' => 0], $this->decode($this->get('/api/admin/mass-grants'))['audience']['agents'], "Mina's alone");
        $gift = $this->decode($this->postJson('/api/admin/mass-grants', ['days' => 2, 'audience' => 'agents']))['grant'];
        self::assertSame(1, $gift['total']);
        $this->postJson("/api/admin/mass-grants/{$gift['id']}/run");
        self::assertSame(['mina_1'], FakeProvider::updatedNames());
    }

    public function testOneServersAndTheRulesOfStartingOne(): void
    {
        self::assertSame(422, $this->unchecked()->postJson('/api/admin/mass-grants', ['days' => 2])->getStatusCode(), 'no audience');
        self::assertArrayHasKey('server_id', $this->decode($this->postJson('/api/admin/mass-grants', ['days' => 2, 'audience' => 'server']))['errors']);
        self::assertArrayHasKey('grant', $this->decode($this->postJson('/api/admin/mass-grants', ['audience' => 'all']))['errors'], 'nothing to give');

        $empty = Server::query()->where('name', 'هلند')->sole();
        $nobody = $this->postJson('/api/admin/mass-grants', ['days' => 2, 'audience' => 'server', 'server_id' => $empty->id]);
        self::assertSame(422, $nobody->getStatusCode());
        self::assertStringContainsString('سرویس فعالی نیست', $this->decode($nobody)['errors']['grant'][0]);
        self::assertSame([0, 0], [Grant::query()->count(), GrantPart::query()->count()], 'nothing is left of a refused one');

        $one = $this->decode($this->postJson('/api/admin/mass-grants', ['days' => 2, 'audience' => 'server', 'server_id' => $this->finland->id]))['grant'];
        self::assertSame(['server', 'فنلاند', 1], [$one['audience'], $one['server']['name'], count($one['parts'])]);

        $busy = $this->postJson('/api/admin/mass-grants', ['days' => 2, 'audience' => 'all']);
        self::assertSame(422, $busy->getStatusCode());
        self::assertStringContainsString('«فنلاند»', $this->decode($busy)['errors']['grant'][0], 'a server it would reach has one under way');
    }

    public function testAGrantIssuedOnAServersPageIsAGrantForThatServerAlone(): void
    {
        // A server's page takes no audience: one sent anyway changes nothing.
        $part = $this->decode($this->unchecked()->postJson("/api/admin/servers/{$this->germany->id}/grants", ['days' => 2, 'audience' => 'all', 'server_id' => $this->finland->id]))['grant'];

        self::assertSame([null, false, 2], [$part['mass_grant_id'], $part['agents_only'], $part['total']], 'its own server, whatever else the request says');
        $grants = $this->decode($this->get('/api/admin/mass-grants'))['grants'];
        self::assertSame(['server', 'آلمان', [$part['id']]], [$grants[0]['audience'], $grants[0]['server']['name'], array_column($grants[0]['parts'], 'id')], 'the broadcasts page lists every grant');
    }

    public function testStoppingAGiftStopsEveryPartStillGoingAndTheSchedulerSettlesAGiftWhosePartsAreOver(): void
    {
        $gift = $this->decode($this->postJson('/api/admin/mass-grants', ['days' => 2, 'audience' => 'all']))['grant'];

        $stopped = $this->decode($this->postJson("/api/admin/mass-grants/{$gift['id']}/cancel"))['grant'];
        self::assertSame(['cancelled', ['cancelled', 'cancelled']], [$stopped['status'], array_column($stopped['parts'], 'status')]);
        self::assertSame(422, $this->postJson("/api/admin/mass-grants/{$gift['id']}/cancel")->getStatusCode());
        self::assertSame([], FakeProvider::$updated);

        // One stopped from its server's page, the other finished by the scheduler a minute later: the gift is over —
        // stopped, since it did not reach every server it was for — and ended when its last part did.
        $gift = $this->decode($this->postJson('/api/admin/mass-grants', ['days' => 2, 'audience' => 'all']))['grant'];
        $this->postJson("/api/admin/servers/{$this->germany->id}/grants/{$gift['parts'][0]['id']}/cancel");
        Carbon::setTestNow(now()->addMinute());
        $this->service(GrantsTask::class)->run();
        $gift = $this->decode($this->get('/api/admin/mass-grants'))['grants'][0];
        self::assertSame(['cancelled', ['cancelled', 'done']], [$gift['status'], array_column($gift['parts'], 'status')]);
        self::assertSame(now()->getTimestamp(), Carbon::parse((string) $gift['finished_at'])->getTimestamp());
        self::assertSame(['mina_1'], FakeProvider::updatedNames());
    }

    public function testAPanelOutOfReachHoldsUpItsOwnServerOnly(): void
    {
        FakeProvider::$down = [$this->germany->id];
        $gift = $this->decode($this->postJson('/api/admin/mass-grants', ['days' => 2, 'audience' => 'all']))['grant'];

        $gift = $this->decode($this->postJson("/api/admin/mass-grants/{$gift['id']}/run"))['grant'];

        self::assertSame('running', $gift['status'], 'while a part waits, the gift runs');
        self::assertSame(['running', 'done'], array_column($gift['parts'], 'status'));
        self::assertNotNull($gift['parts'][0]['waiting_reason'], "Germany's part waits for its panel");
        self::assertNull($gift['finished_at']);
        self::assertSame(['mina_1'], FakeProvider::updatedNames(), 'Finland was not held up');
    }
}
