<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\Reports\GroupProblem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which of Telegram's refusals are the report group's own trouble — the screen's problem, every report held back —
 * and which are not: a topic to make or open again, one report to drop, a flood limit or an outage to wait out; and
 * what the bot's own membership there says.
 */
final class GroupProblemTest extends TestCase
{
    #[DataProvider('refusals')]
    public function testWhatARefusalSaysAboutTheGroup(int $code, string $description, ?GroupProblem $problem): void
    {
        self::assertSame($problem, GroupProblem::of(new TelegramApiException($description, $code)));
    }

    /** @return iterable<string, array{int, string, GroupProblem|null}> */
    public static function refusals(): iterable
    {
        yield 'kicked' => [403, 'Forbidden: bot was kicked from the supergroup chat', GroupProblem::Removed];
        yield 'no longer a member' => [403, 'Forbidden: bot is not a member of the supergroup chat', GroupProblem::Removed];
        yield 'group deleted' => [403, 'Forbidden: the group chat was deleted', GroupProblem::Removed];
        yield 'chat gone' => [400, 'Bad Request: chat not found', GroupProblem::Removed];
        yield 'any other 403' => [403, 'Forbidden: something new', GroupProblem::Removed];
        yield 'topics switched off' => [400, 'Bad Request: the chat is not a forum', GroupProblem::ForumOff];
        yield 'no right to make topics' => [400, 'Bad Request: not enough rights to create a topic', GroupProblem::NoTopicsRight];
        yield 'no right to send' => [400, 'Bad Request: not enough rights to send text messages to the chat', GroupProblem::NotAdmin];
        yield 'admin required' => [400, 'Bad Request: CHAT_ADMIN_REQUIRED', GroupProblem::NotAdmin];
        yield 'topic deleted' => [400, 'Bad Request: message thread not found', null];
        yield 'topic closed' => [400, 'Bad Request: TOPIC_CLOSED', null];
        yield 'one bad report' => [400, 'Bad Request: message is too long', null];
        yield 'flood limit' => [429, 'Too Many Requests: retry after 5', null];
        yield 'telegram down' => [502, 'Bad Gateway', null];
        yield 'unreachable' => [0, 'Telegram request sendMessage failed: cURL error 28: Operation timed out', null];
    }

    /** @param array<string, mixed> $member */
    #[DataProvider('memberships')]
    public function testWhatTheBotsMembershipSays(array $member, ?GroupProblem $problem): void
    {
        self::assertSame($problem, GroupProblem::ofMember($member));
    }

    /** @return iterable<string, array{array<string, mixed>, GroupProblem|null}> */
    public static function memberships(): iterable
    {
        yield 'admin who manages topics' => [['status' => 'administrator', 'can_manage_topics' => true], null];
        yield 'admin without the right' => [['status' => 'administrator', 'can_manage_topics' => false], GroupProblem::NoTopicsRight];
        yield 'admin, right not said' => [['status' => 'administrator'], GroupProblem::NoTopicsRight];
        yield 'member' => [['status' => 'member'], GroupProblem::NotAdmin];
        yield 'restricted' => [['status' => 'restricted'], GroupProblem::NotAdmin];
        yield 'left' => [['status' => 'left'], GroupProblem::Removed];
        yield 'kicked' => [['status' => 'kicked'], GroupProblem::Removed];
    }

    public function testEveryProblemIsWordedForTheScreen(): void
    {
        foreach (GroupProblem::cases() as $problem) {
            self::assertNotSame('', $problem->message());
            self::assertSame($problem, GroupProblem::from($problem->value), 'kept by its value');
        }
    }
}
