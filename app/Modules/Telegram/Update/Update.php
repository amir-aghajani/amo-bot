<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Update;

/**
 * Read-only view over a raw Telegram Update array with the accessors handlers actually need. The bot
 * subscribes to messages, callback queries and its own membership changes (config/telegram.php),
 * so those are the shapes read here.
 */
final class Update
{
    /**
     * What a message can carry, by Telegram's field names, in the order they are told apart: an animation carries a
     * `document` too, so it comes first.
     */
    private const CONTENT = ['animation', 'photo', 'video', 'video_note', 'voice', 'audio', 'sticker', 'live_photo', 'paid_media', 'document', 'poll', 'location', 'contact', 'text'];

    /** The kinds of content that are media (Bot API 10.3) — what Telegram cannot edit into a text message. */
    private const MEDIA = ['animation', 'audio', 'document', 'live_photo', 'paid_media', 'photo', 'sticker', 'video', 'video_note', 'voice'];

    /** @param array<string, mixed> $raw */
    public function __construct(public readonly array $raw) {}

    public function id(): int
    {
        return (int) ($this->raw['update_id'] ?? 0);
    }

    /** "message", "callback_query", "my_chat_member", ... */
    public function type(): string
    {
        foreach (array_keys($this->raw) as $key) {
            if ($key !== 'update_id') {
                return (string) $key;
            }
        }

        return 'unknown';
    }

    /** @return array<string, mixed>|null */
    public function message(): ?array
    {
        return $this->raw['message'] ?? $this->raw['callback_query']['message'] ?? null;
    }

    /**
     * What the message (a button's, for a callback) is, by Telegram's field name: "text", "photo", "video", "voice",
     * "sticker", "document" (a file), "poll"… — null for none of them.
     */
    public function contentKind(): ?string
    {
        $message = $this->message() ?? [];
        foreach (self::CONTENT as $kind) {
            if (isset($message[$kind])) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Whether the message (a button's, for a callback) carries media — a photo, a video, a file… — rather
     * than text: Telegram cannot edit such a message into a text one.
     */
    public function hasMedia(): bool
    {
        return in_array($this->contentKind(), self::MEDIA, true);
    }

    public function isCallback(): bool
    {
        return isset($this->raw['callback_query']);
    }

    public function isMessage(): bool
    {
        return isset($this->raw['message']);
    }

    /** @return array<string, mixed>|null */
    public function from(): ?array
    {
        return $this->raw['callback_query']['from']
            ?? $this->raw['message']['from']
            ?? $this->raw['my_chat_member']['from']
            ?? null;
    }

    public function fromId(): ?int
    {
        $id = $this->from()['id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    /** @return array<string, mixed>|null */
    public function chat(): ?array
    {
        return $this->message()['chat'] ?? $this->raw['my_chat_member']['chat'] ?? null;
    }

    public function chatId(): ?int
    {
        $id = $this->chat()['id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    /**
     * When its chat sent it (unix seconds): the message's date, a membership change's; null for a press of a button,
     * which carries none (its message's date is the message's).
     */
    public function sentAt(): ?int
    {
        $date = $this->raw['message']['date'] ?? $this->raw['my_chat_member']['date'] ?? null;

        return is_int($date) ? $date : null;
    }

    public function isPrivateChat(): bool
    {
        return ($this->chat()['type'] ?? null) === 'private';
    }

    /** A group or a supergroup — the admins' report group is one; a channel is neither. */
    public function isGroupChat(): bool
    {
        return in_array($this->chat()['type'] ?? null, ['group', 'supergroup'], true);
    }

    /** What a my_chat_member update says the bot now is in the chat ("member", "kicked" — blocked —, "administrator"…). */
    public function memberStatus(): ?string
    {
        $status = $this->raw['my_chat_member']['new_chat_member']['status'] ?? null;

        return is_string($status) && $status !== '' ? $status : null;
    }

    public function messageId(): ?int
    {
        $id = $this->message()['message_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function text(): ?string
    {
        $text = $this->raw['message']['text'] ?? null;

        return $text === null ? null : (string) $text;
    }

    public function isCommand(): bool
    {
        $text = $this->text();

        return $text !== null && str_starts_with($text, '/') && strlen($text) > 1;
    }

    /** Command name without the slash or "@botname" suffix, e.g. "start". */
    public function command(): ?string
    {
        $parts = $this->commandParts();

        return $parts === null ? null : strtolower($parts['name']);
    }

    /** The bot a command is addressed to — "amo_bot" for "/start@amo_bot x", as a group writes it — or null for none. */
    public function commandTarget(): ?string
    {
        $target = $this->commandParts()['target'] ?? '';

        return $target === '' ? null : $target;
    }

    /** What follows the command — "ref_k7m2p9qa" for "/start ref_k7m2p9qa", a deep link's parameter — or null. */
    public function commandArgument(): ?string
    {
        $argument = $this->commandParts()['argument'] ?? '';

        return $argument === '' ? null : $argument;
    }

    public function callbackData(): ?string
    {
        $data = $this->raw['callback_query']['data'] ?? null;

        return $data === null ? null : (string) $data;
    }

    /**
     * The tapped button's arguments after `$prefix` (CallbackData): "plan:12:srv:3" after "plan:" is ['12', 'srv', '3'];
     * null for a message, or a button under another prefix.
     *
     * @return list<string>|null
     */
    public function callbackArgs(string $prefix): ?array
    {
        $data = $this->callbackData();

        return $data === null ? null : CallbackData::args($data, $prefix);
    }

    public function callbackId(): ?string
    {
        $id = $this->raw['callback_query']['id'] ?? null;

        return $id === null ? null : (string) $id;
    }

    /** @return array<string, mixed>|null Attached photo (largest size) if any. */
    public function photo(): ?array
    {
        $sizes = $this->raw['message']['photo'] ?? null;

        return is_array($sizes) && $sizes !== [] ? end($sizes) : null;
    }

    /** @return array<string, mixed>|null */
    public function document(): ?array
    {
        return $this->raw['message']['document'] ?? null;
    }

    /** @return array<string, mixed>|null A shared contact card ({phone_number, user_id?, first_name, …}). */
    public function contact(): ?array
    {
        $contact = $this->raw['message']['contact'] ?? null;

        return is_array($contact) ? $contact : null;
    }

    /** Whether the shared contact is the sender's own number (the "request_contact" button), not someone from their address book. */
    public function isOwnContact(): bool
    {
        $contact = $this->contact();

        return $contact !== null && isset($contact['user_id']) && (int) $contact['user_id'] === $this->fromId();
    }

    /**
     * A command read the way Telegram marks one: "/name@target argument", the name ending at the first white space.
     *
     * @return array{name: string, target: string, argument: string}|null
     */
    private function commandParts(): ?array
    {
        if (!$this->isCommand() || preg_match('/^\/([^\s@]*)(?:@(\S*))?(?:\s+(.*))?$/su', trim((string) $this->text()), $match) !== 1) {
            return null;
        }

        return ['name' => $match[1], 'target' => $match[2] ?? '', 'argument' => trim($match[3] ?? '')];
    }
}
