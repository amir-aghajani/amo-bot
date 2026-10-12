<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\TestAccount;
use App\Modules\Telegram\Channels\JoinPrompt;
use App\Modules\Telegram\Handlers\ExtrasHandler;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Handlers\TestAccountHandler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Session\ChatSession;
use App\Modules\Telegram\Session\SessionStore;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Support\Persian;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;

/**
 * «🧪 اکانت تست» in the bot: on the menu only while the shop gives tests; /free, the menu's button and the
 * `?start=usertest` link show the servers giving one with their terms; a tap makes the test on the panel and sends it;
 * every refusal of the quota is worded (with «خرید اشتراک» where buying is the way on), and a tap on a server giving no
 * test shows the screen as it is now; a link held back by a gate opens once the customer gets through within the hour,
 * and only what a link carries is held; a panel that fails is worded and spends nothing of the quota, and a test
 * Telegram would not take still counts.
 */
final class BotTestAccountsTest extends BotTestCase
{
    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->withoutQr();
        $this->ali = $this->customer();
    }

    public function testTheButtonIsOnTheMenuOnlyWhileTestsAreGiven(): void
    {
        $this->send($this->message('/start'));
        self::assertNotContains(Messages::MENU_TEST, array_merge(...$this->replyKeyboard(0)));

        $this->testsOn();
        $this->send($this->message('/start'));
        self::assertSame([[Messages::MENU_BUY], [Messages::MENU_TEST]], array_slice($this->replyKeyboard(0), 0, 2), 'a row of its own under «خرید اشتراک»');
    }

    public function testFreeAndTheMenusButtonListTheServersWithTheirTerms(): void
    {
        $this->testsOn();
        $germany = $this->testServer();
        $dutch = $this->testServer('هلند', ['test_traffic_bytes' => Traffic::GIGABYTE, 'test_duration_hours' => 12, 'test_on_hold' => false]);
        $this->sellingServer('فرانسه');
        $screen = self::text(BotText::TestScreen, ['servers' => implode("\n", [
            self::text(BotText::TestServerLine, ['server' => 'آلمان', 'traffic' => '۵۰۰ مگابایت', 'duration' => '۱ روز', 'hold' => self::text(BotText::TestOnHold)]),
            self::text(BotText::TestServerLine, ['server' => 'هلند', 'traffic' => '۱ گیگابایت', 'duration' => '۱۲ ساعت', 'hold' => '']),
        ])]);

        $this->send($this->message('/free'));
        self::assertSame([$screen], $this->said());
        self::assertSame([self::serverTap($germany), self::serverTap($dutch)], $this->callbacks(0), 'a button a server, none for one giving no test');
        self::assertSame(self::text(BotText::ServerButton, ['server' => 'آلمان']), $this->inlineKeyboard(0)[0][0]['text']);

        $this->send($this->message(Messages::MENU_TEST));
        self::assertSame([$screen], $this->said());
    }

    public function testATapMakesATestCountedFromItsFirstConnectionAndSendsIt(): void
    {
        $this->testsOn();
        $server = $this->testServer();

        $this->send($this->tap(self::serverTap($server)));

        $test = Subscription::query()->sole();
        self::assertSame([true, null, 24, 500 * Traffic::MEGABYTE, null], [$test->is_test, $test->plan_id, $test->duration_hours, $test->traffic_limit_bytes, $test->expires_at], 'no plan, its day waiting for the first connection');
        self::assertStringStartsWith('test_', $test->remote_name);
        self::assertSame([$test->remote_name], array_keys(FakeProvider::$clients[$server->id]), 'made on the panel');
        self::assertSame($test->id, TestAccount::query()->sole()->subscription_id, 'the quota counts it');

        self::assertSame(['deleteMessage', 'sendMessage', 'answerCallbackQuery'], $this->calls(), 'the screen makes way for the delivery');
        self::assertSame([self::text(BotText::TestDelivered, [
            'client' => $test->remote_name,
            'server' => 'آلمان',
            'traffic' => '۵۰۰ مگابایت',
            'expires' => Messages::expiry(null, 24),
            'subscription' => $test->subscription_url,
        ])], $this->said());
        self::assertSame([SubscriptionHandler::serviceCallback($test->id)], $this->callbacks(1));
    }

    public function testATestOnAFixedTermRunsFromTheMomentItIsMade(): void
    {
        $this->testsOn();
        $server = $this->testServer('آلمان', ['test_duration_hours' => 12, 'test_on_hold' => false]);

        $this->send($this->tap(self::serverTap($server)));

        $test = Subscription::query()->sole();
        self::assertTrue(now()->addHours(12)->equalTo($test->expires_at), 'its deadline set now');
        self::assertStringContainsString(Messages::expiry(now()->addHours(12), 12), $this->said()[0]);
    }

    public function testATestsOwnScreenNamesItAndOffersNoRenewalNorExtras(): void
    {
        $this->testsOn();
        $this->extrasOnSale();
        $this->walletMethod();
        $this->send($this->tap(self::serverTap($this->testServer())));
        $test = Subscription::query()->sole();

        $this->send($this->tap(SubscriptionHandler::serviceCallback($test->id)));

        self::assertStringContainsString('🌿 نام سرویس: ' . self::text(BotText::TestPlanName), $this->said()[0]);
        $taps = $this->callbacks(0);
        self::assertContains(SubscriptionHandler::serviceCallback($test->id, 'link'), $taps);
        self::assertNotContains(SubscriptionHandler::serviceCallback($test->id, 'renew'), $taps);
        self::assertNotContains(SubscriptionHandler::serviceCallback($test->id, 'autorenew'), $taps);
        self::assertSame([], array_filter($taps, static fn(string $data): bool => str_starts_with($data, ExtrasHandler::PREFIX)), 'no extras');
    }

    public function testWhileOffOrWithoutAServerTheScreenSaysSo(): void
    {
        $this->testServer();
        $this->send($this->tap(MainMenu::TEST));
        self::assertSame([self::text(BotText::TestOff)], $this->said());
        self::assertSame([], $this->callbacks(0));

        $this->testsOn();
        Server::query()->update(['test_enabled' => false]);
        $this->send($this->message('/free'));
        self::assertSame([self::text(BotText::TestNoServer)], $this->said());
        self::assertSame([], $this->callbacks(0));
    }

    public function testABuyerIsRefusedWhileTestsAreForNewcomersOnly(): void
    {
        $this->testsOn(['test_newcomers_only' => true]);
        $server = $this->testServer();
        $this->purchaseOrder($this->ali, $this->plan([], $server), $server, ['status' => OrderStatus::Fulfilled]);

        $this->send($this->message('/free'));

        self::assertSame([self::text(BotText::TestNewcomersOnly)], $this->said());
        self::assertSame([MainMenu::PLANS], $this->callbacks(0), '«خرید اشتراک» is the way on');
    }

    public function testATestTakenForGoodIsSaidSoEvenOnAnOldScreensTap(): void
    {
        $this->testsOn();
        $server = $this->testServer();
        $this->send($this->tap(self::serverTap($server)));

        $this->send($this->message('/free'));
        self::assertSame([self::text(BotText::TestUsed)], $this->said());
        self::assertSame([MainMenu::PLANS], $this->callbacks(0));

        $this->send($this->tap(self::serverTap($server)));
        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls());
        self::assertSame([self::text(BotText::TestUsed)], $this->said());
        self::assertSame(1, Subscription::query()->count(), 'no second test');
    }

    public function testAQuotaOverAWindowSaysWhenTheNextTestIsPossible(): void
    {
        $this->testsOn(['test_period_days' => 7]);
        $server = $this->testServer();
        $this->send($this->tap(self::serverTap($server)));
        $taken = now()->copy();
        Carbon::setTestNow(now()->addDays(2));

        $this->send($this->message('/free'));

        self::assertSame([self::text(BotText::TestUsedUntil, ['next_test' => Persian::date($taken->addDays(7), withTime: true)])], $this->said());
        self::assertSame([MainMenu::PLANS], $this->callbacks(0));
    }

    public function testTheShopsCapADayIsSaid(): void
    {
        $this->testsOn(['test_daily_limit' => 1]);
        $server = $this->testServer();
        $reza = $this->customer(['telegram_id' => 9999, 'first_name' => 'Reza']);
        TestAccount::query()->create(['user_id' => $reza->id, 'server_id' => $server->id, 'traffic_bytes' => 1, 'duration_hours' => 1, 'on_hold' => true, 'issued_at' => now()]);

        $this->send($this->message('/free'));

        self::assertSame([self::text(BotText::TestDailyLimit)], $this->said());
        self::assertSame([MainMenu::PLANS], $this->callbacks(0));
    }

    public function testTheStartLinkOpensTheTestScreenUnderTheGreeting(): void
    {
        $this->testsOn();
        $server = $this->testServer();

        $this->send($this->message('/start ' . TestAccountHandler::START_LINK));

        self::assertSame(['sendMessage', 'sendMessage'], $this->calls());
        self::assertSame(self::text(BotText::Welcome, ['name' => 'Ali']), $this->said()[0]);
        self::assertStringStartsWith('🧪 <b>اکانت تست رایگان</b>', $this->said()[1]);
        self::assertSame([self::serverTap($server)], $this->callbacks(1));
    }

    public function testTheStartLinkSaysWhyNoTestUnderTheGreeting(): void
    {
        $this->testsOn();
        $this->send($this->tap(self::serverTap($this->testServer())));

        $this->send($this->message('/start ' . TestAccountHandler::START_LINK));

        self::assertSame([self::text(BotText::Welcome, ['name' => 'Ali']), self::text(BotText::TestUsed)], $this->said());
        self::assertSame([MainMenu::PLANS], $this->callbacks(1));
    }

    public function testALinkThePhoneGateHeldBackOpensOnceTheNumberIsIn(): void
    {
        $this->botSettings('general', ['enabled' => true, 'phone_required' => true]);
        $this->testsOn();
        $server = $this->testServer();

        $this->send($this->message('/start ' . TestAccountHandler::START_LINK));
        self::assertSame([self::text(BotText::PhonePrompt)], $this->said());

        $this->send($this->contact('+989120000000', userId: self::CHAT));
        self::assertSame([self::text(BotText::PhoneVerified), self::text(BotText::Welcome, ['name' => 'Ali'])], array_slice($this->said(), 0, 2));
        self::assertStringStartsWith('🧪 <b>اکانت تست رایگان</b>', $this->said()[2]);
        self::assertSame([self::serverTap($server)], $this->callbacks(2));

        // The link opened once: the menu after it is just the menu.
        $this->send($this->message('/start'));
        self::assertSame([self::text(BotText::Welcome, ['name' => 'Ali'])], $this->said());
    }

    public function testALinkTheJoinScreenHeldBackOpensAtIJoined(): void
    {
        $this->botSettings('channels', ['join_required' => true]);
        $this->channel(-1001, 'کانال اخبار', ['username' => 'news']);
        $this->testsOn();
        $server = $this->testServer();

        $this->telegram()->reply(['status' => 'left']);
        $this->send($this->message('/start ' . TestAccountHandler::START_LINK));
        self::assertSame([self::text(BotText::JoinPrompt)], $this->said());

        $this->telegram()->reply(['status' => 'member']);
        $this->send($this->tap(JoinPrompt::CHECK));
        self::assertSame(['getChatMember', 'answerCallbackQuery', 'editMessageText', 'sendMessage', 'sendMessage'], $this->calls(), 'the screen a new message under the greeting');
        self::assertSame([self::text(BotText::JoinDone), self::text(BotText::Welcome, ['name' => 'Ali'])], array_slice($this->said(), 0, 2));
        self::assertSame([self::serverTap($server)], $this->callbacks(4));
    }

    public function testALinkHeldOverAnHourOpensNothing(): void
    {
        $this->botSettings('channels', ['join_required' => true]);
        $this->channel(-1001, 'کانال اخبار', ['username' => 'news']);
        $this->testsOn();
        $this->testServer();
        $this->telegram()->reply(['status' => 'left']);
        $this->send($this->message('/start ' . TestAccountHandler::START_LINK));
        self::assertSame([self::text(BotText::JoinPrompt)], $this->said());

        // The customer joined without «عضو شدم» and comes back later.
        Carbon::setTestNow(now()->addHour()->addSecond());
        $this->telegram()->reply(['status' => 'member']);
        $this->send($this->message('/menu'));

        self::assertSame(['getChatMember', 'sendMessage'], $this->calls());
        self::assertSame([self::text(BotText::Welcome, ['name' => 'Ali'])], $this->said(), 'the menu alone');
    }

    public function testOnlyWhatALinkCanCarryIsHeldBack(): void
    {
        $this->botSettings('general', ['enabled' => true, 'phone_required' => true]);

        foreach (['/start ' . str_repeat('a', 65), '/start ' . TestAccountHandler::START_LINK . ' please'] as $typed) {
            $this->send($this->message($typed));
            self::assertSame([self::text(BotText::PhonePrompt)], $this->said(), $typed);
            self::assertNull($this->session()->takeStartLink(), $typed);
        }

        $this->send($this->message('/start ' . str_repeat('a', 64)));
        self::assertSame(str_repeat('a', 64), $this->session()->takeStartLink(), 'the longest a link carries');
    }

    public function testATapOnAServerGivingNoTestShowsTheScreenAsItIsNow(): void
    {
        $this->testsOn();
        $germany = $this->testServer();
        $dutch = $this->testServer('هلند');
        $germany->forceFill(['test_enabled' => false])->save();
        $screen = self::text(BotText::TestScreen, ['servers' => self::text(BotText::TestServerLine, ['server' => 'هلند', 'traffic' => '۵۰۰ مگابایت', 'duration' => '۱ روز', 'hold' => self::text(BotText::TestOnHold)])]);

        foreach ([self::serverTap($germany), TestAccountHandler::PREFIX . '999'] as $tap) {
            $this->send($this->tap($tap));

            self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls(), $tap);
            self::assertSame([$screen], $this->said(), $tap);
            self::assertSame([self::serverTap($dutch)], $this->callbacks(0), $tap);
        }
        self::assertSame(0, Subscription::query()->count());
    }

    public function testATestTelegramWouldNotTakeStillCountsAndIsSaidSo(): void
    {
        $this->testsOn();
        $server = $this->testServer();
        $logs = $this->logs();
        // The screen goes; the delivery is refused.
        $this->telegram()->reply(true);
        $this->telegram()->fail(400, 'Bad Request: message is too long');

        $this->send($this->tap(self::serverTap($server)));

        $test = Subscription::query()->sole();
        self::assertSame($test->id, TestAccount::query()->sole()->subscription_id, 'the quota counts it');
        self::assertSame(['deleteMessage', 'sendMessage', 'sendMessage', 'answerCallbackQuery'], $this->calls());
        self::assertSame(self::text(BotText::TestUnsent), $this->params(2)['text']);
        self::assertSame([SubscriptionHandler::serviceCallback($test->id)], $this->callbacks(2), 'the button to its screen');
        self::assertTrue($logs->hasWarningThatContains('could not send it'));
    }

    /** @return iterable<string, array{\Closure(): void}> */
    public static function panelFailures(): iterable
    {
        yield 'out of reach' => [static function (): void {
            FakeProvider::$unreachable = true;
        }];
        yield 'its subscription server off' => [static function (): void {
            FakeProvider::$subscriptionBase = null;
        }];
    }

    /** @param \Closure(): void $fail */
    #[DataProvider('panelFailures')]
    public function testAPanelThatFailsIsWordedAndSpendsNothingOfTheQuota(\Closure $fail): void
    {
        $this->testsOn();
        $server = $this->testServer();
        $fail();
        $logs = $this->logs();

        $this->send($this->tap(self::serverTap($server)));

        self::assertSame([self::text(BotText::TestFailed)], $this->said());
        self::assertSame([MainMenu::TEST], $this->callbacks(0));
        self::assertSame([0, 0], [TestAccount::query()->count(), Subscription::query()->count()], 'the claim given back');
        self::assertTrue($logs->hasWarningThatContains('Could not make a test account'));
    }

    /**
     * Free test accounts switched on as the bot settings save them: one a customer, for good, no cap a day, for
     * everyone — `$overrides` the group's fields.
     *
     * @param array<string, mixed> $overrides
     */
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

    /**
     * A server the shop sells on that gives tests: 500 MB for a day counted from the first connection, unless told
     * otherwise.
     *
     * @param array<string, mixed> $overrides
     */
    private function testServer(string $name = 'آلمان', array $overrides = []): Server
    {
        return $this->sellingServer($name, $overrides + ['test_enabled' => true, 'test_traffic_bytes' => 500 * Traffic::MEGABYTE, 'test_duration_hours' => 24, 'test_on_hold' => true]);
    }

    /** The button that takes a test on the server. */
    private static function serverTap(Server $server): string
    {
        return TestAccountHandler::PREFIX . $server->id;
    }

    /** The customer's chat as stored. */
    private function session(): ChatSession
    {
        return $this->service(SessionStore::class)->load(self::CHAT);
    }
}
