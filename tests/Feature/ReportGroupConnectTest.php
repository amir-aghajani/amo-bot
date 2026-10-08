<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Models\ReportTopic;
use App\Modules\Telegram\Reports\GroupProblem;
use App\Modules\Telegram\Reports\ReportGroup;
use App\Modules\Telegram\Reports\ReportGroupState;
use App\Modules\Telegram\Reports\ReportSender;
use App\Modules\Telegram\Reports\ShopReports;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;
use Tests\Support\FakeTelegram;

/**
 * Handing the bot its report group: the panel's link adds the bot to a group as an admin and Telegram sends
 * `/start@<bot> reports_<code>` there — a supergroup with topics, where the bot may manage them, becomes the report
 * group and gets a topic per subject the bot makes itself (with an icon when Telegram has one that fits); anything
 * else is refused with the reason, in the group and on the screen. And afterwards: the bot's membership there and the
 * group's title, as Telegram tells of them.
 */
final class ReportGroupConnectTest extends BotTestCase
{
    private const BOT = 'amo_shop_bot';

    private int $thread = 500;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => self::BOT]);
    }

    public function testThePanelsLinkHandsTheBotAForumAndTheBotMakesEveryTopicItself(): void
    {
        $code = $this->code();
        $this->botManagesTopics();
        $this->telegram()->on('getForumTopicIconStickers', static fn(): array => [
            ['type' => 'custom_emoji', 'emoji' => '🛒', 'custom_emoji_id' => 'icon-cart'],
            ['type' => 'custom_emoji', 'emoji' => "⚠\u{FE0F}", 'custom_emoji_id' => 'icon-warning'],
            ['type' => 'custom_emoji', 'emoji' => '💰', 'custom_emoji_id' => 'icon-money'],
        ]);

        $this->send($this->groupText('/start@' . self::BOT . ' reports_' . $code));

        $topics = count(Topic::cases());
        self::assertSame(['getChatMember', 'getForumTopicIconStickers', ...array_fill(0, $topics, 'createForumTopic'), 'sendMessage'], $this->calls());
        self::assertSame(['chat_id' => (string) self::REPORT_GROUP, 'user_id' => (string) FakeTelegram::BOT_ID], $this->params(0), 'the bot asked for its own rights there');

        $made = array_map(fn(int $i): array => $this->params($i), range(2, 1 + $topics));
        self::assertSame(array_map(static fn(Topic $topic): string => $topic->title(), Topic::cases()), array_column($made, 'name'));
        self::assertSame(['9367192', '7322096', '16766590', '13338331', '16749490', '16478047', '7322096', '16766590', '13338331'], array_column($made, 'icon_color'), "Telegram's own six colours");
        self::assertSame('icon-cart', $made[0]['icon_custom_emoji_id']);
        self::assertSame('icon-money', $made[2]['icon_custom_emoji_id']);
        self::assertSame('icon-warning', $made[5]['icon_custom_emoji_id'], 'matched whether or not the emoji carries its variation selector');
        self::assertArrayNotHasKey('icon_custom_emoji_id', $made[1], 'no fitting icon: the colour alone');
        foreach ($made as $params) {
            self::assertSame((string) self::REPORT_GROUP, $params['chat_id']);
        }

        $state = $this->service(ReportGroupState::class);
        self::assertSame(self::REPORT_GROUP, $state->chatId());
        self::assertSame('گزارش فروشگاه', $state->title());
        self::assertNull($state->code(), 'the link is used up');
        self::assertSame(
            array_combine(array_map(static fn(Topic $topic): string => $topic->value, Topic::cases()), range(501, 500 + $topics)),
            ReportTopic::query()->oldest('id')->pluck('thread_id', 'topic')->all(),
        );

        $reply = $this->params(2 + $topics);
        self::assertSame((string) self::REPORT_GROUP, $reply['chat_id']);
        self::assertSame(['message_id' => 9, 'allow_sending_without_reply' => true], json_decode($reply['reply_parameters'], true));
        self::assertStringStartsWith('✅', $reply['text']);
        self::assertStringContainsString('• خریدها', $reply['text']);
        self::assertStringNotContainsString('⚠️', $reply['text'], 'every topic was made');
        self::assertFalse(User::query()->where('telegram_id', self::GROUP_ADMIN)->exists(), 'a group is not a customer');

        // Each topic's first message says what it is for, and goes to it — in the queue's order: the receipts' and the
        // errors' first, as everything of theirs.
        $this->telegram()->reset();
        self::assertSame($topics, $this->service(ReportSender::class)->flush());
        self::assertSame(array_fill(0, $topics, 'sendMessage'), $this->calls());
        $queued = Topic::cases();
        usort($queued, static fn(Topic $a, Topic $b): int => $a->priority() <=> $b->priority());
        foreach ($queued as $i => $topic) {
            self::assertSame((string) (501 + (int) array_search($topic, Topic::cases(), true)), $this->params($i)['message_thread_id']);
            self::assertSame("📌 <b>{$topic->title()}</b>\n{$topic->about()}", $this->params($i)['text']);
        }
    }

    public function testALinkMadeWhileTheBotWaitedForUpdatesStillWorks(): void
    {
        $this->botManagesTopics();
        $poller = new ReportGroupState();
        // bot:poll looks at the report group before its long wait for updates…
        self::assertNull($poller->chatId());
        // …and the panel, another process, makes the link meanwhile.
        $code = (new ReportGroupState())->issueCode()['code'];

        $this->send($this->groupText('/start@' . self::BOT . ' reports_' . $code));

        self::assertSame(self::REPORT_GROUP, $poller->chatId(), 'nothing kept from before the wait');
    }

    public function testAGroupWithoutTopicsIsRefusedAndTheSameLinkWorksOnceTheyAreOn(): void
    {
        $code = $this->code();
        $this->botManagesTopics();

        $this->send($this->groupText('/start@' . self::BOT . ' reports_' . $code, ['is_forum' => false]));
        self::assertSame(['sendMessage'], $this->calls(), 'nothing asked of a group without topics');
        self::assertStringStartsWith('⛔️', $this->params(0)['text']);
        self::assertStringContainsString(GroupProblem::ForumOff->message(), $this->params(0)['text']);

        $this->send($this->groupText('/start@' . self::BOT . ' reports_' . $code, ['type' => 'group', 'is_forum' => null], -4455));
        self::assertStringContainsString(GroupProblem::ForumOff->message(), $this->params(0)['text'], 'a plain group has no topics at all');

        $state = $this->service(ReportGroupState::class);
        self::assertNull($state->chatId());
        self::assertSame(['title' => 'گزارش فروشگاه', 'problem' => GroupProblem::ForumOff], array_intersect_key((array) $state->attempt(), ['title' => 0, 'problem' => 0]), 'the screen says why');

        $this->send($this->groupText('/start@' . self::BOT . ' reports_' . $code));
        self::assertSame(self::REPORT_GROUP, $state->chatId(), 'the code was not used up by the refusals');
        self::assertNull($state->attempt());
    }

    public function testABotThatIsNoAdminOrMayNotManageTopicsIsRefused(): void
    {
        $code = $this->code();

        $this->botManagesTopics(status: 'member');
        $this->send($this->groupText('/start@' . self::BOT . ' reports_' . $code));
        self::assertSame(['getChatMember', 'sendMessage'], $this->calls());
        self::assertStringContainsString(GroupProblem::NotAdmin->message(), $this->params(1)['text']);

        $this->botManagesTopics(right: false);
        $this->send($this->groupText('/start@' . self::BOT . ' reports_' . $code));
        self::assertStringContainsString(GroupProblem::NoTopicsRight->message(), $this->params(1)['text']);

        $this->telegram()->on('getChatMember', static fn(): mixed => FakeTelegram::error(500, 'Internal Server Error'));
        $this->send($this->groupText('/start@' . self::BOT . ' reports_' . $code));
        self::assertStringContainsString(GroupProblem::CheckFailed->message(), $this->params(1)['text']);

        self::assertNull($this->service(ReportGroupState::class)->chatId());
        self::assertSame(0, ReportTopic::query()->count());
    }

    public function testAnUnknownExpiredOrUsedCodeConnectsNothing(): void
    {
        $this->botManagesTopics();
        $state = $this->service(ReportGroupState::class);

        $this->send($this->groupText('/start@' . self::BOT . ' reports_0123456789abcdef0123456789abcdef'));
        self::assertSame(['sendMessage'], $this->calls());
        self::assertStringContainsString('معتبر نیست', $this->params(0)['text']);

        $code = $this->code();
        Carbon::setTestNow(now()->addMinutes(ReportGroupState::CODE_MINUTES + 1));
        $this->send($this->groupText('/start@' . self::BOT . ' reports_' . $code));
        self::assertSame(['sendMessage'], $this->calls(), 'an hour later the link is dead');
        self::assertNull($state->chatId());
        Carbon::setTestNow();

        $code = $this->code();
        $this->send($this->groupText('/start@' . self::BOT . ' reports_' . $code));
        self::assertSame(self::REPORT_GROUP, $state->chatId());

        $this->send($this->groupText('/start@' . self::BOT . ' reports_' . $code, [], -1009999));
        self::assertSame(['sendMessage'], $this->calls(), 'a link connects one group');
        self::assertSame(self::REPORT_GROUP, $state->chatId());
    }

    public function testAnAnswerTheGroupWillNotTakeIsLoggedAndConnectsAllTheSame(): void
    {
        $code = $this->code();
        $this->botManagesTopics();
        $logs = $this->logs();
        // The admin left the bot the right to manage topics alone: it may not post where the command was typed.
        $this->telegram()->on('sendMessage', static fn(): mixed => FakeTelegram::error(400, 'Bad Request: not enough rights to send text messages to the chat'));

        $this->send($this->groupText('/start@' . self::BOT . ' reports_' . $code));

        self::assertSame(self::REPORT_GROUP, $this->service(ReportGroupState::class)->chatId());
        self::assertTrue($logs->hasWarningThatContains('Could not answer in group'));
    }

    public function testOnlyThisBotsStartIsItsBusinessInAGroup(): void
    {
        $code = $this->code();
        $this->botManagesTopics();
        $state = $this->service(ReportGroupState::class);

        foreach (['/start@other_bot reports_' . $code, '/start', 'سلام به همه', '/menu@' . self::BOT] as $text) {
            $this->send($this->groupText($text));
            self::assertSame([], $this->calls(), $text);
        }
        self::assertNull($state->chatId());

        $this->send($this->groupText('/start reports_' . $code));
        self::assertSame(self::REPORT_GROUP, $state->chatId(), 'a /start with no "@bot" is ours as well');
    }

    public function testTheBotsOwnMembershipThereIsWhatTheScreenShows(): void
    {
        $this->reportGroup();
        $state = $this->service(ReportGroupState::class);

        $this->send($this->botMembership(['status' => 'left']));
        self::assertSame(GroupProblem::Removed, $state->problem());
        self::assertSame([], $this->calls(), 'nobody is told in the group');

        $this->send($this->botMembership(['status' => 'administrator', 'can_manage_topics' => false]));
        self::assertSame(GroupProblem::NoTopicsRight, $state->problem());

        $this->send($this->botMembership(['status' => 'member']));
        self::assertSame(GroupProblem::NotAdmin, $state->problem());

        $state->pause(600);
        $this->send($this->botMembership(['status' => 'administrator', 'can_manage_topics' => true]));
        self::assertNull($state->problem());
        self::assertNull($state->pausedUntil(), 'what waited goes out now');

        $this->send($this->botMembership(['status' => 'kicked'], -1009999));
        self::assertNull($state->problem(), 'another group is none of the report group\'s business');
    }

    public function testAButtonNoRouteTakesIsOnlyAcknowledged(): void
    {
        $threads = $this->reportGroup();

        $this->send($this->groupTap('old:1', 40, $threads['errors']));

        self::assertSame(['answerCallbackQuery'], $this->calls(), 'its spinner stops, nothing else');
        self::assertArrayNotHasKey('text', $this->params(0));
    }

    public function testTheGroupsNewTitleIsKept(): void
    {
        $this->reportGroup();
        $state = $this->service(ReportGroupState::class);

        $this->send(new Update(['update_id' => 1, 'message' => ['message_id' => 3, 'chat' => ['id' => self::REPORT_GROUP, 'type' => 'supergroup', 'is_forum' => true], 'from' => ['id' => self::GROUP_ADMIN, 'first_name' => 'Boss'], 'new_chat_title' => 'گزارش‌های جدید']]));
        self::assertSame('گزارش‌های جدید', $state->title());

        $this->send(new Update(['update_id' => 2, 'message' => ['message_id' => 4, 'chat' => ['id' => -1009999, 'type' => 'supergroup'], 'from' => ['id' => self::GROUP_ADMIN, 'first_name' => 'Boss'], 'new_chat_title' => 'یک گروه دیگر']]));
        self::assertSame('گزارش‌های جدید', $state->title());
    }

    public function testTheSameGroupAgainKeepsItsTopicsAndAnotherGroupGetsItsOwn(): void
    {
        $this->reportGroup();
        $this->botManagesTopics();
        $state = $this->service(ReportGroupState::class);

        $this->send($this->groupText('/start@' . self::BOT . ' reports_' . $this->code()));
        self::assertSame(['getChatMember', 'sendMessage'], $this->calls(), 'its topics are there already');
        self::assertSame(0, ReportMessage::query()->count(), 'and said what they are for when they were made');

        $this->send($this->groupText('/start@' . self::BOT . ' reports_' . $this->code(), ['title' => 'گروه تازه'], -1009999));
        self::assertSame(count(Topic::cases()), count(array_keys($this->calls(), 'createForumTopic', true)));
        self::assertSame(-1009999, $state->chatId());
        self::assertSame('گروه تازه', $state->title());
        self::assertSame(range(501, 500 + count(Topic::cases())), ReportTopic::query()->oldest('id')->pluck('thread_id')->all(), 'the old group\'s topics are forgotten, the new one\'s kept');
    }

    public function testAFloodLimitWhileMakingTopicsLeavesTheRestForTheirFirstReport(): void
    {
        $code = $this->code();
        $this->botManagesTopics();
        $made = 0;
        $this->telegram()->on('createForumTopic', function (array $params) use (&$made): mixed {
            return ++$made === 3 ? FakeTelegram::flood(30) : ['message_thread_id' => ++$this->thread, 'name' => $params['name'], 'icon_color' => (int) $params['icon_color']];
        });

        $this->send($this->groupText('/start@' . self::BOT . ' reports_' . $code));

        self::assertSame(['getChatMember', 'getForumTopicIconStickers', 'createForumTopic', 'createForumTopic', 'createForumTopic', 'sendMessage'], $this->calls(), 'the flood limit would refuse the rest too');
        $missing = array_map(static fn(Topic $topic): string => '«' . $topic->title() . '»', [Topic::Wallet, Topic::Receipts, Topic::Users, Topic::Errors, Topic::Agency]);
        self::assertStringContainsString(implode('، ', $missing), $this->telegram()->sentTo(self::REPORT_GROUP)[0], 'the group is told which are still to come');
        $state = $this->service(ReportGroupState::class);
        self::assertSame(self::REPORT_GROUP, $state->chatId(), 'connected all the same');
        self::assertNotNull($state->pausedUntil());
        self::assertSame([Topic::Purchases->value => 501, Topic::Renewals->value => 502, Topic::Wallet->value => null], ReportTopic::query()->oldest('id')->pluck('thread_id', 'topic')->all());
        self::assertNull(ReportTopic::query()->where('topic', Topic::Wallet->value)->value('lease_token'), 'the hold on the topic was let go');

        // Once the wait is over, the wallet's first report makes its topic.
        Carbon::setTestNow(now()->addSeconds(31));
        $this->service(ShopReports::class)->notice(Topic::Wallet, 'کیف پول');
        $this->telegram()->reset();
        $this->service(ReportSender::class)->flush(30);
        self::assertSame(['sendMessage', 'sendMessage', 'getForumTopicIconStickers', 'createForumTopic', 'sendMessage'], array_slice($this->calls(), 0, 5), 'the two introductions, then the wallet topic made for its own');
        self::assertSame((string) $this->thread, $this->params(4)['message_thread_id']);
    }

    /** A connect code, as the screen's «ساخت لینک اتصال» makes one. */
    private function code(): string
    {
        $this->service(ReportGroup::class)->newLink();

        return (string) $this->service(ReportGroupState::class)->code()['code'];
    }

    /** What Telegram says of the bot's rights in the group, and the topics it makes there (threads 501, 502, …). */
    private function botManagesTopics(bool $right = true, string $status = 'administrator'): void
    {
        $this->telegram()->on('getChatMember', static fn(): array => ['status' => $status, 'can_manage_topics' => $right, 'user' => ['id' => FakeTelegram::BOT_ID, 'is_bot' => true, 'first_name' => 'AmoBot']]);
        $this->telegram()->on('createForumTopic', fn(array $params): array => ['message_thread_id' => ++$this->thread, 'name' => $params['name'], 'icon_color' => (int) $params['icon_color']]);
    }
}
