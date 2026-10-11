<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Core\Config\Repository as Config;
use App\Core\Database\Lease;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\Refusal;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\Models\ReportTopic;
use Illuminate\Database\Eloquent\Builder;
use Psr\Log\LoggerInterface;

/**
 * The report group's topics, one per Topic, made by the bot (name, colour, and an icon when Telegram offers one of
 * Topic::icons()) and kept by thread id (`report_topics`). A missing topic is made when needed; making one is leased on
 * its row, so two processes never make the same topic twice, and one whose maker went quiet is taken over.
 */
final class ReportTopics
{
    /** The most Telegram calls making a topic takes: Telegram's icons asked, the topic made, made again without the icon it refused. */
    private const CALLS_TO_MAKE = 3;

    public function __construct(
        private readonly BotApi $api,
        private readonly ReportGroupState $state,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    /** Whether the connected group has this topic. */
    public function has(Topic $topic): bool
    {
        return ReportTopic::query()->where('topic', $topic->value)->whereNotNull('thread_id')->exists();
    }

    /** The topic's thread in the connected group; null while it has none. */
    public function thread(Topic $topic): ?int
    {
        $thread = ReportTopic::query()->where('topic', $topic->value)->value('thread_id');

        return $thread === null ? null : (int) $thread;
    }

    /**
     * Whether a group message was written in the topic's thread of the connected group.
     *
     * @param array<string, mixed> $message
     */
    public function holds(Topic $topic, int $chatId, array $message): bool
    {
        $thread = GroupButtons::threadOf($message);

        return $thread !== null && $chatId === $this->state->chatId() && $thread === $this->thread($topic);
    }

    /**
     * The thread a topic's reports go to, made first when missing. Null when no group is connected, or another process
     * is making the topic right now (its reports wait for the next round).
     *
     * @param array<string, string>|null $icons Telegram's topic icons, when the caller asked for them already (icons())
     * @throws TelegramApiException when Telegram refuses to make it
     */
    public function threadFor(Topic $topic, ?array $icons = null): ?int
    {
        $chatId = $this->state->chatId();
        if ($chatId === null) {
            return null;
        }

        $row = ReportTopic::query()->createOrFirst(['topic' => $topic->value]);
        if ($row->thread_id !== null) {
            return $row->thread_id;
        }

        // Held longer than making can take, so nobody takes it over meanwhile.
        $seconds = self::CALLS_TO_MAKE * BotApi::longestCall((float) $this->config->get('app.http_timeout', 30));
        $lease = Lease::take($row, $seconds, static fn(Builder $query) => $query->whereNull('thread_id'));
        if ($lease === null) {
            return null;
        }

        try {
            $thread = $this->make($chatId, $topic, $icons ?? $this->icons());
            if (!$lease->finish(['thread_id' => $thread])) {
                // The hold ran out mid-call and another process made it: theirs is kept, ours is left unused.
                $this->logger->warning('Report topic {topic} was made twice in chat {chat}; thread {thread} is left unused', ['topic' => $topic->value, 'chat' => $chatId, 'thread' => $thread]);

                return ReportTopic::query()->where('topic', $topic->value)->first()?->thread_id;
            }
        } finally {
            $lease->release();
        }

        $this->logger->info('Report topic {topic} made in chat {chat}: thread {thread}', ['topic' => $topic->value, 'chat' => $chatId, 'thread' => $thread]);

        return $thread;
    }

    /**
     * An admin deleted the topic: forget that thread (unless another process already replaced it) and make it again.
     *
     * @throws TelegramApiException
     */
    public function replaceThread(Topic $topic, int $gone): ?int
    {
        ReportTopic::query()->where('topic', $topic->value)->where('thread_id', $gone)->update(['thread_id' => null]);

        return $this->threadFor($topic);
    }

    /**
     * An admin closed the topic: open it again.
     *
     * @throws TelegramApiException
     */
    public function reopen(int $thread): void
    {
        $chatId = $this->state->chatId();
        if ($chatId !== null) {
            $this->api->reopenForumTopic($chatId, $thread);
        }
    }

    /** Forget every topic: another group is connected, or none. */
    public function forget(): void
    {
        ReportTopic::query()->delete();
    }

    /** @return list<Topic> The topics the group has, made. */
    public function made(): array
    {
        return ReportTopic::query()->whereNotNull('thread_id')->get()->map(static fn(ReportTopic $row): Topic => $row->topic)->values()->all();
    }

    /**
     * Telegram's topic icons, custom emoji id by emoji (without the variation selector); none when Telegram cannot say,
     * and the topics get their colours alone.
     *
     * @return array<string, string>
     */
    public function icons(): array
    {
        try {
            $stickers = $this->api->getForumTopicIconStickers();
        } catch (TelegramApiException $e) {
            $this->logger->info('Topic icons not available, colours only: {message}', ['message' => $e->getMessage()]);

            return [];
        }

        $icons = [];
        foreach ($stickers as $sticker) {
            $emoji = $sticker['emoji'] ?? null;
            $id = $sticker['custom_emoji_id'] ?? null;
            if (is_string($emoji) && is_string($id) && $id !== '') {
                $icons[self::bare($emoji)] ??= $id;
            }
        }

        return $icons;
    }

    /**
     * Create the topic with its title, colour and icon (when one fits); an icon Telegram refuses costs the icon only.
     *
     * @param array<string, string> $icons
     * @throws TelegramApiException
     */
    private function make(int $chatId, Topic $topic, array $icons): int
    {
        $icon = null;
        foreach ($topic->icons() as $emoji) {
            $icon ??= $icons[self::bare($emoji)] ?? null;
        }

        try {
            $created = $this->api->createForumTopic($chatId, $topic->title(), $topic->color(), $icon);
        } catch (TelegramApiException $e) {
            if ($icon === null || !$e->is(Refusal::CustomEmoji)) {
                throw $e;
            }
            $created = $this->api->createForumTopic($chatId, $topic->title(), $topic->color());
        }

        $thread = (int) ($created['message_thread_id'] ?? 0);

        return $thread > 0 ? $thread : throw new TelegramApiException('createForumTopic answered without a message_thread_id.');
    }

    /** An emoji without the variation selector (U+FE0F), which one list may carry and the other not. */
    private static function bare(string $emoji): string
    {
        return str_replace("\u{FE0F}", '', $emoji);
    }
}
