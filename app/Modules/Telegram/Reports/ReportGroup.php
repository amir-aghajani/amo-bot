<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Core\Exceptions\ValidationException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Services\Bots;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\Limits;
use App\Modules\Telegram\Api\Refusal;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\Api\TelegramUnreachableException;
use App\Modules\Telegram\Models\ReportMessage;
use Psr\Log\LoggerInterface;

/**
 * The admins' report group: a Telegram supergroup with topics (a "forum") the admin made, which the bot is handed by a
 * link from the panel — `t.me/<bot>?startgroup=reports_<code>&admin=manage_topics`, Telegram's way to add a bot to a
 * group as an admin with the rights it asks for; the bot then gets `/start@<bot> reports_<code>` in that group. With a
 * good code, a group that has topics and the "manage topics" right, it becomes the report group and gets a topic per
 * subject (ReportTopics), each saying what it is for. What goes wrong with the group itself — the bot removed, demoted,
 * topics switched off — is the problem the screen shows (GroupProblem), learned from a check, the bot's own membership
 * updates or a refused send; a flood limit or an unreachable Telegram holds the reports back for a while (hold()).
 */
final class ReportGroup
{
    /** What a connect link carries after /start: `reports_<code>`. */
    public const PAYLOAD = 'reports_';

    /** The admin right the link asks Telegram to give the bot — the one it needs to make topics. */
    public const RIGHTS = 'manage_topics';

    /** The main bot's @username not known yet: config.php's, which the owner's «تنظیمات پنل» asks Telegram for. */
    public const NO_USERNAME = 'نام کاربری ربات هنوز مشخص نیست: در «تنظیمات پنل»، بخش «ربات تلگرام»، «بررسی توکن» را بزنید و ذخیره کنید (یا Webhook را ثبت کنید) تا نامش از تلگرام گرفته شود.';

    /** An agent's bot not handed over yet: its @username comes with its token, sent in the main bot — no panel's setting. */
    public const NO_AGENT_BOT = 'ربات این فروشگاه هنوز وصل نشده است: توکنش در ربات اصلی از «نمایندگی» ← «ربات من» فرستاده می‌شود؛ بعد لینک اتصال ساخته می‌شود.';

    public const NOT_CONNECTED = 'گروه گزارش‌ها وصل نیست.';

    /** A topic's first message: what it is for. */
    private const INTRO = "📌 <b>%s</b>\n%s";

    /** The screen's test, one per topic. */
    private const TEST = '✅ پیام تست: گزارش‌های «%s» در همین تاپیک می‌آید.';

    /** Seconds sending waits: a flood limit that did not say for how long, an unreachable Telegram, the group's own trouble. */
    private const FLOOD_PAUSE = 5;
    private const OFFLINE_PAUSE = 60;
    private const PROBLEM_PAUSE = 600;

    public function __construct(
        private readonly BotApi $api,
        private readonly ReportGroupState $state,
        private readonly ReportSettings $settings,
        private readonly ReportTopics $topics,
        private readonly ShopReports $reports,
        private readonly Bots $bots,
        private readonly LoggerInterface $logger,
    ) {}

    /** Whether a group is connected. */
    public function connected(): bool
    {
        return $this->state->chatId() !== null;
    }

    /**
     * A fresh connect link (any older one stops working).
     *
     * @throws ValidationException while the bot's @username is not known — worded for the shop: the main bot's comes from
     *                             the owner's settings, an agent's with its token
     */
    public function newLink(): void
    {
        if ($this->bots->username() === '') {
            throw ValidationException::on('link', CurrentBot::isMain() ? self::NO_USERNAME : self::NO_AGENT_BOT);
        }

        $this->state->issueCode();
    }

    /**
     * The connect link outstanding: the startgroup link, the command that does the same typed into the group, and when
     * they stop working — or null when there is none (or the bot's @username is not known).
     *
     * @return array{url: string, command: string, expires_at: string}|null
     */
    public function link(): ?array
    {
        $code = $this->state->code();
        $bot = $this->bots->username();
        if ($code === null || $bot === '') {
            return null;
        }

        $payload = self::PAYLOAD . $code['code'];

        return [
            'url' => "https://t.me/{$bot}?startgroup={$payload}&admin=" . self::RIGHTS,
            'command' => "/start@{$bot} {$payload}",
            'expires_at' => $code['expires_at']->toIso8601String(),
        ];
    }

    /**
     * Why this chat cannot be the report group, or null when it can: it must be a supergroup with topics, where the bot
     * is an admin with the "manage topics" right.
     *
     * @param array<string, mixed> $chat The Chat of the message that carried the code
     */
    public function refusal(array $chat): ?GroupProblem
    {
        if (($chat['type'] ?? null) !== 'supergroup' || ($chat['is_forum'] ?? false) !== true) {
            return GroupProblem::ForumOff;
        }

        try {
            return $this->memberProblem((int) $chat['id']);
        } catch (TelegramApiException $e) {
            $this->logger->warning('The bot could not read its rights in group {chat}: {message}', ['chat' => $chat['id'], 'message' => $e->getMessage()]);

            return GroupProblem::CheckFailed;
        }
    }

    /**
     * The chat is the report group from now on: kept, the topics of any group before it forgotten, and every topic it
     * does not have yet made, each with a first message saying what it is for. Returns the topics that could not be made
     * now (a flood limit, a lost right) — each is made with its first report.
     *
     * @return list<Topic>
     */
    public function adopt(int $chatId, string $title): array
    {
        if ($this->state->chatId() !== $chatId) {
            $this->topics->forget();
        }
        $this->state->connected($chatId, self::title($title));
        $this->logger->info('Report group connected: chat {chat} ({title})', ['chat' => $chatId, 'title' => $title]);

        return $this->makeTopics();
    }

    /**
     * Look the group up again for the screen: its title, whether it still has topics and the bot its rights — the
     * problem is set or cleared by what is found — and, when all is well, the topics it lacks are made.
     *
     * @throws ValidationException on `group` while none is connected
     * @throws TelegramUnreachableException when Telegram could not be asked: nothing changes
     */
    public function check(): void
    {
        $chatId = $this->state->chatId() ?? throw ValidationException::on('group', self::NOT_CONNECTED);

        try {
            $chat = $this->api->getChat($chatId);
            $problem = ($chat['is_forum'] ?? false) !== true ? GroupProblem::ForumOff : $this->memberProblem($chatId);
        } catch (TelegramApiException $e) {
            $problem = GroupProblem::of($e) ?? throw new TelegramUnreachableException($e);
            $chat = [];
        }

        $title = self::title((string) ($chat['title'] ?? ''));
        if ($title !== '') {
            $this->state->retitle($title);
        }
        $this->state->setProblem($problem);
        if ($problem === null) {
            $this->state->resume();
            $this->makeTopics();
        }
    }

    /** Forget the group: its topics and the reports still waiting to go there. The bot stays in it. */
    public function disconnect(): void
    {
        $chatId = $this->state->chatId();
        $this->state->forget();
        $this->topics->forget();
        ReportMessage::waiting()->delete();
        $this->logger->info('Report group disconnected: chat {chat}', ['chat' => $chatId]);
    }

    /**
     * The screen's test: a message in every topic the admin wants. Returns how many were queued.
     *
     * @throws ValidationException on `group` while none is connected
     */
    public function test(): int
    {
        if (!$this->connected()) {
            throw ValidationException::on('group', self::NOT_CONNECTED);
        }

        $queued = 0;
        foreach (ReportSettings::topics() as $topic) {
            if ($this->settings->enabled($topic)) {
                $this->reports->notice($topic, sprintf(self::TEST, $topic->title()));
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * The bot's membership in a group changed (my_chat_member): in the report group, that is whether reports can reach it
     * — removed, demoted or without the "manage topics" right is the problem the screen shows; an admin with it again
     * clears the problem and whatever waited goes out.
     *
     * @param array<string, mixed> $change The ChatMemberUpdated
     */
    public function membershipChanged(array $change): void
    {
        if ((int) ($change['chat']['id'] ?? 0) !== $this->state->chatId()) {
            return;
        }

        $problem = GroupProblem::ofMember((array) ($change['new_chat_member'] ?? []));
        $this->state->setProblem($problem);
        if ($problem === null) {
            $this->state->resume();
        }
    }

    /** The group got a new title (a new_chat_title service message). */
    public function renamed(int $chatId, string $title): void
    {
        $title = self::title($title);
        if ($chatId === $this->state->chatId() && $title !== '') {
            $this->state->retitle($title);
        }
    }

    /**
     * What a refusal means for sending to the group: Telegram's flood limit, an unreachable Telegram or the group's own
     * trouble holds every report back for a while — the trouble is the screen's problem — and true says it did; a
     * refusal of one report alone holds nothing.
     */
    public function hold(TelegramApiException $e): bool
    {
        $problem = GroupProblem::of($e);
        $seconds = match (true) {
            $e->is(Refusal::FloodWait) => $e->retryAfter() ?? self::FLOOD_PAUSE,
            $e->is(Refusal::Unreachable) => self::OFFLINE_PAUSE,
            $problem !== null => self::PROBLEM_PAUSE,
            default => null,
        };
        if ($seconds === null) {
            return false;
        }

        if ($problem !== null) {
            $this->state->setProblem($problem);
        }
        $this->state->pause($seconds);
        $this->logger->warning('Reports wait {seconds}s: {message}', ['seconds' => $seconds, 'message' => $e->getMessage()]);

        return true;
    }

    /** @return array<string, mixed> What the screen shows */
    public function present(): array
    {
        $chatId = $this->state->chatId();
        $made = $chatId === null ? [] : $this->topics->made();
        $attempt = $this->state->attempt();
        $bot = $this->bots->username();

        return [
            'connected' => $chatId !== null,
            'chat_id' => $chatId,
            'title' => $this->state->title(),
            'connected_at' => $this->state->connectedAt()?->toIso8601String(),
            'problem' => $this->state->problem()?->message(),
            'paused_until' => $this->state->pausedUntil()?->toIso8601String(),
            'waiting' => $chatId === null ? 0 : ReportMessage::waiting()->count(),
            'topics' => array_map(fn(Topic $topic): array => [
                'key' => $topic->value,
                'title' => $topic->title(),
                'about' => $topic->about(),
                'ready' => in_array($topic, $made, true),
                'enabled' => $this->settings->enabled($topic),
            ], ReportSettings::topics()),
            'link' => $this->link(),
            'attempt' => $attempt === null ? null : ['title' => $attempt['title'], 'message' => $attempt['problem']->message(), 'at' => $attempt['at']->toIso8601String()],
            'bot_username' => $bot === '' ? null : $bot,
        ];
    }

    /**
     * The bot's own rights in the chat, asked of Telegram.
     *
     * @throws TelegramApiException when the answer says nothing about the group (Telegram unreachable…)
     */
    private function memberProblem(int $chatId): ?GroupProblem
    {
        $botId = $this->api->botId() ?? throw new TelegramApiException('Telegram bot token is not configured.');

        try {
            return GroupProblem::ofMember($this->api->getChatMember($chatId, $botId));
        } catch (TelegramApiException $e) {
            return GroupProblem::of($e) ?? throw $e;
        }
    }

    /**
     * Make every topic the group lacks, each with its first message — Telegram's icons asked once. Stops at a refusal
     * that would refuse the rest too (a flood limit, the group's own trouble, an unreachable Telegram — held, hold()) and
     * returns what is still missing.
     *
     * @return list<Topic>
     */
    private function makeTopics(): array
    {
        $lacking = array_values(array_filter(ReportSettings::topics(), fn(Topic $topic): bool => !$this->topics->has($topic)));
        if ($lacking === []) {
            return [];
        }

        $icons = $this->topics->icons();
        $missing = [];
        foreach ($lacking as $i => $topic) {
            try {
                $thread = $this->topics->threadFor($topic, $icons);
            } catch (TelegramApiException $e) {
                $this->logger->warning('Report topic {topic} was not made: {message}', ['topic' => $topic->value, 'message' => $e->getMessage()]);
                $this->hold($e);

                return [...$missing, ...array_slice($lacking, $i)];
            }

            if ($thread === null) {
                // Someone else is making it right now: theirs to introduce.
                $missing[] = $topic;
            } else {
                $this->reports->notice($topic, sprintf(self::INTRO, $topic->title(), $topic->about()));
            }
        }

        return $missing;
    }

    /** A group's title as kept. */
    private static function title(string $title): string
    {
        return mb_substr(trim($title), 0, Limits::TITLE);
    }
}
