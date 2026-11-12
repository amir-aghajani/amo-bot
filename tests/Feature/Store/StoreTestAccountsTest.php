<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Providers\Models\Server;
use App\Modules\Store\Exceptions\ProgramOffException;
use App\Modules\Store\Models\Website;
use App\Modules\Store\Services\CustomerTestAccounts;
use App\Modules\Subscriptions\Enums\TestRefusal;
use App\Modules\Subscriptions\Models\TestAccount;
use App\Modules\Users\Models\User;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * Free test accounts on the website and in the mini app, as the bot gives them: the servers offering one with their terms,
 * or the quota's words; one taken there, counted with the bot's; a server giving none, a quota spent and a panel failure
 * refused, nothing counted; a 404 while the shop gives none; nothing sent in Telegram.
 */
final class StoreTestAccountsTest extends HttpTestCase
{
    private Website $website;

    private User $ali;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->telegram();
        $this->website = $this->website();
        $this->ali = $this->customer(['username' => 'ali']);
        $this->server = $this->sellingServer('آلمان', ['test_enabled' => true]);
        $this->testsOn();
        $this->bearer($this->customerSession($this->ali));
    }

    public function testTheServersGivingATestNowWithTheirTerms(): void
    {
        $hourly = $this->sellingServer('هلند', ['test_enabled' => true, 'test_on_hold' => false, 'test_duration_hours' => 3, 'test_traffic_bytes' => Traffic::GIGABYTE]);
        $this->sellingServer('ترکیه');

        self::assertSame(['offers' => [
            ['server' => ['id' => $this->server->id, 'name' => 'آلمان'], 'traffic_mb' => 500, 'duration_hours' => 24, 'on_hold' => true],
            ['server' => ['id' => $hourly->id, 'name' => 'هلند'], 'traffic_mb' => 1024, 'duration_hours' => 3, 'on_hold' => false],
        ], 'refusal' => null], $this->decode($this->get($this->storeApi($this->website, '/test-account'))), 'a server not giving tests is not offered');
    }

    public function testATestIsTakenAsTheBotGivesOne(): void
    {
        $response = $this->postJson($this->storeApi($this->website, '/test-account'), ['server_id' => $this->server->id]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $test = $this->decode($response)['subscription'];
        self::assertSame(['test_ali_1', true, null, ['id' => $this->server->id, 'name' => 'آلمان']], [$test['name'], $test['is_test'], $test['plan'], $test['server']]);
        self::assertSame(['limit_bytes' => 500 * Traffic::MEGABYTE, 'used_bytes' => 0, 'remaining_bytes' => 500 * Traffic::MEGABYTE], $test['traffic']);
        self::assertTrue($test['term']['awaits_first_use']);
        self::assertSame([$this->ali->id, $test['id']], [TestAccount::query()->sole()->user_id, TestAccount::query()->sole()->subscription_id]);
        self::assertSame([], $this->telegram()->calls(), 'the screen shows it: nothing sent in Telegram');

        // The quota is the bot's: one for good, taken.
        self::assertSame(['offers' => [], 'refusal' => TestRefusal::Used->message()], $this->decode($this->get($this->storeApi($this->website, '/test-account'))));
        $this->assertRefusedUnder('status', TestRefusal::Used->message(), $this->postJson($this->storeApi($this->website, '/test-account'), ['server_id' => $this->server->id]));
        self::assertCount(1, FakeProvider::$created);
    }

    public function testAQuotaWithAWindowSaysWhenTheNextOneMayBeTaken(): void
    {
        $this->testsOn(['test_period_days' => 7]);
        self::assertSame(201, $this->postJson($this->storeApi($this->website, '/test-account'), ['server_id' => $this->server->id])->getStatusCode());

        $words = 'سهمیه اکانت تست شما فعلا تمام شده است؛ اکانت تست بعدی از ۲۲ مهر ۱۴۰۵، ۱۲:۰۰ ممکن می‌شود.';
        self::assertSame(['offers' => [], 'refusal' => $words], $this->decode($this->get($this->storeApi($this->website, '/test-account'))));
        $this->assertRefusedUnder('status', $words, $this->postJson($this->storeApi($this->website, '/test-account'), ['server_id' => $this->server->id]));
    }

    public function testNewcomersOnlyAndTheDaysCapAreTheQuotasWords(): void
    {
        $this->testsOn(['test_daily_limit' => 1]);
        $this->postJson($this->storeApi($this->website, '/test-account'), ['server_id' => $this->server->id]);
        $reza = $this->customer(['telegram_id' => 7272]);
        $this->bearer($this->customerSession($reza));

        self::assertSame(TestRefusal::DailyLimit->message(), $this->decode($this->get($this->storeApi($this->website, '/test-account')))['refusal']);

        $this->testsOn(['test_newcomers_only' => true]);
        $this->buy($reza, $this->plan([], $this->server), $this->server);
        self::assertSame(TestRefusal::NewcomersOnly->message(), $this->decode($this->get($this->storeApi($this->website, '/test-account')))['refusal']);
    }

    public function testAServerGivingNoneNowIsRefusedOnIt(): void
    {
        $other = $this->sellingServer('ترکیه');

        foreach ([$other->id, 999] as $id) {
            $this->assertRefusedUnder('server_id', CustomerTestAccounts::SERVER_GIVES_NONE, $this->postJson($this->storeApi($this->website, '/test-account'), ['server_id' => $id]));
        }
        self::assertSame([[], 0], [FakeProvider::$created, TestAccount::query()->count()]);

        $this->server->forceFill(['test_enabled' => false])->save();
        self::assertSame(['offers' => [], 'refusal' => TestRefusal::NoServer->message()], $this->decode($this->get($this->storeApi($this->website, '/test-account'))));
    }

    public function testAPanelThatFailsCountsNothing(): void
    {
        FakeProvider::$unreachable = true;

        $response = $this->postJson($this->storeApi($this->website, '/test-account'), ['server_id' => $this->server->id]);

        self::assertSame(502, $response->getStatusCode());
        self::assertSame([CustomerTestAccounts::NOT_MADE . 'سرور در دسترس نبود.'], $this->decode($response)['errors']['panel'] ?? null);
        self::assertSame(0, TestAccount::query()->count(), 'nothing of the quota spent');
        FakeProvider::$unreachable = false;
    }

    public function testNoneWhileTheShopGivesNone(): void
    {
        $this->testsOn(['test_enabled' => false]);

        foreach ([$this->get($this->storeApi($this->website, '/test-account')), $this->postJson($this->storeApi($this->website, '/test-account'), ['server_id' => $this->server->id])] as $response) {
            self::assertSame([404, ProgramOffException::TEST_ACCOUNTS], [$response->getStatusCode(), $this->decode($response)['message']]);
        }
        self::assertSame([], FakeProvider::$created);
    }

    public function testSignedInOnly(): void
    {
        $this->bearer(null);

        self::assertSame(401, $this->unchecked()->get($this->storeApi($this->website, '/test-account'))->getStatusCode());
    }

    /** @param array<string, mixed> $overrides */
    private function testsOn(array $overrides = []): void
    {
        $this->botSettings('test_accounts', $overrides + [
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
