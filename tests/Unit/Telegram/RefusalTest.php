<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Modules\Telegram\Api\Refusal;
use App\Modules\Telegram\Api\TelegramApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one reading of Telegram's refusals: what each answer the shop meets is, by its code and — for a 400 — by its
 * description, and which of them are worth trying again as they are.
 */
final class RefusalTest extends TestCase
{
    #[DataProvider('answers')]
    public function testWhatTelegramsAnswerIs(int $code, string $description, Refusal $refusal): void
    {
        $e = new TelegramApiException($description, $code);

        self::assertSame($refusal, $e->refusal());
        self::assertTrue($e->is(Refusal::NoForum, $refusal), 'one of several');
        self::assertFalse($e->is(...array_filter(Refusal::cases(), static fn(Refusal $other): bool => $other !== $refusal)));
        self::assertSame(in_array($refusal, [Refusal::Unreachable, Refusal::FloodWait], true), $refusal->isTransient());
    }

    /** @return iterable<string, array{int, string, Refusal}> */
    public static function answers(): iterable
    {
        yield 'no answer' => [0, 'Telegram request sendMessage failed: cURL error 28: Operation timed out', Refusal::Unreachable];
        yield 'telegram down' => [502, 'Bad Gateway', Refusal::Unreachable];
        yield 'flood limit' => [429, 'Too Many Requests: retry after 5', Refusal::FloodWait];
        yield 'revoked token' => [401, 'Unauthorized', Refusal::TokenRejected];
        yield 'malformed token' => [404, 'Not Found', Refusal::TokenRejected];
        yield 'another poller' => [409, 'Conflict: terminated by other getUpdates request; make sure that only one bot instance is running', Refusal::Conflict];
        yield 'blocked by the customer' => [403, 'Forbidden: bot was blocked by the user', Refusal::Forbidden];
        yield 'account deleted' => [403, 'Forbidden: user is deactivated', Refusal::Forbidden];
        yield 'kicked from a group' => [403, 'Forbidden: bot was kicked from the supergroup chat', Refusal::Forbidden];
        yield 'chat never started' => [400, 'Bad Request: chat not found', Refusal::ChatGone];
        yield 'no such peer' => [400, 'Bad Request: PEER_ID_INVALID', Refusal::ChatGone];
        yield 'group upgraded' => [400, 'Bad Request: group chat was upgraded to a supergroup chat', Refusal::ChatGone];
        yield 'edit to the same' => [400, 'Bad Request: message is not modified: specified new message content and reply markup are exactly the same', Refusal::NotModified];
        yield 'topic deleted' => [400, 'Bad Request: message thread not found', Refusal::ThreadGone];
        yield 'topic id gone' => [400, 'Bad Request: TOPIC_DELETED', Refusal::ThreadGone];
        yield 'topic closed' => [400, 'Bad Request: TOPIC_CLOSED', Refusal::TopicClosed];
        yield 'premium emoji refused' => [400, 'Bad Request: DOCUMENT_INVALID', Refusal::CustomEmoji];
        yield 'emoji icon refused' => [400, 'Bad Request: invalid custom emoji identifier specified', Refusal::CustomEmoji];
        yield 'markup unreadable' => [400, "Bad Request: can't parse entities: unsupported start tag \"x\" at byte offset 0", Refusal::Unparsable];
        yield 'no right to post' => [400, 'Bad Request: not enough rights to send text messages to the chat', Refusal::NoRights];
        yield 'admin required' => [400, 'Bad Request: CHAT_ADMIN_REQUIRED', Refusal::NoRights];
        yield 'not a forum' => [400, 'Bad Request: the chat is not a forum', Refusal::NoForum];
        yield 'any other bad request' => [400, 'Bad Request: message is too long', Refusal::BadRequest];
        yield 'anything else' => [420, 'FLOOD_WAIT_X', Refusal::Other];
    }

    public function testAFloodLimitSaysHowLongToWait(): void
    {
        self::assertSame(30, (new TelegramApiException('Too Many Requests: retry after 30', 429, ['retry_after' => 30]))->retryAfter());
        self::assertNull((new TelegramApiException('Too Many Requests', 429))->retryAfter());
    }
}
