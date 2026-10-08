<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Telegram\Update\Dispatcher;
use App\Modules\Telegram\Update\Update;
use Tests\Support\FakeTelegram;

/**
 * Drives the bot the way Telegram would: hand the Dispatcher (the container's, wired from routes/bot.php
 * and talking to the fake Telegram) an update, then look at what the bot sent back. `send()` forgets the
 * previous step's calls first, so `calls()` / `params()` describe the last thing the customer did. The
 * report group's updates have builders of their own (groupTap(), groupText(), groupReply(), botMembership()).
 */
abstract class BotTestCase extends DatabaseTestCase
{
    /** The customer's chat (= Telegram user id) in these tests: the `customer()` fixture's. */
    protected const CHAT = self::TELEGRAM_ID;

    /** In the report group: a bot admin's Telegram account (make them one: `admin(['telegram_id' => …])`), and a member's who is not one. */
    protected const GROUP_ADMIN = 6060;
    protected const GROUP_MEMBER = 9090;

    /** The last update id handed out: each update a test makes is new to the bot, as each Telegram sends is. */
    private static int $updateId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Up front, so every service a test resolves — the dispatcher first of all — is already wired to the fake.
        $this->telegram();
    }

    /** The bot: the container's dispatcher, rebuilt around this test's fake Telegram by `telegram()`. */
    protected function bot(): Dispatcher
    {
        return $this->service(Dispatcher::class);
    }

    /** The customer does something: one update in, whatever the bot answers recorded fresh. */
    protected function send(Update $update): void
    {
        $this->telegram()->reset();
        $this->bot()->dispatch($update);
    }

    /** The customer does something in an agent's bot: the update served in its shop, as bot:poll or its webhook would. */
    protected function sendTo(Bot $bot, Update $update): void
    {
        CurrentBot::run($bot, fn() => $this->send($update));
    }

    /** @return list<string> Bot API methods the bot called since the last send(). */
    protected function calls(): array
    {
        return $this->telegram()->calls();
    }

    /** @return array<string, string> The parameters of the n-th call since the last send(). */
    protected function params(int $index): array
    {
        return $this->telegram()->params($index);
    }

    /** @return array<mixed> The reply markup the n-th call carried, decoded: `remove_keyboard`, `force_reply`, a keyboard… */
    protected function markup(int $call): array
    {
        return $this->telegram()->markup($call);
    }

    /** @return list<list<array<string, mixed>>> The inline buttons the n-th call put under its message, row by row. */
    protected function inlineKeyboard(int $call): array
    {
        return $this->markup($call)['inline_keyboard'] ?? [];
    }

    /** @return list<string> The callback data of the n-th call's inline buttons, row after row. */
    protected function callbacks(int $call): array
    {
        return array_values(array_filter(array_column(array_merge(...$this->inlineKeyboard($call)), 'callback_data')));
    }

    /** @return list<list<string>> The labels of the reply keyboard the n-th call showed, row by row as Telegram lays them out. */
    protected function replyKeyboard(int $call): array
    {
        $rows = $this->markup($call)['keyboard'] ?? [];

        return array_map(static fn(array $row): array => array_column($row, 'text'), $rows);
    }

    /**
     * What the bot wrote since the last send(), in order, wherever it wrote it: the text of each message it sent or
     * edited, the caption of each picture — so a test asks what the customer read, not which call carried it.
     *
     * @return list<string>
     */
    protected function said(): array
    {
        $said = [];
        foreach ($this->telegram()->history as $call) {
            $params = $call['params'];
            $said[] = match ($call['method']) {
                'sendMessage', 'editMessageText' => $params['text'] ?? '',
                'sendPhoto', 'editMessageCaption', 'copyMessage' => $params['caption'] ?? null,
                'editMessageMedia' => json_decode($params['media'] ?? '{}', true)['caption'] ?? null,
                default => null,
            };
        }

        return array_values(array_filter($said, static fn(?string $text): bool => $text !== null));
    }

    /** What the bot's answer to the tap said — a popup or a toast — since the last send(); null when it said nothing. */
    protected function popup(): ?string
    {
        $index = array_search('answerCallbackQuery', $this->calls(), true);
        $text = $index === false ? null : ($this->params($index)['text'] ?? null);

        return $text === '' ? null : $text;
    }

    /**
     * The buttons the bot last put under its message `$messageId` since the last send(), row by row — none when it took
     * them off —, or null when it left them alone (a report in the group whose buttons a press changed, say).
     *
     * @return list<list<array<string, mixed>>>|null
     */
    protected function buttonsUnder(int $messageId): ?array
    {
        $buttons = null;
        foreach ($this->telegram()->history as $call) {
            if ($call['method'] === 'editMessageReplyMarkup' && ($call['params']['message_id'] ?? null) === (string) $messageId) {
                $buttons = FakeTelegram::markupOf($call['params'])['inline_keyboard'] ?? [];
            }
        }

        return $buttons;
    }

    /**
     * A message from the customer: text, or something else (`['photo' => …]`, `['document' => …]`).
     *
     * @param array<string, mixed> $extra Other message fields
     */
    protected function message(?string $text, array $extra = [], ?int $chat = null): Update
    {
        $chat ??= static::CHAT;

        return new Update([
            'update_id' => self::nextUpdateId(),
            'message' => ($text !== null ? ['text' => $text] : []) + $extra + [
                'message_id' => random_int(1, 1_000_000),
                'chat' => ['id' => $chat, 'type' => 'private'],
                'from' => ['id' => $chat, 'first_name' => 'Ali'],
            ],
        ]);
    }

    /**
     * A tap on an inline button — of a text message, or of what `$message` makes it (`['photo' => …]`).
     *
     * @param array<string, mixed> $message Other fields of the button's message
     */
    protected function tap(string $data, ?int $chat = null, array $message = []): Update
    {
        $chat ??= static::CHAT;

        return new Update([
            'update_id' => self::nextUpdateId(),
            'callback_query' => [
                'id' => 'cb-' . random_int(1, 1_000_000),
                'data' => $data,
                'from' => ['id' => $chat, 'first_name' => 'Ali'],
                'message' => $message + ['message_id' => 77, 'chat' => ['id' => $chat, 'type' => 'private']],
            ],
        ]);
    }

    /** A shared contact card; `$userId` is whose number it is (the sender's own, or someone else's). */
    protected function contact(string $phone, int $userId): Update
    {
        return $this->message(null, ['contact' => ['phone_number' => $phone, 'first_name' => 'Ali', 'user_id' => $userId]]);
    }

    /** A press on a button under a post of the bot's in a topic of the report group — by a bot admin unless told otherwise. */
    protected function groupTap(string $data, int $post, int $thread, int $from = self::GROUP_ADMIN): Update
    {
        return new Update([
            'update_id' => self::nextUpdateId(),
            'callback_query' => [
                'id' => 'cb-' . random_int(1, 1_000_000),
                'from' => ['id' => $from, 'is_bot' => false, 'first_name' => 'Boss', 'username' => 'boss'],
                'data' => $data,
                'message' => [
                    'message_id' => $post,
                    'chat' => self::reportGroupChat(),
                    'message_thread_id' => $thread,
                    'is_topic_message' => true,
                    'date' => time(),
                ],
            ],
        ]);
    }

    /**
     * A message in a group — the report group unless told otherwise, a supergroup with topics — by a bot admin.
     *
     * @param array<string, mixed> $chat Chat fields that differ (null takes one away)
     */
    protected function groupText(string $text, array $chat = [], int $chatId = self::REPORT_GROUP): Update
    {
        return new Update([
            'update_id' => self::nextUpdateId(),
            'message' => [
                'message_id' => 9,
                'from' => ['id' => self::GROUP_ADMIN, 'first_name' => 'Boss'],
                'chat' => array_filter($chat + ['id' => $chatId] + self::reportGroupChat(), static fn(mixed $value): bool => $value !== null),
                'text' => $text,
            ],
        ]);
    }

    /**
     * A message in a topic of the report group answering a post (`$repliedPost`, of the bot's unless told otherwise)
     * that Telegram hands back with its text, `$repliedText`.
     *
     * @param array<string, mixed> $extra Other fields of the message
     */
    protected function groupReply(string $repliedText, ?string $text, int $thread, int $repliedPost, int $from = self::GROUP_ADMIN, array $extra = [], int $repliedFrom = FakeTelegram::BOT_ID): Update
    {
        return new Update([
            'update_id' => self::nextUpdateId(),
            'message' => ($text !== null ? ['text' => $text] : []) + $extra + [
                'message_id' => 77,
                'from' => ['id' => $from, 'is_bot' => false, 'first_name' => 'Boss'],
                'chat' => self::reportGroupChat(),
                'message_thread_id' => $thread,
                'is_topic_message' => true,
                'reply_to_message' => [
                    'message_id' => $repliedPost,
                    'from' => ['id' => $repliedFrom, 'is_bot' => $repliedFrom === FakeTelegram::BOT_ID, 'first_name' => 'AmoBot'],
                    'chat' => self::reportGroupChat(),
                    'text' => $repliedText,
                ],
            ],
        ]);
    }

    /**
     * The bot's own membership in a group changed.
     *
     * @param array<string, mixed> $member The bot's new ChatMember
     */
    protected function botMembership(array $member, int $chatId = self::REPORT_GROUP): Update
    {
        $bot = ['id' => FakeTelegram::BOT_ID, 'is_bot' => true, 'first_name' => 'AmoBot'];

        return new Update([
            'update_id' => self::nextUpdateId(),
            'my_chat_member' => [
                'chat' => ['id' => $chatId] + self::reportGroupChat(),
                'from' => ['id' => self::GROUP_ADMIN, 'first_name' => 'Boss'],
                'date' => time(),
                'old_chat_member' => ['status' => 'administrator', 'user' => $bot],
                'new_chat_member' => $member + ['user' => $bot],
            ],
        ]);
    }

    /** @return array{id: int, type: string, title: string, is_forum: bool} The report group as Telegram describes it. */
    private static function reportGroupChat(): array
    {
        return ['id' => self::REPORT_GROUP, 'type' => 'supergroup', 'title' => 'گزارش فروشگاه', 'is_forum' => true];
    }

    /** An update id no update of this process had: the bot serves each id once (a redelivery is not served again). */
    private static function nextUpdateId(): int
    {
        return ++self::$updateId;
    }
}
