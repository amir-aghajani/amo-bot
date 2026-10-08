<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database\ChangeFeed;
use App\Modules\Referrals\Services\ReferralService;
use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Handlers\MenuHandler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Models\TelegramSession;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Telegram\Session\SessionStore;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\Dispatcher;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\BotTestCase;
use Tests\Fakes\AnsweredBoomHandler;
use Tests\Fakes\BoomHandler;
use Tests\Fakes\GroupBoomHandler;
use Tests\Fakes\StepHandler;
use Tests\Support\FakeTelegram;

/**
 * The dispatcher: a banned customer told so and nothing more, then the gates, then the route that takes the update — a
 * command, a keyboard label, the contact card, a tap's longest prefix, the step the chat is at by its longest prefix —
 * with the flow left behind on navigation and an admin's routes nobody else's; a tap answered once, after its handler;
 * a failure told on the button while it can be, in a message otherwise; the customer's row and the chat's session made
 * once though a chat's first updates come at once, and the row kept fresh without a write per update.
 */
final class BotDispatchTest extends BotTestCase
{
    /** The prefix of the flow the tests' own routes add (botWith(), atStep()). */
    private const STEP = 'test';

    public function testStartRegistersTheUserAndSendsTheMainMenu(): void
    {
        $this->send($this->message('/start'));

        self::assertSame(['sendMessage'], $this->calls());
        $params = $this->params(0);
        self::assertSame((string) self::CHAT, $params['chat_id']);
        self::assertSame(self::text(BotText::Welcome, ['name' => 'Ali']), $params['text']);
        self::assertTrue($this->markup(0)['is_persistent'] ?? false, 'the main menu is a persistent reply keyboard');
        self::assertSame(
            [[Messages::MENU_BUY], [Messages::MENU_RENEW, Messages::MENU_SERVICES], [Messages::MENU_WALLET, Messages::MENU_TUTORIAL], [Messages::MENU_SUPPORT]],
            $this->replyKeyboard(0),
            'rows are listed reversed so they read right to left on screen; the referral and agency buttons wait for their programs',
        );

        $user = User::query()->where('telegram_id', self::CHAT)->first();
        self::assertNotNull($user);
        self::assertSame('Ali', $user->first_name);
        self::assertNotNull(TelegramSession::of(self::CHAT), "the chat's session, made the first time the bot serves it");
    }

    /** @return iterable<string, array{string}> */
    public static function notUnderstood(): iterable
    {
        yield 'a text' => ['hello?'];
        yield 'a command the bot does not have' => ['/nope'];
    }

    #[DataProvider('notUnderstood')]
    public function testWhatTheBotHasNoAnswerForGetsTheMenu(string $text): void
    {
        $this->send($this->message($text));

        self::assertSame([self::text(BotText::Unknown)], $this->said());
        self::assertNotSame([], $this->replyKeyboard(0), 'with the menu');
    }

    public function testAButtonOfAScreenLongGoneIsNotUnderstoodEither(): void
    {
        $this->send($this->tap('nobody:knows'));

        self::assertSame(['sendMessage', 'answerCallbackQuery'], $this->calls(), 'the fallback, then the tap acknowledged');
        self::assertSame(self::text(BotText::Unknown), $this->params(0)['text']);
    }

    /** @return iterable<string, array{\Closure(self): Update}> */
    public static function navigation(): iterable
    {
        yield 'a command' => [static fn(self $test): Update => $test->message('/menu')];
        yield 'a keyboard label' => [static fn(self $test): Update => $test->message(Messages::MENU_SERVICES)];
        yield 'the contact card' => [static fn(self $test): Update => $test->contact('+989120000000', self::CHAT)];
        yield "the menu's button" => [static fn(self $test): Update => $test->tap(MainMenu::HOME)];
        yield 'a button of a screen long gone' => [static fn(self $test): Update => $test->tap('nobody:knows')];
        yield 'a command the bot does not have' => [static fn(self $test): Update => $test->message('/nope')];
    }

    /** @param \Closure(self): Update $update */
    #[DataProvider('navigation')]
    public function testNavigationLeavesTheFlowInProgress(\Closure $update): void
    {
        $this->atStep();

        $this->send($update($this));
        $this->send($this->message('42'));

        self::assertSame([self::text(BotText::Unknown)], $this->said(), 'the step no longer takes what the customer types');
    }

    public function testAButtonOfTheStepsOwnScreenLeavesTheFlowWhereItIs(): void
    {
        $this->atStep();

        $this->send($this->tap(MenuHandler::categoryCallback(null)));
        $this->send($this->message('42'));

        self::assertSame([StepHandler::SAYS], $this->said(), 'only the menu\'s buttons are navigation');
    }

    public function testTheLongestPrefixTakesATapAndAStep(): void
    {
        // Registered longer first for taps and shorter first for steps: neither the first nor the last registered wins.
        $this->botWith(static function (Dispatcher $bot): void {
            $bot->callback(self::STEP . ':deep', StepHandler::class)->callback(self::STEP . ':', BoomHandler::class);
            $bot->state(self::STEP, BoomHandler::class)->state(self::STEP . '.deep', StepHandler::class);
        });

        $this->send($this->tap(self::STEP . ':deep:1'));
        self::assertSame([StepHandler::SAYS], $this->said());

        $session = $this->service(SessionStore::class)->load(self::CHAT);
        $session->enter(self::STEP . '.deep.amount');
        $session->save();
        $this->send($this->message('42'));
        self::assertSame([StepHandler::SAYS], $this->said());
    }

    public function testGroupChatsAreIgnored(): void
    {
        $this->send($this->message('/start', ['chat' => ['id' => -100, 'type' => 'supergroup']]));

        self::assertSame([], $this->calls());
        self::assertFalse(User::query()->where('telegram_id', self::CHAT)->exists());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function nobodyBehind(): iterable
    {
        yield 'a bot' => [['from' => ['id' => self::CHAT, 'is_bot' => true, 'first_name' => 'Bot']]];
        yield 'no sender' => [['from' => null]];
    }

    /** @param array<string, mixed> $fields */
    #[DataProvider('nobodyBehind')]
    public function testAnUpdateWithNoPersonBehindItIsNotServed(array $fields): void
    {
        $this->send($this->message('/start', $fields));

        self::assertSame([], $this->calls());
        self::assertFalse(User::query()->where('telegram_id', self::CHAT)->exists(), 'nobody to register');
    }

    public function testAGroupButtonWhoseHandlerFailsIsLoggedAndNobodyThereIsTold(): void
    {
        $this->botWith(static fn(Dispatcher $bot) => $bot->groupCallback('boom:', GroupBoomHandler::class));
        $log = $this->logs();

        $this->send($this->groupTap('boom:now', 9, self::FIRST_THREAD));

        self::assertSame([], $this->calls());
        self::assertTrue($log->hasErrorThatContains('failed: group boom'));
    }

    public function testABannedCustomerIsToldSoAndNothingElse(): void
    {
        $this->customer(['status' => UserStatus::Banned]);
        // Gates that would hold anyone else back: the ban is told before them.
        $this->botSettings('general', ['enabled' => false, 'phone_required' => true]);

        $this->send($this->message('/start'));
        self::assertSame([self::text(BotText::Banned)], $this->said());

        $this->send($this->tap(MainMenu::PLANS));
        self::assertSame(['answerCallbackQuery'], $this->calls(), 'a tap: the popup, its one answer');
        self::assertSame([self::text(BotText::Banned), 'true'], [$this->popup(), $this->params(0)['show_alert']]);
    }

    public function testHandlerFailuresOnButtonsBecomeAnAlertNotAMessage(): void
    {
        $this->botWith(static fn(Dispatcher $bot) => $bot->callback('boom:', BoomHandler::class));

        $this->send($this->tap('boom:now'));

        self::assertSame(['answerCallbackQuery'], $this->calls());
        self::assertSame([self::text(BotText::Error), 'true'], [$this->popup(), $this->params(0)['show_alert']]);
    }

    public function testAFailureAfterTheTapWasAnsweredIsToldInAMessage(): void
    {
        $this->botWith(static fn(Dispatcher $bot) => $bot->callback('answered-boom:', AnsweredBoomHandler::class));
        // Telegram takes one answer per tap: a second is refused, as it is there.
        $answered = [];
        $this->telegram()->on('answerCallbackQuery', static function (array $params) use (&$answered): mixed {
            $first = !isset($answered[$params['callback_query_id']]);
            $answered[$params['callback_query_id']] = true;

            return $first ? true : FakeTelegram::error(400, 'Bad Request: query is too old and response timeout expired or query ID is invalid');
        });

        $this->send($this->tap('answered-boom:now'));

        self::assertSame('working on it', $this->popup(), "the handler's own answer stands");
        self::assertSame([self::text(BotText::Error)], $this->said(), 'and the failure comes in a message');
        self::assertSame(['answerCallbackQuery', 'sendMessage'], $this->calls(), 'no second answer was tried');
        self::assertArrayNotHasKey('parse_mode', $this->params(1), 'shown as written');
    }

    public function testATapTooOldToAnswerHearsOfAFailureInAMessage(): void
    {
        $this->botWith(static fn(Dispatcher $bot) => $bot->callback('boom:', BoomHandler::class));
        $this->telegram()->fail(400, 'Bad Request: query is too old and response timeout expired or query ID is invalid');

        $this->send($this->tap('boom:now'));

        self::assertSame(['answerCallbackQuery', 'sendMessage'], $this->calls());
        self::assertSame(self::text(BotText::Error), $this->params(1)['text']);
    }

    public function testAFailureOnAMessageIsToldAsWrittenInTheAdminsWordsAndLogged(): void
    {
        $this->botWith(static fn(Dispatcher $bot) => $bot->command('boom', BoomHandler::class));
        $this->service(BotTexts::class)->save(BotText::Error, 'یک <لحظه> صبر کنید');
        $log = $this->logs();

        $this->send($this->message('/boom'));

        self::assertSame(['sendMessage'], $this->calls());
        self::assertSame('یک <لحظه> صبر کنید', $this->params(0)['text']);
        self::assertArrayNotHasKey('parse_mode', $this->params(0), 'a popup\'s words, shown as written');
        self::assertTrue($log->hasErrorThatContains('failed: boom'));
    }

    public function testAFailureOfTheSettingsTableItselfIsToldInTheShopsWords(): void
    {
        $this->customer();
        $this->service(BotTexts::class)->save(BotText::Error, 'خطای ما');
        $this->service(Settings::class)->refresh();
        // The table the bot's settings — and the admin's wordings — are read from is gone (the test's transaction brings it back).
        $this->db()->getSchemaBuilder()->drop('settings');

        $this->send($this->message('/start'));

        self::assertSame([self::text(BotText::Error)], $this->telegram()->sentTo(self::CHAT));
    }

    public function testAFailureThatCannotBeToldIsLogged(): void
    {
        $log = $this->logs();
        $this->telegram()->on('sendMessage', static fn(): Response => FakeTelegram::error(502, 'Bad Gateway'));

        $this->send($this->message('/start'));

        self::assertSame(['sendMessage', 'sendMessage'], $this->calls(), 'the welcome, then the word of the failure — neither reached the chat');
        self::assertTrue($log->hasWarningThatContains('Could not report a failure to chat ' . self::CHAT));
    }

    public function testAnAdminOnlyRouteAnswersAnyoneElseNothingAtAll(): void
    {
        $this->botWith(static fn(Dispatcher $bot) => $bot->command('boom', BoomHandler::class, adminOnly: true));

        $this->send($this->message('/boom'));
        self::assertSame([], $this->calls(), 'as if there were no such command');

        $this->admin(['telegram_id' => 6161]);
        $this->send($this->message('/boom', chat: 6161));
        self::assertSame(self::text(BotText::Error), $this->params(0)['text'], 'the route is there for an admin');
    }

    public function testAnAdminNoLongerOneMidFlowIsNotLeftWithoutAnswers(): void
    {
        $this->botWith(static fn(Dispatcher $bot) => $bot->state(self::STEP, StepHandler::class, adminOnly: true));
        // The chat was at a step of an admin's flow when its customer stopped being an admin.
        $customer = $this->customer();
        $session = $this->service(SessionStore::class)->load(self::CHAT);
        $session->enter(self::STEP . '.compose');
        $session->save();

        $this->send($this->message('hi'));
        self::assertSame([self::text(BotText::Unknown)], $this->said(), 'the menu, as for any text');

        // Out of the flow: an admin again, the same text is still no answer to it.
        $customer->forceFill(['role' => UserRole::Admin])->save();
        $this->send($this->message('hi'));
        self::assertSame([self::text(BotText::Unknown)], $this->said());
    }

    public function testBlockingTheBotIsRecordedWithoutAReplyAndLiftedEitherWay(): void
    {
        $blocked = fn(): bool => User::query()->where('telegram_id', self::CHAT)->firstOrFail()->bot_blocked;

        $this->send($this->membership('kicked'));
        self::assertSame([], $this->calls());
        self::assertTrue($blocked());

        // Started again…
        $this->send($this->membership('member'));
        self::assertFalse($blocked());

        // …or writing to the bot: whoever writes to it has not blocked it.
        $this->send($this->membership('kicked'));
        $this->send($this->message('hi'));
        self::assertFalse($blocked());
    }

    public function testACustomerWithoutAVisibleNameIsGreetedWithoutOne(): void
    {
        // Some accounts' names are only invisible format characters.
        $this->send($this->message('/start', ['from' => ['id' => self::CHAT, 'first_name' => "\u{200F}\u{2063}"]]));

        self::assertSame([self::text(BotText::WelcomeNameless)], $this->said());
        self::assertNull(User::query()->where('telegram_id', self::CHAT)->firstOrFail()->name());
    }

    public function testOfTwoFirstUpdatesAtOnceOnlyTheOneThatMadeTheCustomerReportsThem(): void
    {
        $this->reportGroup();
        $this->referralProgram();
        $owner = $this->customer(['telegram_id' => 7070]);
        $start = '/start ref_' . $this->service(ReferralService::class)->codeFor($owner);
        $reports = static fn(): int => ReportMessage::query()->where('topic', Topic::Users)->count();
        // A newcomer is reported once.
        $this->send($this->message('/start', chat: 7171));
        self::assertSame(1, $reports());

        // The other update registers this one's newcomer between its lookup and its row.
        $this->whileAnotherUpdateArrives('users', fn() => $this->customer(), fn() => $this->send($this->message($start)));

        self::assertSame([self::text(BotText::Welcome, ['name' => 'Ali'])], $this->said(), 'served all the same');
        self::assertSame(1, User::query()->where('telegram_id', self::CHAT)->count(), 'one customer');
        self::assertSame(1, $reports(), 'reported by the update that made the row, not by this one too');
        self::assertSame([], $this->telegram()->sentTo(7070), 'nor is the referrer told twice');
    }

    public function testTwoFirstUpdatesAtOnceShareTheChatsSession(): void
    {
        // The other update makes the chat's session between this one's lookup and its row.
        $this->whileAnotherUpdateArrives('telegram_sessions', fn() => $this->service(SessionStore::class)->load(self::CHAT), fn() => $this->send($this->message('/start')));

        self::assertSame([self::text(BotText::Welcome, ['name' => 'Ali'])], $this->said(), 'served on the row the other made');
        self::assertSame(1, TelegramSession::query()->where('chat_id', self::CHAT)->count());
    }

    public function testTheCustomersRowIsWrittenWhenSomethingChangesOrAMinuteHasPassed(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->send($this->message('/start'));
        $seen = static fn(): string => (string) User::query()->where('telegram_id', self::CHAT)->firstOrFail()->last_seen_at?->toDateTimeString();
        $usersArea = fn(): int => $this->service(ChangeFeed::class)->versions()['users'] ?? 0;
        self::assertSame('2026-10-05 10:00:00', $seen());
        $moved = $usersArea();

        // Tapping through the shop within the minute writes nothing.
        Carbon::setTestNow('2026-10-05 10:00:40');
        $this->send($this->message('hello?'));
        self::assertSame('2026-10-05 10:00:00', $seen());

        // A new name is written at once, and the panels hear of it…
        $this->send($this->message('hello?', ['from' => ['id' => self::CHAT, 'first_name' => 'Ahmad']]));
        self::assertSame(['Ahmad', '2026-10-05 10:00:40'], [User::query()->where('telegram_id', self::CHAT)->firstOrFail()->first_name, $seen()]);
        self::assertSame($moved + 1, $usersArea());

        // …and a minute on, the visit — quietly: no panel reads its customers again for it.
        Carbon::setTestNow('2026-10-05 10:01:41');
        $this->send($this->message('hello?', ['from' => ['id' => self::CHAT, 'first_name' => 'Ahmad']]));
        self::assertSame('2026-10-05 10:01:41', $seen());
        self::assertSame($moved + 1, $usersArea());
    }

    /**
     * The bot with routes of the test's own on top of routes/bot.php's, on a dispatcher of its own: the container's,
     * which every other test talks to, never takes them (it is built afresh once the test is over).
     *
     * @param \Closure(Dispatcher): mixed $routes
     */
    private function botWith(\Closure $routes): void
    {
        $bot = $this->app()->container()->make('telegram.dispatcher.base');
        assert($bot instanceof Dispatcher);
        (require $this->app()->basePath('routes/bot.php'))($bot);
        $routes($bot);

        $this->swap(Dispatcher::class, $bot, 'telegram.dispatcher.base');
    }

    /** The chat at a step of a flow of the test's own, whose route answers whatever reaches it with StepHandler::SAYS. */
    private function atStep(): void
    {
        $this->botWith(static fn(Dispatcher $bot) => $bot->state(self::STEP, StepHandler::class));

        $session = $this->service(SessionStore::class)->load(self::CHAT);
        $session->enter(self::STEP . '.amount');
        $session->save();
    }

    /**
     * Another update of the same brand-new chat, served in the same moment: `$other` runs once, right after this one
     * (`$act`) looked for the chat's row in `$table` and found none — before it makes one.
     *
     * @param \Closure(): mixed $other
     * @param \Closure(): mixed $act
     */
    private function whileAnotherUpdateArrives(string $table, \Closure $other, \Closure $act): void
    {
        $waiting = true;
        $this->whileListening(QueryExecuted::class, static function (QueryExecuted $query) use ($table, $other, &$waiting): void {
            if ($waiting && str_starts_with($query->sql, 'select') && str_contains($query->sql, "from \"{$table}\"")) {
                $waiting = false;
                $other();
            }
        }, $act);

        self::assertFalse($waiting, "the other update's moment came");
    }

    /** The customer's my_chat_member update: they blocked the bot ("kicked") or started it again ("member"). */
    private function membership(string $status): Update
    {
        return new Update([
            // An id of its own, from the counter every update of the tests takes theirs from: the bot serves each once.
            'update_id' => $this->message(null)->id(),
            'my_chat_member' => [
                'chat' => ['id' => self::CHAT, 'type' => 'private'],
                'from' => ['id' => self::CHAT, 'first_name' => 'Ali'],
                'old_chat_member' => ['status' => $status === 'member' ? 'kicked' : 'member'],
                'new_chat_member' => ['status' => $status],
            ],
        ]);
    }
}
