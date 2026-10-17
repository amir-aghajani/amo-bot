<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database\Lease;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Enums\ConnectionFailure;
use App\Modules\Providers\Exceptions\ConnectionException;
use App\Modules\Providers\Exceptions\PanelApiException;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Services\ProviderErrorPresenter;
use App\Modules\Providers\Services\ServerHealth;
use App\Modules\Subscriptions\Enums\MigrationStatus;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\MigrationLeftover;
use App\Modules\Subscriptions\Models\ServerMigration;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ServerMigrations;
use App\Modules\Subscriptions\Tasks\MigrationsTask;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Support\Traffic;
use App\Support\Validation;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;
use Tests\Support\FakeTelegram;

/**
 * «انتقال سرویس‌ها» on a server's page: every shop's services on one server moved to another by the owner — a migration
 * reading, making and deleting each client while the source answers; a rescue making them from the shop's own copy and
 * writing down the clients it left on the dead panel, removed once it answers. One service at a time by whoever holds
 * the job (the page, or the scheduler), never twice; waiting while a panel is out of reach or another change holds a
 * service; failing when the target can take no more; paused, resumed, cancelled; one open job per server. Each customer
 * gets the new link in their own shop's bot, and the report group hears the start and the end.
 */
final class ServerMigrationsTest extends HttpTestCase
{
    private User $ali;
    private User $sara;
    private Server $source;
    private Server $target;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-20 12:00:00');
        $this->fakePanel();
        $this->telegram();
        $this->loginAsAdmin();
        $this->reportGroup();

        $this->ali = $this->customer(['telegram_id' => 1001, 'username' => 'ali']);
        $this->sara = $this->customer(['telegram_id' => 1002, 'username' => 'sara']);
        $this->source = $this->fakeServer('آلمان');
        $this->inbound($this->source, '1');
        $this->target = $this->fakeServer('هلند');
        $this->inbound($this->target, '7');
        $this->plan = $this->plan();
    }

    public function testAMigrationMovesEveryShopsServicesWithWhatTheyHaveLeftAndSendsEachTheNewLink(): void
    {
        $ali = $this->subscription($this->ali, $this->plan, $this->source, 'ali_1');
        FakeProvider::put($this->source, new ClientInfo(
            name: 'ali_1',
            enabled: true,
            uploadBytes: 4 * Traffic::GIGABYTE,
            downloadBytes: 8 * Traffic::GIGABYTE,
            totalBytes: 30 * Traffic::GIGABYTE,
            expiry: Expiry::at(new \DateTimeImmutable('2026-10-10 12:00:00')),
            subscriptionUrl: 'https://fake.test/sub/ali_1',
        ));
        $sara = $this->mirrored($this->subscription($this->sara, $this->plan, $this->source, 'sara_1', ['starts_at' => null, 'expires_at' => null]));
        $bot = $this->agentBot();
        $reza = CurrentBot::run($bot, fn(): Subscription => $this->subscription($this->customer(['telegram_id' => 2001, 'username' => 'reza']), $this->plan(['name' => 'پلن نماینده']), $this->source, 'reza_1'));
        FakeProvider::mirror($reza);

        $preview = $this->decode($this->postJson("/api/admin/servers/{$this->source->id}/migrations/preview", ['target_server_id' => $this->target->id]));
        self::assertSame(3, $preview['services']);
        self::assertSame([[1, 2], [$bot->id, 1]], array_map(static fn(array $row): array => [$row['shop']['id'], $row['services']], $preview['shops']), 'every shop\'s services there');
        self::assertSame([['یک‌ماهه', 'فروشگاه اصلی', 2], ['پلن نماینده', 'فروشگاه نماینده', 1]], array_map(static fn(array $plan): array => [$plan['name'], $plan['shop'], $plan['services']], $preview['plans']));
        self::assertSame(['problem' => null, 'room' => null], $preview['target']);

        $response = $this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate']);
        self::assertSame(201, $response->getStatusCode());
        $job = $this->decode($response)['migration'];
        self::assertSame(
            ['queued', 3, 0, 'migrate', ['id' => $this->source->id, 'name' => 'آلمان'], ['id' => $this->target->id, 'name' => 'هلند'], true, null, self::ADMIN_USERNAME, null],
            [$job['status'], $job['total'], $job['moved'], $job['mode'], $job['source'], $job['target'], $job['notify'], $job['plans'], $job['reviewer'], $job['reviewer_name']],
        );
        self::assertSame([], FakeProvider::$created, 'nothing moves until it is worked on');

        $job = $this->work($job['id']);
        self::assertSame(['done', 3, 0, 0, 0], [$job['status'], $job['moved'], $job['failed'], $job['skipped'], $job['left_behind']]);
        self::assertNotNull($job['started_at']);
        self::assertNotNull($job['finished_at']);

        // What the old panel said was left — 18 of 30 GB, the same deadline — on a new client there; the old one gone.
        $ali->refresh();
        self::assertSame([$this->target->id, 18 * Traffic::GIGABYTE, 0, '2026-10-10 12:00:00'], [$ali->server_id, $ali->traffic_limit_bytes, $ali->usedBytes(), $ali->expires_at?->format('Y-m-d H:i:s')]);
        self::assertNotSame('https://fake.test/sub/ali_1', $ali->subscription_url, 'a new link');
        self::assertSame($this->plan->id, $ali->plan_id, 'its plan stays');
        self::assertSame([null, 30 * 24, $this->target->id], [$sara->refresh()->expires_at, $sara->duration_hours, $sara->server_id], 'still waiting for its first connection');
        self::assertSame($this->target->id, $reza->refresh()->server_id);
        self::assertCount(3, FakeProvider::$created);
        self::assertSame(['ali_1', 'sara_1', 'reza_1'], array_column(FakeProvider::$deleted, 'name'));
        self::assertSame([], FakeProvider::$clients[$this->source->id], 'nothing is left on the old panel');

        self::assertSame([self::text(BotText::ServiceMoved, [
            'client' => 'ali_1',
            'plan' => $this->plan->name,
            'server' => 'هلند',
            'expires' => Messages::expiry(new \DateTimeImmutable('2026-10-10 12:00:00'), 30 * 24),
            'traffic' => Messages::traffic(18 * Traffic::GIGABYTE),
            'subscription' => $ali->subscription_url,
        ])], $this->telegram()->sentTo(1001), 'the new link, with what is left');
        self::assertCount(1, $this->telegram()->sentTo(1002));
        self::assertCount(1, $this->telegram()->sentTo(2001));
        self::assertSame(FakeTelegram::AGENT_TOKEN, $this->telegram()->tokenOf($this->callTo(2001)), "an agent's customer hears it from the agent's bot");

        self::assertSame([
            "🚚 <b>انتقال سرویس‌های سرور «آلمان» به سرور «هلند» شروع شد</b>\n🧭 حالت: مهاجرت\n📦 سرویس‌ها: ۳\n🎧 توسط پشتیبانی",
            "✅ <b>انتقال سرویس‌های سرور «آلمان» به سرور «هلند» تمام شد</b>\n📊 منتقل‌شده: ۳ · ناموفق: ۰ · ردشده: ۰",
        ], $this->migrationReports());

        $card = $this->decode($this->get("/api/admin/servers/{$this->source->id}/migrations"));
        self::assertSame([[$job['id'], 'done']], array_map(static fn(array $row): array => [$row['id'], $row['status']], $card['migrations']));
        self::assertSame(['pending' => 0, 'last_error' => null], $card['leftovers']);

        // Kept by an admin's handle — as a panel's reviewer is kept everywhere — it reads as their name on the card.
        $this->admin(['telegram_id' => self::BOSS_TELEGRAM_ID, 'username' => 'boss']);
        ServerMigration::query()->whereKey($job['id'])->update(['reviewer' => '@boss']);
        $row = $this->decode($this->get("/api/admin/servers/{$this->source->id}/migrations"))['migrations'][0];
        self::assertSame(['@boss', 'Boss'], [$row['reviewer'], $row['reviewer_name']]);
    }

    public function testARescueCarriesTheShopsOwnCopyNeverAsksTheDeadPanelAndRemovesWhatItLeftOnceItAnswers(): void
    {
        // The shop last read 5 GB used; the panel is dead since, in backoff.
        $ali = $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1', ['download_bytes' => 5 * Traffic::GIGABYTE, 'expires_at' => now()->addDays(10)]));
        $sara = $this->mirrored($this->subscription($this->sara, $this->plan, $this->source, 'sara_1'));
        $this->source->forceFill(['last_error' => 'Connection timed out', 'last_checked_at' => now()])->save();
        FakeProvider::$down = [$this->source->id];
        $asked = [];
        FakeProvider::$onCall = static function (int $server) use (&$asked): void {
            $asked[] = $server;
        };

        $job = $this->work($this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'rescue']))['migration']['id']);

        self::assertSame(['done', 2, 0, 2], [$job['status'], $job['moved'], $job['failed'], $job['left_behind']]);
        self::assertNotContains($this->source->id, $asked, 'the dead panel is never asked');
        $ali->refresh();
        self::assertSame([$this->target->id, 25 * Traffic::GIGABYTE, '2026-09-30 12:00:00'], [$ali->server_id, $ali->traffic_limit_bytes, $ali->expires_at?->format('Y-m-d H:i:s')], 'what the shop last read: 25 of 30 GB left, the same deadline');
        self::assertSame($this->target->id, $sara->refresh()->server_id);
        self::assertCount(1, $this->telegram()->sentTo(1001));
        self::assertCount(1, $this->telegram()->sentTo(1002));
        self::assertSame(['ali_1', 'sara_1'], MigrationLeftover::query()->orderBy('id')->pluck('remote_name')->all());
        self::assertSame(['pending' => 2, 'last_error' => null], $this->card()['leftovers']);
        self::assertSame([
            "🚚 <b>انتقال سرویس‌های سرور «آلمان» به سرور «هلند» شروع شد</b>\n🧭 حالت: نجات (بدون تماس با پنل سرور مبدا)\n📦 سرویس‌ها: ۲\n🎧 توسط پشتیبانی",
            "✅ <b>انتقال سرویس‌های سرور «آلمان» به سرور «هلند» تمام شد</b>\n📊 منتقل‌شده: ۲ · ناموفق: ۰ · ردشده: ۰\n🧹 ۲ کلاینت روی پنل مانده است؛ وقتی پنل جواب بدهد، خودکار حذف می‌شوند.",
        ], $this->migrationReports());

        // Left alone while it is in backoff…
        $this->service(MigrationsTask::class)->run();
        self::assertSame([], FakeProvider::$deleted);

        // …and once it answers again, the clients left there go: they would be a second service nobody pays for.
        FakeProvider::$down = [];
        Carbon::setTestNow(now()->addMinutes(Server::BACKOFF_MINUTES));
        $this->service(MigrationsTask::class)->run();
        self::assertSame(['ali_1', 'sara_1'], array_column(FakeProvider::$deleted, 'name'));
        self::assertSame(['pending' => 0, 'last_error' => null], $this->card()['leftovers']);
        self::assertSame(2, MigrationLeftover::query()->whereNotNull('removed_at')->count(), 'kept, stamped');
        self::assertSame([0, 0], [$this->card()['migrations'][0]['left_behind'], $this->work($job['id'])['left_behind']], 'the job counts only those still there, in the list as alone');
    }

    public function testTheOwnerRemovesWhatARescueLeftNowOrForgetsIt(): void
    {
        $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1'));
        $this->mirrored($this->subscription($this->sara, $this->plan, $this->source, 'sara_1'));
        FakeProvider::$down = [$this->source->id];
        $this->work($this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'rescue']))['migration']['id']);

        // Still dead: the first try says why and stops.
        $leftovers = $this->decode($this->postJson("/api/admin/servers/{$this->source->id}/leftovers/clean"))['leftovers'];
        self::assertSame(2, $leftovers['pending']);
        self::assertSame(ProviderErrorPresenter::describe(new ConnectionException(ConnectionFailure::Timeout, 'Connection timed out')), $leftovers['last_error']);
        self::assertTrue($this->source->refresh()->isBackingOff());

        // Back: the owner's button does not wait for the backoff.
        FakeProvider::$down = [];
        unset(FakeProvider::$clients[$this->source->id]['sara_1']); // removed on the panel by hand meanwhile
        $leftovers = $this->decode($this->postJson("/api/admin/servers/{$this->source->id}/leftovers/clean"))['leftovers'];
        self::assertSame(['pending' => 0, 'last_error' => null], $leftovers);
        self::assertSame(['ali_1'], array_column(FakeProvider::$deleted, 'name'), 'one gone already counts as removed');

        // Another rescue's leftovers on a panel gone for good: forgotten, nothing asked.
        FakeProvider::mirror($this->subscription($this->ali, $this->plan, $this->source, 'ali_2'));
        FakeProvider::$down = [$this->source->id];
        $this->work($this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'rescue']))['migration']['id']);
        self::assertSame(1, $this->card()['leftovers']['pending']);
        self::assertSame(['pending' => 0, 'last_error' => null], $this->decode($this->deleteJson("/api/admin/servers/{$this->source->id}/leftovers"))['leftovers']);
        self::assertSame(0, MigrationLeftover::query()->where('remote_name', 'ali_2')->count());
    }

    public function testALeftClientByANameAServiceHasThereAgainIsNeverDeletedNorOneWhoseServiceIsBusy(): void
    {
        $ali = $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1'));
        $sara = $this->mirrored($this->subscription($this->sara, $this->plan, $this->source, 'sara_1'));
        FakeProvider::$down = [$this->source->id];
        $this->work($this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'rescue']))['migration']['id']);

        // Back, without Ali's old client (removed there by hand) — and his service is moved back by hand, under its name.
        FakeProvider::$down = [];
        Carbon::setTestNow(now()->addMinutes(Server::BACKOFF_MINUTES));
        unset(FakeProvider::$clients[$this->source->id]['ali_1']);
        self::assertSame(200, $this->postJson("/api/admin/subscriptions/{$ali->id}/move", ['server_id' => $this->source->id])->getStatusCode());
        self::assertSame([$this->source->id, 'ali_1'], [$ali->refresh()->server_id, $ali->remote_name]);
        // Sara's service is being changed meanwhile.
        $renewal = Lease::take($sara->refresh(), 60) ?? self::fail('the service could not be held');

        $leftovers = $this->decode($this->postJson("/api/admin/servers/{$this->source->id}/leftovers/clean"))['leftovers'];

        self::assertSame(1, $leftovers['pending'], "Sara's waits for her service");
        self::assertArrayHasKey('ali_1', FakeProvider::$clients[$this->source->id], "Ali's service's client there now, untouched");
        self::assertSame(['sara_1'], MigrationLeftover::query()->pluck('remote_name')->all(), 'his forgotten');
        self::assertNotContains(['server' => $this->source->id, 'name' => 'sara_1'], FakeProvider::$deleted);

        $renewal->release();
        $this->service(MigrationsTask::class)->run();
        self::assertContains(['server' => $this->source->id, 'name' => 'sara_1'], FakeProvider::$deleted);
        self::assertSame(0, $this->card()['leftovers']['pending']);
    }

    public function testAnOldClientThePanelDidNotSayItDeletedIsWrittenDownAndRemovedOnceItAnswers(): void
    {
        $ali = $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1'));
        $sara = $this->mirrored($this->subscription($this->sara, $this->plan, $this->source, 'sara_1'));
        // The source stops answering at Ali's delete: whether it deleted his old client nobody knows.
        $stopped = false;
        FakeProvider::$onCall = function (int $server, string $call) use (&$stopped): void {
            if ($call === 'deleteClient' && $server === $this->source->id && !$stopped) {
                $stopped = true;
                FakeProvider::$down = [$server];
            }
        };
        $id = $this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate']))['migration']['id'];

        $job = $this->work($id);

        self::assertSame(['running', 1, 1], [$job['status'], $job['moved'], $job['left_behind']], 'his moved — he has a service either way —; Sara waits for the source');
        self::assertSame($this->target->id, $ali->refresh()->server_id);
        self::assertSame([['ali_1', $this->source->id]], MigrationLeftover::query()->get()->map(static fn(MigrationLeftover $left): array => [$left->remote_name, $left->server_id])->all());

        FakeProvider::$down = [];
        Carbon::setTestNow(now()->addMinutes(Server::BACKOFF_MINUTES));
        $this->service(MigrationsTask::class)->run();

        self::assertSame([MigrationStatus::Done, $this->target->id], [ServerMigration::query()->findOrFail($id)->status, $sara->refresh()->server_id]);
        self::assertSame(['sara_1', 'ali_1'], array_column(array_filter(FakeProvider::$deleted, fn(array $deleted): bool => $deleted['server'] === $this->source->id), 'name'), "Sara's deleted by her move, then Ali's left one");
        self::assertSame(0, $this->card()['leftovers']['pending']);
    }

    public function testAClientTheTargetMayHaveMadeIsWrittenDownAndGoesOnceTheRetryMadeAnother(): void
    {
        $ali = $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1'));
        // The target makes the client, then stops answering: neither the create nor the look after it gets an answer.
        $made = false;
        FakeProvider::$onCall = function (int $server, string $call) use (&$made): void {
            if ($call === 'createClient' && $server === $this->target->id && !$made) {
                $made = true;
                FakeProvider::put($this->target, new ClientInfo(name: 'ali_1', enabled: true, totalBytes: 30 * Traffic::GIGABYTE, subscriptionUrl: 'https://fake.test/sub/lost'));
                FakeProvider::$down = [$server];
            }
        };
        $id = $this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate']))['migration']['id'];

        $job = $this->work($id);
        self::assertSame(['running', 0], [$job['status'], $job['moved']]);
        self::assertStringStartsWith('سرور مقصد «هلند»: ', (string) $job['waiting_reason']);
        self::assertSame($this->source->id, $ali->refresh()->server_id, 'nothing moved: the old client is still his');
        self::assertSame([['ali_1', $this->target->id]], MigrationLeftover::query()->get()->map(static fn(MigrationLeftover $left): array => [$left->remote_name, $left->server_id])->all());

        // Back: the retry finds the name taken and makes the next; the client the first try made is removed after it.
        FakeProvider::$down = [];
        Carbon::setTestNow(now()->addMinutes(Server::BACKOFF_MINUTES));
        $this->service(MigrationsTask::class)->run();

        $ali->refresh();
        self::assertSame([$this->target->id, 'ali_2'], [$ali->server_id, $ali->remote_name]);
        self::assertSame(['ali_2'], array_keys(FakeProvider::$clients[$this->target->id]), 'no stray client left on the target');
        self::assertSame(0, $this->card()['leftovers']['pending']);
    }

    public function testANewClientTheTargetWouldNotTakeBackIsWrittenDownAndGoesOnceItDoes(): void
    {
        $ali = $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1'));
        // The target makes the client without a link, then refuses to delete it again.
        FakeProvider::$mintsSubId = false;
        FakeProvider::$refusing[$this->target->id] = ['deleteClient'];

        $job = $this->work($this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate']))['migration']['id']);

        self::assertSame(['done', 0, 1, 1], [$job['status'], $job['moved'], $job['failed'], $job['left_behind']]);
        self::assertSame($this->source->id, $ali->refresh()->server_id, 'nothing moved');
        self::assertSame([['ali_1', $this->target->id]], MigrationLeftover::query()->get()->map(static fn(MigrationLeftover $left): array => [$left->remote_name, $left->server_id])->all());

        FakeProvider::$refusing = [];
        $this->service(MigrationsTask::class)->run();

        self::assertSame([], FakeProvider::$clients[$this->target->id], 'no stray client left on the target');
        self::assertArrayHasKey('ali_1', FakeProvider::$clients[$this->source->id], "Ali's own untouched");
        self::assertSame(0, $this->decode($this->get("/api/admin/servers/{$this->target->id}/migrations"))['leftovers']['pending']);
    }

    public function testTheOwnersMoveCarriesAnAgentsServicesOntoAServerKeptFromTheirShop(): void
    {
        $bot = $this->agentBot();
        $bot->hideServers([$this->target->id]);
        $reza = CurrentBot::run($bot, fn(): Subscription => $this->subscription($this->customer(['telegram_id' => 2001, 'username' => 'reza']), $this->plan(['name' => 'پلن نماینده']), $this->source, 'reza_1'));
        FakeProvider::mirror($reza);

        $job = $this->work($this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate']))['migration']['id']);

        self::assertSame(['done', 1, 0], [$job['status'], $job['moved'], $job['failed']], "the owner's own decision: the list binds the agent's moves");
        self::assertSame($this->target->id, $reza->refresh()->server_id);
    }

    public function testAMigrationWaitsForASourceThatDoesNotAnswerAndMayGoOnAsARescue(): void
    {
        $ali = $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1'));
        $this->mirrored($this->subscription($this->sara, $this->plan, $this->source, 'sara_1'));
        FakeProvider::$down = [$this->source->id];
        $id = $this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate']))['migration']['id'];

        $job = $this->work($id);
        self::assertSame(['running', 0, 0], [$job['status'], $job['moved'], $job['failed']]);
        self::assertSame('سرور قبلی «آلمان»: ' . ProviderErrorPresenter::describe(new ConnectionException(ConnectionFailure::Timeout, 'Connection timed out')), $job['waiting_reason'], "why, in the owner's words");
        self::assertSame('source', $job['waiting_on'], 'the source holds it: the one a rescue goes on without');
        self::assertSame([], FakeProvider::$created);
        $job = $this->work($id);
        self::assertStringStartsWith('سرور مبدا «آلمان»: ', (string) $job['waiting_reason'], 'in backoff: it waits without asking');
        self::assertSame('source', $job['waiting_on']);

        $job = $this->decode($this->postJson("/api/admin/servers/{$this->source->id}/migrations/{$id}/rescue"))['migration'];
        self::assertSame(['rescue', 'running', null, null], [$job['mode'], $job['status'], $job['waiting_reason'], $job['waiting_on']]);
        $job = $this->work($id);

        self::assertSame(['done', 2, 2], [$job['status'], $job['moved'], $job['left_behind']]);
        self::assertSame($this->target->id, $ali->refresh()->server_id);
        $again = $this->postJson("/api/admin/servers/{$this->source->id}/migrations/{$id}/rescue");
        self::assertSame([422, ['status']], [$again->getStatusCode(), array_keys($this->decode($again)['errors'])]);
    }

    public function testATargetThatFillsUpEndsTheJobAsFailedAndANewOneTakesTheRest(): void
    {
        $this->target->forceFill(['capacity' => 1])->save();
        $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1'));
        $second = $this->mirrored($this->subscription($this->sara, $this->plan, $this->source, 'sara_1'));
        self::assertSame(['problem' => null, 'room' => 1], $this->decode($this->postJson("/api/admin/servers/{$this->source->id}/migrations/preview", ['target_server_id' => $this->target->id]))['target']);

        $job = $this->work($this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate']))['migration']['id']);

        self::assertSame(['failed', 1, 'سرور مقصد «هلند»: ظرفیت سرور پر است.'], [$job['status'], $job['moved'], $job['reason']]);
        self::assertSame($this->source->id, $second->refresh()->server_id, 'the rest stay where they are');
        self::assertStringStartsWith("⛔️ <b>انتقال سرویس‌های سرور «آلمان» به سرور «هلند» ناتمام ماند</b>\n📊 منتقل‌شده: ۱ · ناموفق: ۰ · ردشده: ۰\n❗️ علت: سرور مقصد «هلند»: ظرفیت سرور پر است.", $this->migrationReports()[1]);

        $this->target->forceFill(['capacity' => null])->save();
        $job = $this->work($this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate']))['migration']['id']);
        self::assertSame(['done', 1, 1], [$job['status'], $job['total'], $job['moved']]);
    }

    public function testATargetSwitchedOffMidWayEndsTheJob(): void
    {
        $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1'));
        $this->mirrored($this->subscription($this->sara, $this->plan, $this->source, 'sara_1'));
        FakeProvider::$onCall = function (int $server, string $call): void {
            if ($call === 'createClient' && $server === $this->target->id) {
                Server::query()->whereKey($server)->update(['is_active' => false]);
            }
        };

        $job = $this->work($this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate']))['migration']['id']);

        self::assertSame(['failed', 1, 'سرور مقصد «هلند»: سرور غیرفعال است.'], [$job['status'], $job['moved'], $job['reason']]);
    }

    public function testTheJobSaysWhichPanelHoldsItAsItsNextTurnMeetsThem(): void
    {
        $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1'));
        FakeProvider::$down = [$this->source->id];
        $id = $this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate']))['migration']['id'];
        self::assertSame('source', $this->work($id)['waiting_on']);
        $onCard = fn(): ?string => $this->card()['migrations'][0]['waiting_on'];

        // The target stops answering too (a customer's screen found it so).
        $this->service(ServerHealth::class)->record($this->target, 'پاسخ نداد');
        self::assertSame('target', $onCard(), 'both out of reach: the target is asked first, and a rescue would wait on it as well');
        $this->service(ServerHealth::class)->record($this->target->refresh(), null);
        self::assertSame('source', $onCard(), 'the target answers again: the source alone holds it now');
        Carbon::setTestNow(now()->addMinutes(Server::BACKOFF_MINUTES + 1));
        self::assertSame('source', $onCard(), 'past its backoff, its last contact still failed: the next turn asks it again');
        $this->service(ServerHealth::class)->record($this->source->refresh(), null);
        self::assertNull($onCard(), 'both answer: the next turn goes on, held by neither');
        self::assertNotNull($this->card()['migrations'][0]['waiting_reason'], 'until it does, the reason it last waited for stays');
    }

    public function testATargetOutOfReachHoldsTheJobUntilItAnswers(): void
    {
        $ali = $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1'));
        FakeProvider::$down = [$this->target->id];
        $id = $this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate']))['migration']['id'];

        $job = $this->work($id);
        self::assertSame(['running', 0, 0], [$job['status'], $job['moved'], $job['failed']]);
        self::assertStringStartsWith('سرور مقصد «هلند»: ', (string) $job['waiting_reason']);
        self::assertSame('target', $job['waiting_on'], 'the target holds it: a rescue would not help');
        self::assertSame([], FakeProvider::$deleted, 'the old client stays while the new one is not made');

        FakeProvider::$down = [];
        Carbon::setTestNow(now()->addMinutes(Server::BACKOFF_MINUTES));
        $this->service(MigrationsTask::class)->run();
        self::assertSame([MigrationStatus::Done, 1], [ServerMigration::query()->findOrFail($id)->status, ServerMigration::query()->findOrFail($id)->moved], 'the scheduler carries on once it answers');
        self::assertSame($this->target->id, $ali->refresh()->server_id);
    }

    public function testAServiceTheTargetRefusesIsRecordedWithWhyAndTheRestGoOn(): void
    {
        $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1'));
        $this->mirrored($this->subscription($this->sara, $this->plan, $this->source, 'sara_1'));
        FakeProvider::$refusing[$this->target->id] = ['createClient'];

        $job = $this->work($this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate']))['migration']['id']);

        $why = 'سرور مقصد «هلند»: ' . ProviderErrorPresenter::describe(new PanelApiException('The fake panel refused createClient.', 200, 'refused by the test'));
        self::assertSame(['done', 0, 2], [$job['status'], $job['moved'], $job['failed']]);
        self::assertSame("سرویس sara_1: {$why}", $job['last_failure']);
        $failures = $this->decode($this->get("/api/admin/servers/{$this->source->id}/migrations/{$job['id']}"))['failures'];
        self::assertSame([['sara_1', $why, 'فروشگاه اصلی'], ['ali_1', $why, 'فروشگاه اصلی']], array_map(static fn(array $row): array => [$row['client'], $row['reason'], $row['shop']['name'] ?? null], $failures), 'the latest first, with why');
        self::assertSame([], FakeProvider::$deleted, 'nothing moved, nothing deleted');
        self::assertSame([], $this->telegram()->sentTo(1001));
    }

    public function testEndedServicesStayAndSwitchedOffOnesMoveSwitchedOffWhenTicked(): void
    {
        $ended = $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1', ['status' => SubscriptionStatus::Expired, 'expires_at' => now()->subDay()]), enabled: false);
        $off = $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_2', ['status' => SubscriptionStatus::Disabled, 'disabled_at' => now()]), enabled: false);
        // Running as far as the shop knows; the panel has its traffic used up.
        $usedUp = $this->subscription($this->sara, $this->plan, $this->source, 'sara_1');
        FakeProvider::mirror((clone $usedUp)->forceFill(['download_bytes' => 30 * Traffic::GIGABYTE]));
        $preview = fn(bool $ticked): int => $this->decode($this->postJson("/api/admin/servers/{$this->source->id}/migrations/preview", ['include_disabled' => $ticked]))['services'];
        self::assertSame([1, 2], [$preview(false), $preview(true)]);

        $job = $this->work($this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate', 'include_disabled' => true]))['migration']['id']);

        self::assertSame(['done', 2, 1, 1], [$job['status'], $job['total'], $job['moved'], $job['skipped']], 'the one the panel ended is passed by');
        $off->refresh();
        self::assertSame([$this->target->id, SubscriptionStatus::Disabled], [$off->server_id, $off->status], 'still switched off');
        self::assertFalse(FakeProvider::$created[0]['spec']->enabled, 'made switched off on the target');
        self::assertFalse(FakeProvider::$clients[$this->target->id]['ali_2']->enabled, 'and it is off there');
        self::assertSame([], FakeProvider::$enabled, 'made so: no switch after it');
        self::assertSame([$this->source->id, $this->source->id], [$ended->refresh()->server_id, $usedUp->refresh()->server_id]);
        self::assertSame(SubscriptionStatus::Expired, $usedUp->status);
    }

    public function testASwitchedOffServiceTheTargetDoesNotMakeSwitchedOffStaysWhereItWas(): void
    {
        $off = $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1', ['status' => SubscriptionStatus::Disabled, 'disabled_at' => now()]), enabled: false);
        // A connector that cannot make it switched off refuses to make it (PasarGuard takes its user back).
        FakeProvider::$refusing = [$this->target->id => ['createClient']];

        $job = $this->work($this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate', 'include_disabled' => true]))['migration']['id']);

        self::assertSame(['done', 0, 1], [$job['status'], $job['moved'], $job['failed']]);
        self::assertSame([$this->source->id, SubscriptionStatus::Disabled], [$off->refresh()->server_id, $off->status]);
        self::assertArrayNotHasKey('ali_1', FakeProvider::$clients[$this->target->id] ?? [], 'nothing of it on the target');
        self::assertArrayHasKey('ali_1', FakeProvider::$clients[$this->source->id], 'the old one not deleted');
        self::assertFalse(FakeProvider::$clients[$this->source->id]['ali_1']->enabled, 'still off where it was');
    }

    public function testASwitchedOffServiceMadeSwitchedOnOnTheTargetIsTakenBackThere(): void
    {
        $off = $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1', ['status' => SubscriptionStatus::Disabled, 'disabled_at' => now()]), enabled: false);
        // The target makes the user but neither switches it off nor takes it back: it fails after the write, the user on.
        FakeProvider::$unread = [$this->target->id => ['createClient']];
        FakeProvider::$onCall = function (int $server, string $call): void {
            $made = FakeProvider::$clients[$this->target->id]['ali_1'] ?? null;
            if ($server === $this->target->id && $call === 'findClient' && $made !== null) {
                FakeProvider::put($this->target, new ClientInfo(...['enabled' => true] + get_object_vars($made)));
            }
        };

        $job = $this->work($this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate', 'include_disabled' => true]))['migration']['id']);

        self::assertSame(['done', 0, 1], [$job['status'], $job['moved'], $job['failed']]);
        self::assertSame([$this->source->id, SubscriptionStatus::Disabled], [$off->refresh()->server_id, $off->status]);
        self::assertArrayNotHasKey('ali_1', FakeProvider::$clients[$this->target->id], 'no working client of a switched-off service on the target');
        self::assertFalse(FakeProvider::$clients[$this->source->id]['ali_1']->enabled, 'still off where it was');
    }

    public function testOnlyThePlansChosenMove(): void
    {
        $other = $this->plan(['name' => 'دوماهه']);
        $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1'));
        $kept = $this->mirrored($this->subscription($this->sara, $other, $this->source, 'sara_1'));
        $preview = $this->decode($this->postJson("/api/admin/servers/{$this->source->id}/migrations/preview", ['plan_ids' => [$this->plan->id]]));
        self::assertSame(1, $preview['services']);
        self::assertSame([[$this->plan->id, 'یک‌ماهه', 'فروشگاه اصلی', 1], [$other->id, 'دوماهه', 'فروشگاه اصلی', 1]], array_map(static fn(array $plan): array => array_values($plan), $preview['plans']), 'the choices, counted without the filter');

        $job = $this->work($this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate', 'plan_ids' => [$this->plan->id]]))['migration']['id']);

        self::assertSame(['done', 1, [['id' => $this->plan->id, 'name' => 'یک‌ماهه']]], [$job['status'], $job['moved'], $job['plans']]);
        self::assertSame($this->source->id, $kept->refresh()->server_id);

        // The card names every job's plans from one read; one deleted since drops out.
        $this->work($this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate', 'plan_ids' => [$other->id, $this->plan->id]]))['migration']['id']);
        $this->plan->delete();
        self::assertSame([[['id' => $other->id, 'name' => 'دوماهه']], []], array_column($this->card()['migrations'], 'plans'));
    }

    public function testAServiceAnotherChangeHoldsIsComeBackToOnTheNextTurn(): void
    {
        $held = $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1'));
        $id = $this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate']))['migration']['id'];
        $renewal = Lease::take($held, 60) ?? self::fail('the service could not be held'); // a renewal is working on it

        $job = $this->work($id);
        self::assertSame(['running', 0, 0, 0], [$job['status'], $job['moved'], $job['skipped'], $job['failed']], 'it waits at that service');

        $renewal->release();
        $job = $this->work($id);
        self::assertSame(['done', 1], [$job['status'], $job['moved']]);
        self::assertSame($this->target->id, $held->refresh()->server_id);
    }

    public function testAServiceMovedElsewhereMeanwhileIsLeftWhereItIs(): void
    {
        $moved = $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1'));
        $id = $this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate']))['migration']['id'];
        $elsewhere = $this->fakeServer('فرانسه');
        $moved->forceFill(['server_id' => $elsewhere->id])->save();

        self::assertSame(['done', 0, 0], array_values(array_intersect_key($this->work($id), array_flip(['status', 'moved', 'skipped']))), 'no longer covered: off its source');
        self::assertSame($elsewhere->id, $moved->refresh()->server_id);
    }

    public function testAJobCanBePausedResumedAndCancelledAndWhatMovedStaysMoved(): void
    {
        $first = $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1'));
        $second = $this->mirrored($this->subscription($this->sara, $this->plan, $this->source, 'sara_1'));
        $id = $this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate']))['migration']['id'];
        $act = fn(string $action): ResponseInterface => $this->postJson("/api/admin/servers/{$this->source->id}/migrations/{$id}/{$action}");

        self::assertSame('paused', $this->decode($act('pause'))['migration']['status']);
        self::assertSame(['paused', 0], [$this->work($id)['status'], $this->work($id)['moved']], 'a paused job is not worked');
        self::assertSame(422, $act('pause')->getStatusCode());
        self::assertSame('queued', $this->decode($act('resume'))['migration']['status'], 'it never began');
        self::assertSame(422, $act('resume')->getStatusCode());

        // The old panel stops answering once the first service moved: the job waits at the second, and is cancelled there.
        FakeProvider::$onCall = function (int $server, string $call): void {
            if ($call === 'findClient' && $server === $this->source->id && FakeProvider::$created !== []) {
                FakeProvider::$down = [$server];
            }
        };
        self::assertSame(['running', 1], array_values(array_intersect_key($this->work($id), array_flip(['status', 'moved']))));
        self::assertSame('cancelled', $this->decode($act('cancel'))['migration']['status']);
        self::assertSame(422, $act('cancel')->getStatusCode());
        self::assertSame(422, $act('resume')->getStatusCode());

        FakeProvider::$down = [];
        Carbon::setTestNow(now()->addMinutes(Server::BACKOFF_MINUTES));
        $this->service(MigrationsTask::class)->run();
        self::assertSame([$this->target->id, $this->source->id], [$first->refresh()->server_id, $second->refresh()->server_id], 'what moved stays moved, nothing more is');
        self::assertStringStartsWith("⏹ <b>انتقال سرویس‌های سرور «آلمان» به سرور «هلند» لغو شد</b>\n📊 منتقل‌شده: ۱", $this->migrationReports()[1]);
    }

    /** @return array<string, array{string}> What the owner presses while a service is under way. */
    public static function pressedMidService(): array
    {
        return ['a pause' => ['pause'], 'the switch to rescue' => ['rescue']];
    }

    #[DataProvider('pressedMidService')]
    public function testAServiceUnderWayWhenTheOwnerPausesOrSwitchesToRescueIsComeBackToNotPassedBy(string $pressed): void
    {
        $ali = $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1'));
        $sara = $this->mirrored($this->subscription($this->sara, $this->plan, $this->source, 'sara_1'));
        $id = $this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate']))['migration']['id'];
        // While Ali's client is read on the source, the owner presses it — and that read never gets an answer.
        $done = false;
        FakeProvider::$onCall = function (int $server, string $call) use (&$done, $id, $pressed): void {
            if ($server === $this->source->id && $call === 'findClient' && !$done) {
                $done = true;
                $migrations = $this->service(ServerMigrations::class);
                $job = ServerMigration::query()->findOrFail($id);
                $pressed === 'pause' ? $migrations->pause($job) : $migrations->toRescue($job);
                FakeProvider::$down = [$server];
            }
        };

        $job = $this->work($id);

        self::assertSame([$pressed === 'pause' ? 'paused' : 'running', 0, null], [$job['status'], $job['moved'], $job['waiting_reason']]);
        $row = ServerMigration::query()->findOrFail($id);
        self::assertSame([0, null], [$row->last_subscription_id, $row->lease_token], 'its cursor back before Ali, and the job let go at once');

        FakeProvider::$down = [];
        Carbon::setTestNow(now()->addMinutes(Server::BACKOFF_MINUTES));
        if ($pressed === 'pause') {
            $this->postJson("/api/admin/servers/{$this->source->id}/migrations/{$id}/resume");
        }
        $job = $this->work($id);

        self::assertSame(['done', 2, 0, 0], [$job['status'], $job['moved'], $job['skipped'], $job['failed']]);
        self::assertSame([$this->target->id, $this->target->id], [$ali->refresh()->server_id, $sara->refresh()->server_id], 'Ali moved too');
    }

    public function testOneOpenMoveAServerAtATime(): void
    {
        $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1'));
        $id = $this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate']))['migration']['id'];
        $third = $this->fakeServer('فرانسه');
        $this->inbound($third, '9');

        $this->assertRefusedUnder('migration', 'انتقال دیگری از این سرور در جریان است؛ صبر کنید تمام شود یا آن را لغو کنید.', $this->start(['target_server_id' => $third->id, 'mode' => 'migrate']));
        // The target of an open move is not emptied meanwhile, and its source takes no services.
        $this->assertRefusedUnder('migration', 'سرویس‌های سرور «آلمان» در حال انتقال به همین سرور است؛ صبر کنید تمام شود یا آن را لغو کنید.', $this->postJson("/api/admin/servers/{$this->target->id}/migrations", ['target_server_id' => $third->id, 'mode' => 'migrate']));
        $this->assertRefusedUnder('target_server_id', 'سرویس‌های سرور «آلمان» خودش در حال انتقال است؛ سرور مقصد دیگری انتخاب کنید.', $this->postJson("/api/admin/servers/{$third->id}/migrations", ['target_server_id' => $this->source->id, 'mode' => 'migrate']));
        self::assertSame(1, ServerMigration::query()->count());

        $this->postJson("/api/admin/servers/{$this->source->id}/migrations/{$id}/cancel");
        self::assertSame(201, $this->start(['target_server_id' => $third->id, 'mode' => 'migrate'])->getStatusCode(), 'a new one may start');
    }

    public function testTheSchedulerCarriesOnWhenNoPageIsOpenAndStopsWhenItsTurnIsOver(): void
    {
        $subscription = $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1'));
        $id = $this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate']))['migration']['id'];
        $task = $this->service(MigrationsTask::class);

        $this->withTheTurnOver($task->run(...));
        self::assertSame([MigrationStatus::Queued, $this->source->id], [ServerMigration::query()->findOrFail($id)->status, $subscription->refresh()->server_id]);

        $task->run();
        self::assertSame([MigrationStatus::Done, $this->target->id], [ServerMigration::query()->findOrFail($id)->status, $subscription->refresh()->server_id]);
    }

    public function testAWorkerHoldingTheJobKeepsOthersOut(): void
    {
        $subscription = $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1'));
        $id = $this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate']))['migration']['id'];
        ServerMigration::query()->whereKey($id)->update(['lease_token' => 'someone-else', 'leased_until' => now()->addMinute()]);

        self::assertSame(0, $this->work($id)['moved']);
        self::assertSame($this->source->id, $subscription->refresh()->server_id);

        Carbon::setTestNow(now()->addMinutes(2));
        self::assertSame(1, $this->work($id)['moved'], 'a holder that went quiet loses it');
    }

    public function testCustomersAreToldOnlyWhenTheOwnerSaysSo(): void
    {
        $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1'));

        $job = $this->work($this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate', 'notify' => false]))['migration']['id']);

        self::assertSame([1, false], [$job['moved'], $job['notify']]);
        self::assertSame([], $this->telegram()->sentTo(1001));
    }

    public function testWhatTheOwnerChoosesIsChecked(): void
    {
        $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1'));
        $off = $this->fakeServer('فرانسه', ['is_active' => false]);

        $this->assertRefusedUnder('target_server_id', 'سرور مقصد را انتخاب کنید.', $this->unchecked()->postJson("/api/admin/servers/{$this->source->id}/migrations", ['mode' => 'migrate']));
        $this->assertRefusedUnder('mode', 'حالت انتقال را انتخاب کنید.', $this->unchecked()->start(['target_server_id' => $this->target->id, 'mode' => 'teleport']));
        $this->assertRefusedUnder('target_server_id', 'سرور مقصد باید سروری غیر از همین سرور باشد.', $this->start(['target_server_id' => $this->source->id, 'mode' => 'migrate']));
        $this->assertRefusedUnder('target_server_id', '«فرانسه»: سرور غیرفعال است.', $this->start(['target_server_id' => $off->id, 'mode' => 'migrate']));
        $this->assertRefusedUnder('migration', 'روی این سرور سرویس فعالی برای انتقال نیست.', $this->postJson("/api/admin/servers/{$this->target->id}/migrations", ['target_server_id' => $this->source->id, 'mode' => 'migrate']));
        $this->assertRefusedUnder('plan_ids', 'یکی از پلن‌ها پیدا نشد؛ صفحه را دوباره باز کنید.', $this->unchecked()->start(['target_server_id' => $this->target->id, 'mode' => 'migrate', 'plan_ids' => ['x']]));
        self::assertSame(0, ServerMigration::query()->count());
        self::assertSame('همین سرور است.', $this->decode($this->postJson("/api/admin/servers/{$this->source->id}/migrations/preview", ['target_server_id' => $this->source->id]))['target']['problem']);
        self::assertSame(404, $this->postJson('/api/admin/servers/999/migrations', ['target_server_id' => $this->target->id, 'mode' => 'migrate'])->getStatusCode());

        $id = $this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'migrate']))['migration']['id'];
        self::assertSame(404, $this->postJson("/api/admin/servers/{$this->target->id}/migrations/{$id}/run")->getStatusCode(), "another server's job");
        self::assertSame(404, $this->get("/api/admin/servers/{$this->target->id}/migrations/{$id}")->getStatusCode());
    }

    public function testASwitchThatIsNoSwitchIsRefusedNotReadAsOffAndThePlansAreAThousandAtMost(): void
    {
        $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1'));
        $body = ['target_server_id' => $this->target->id, 'mode' => 'migrate'];

        $this->assertRefusedUnder('notify', Validation::NOT_A_SWITCH, $this->unchecked()->start($body + ['notify' => 'nope']), message: 'never a move nobody is told of');
        $this->assertRefusedUnder('include_disabled', Validation::NOT_A_SWITCH, $this->unchecked()->start($body + ['include_disabled' => 'nope']));
        $this->assertRefusedUnder('include_disabled', Validation::NOT_A_SWITCH, $this->unchecked()->postJson("/api/admin/servers/{$this->source->id}/migrations/preview", ['include_disabled' => ['on']]));
        $this->assertRefusedUnder('plan_ids', 'لیست پلن‌ها بلندتر از حد است؛ حداکثر ۱٬۰۰۰ پلن.', $this->unchecked()->start($body + ['plan_ids' => range(1, 1001)]));
        self::assertSame(0, ServerMigration::query()->count());

        $job = $this->decode($this->start($body + ['notify' => false, 'include_disabled' => true, 'plan_ids' => [$this->plan->id]]))['migration'];
        self::assertSame([false, true], [$job['notify'], $job['include_disabled']], 'a switch as sent');
    }

    public function testOnlyTheOwnerMovesAServersServicesAndEveryShopsWhicheverIsOpen(): void
    {
        $bot = $this->agentBot();
        $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1'));
        CurrentBot::run($bot, fn() => FakeProvider::mirror($this->subscription($this->customer(['telegram_id' => 2001]), $this->plan(['name' => 'پلن نماینده']), $this->source, 'reza_1')));

        $this->openShop($bot);
        self::assertSame(2, $this->decode($this->postJson("/api/admin/servers/{$this->source->id}/migrations/preview", []))['services'], "every shop's, whichever is open");

        $this->loginAsAgent($bot);
        self::assertSame(404, $this->get("/api/agent/servers/{$this->source->id}/migrations")->getStatusCode(), "no part of an agent's panel");

        $this->openShop(CurrentBot::main());
        $this->postJson('/api/admin/auth/logout');
        self::assertSame(401, $this->get("/api/admin/servers/{$this->source->id}/migrations")->getStatusCode());
    }

    public function testAServerAMoveIsOpenFromOrToIsNotDeletedAndADeletedOneTakesItsMovesWithIt(): void
    {
        $ali = $this->mirrored($this->subscription($this->ali, $this->plan, $this->source, 'ali_1'));
        FakeProvider::$down = [$this->source->id];
        $id = $this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'rescue']))['migration']['id'];
        $this->postJson("/api/admin/servers/{$this->source->id}/migrations/{$id}/pause");
        $ali->delete(); // the source emptied meanwhile: the job would still go with it

        $refused = fn(Server $server): array => [$this->deleteJson("/api/admin/servers/{$server->id}")->getStatusCode(), $this->decode($this->deleteJson("/api/admin/servers/{$server->id}"))['message'] ?? null];
        self::assertSame([409, 'سرویس‌های این سرور در حال انتقال به سرور «هلند» است؛ صبر کنید تمام شود یا آن را لغو کنید.'], $refused($this->source));
        self::assertSame([409, 'سرویس‌های سرور «آلمان» در حال انتقال به این سرور است؛ صبر کنید تمام شود یا آن را لغو کنید.'], $refused($this->target), 'its target neither, though nothing reached it yet');

        // A rescue's leftovers on the source outlive the target's deletion: the source's panel still has them.
        $this->postJson("/api/admin/servers/{$this->source->id}/migrations/{$id}/cancel");
        $sara = $this->mirrored($this->subscription($this->sara, $this->plan, $this->source, 'sara_1'));
        $this->work($this->decode($this->start(['target_server_id' => $this->target->id, 'mode' => 'rescue']))['migration']['id']);
        $sara->refresh()->delete();
        self::assertSame(204, $this->deleteJson("/api/admin/servers/{$this->target->id}")->getStatusCode(), 'emptied, it can go');
        self::assertSame(0, ServerMigration::query()->count(), 'with its moves');
        self::assertSame(['pending' => 1, 'last_error' => null], $this->card()['leftovers'], 'Sara\'s old client still to remove from the source');

        self::assertSame(204, $this->deleteJson("/api/admin/servers/{$this->source->id}")->getStatusCode());
        self::assertSame(0, MigrationLeftover::query()->count(), 'those on a server deleted go with it');
    }

    /** @param array<string, mixed> $body */
    private function start(array $body): ResponseInterface
    {
        return $this->postJson("/api/admin/servers/{$this->source->id}/migrations", $body);
    }

    /** @return array<string, mixed> The job after a request's worth of work on it. */
    private function work(int $id): array
    {
        return $this->decode($this->postJson("/api/admin/servers/{$this->source->id}/migrations/{$id}/run"))['migration'];
    }

    /** @return array<string, mixed> The source server's card. */
    private function card(): array
    {
        return $this->decode($this->get("/api/admin/servers/{$this->source->id}/migrations"));
    }

    /** @return list<string> What the report group was told of the moves, in order. */
    private function migrationReports(): array
    {
        return ReportMessage::query()->where('topic', Topic::Errors->value)->where('text', 'like', '%انتقال سرویس‌های سرور%')->oldest('id')->pluck('text')->all();
    }

    /** The index of the Telegram call to the chat. */
    private function callTo(int $chat): int
    {
        foreach ($this->telegram()->calls() as $i => $method) {
            if (($this->telegram()->params($i)['chat_id'] ?? null) === (string) $chat) {
                return $i;
            }
        }

        self::fail("Nothing was sent to {$chat}.");
    }
}
