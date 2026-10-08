<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Telegram\Update\Update;
use PHPUnit\Framework\TestCase;

/**
 * An update as Telegram sends it, read: a command with its bot and argument, a tap's data and arguments (CallbackData,
 * the one format, ≤ 64 bytes), the chat and its kind, the bot's own membership, what a message carries, and whose
 * number a contact card is.
 */
final class UpdateTest extends TestCase
{
    public function testParsesCommandsWithBotSuffixAndArguments(): void
    {
        $update = new Update([
            'update_id' => 7,
            'message' => [
                'message_id' => 3,
                'text' => '/Start@amo_bot ref_ABC',
                'chat' => ['id' => 42, 'type' => 'private'],
                'from' => ['id' => 42, 'first_name' => 'Ali'],
            ],
        ]);

        self::assertSame('message', $update->type());
        self::assertTrue($update->isCommand());
        self::assertSame('start', $update->command(), 'the name alone: no bot suffix, no argument');
        self::assertSame('amo_bot', $update->commandTarget());
        self::assertSame('ref_ABC', $update->commandArgument());
        self::assertSame(42, $update->chatId());
        self::assertSame(42, $update->fromId());
        self::assertTrue($update->isPrivateChat());
        self::assertFalse($update->isGroupChat());
    }

    public function testParsesCallbackQueries(): void
    {
        $update = new Update([
            'update_id' => 8,
            'callback_query' => [
                'id' => 'cb1',
                'data' => 'plan:5:srv:2',
                'from' => ['id' => 42],
                'message' => ['message_id' => 9, 'chat' => ['id' => 42, 'type' => 'private']],
            ],
        ]);

        self::assertTrue($update->isCallback());
        self::assertFalse($update->isCommand());
        self::assertSame('plan:5:srv:2', $update->callbackData());
        self::assertSame(['5', 'srv', '2'], $update->callbackArgs('plan:'));
        self::assertNull($update->callbackArgs('pay:'), 'another prefix');
        self::assertSame('cb1', $update->callbackId());
        self::assertSame(9, $update->messageId());
        self::assertSame(42, $update->chatId());
    }

    public function testACommandEndsAtAnyWhiteSpace(): void
    {
        $update = new Update(['update_id' => 7, 'message' => ['text' => "/start\nref_ABC", 'chat' => ['id' => 42, 'type' => 'private']]]);

        self::assertSame('start', $update->command());
        self::assertNull($update->commandTarget());
        self::assertSame('ref_ABC', $update->commandArgument());
    }

    public function testPlainTextIsNotACommand(): void
    {
        $update = new Update(['update_id' => 1, 'message' => ['text' => 'hello /there', 'chat' => ['id' => 1, 'type' => 'private']]]);

        self::assertFalse($update->isCommand());
        self::assertNull($update->command());
        self::assertNull($update->commandTarget());
        self::assertNull($update->callbackArgs('plan:'), 'a message is no tap');
    }

    public function testTheBotsOwnMembershipAndTheKindOfChat(): void
    {
        $update = new Update([
            'update_id' => 9,
            'my_chat_member' => [
                'chat' => ['id' => -100, 'type' => 'supergroup'],
                'from' => ['id' => 42],
                'new_chat_member' => ['status' => 'administrator'],
            ],
        ]);

        self::assertSame('administrator', $update->memberStatus());
        self::assertTrue($update->isGroupChat());
        self::assertFalse($update->isPrivateChat());
        self::assertNull((new Update(['update_id' => 1, 'message' => ['chat' => ['id' => -5, 'type' => 'channel']]]))->memberStatus());
    }

    public function testWhatAMessageCarries(): void
    {
        $message = static fn(array $fields): Update => new Update(['update_id' => 1, 'message' => $fields + ['message_id' => 1, 'chat' => ['id' => 1, 'type' => 'private']]]);

        self::assertSame('text', $message(['text' => 'hi'])->contentKind());
        self::assertSame('photo', $message(['photo' => [['file_id' => 'p']], 'caption' => 'receipt'])->contentKind());
        self::assertSame('animation', $message(['animation' => ['file_id' => 'a'], 'document' => ['file_id' => 'a']])->contentKind(), 'a GIF is no file, though Telegram sends it as one too');
        self::assertSame('poll', $message(['poll' => ['id' => 'x']])->contentKind());
        self::assertNull($message([])->contentKind());

        self::assertTrue($message(['document' => ['file_id' => 'd']])->hasMedia());
        self::assertFalse($message(['text' => 'hi'])->hasMedia());

        $tapOn = static fn(array $fields): Update => new Update(['update_id' => 1, 'callback_query' => ['id' => 'cb', 'data' => 'x', 'from' => ['id' => 1], 'message' => $fields + ['message_id' => 1, 'chat' => ['id' => 1, 'type' => 'private']]]]);
        self::assertTrue($tapOn(['photo' => [['file_id' => 'qr']], 'caption' => 'link'])->hasMedia(), "a tap: the button's message — a QR card cannot be edited into text");
        self::assertFalse($tapOn(['text' => 'screen'])->hasMedia());
    }

    public function testOnlyTheSendersOwnNumberIsTheirContact(): void
    {
        $card = static fn(array $fields): Update => new Update(['update_id' => 1, 'message' => [
            'message_id' => 1,
            'from' => ['id' => 42],
            'chat' => ['id' => 42, 'type' => 'private'],
            'contact' => $fields + ['phone_number' => '+989120000000', 'first_name' => 'Ali'],
        ]]);

        self::assertTrue($card(['user_id' => 42])->isOwnContact(), 'Telegram stamps the card the request_contact button sends with its sender');
        self::assertSame('contact', $card(['user_id' => 42])->contentKind());
        self::assertFalse($card(['user_id' => 7])->isOwnContact(), "someone else's from the address book");
        self::assertFalse($card([])->isOwnContact(), 'a number with no account behind it');
        self::assertFalse((new Update(['update_id' => 1, 'message' => ['text' => 'hi', 'from' => ['id' => 42], 'chat' => ['id' => 42, 'type' => 'private']]]))->isOwnContact(), 'no card at all');
    }

    public function testCallbackDataIsWrittenAndReadOneWay(): void
    {
        self::assertSame('plan:12:srv:3', CallbackData::build('plan:', 12, 'srv', 3));
        self::assertSame('menu:subs:2', CallbackData::build('menu:subs', 2), 'a prefix without its colon gets one');
        self::assertSame('menu:subs', CallbackData::build('menu:subs'));
        self::assertSame(CallbackData::MAX_BYTES, strlen(CallbackData::build('plan:', str_repeat('x', CallbackData::MAX_BYTES - strlen('plan:')))), "Telegram's cap is still data");

        self::assertSame(['12', 'srv', '3'], CallbackData::args('plan:12:srv:3', 'plan:'));
        self::assertSame(['2'], CallbackData::args('menu:subs:2', 'menu:subs'));
        self::assertSame([], CallbackData::args('menu:subs', 'menu:subs'));
        self::assertNull(CallbackData::args('menu:subsX', 'menu:subs'), 'not that prefix, though it starts the same');

        $this->expectException(\LogicException::class);
        CallbackData::build('plan:', str_repeat('x', CallbackData::MAX_BYTES));
    }
}
