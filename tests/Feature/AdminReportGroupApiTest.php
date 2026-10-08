<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Models\ReportTopic;
use App\Modules\Telegram\Reports\GroupProblem;
use App\Modules\Telegram\Reports\ReportGroup;
use App\Modules\Telegram\Reports\ReportGroupState;
use App\Modules\Telegram\Reports\ReportSettings;
use App\Modules\Telegram\Reports\ShopReports;
use App\Modules\Telegram\Reports\Topic;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;
use Tests\Support\FakeTelegram;

/**
 * «گروه گزارش‌ها» on the bot settings screen: the group connected and its topics, the link that hands the bot a group,
 * a check of the group, a test message in every topic, disconnecting — and the topic switches, one group of the bot
 * settings.
 */
final class AdminReportGroupApiTest extends HttpTestCase
{
    private const SWITCHES = ['report_purchases' => true, 'report_renewals' => true, 'report_wallet' => true, 'report_receipts' => true, 'report_users' => true, 'report_errors' => true, 'report_agency' => true, 'report_tickets' => true, 'report_reviews' => true];

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
        $this->telegram();
    }

    public function testNoGroupIsConnectedUntilTheAdminHandsTheBotOne(): void
    {
        $group = $this->group($this->get('/api/admin/bot/report-group'));

        self::assertFalse($group['connected']);
        self::assertSame([null, null, null, null, 0], [$group['chat_id'], $group['title'], $group['problem'], $group['link'], $group['waiting']]);
        self::assertNull($group['bot_username']);
        self::assertSame(['purchases', 'renewals', 'wallet', 'receipts', 'users', 'errors', 'agency', 'tickets', 'reviews'], array_column($group['topics'], 'key'));
        self::assertSame(['key' => 'purchases', 'title' => 'خریدها', 'about' => Topic::Purchases->about(), 'ready' => false, 'enabled' => true], $group['topics'][0]);
        self::assertStringContainsString('سرورهایی که پنلشان جواب نمی‌دهد', $group['topics'][5]['about'], "the servers are the main bot's news");
    }

    public function testAnAgentsGroupIsPromisedOnlyWhatItGets(): void
    {
        $this->loginAsAgent($this->agentBot());

        $topics = array_column($this->group($this->get('/api/agent/bot/report-group'))['topics'], 'about', 'key');

        self::assertSame(['purchases', 'renewals', 'wallet', 'receipts', 'users', 'errors', 'tickets', 'reviews'], array_keys($topics), 'no agency of its own; its tickets and its reviews its own');
        self::assertSame('سفارش‌های پرداخت‌شده‌ای که تحویلشان ناموفق بود، با دکمه تلاش دوباره برای مدیرهای ربات.', $topics['errors'], 'its own deliveries, not the servers');
    }

    public function testALinkNeedsTheBotsUsernameAndANewOneReplacesTheLast(): void
    {
        $refused = $this->postJson('/api/admin/bot/report-group/link');
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame([ReportGroup::NO_USERNAME], $this->decode($refused)['errors']['link']);

        $this->config(['telegram.username' => '@amo_shop_bot']);
        Carbon::setTestNow('2026-10-01 12:00:00');
        $first = $this->group($this->postJson('/api/admin/bot/report-group/link'))['link'];

        self::assertMatchesRegularExpression('~^https://t\.me/amo_shop_bot\?startgroup=reports_[0-9a-f]{32}&admin=manage_topics$~', $first['url']);
        $payload = (string) substr($first['url'], (int) strpos($first['url'], 'reports_'), 40);
        self::assertSame("/start@amo_shop_bot {$payload}", $first['command'], 'what to type into the group instead');
        self::assertSame('2026-10-01T13:00:00+00:00', $first['expires_at']);
        self::assertSame($first, $this->group($this->get('/api/admin/bot/report-group'))['link'], 'shown again until it is used or runs out');

        $second = $this->group($this->postJson('/api/admin/bot/report-group/link'))['link'];
        self::assertNotSame($first['url'], $second['url']);
        self::assertFalse($this->service(ReportGroupState::class)->matches(substr($payload, strlen(ReportGroup::PAYLOAD))), 'the first link stops working');
    }

    public function testAnAgentsShopWhoseBotIsNotHandedOverSaysWhereItsTokenGoes(): void
    {
        $bot = $this->agent()->ownBot ?? self::fail('The agent has no shop.');
        $this->openShop($bot);

        $refused = $this->postJson('/api/admin/bot/report-group/link');

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame([ReportGroup::NO_AGENT_BOT], $this->decode($refused)['errors']['link'], "not the owner's «تنظیمات پنل»: an agent's bot comes with its token, sent in the main bot");
    }

    public function testACheckReadsTheGroupAgainClearsWhatWasWrongAndMakesTheTopicsItLacks(): void
    {
        $state = $this->service(ReportGroupState::class);
        $state->connected(self::REPORT_GROUP, 'قدیمی');
        $state->setProblem(GroupProblem::Removed);
        $state->pause(600);
        $this->groupAnswers(['id' => self::REPORT_GROUP, 'type' => 'supergroup', 'title' => 'گزارش‌ها', 'is_forum' => true]);
        $thread = 40;
        $this->telegram()->on('createForumTopic', static function (array $params) use (&$thread): array {
            return ['message_thread_id' => ++$thread, 'name' => $params['name'], 'icon_color' => (int) $params['icon_color']];
        });

        $group = $this->group($this->postJson('/api/admin/bot/report-group/check'));

        self::assertNull($group['problem']);
        self::assertNull($group['paused_until'], 'what waited may go now');
        self::assertSame('گزارش‌ها', $group['title']);
        self::assertSame(array_fill(0, count(Topic::cases()), true), array_column($group['topics'], 'ready'));
        self::assertSame(count(Topic::cases()), $group['waiting'], 'each new topic says what it is for');
    }

    public function testACheckSaysWhatIsWrongWithTheGroupOrThatTelegramCouldNotBeAsked(): void
    {
        $this->service(ReportGroupState::class)->connected(self::REPORT_GROUP, 'گزارش‌ها');

        $this->telegram()->on('getChat', static fn(): mixed => FakeTelegram::error(403, 'Forbidden: bot was kicked from the supergroup chat'));
        self::assertSame(GroupProblem::Removed->message(), $this->group($this->postJson('/api/admin/bot/report-group/check'))['problem']);

        $this->groupAnswers(['id' => self::REPORT_GROUP, 'type' => 'supergroup', 'title' => 'گزارش‌ها']);
        self::assertSame(GroupProblem::ForumOff->message(), $this->group($this->postJson('/api/admin/bot/report-group/check'))['problem']);

        $this->groupAnswers(['id' => self::REPORT_GROUP, 'type' => 'supergroup', 'title' => 'گزارش‌ها', 'is_forum' => true], ['status' => 'administrator', 'can_manage_topics' => false]);
        self::assertSame(GroupProblem::NoTopicsRight->message(), $this->group($this->postJson('/api/admin/bot/report-group/check'))['problem']);

        $this->telegram()->on('getChat', static fn(): mixed => FakeTelegram::error(502, 'Bad Gateway'));
        $failed = $this->postJson('/api/admin/bot/report-group/check');
        self::assertSame(502, $failed->getStatusCode());
        self::assertSame(GroupProblem::NoTopicsRight, $this->service(ReportGroupState::class)->problem(), 'an unanswered check changes nothing');
    }

    public function testTheTestSendsAMessageToEveryWantedTopicRightAway(): void
    {
        $threads = $this->reportGroup();
        $this->putJson('/api/admin/bot/settings/reports', ['report_users' => false] + self::SWITCHES);

        $response = $this->postJson('/api/admin/bot/report-group/test');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $wanted = count(Topic::cases()) - 1;
        self::assertSame([$wanted, $wanted], [$this->decode($response)['queued'], $this->decode($response)['sent']]);
        self::assertSame(array_fill(0, $wanted, 'sendMessage'), $this->telegram()->calls());
        // In the queue's order: the receipts' and the errors' first.
        $topics = array_values(array_filter(Topic::cases(), static fn(Topic $topic): bool => $topic !== Topic::Users));
        usort($topics, static fn(Topic $a, Topic $b): int => $a->priority() <=> $b->priority());
        self::assertSame(array_map(static fn(Topic $topic): string => (string) $threads[$topic->value], $topics), array_map(fn(int $i): string => $this->telegram()->params($i)['message_thread_id'], range(0, $wanted - 1)));
        self::assertSame('✅ پیام تست: گزارش‌های «رسیدها» در همین تاپیک می‌آید.', $this->telegram()->params(0)['text']);
        self::assertSame(0, $this->decode($response)['group']['waiting']);
    }

    public function testDisconnectingForgetsTheGroupAndWhatWaitedButKeepsTheSwitches(): void
    {
        $this->reportGroup();
        $this->putJson('/api/admin/bot/settings/reports', ['report_errors' => false] + self::SWITCHES);
        $this->service(ShopReports::class)->notice(Topic::Purchases, 'در صف');

        $group = $this->group($this->deleteJson('/api/admin/bot/report-group'));

        self::assertFalse($group['connected']);
        self::assertSame(0, ReportTopic::query()->count());
        self::assertSame(0, ReportMessage::query()->count());
        self::assertFalse($this->service(ReportSettings::class)->enabled(Topic::Errors));
        self::assertSame([], $this->telegram()->calls(), 'the bot stays in the group; nothing is said there');

        self::assertSame(422, $this->postJson('/api/admin/bot/report-group/check')->getStatusCode());
        self::assertSame(422, $this->postJson('/api/admin/bot/report-group/test')->getStatusCode());
    }

    public function testTheTopicSwitchesAreOneGroupOfTheBotSettings(): void
    {
        $saved = $this->putJson('/api/admin/bot/settings/reports', ['report_users' => false] + self::SWITCHES);

        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertSame(array_replace(self::SWITCHES, ['report_users' => false]), array_intersect_key($this->decode($saved)['settings'], self::SWITCHES));
        self::assertFalse($this->service(ReportSettings::class)->enabled(Topic::Users));

        $refused = $this->unchecked()->putJson('/api/admin/bot/settings/reports', ['report_purchases' => 'maybe']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['report_purchases', 'report_renewals', 'report_wallet', 'report_receipts', 'report_users', 'report_errors', 'report_agency', 'report_tickets', 'report_reviews'], array_keys($this->decode($refused)['errors']), 'every switch must be sent');
        self::assertFalse($this->service(ReportSettings::class)->enabled(Topic::Users), 'nothing stored');
    }

    /** @return array<string, mixed> The group a 200 answered with */
    private function group(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->decode($response)['group'];
    }

    /**
     * What Telegram says of the group (getChat) and of the bot there (getChatMember).
     *
     * @param array<string, mixed> $chat
     * @param array<string, mixed> $member
     */
    private function groupAnswers(array $chat, array $member = ['status' => 'administrator', 'can_manage_topics' => true]): void
    {
        $this->telegram()->on('getChat', static fn(): array => $chat);
        $this->telegram()->on('getChatMember', static fn(): array => $member + ['user' => ['id' => FakeTelegram::BOT_ID, 'is_bot' => true, 'first_name' => 'AmoBot']]);
    }
}
