<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Api;

/**
 * What kind of "no" a Bot API call got — the one reading of Telegram's error codes and descriptions, so a caller asks
 * `$e->is(Refusal::FloodWait)` instead of matching words itself. Telegram describes a 400 only in its description, so
 * the bad requests the shop reacts to are told apart by it here and nowhere else.
 */
enum Refusal
{
    /** No usable answer: the request did not get through (no error code — a transport failure, no token) or Telegram failed itself (5xx). */
    case Unreachable;

    /** 429: too many requests — `retryAfter()` says how long to wait. */
    case FloodWait;

    /** 401 or 404: the token is no bot's (revoked in @BotFather, mistyped — a malformed one is "Not Found"). */
    case TokenRejected;

    /** 409: another program takes this bot's updates (a second poller, a webhook set elsewhere). */
    case Conflict;

    /** 403: the bot may not write there — the customer blocked it or their account is gone, the bot was taken out of the group. */
    case Forbidden;

    /** The chat is not there (any more): never started, deleted, a group upgraded to a supergroup under a new id. */
    case ChatGone;

    /** An edit to what the message shows already: nothing to change. */
    case NotModified;

    /** The forum topic is gone: an admin deleted it. */
    case ThreadGone;

    /** The forum topic is closed: an admin closed it. */
    case TopicClosed;

    /** A premium (custom) emoji the bot may not use, or does not exist: in a text, a caption or a button's icon. */
    case CustomEmoji;

    /** The text's markup is not Telegram HTML it can read. */
    case Unparsable;

    /** The bot lacks an admin right it needs there (to post, to manage topics). */
    case NoRights;

    /** The group has no topics: it is not a forum. */
    case NoForum;

    /** Any other request Telegram refused as asked. */
    case BadRequest;

    /** Anything else Telegram answered with. */
    case Other;

    /** The refusal a call got, from Telegram's error code and its description. */
    public static function of(int $code, string $description): self
    {
        return match (true) {
            $code === 0, $code >= 500 => self::Unreachable,
            $code === 429 => self::FloodWait,
            $code === 401, $code === 404 => self::TokenRejected,
            $code === 409 => self::Conflict,
            $code === 403 => self::Forbidden,
            $code === 400 => self::badRequest($description),
            default => self::Other,
        };
    }

    /** Whether the same call may go through later as it is: Telegram was out of reach, or asked for patience. */
    public function isTransient(): bool
    {
        return $this === self::Unreachable || $this === self::FloodWait;
    }

    /** A 400, by what its description says — the first that fits, the most specific first. */
    private static function badRequest(string $description): self
    {
        $says = static fn(string $pattern): bool => preg_match($pattern, $description) === 1;

        return match (true) {
            $says('/not modified/i') => self::NotModified,
            $says('/message thread not found|topic_deleted|topic_id_invalid|message_thread_invalid/i') => self::ThreadGone,
            $says('/topic_closed/i') => self::TopicClosed,
            // Before the parser's own refusal: an emoji Telegram turns down may read as markup it cannot parse.
            $says('/emoji|document_invalid/i') => self::CustomEmoji,
            $says("/can't parse entities/i") => self::Unparsable,
            $says('/chat not found|peer_id_invalid|user not found|upgraded to a supergroup/i') => self::ChatGone,
            $says('/rights|admin_required|administrator|write_forbidden|chat_restricted/i') => self::NoRights,
            $says('/forum/i') => self::NoForum,
            default => self::BadRequest,
        };
    }
}
