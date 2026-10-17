<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Modules\Subscriptions\Enums\ClientNameMode;
use App\Modules\Subscriptions\Enums\RenewalMethod;
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

    /**
     * In a group the bot is sent everything: a press, a command or a reply to the bot's message is meant for it; a reply
     * to its topic's first message — which every message in a topic replies to — or to someone else is the group's talk.
     */
    public function testWhatAGroupMeansForTheBotIsAPressACommandOrAReplyToIt(): void
    {
        $bot = 555;
        $message = static fn(array $fields): Update => new Update(['update_id' => 1, 'message' => $fields + ['message_id' => 40, 'chat' => ['id' => -100, 'type' => 'supergroup'], 'from' => ['id' => 7]]]);
        $inTopic = ['is_topic_message' => true, 'message_thread_id' => 11];
        $botsPost = ['message_id' => 30, 'from' => ['id' => $bot, 'is_bot' => true]];

        $reply = $message(['text' => 'پاسخ', 'reply_to_message' => $botsPost] + $inTopic);
        self::assertSame($botsPost, $reply->repliedToBot($bot));
        self::assertTrue($reply->isForBot($bot));
        self::assertTrue($message(['text' => '/start'])->isForBot($bot));
        self::assertTrue((new Update(['update_id' => 2, 'callback_query' => ['id' => 'cb', 'data' => 'x', 'from' => ['id' => 7], 'message' => ['message_id' => 30, 'chat' => ['id' => -100, 'type' => 'supergroup']]]]))->isForBot($bot));

        foreach ([
            'plain talk' => $message(['text' => 'سلام']),
            "the topic's first message" => $message(['text' => 'سلام', 'reply_to_message' => ['message_id' => 11, 'from' => ['id' => $bot]]] + $inTopic),
            'the topic made, as Telegram marks it' => $message(['text' => 'سلام', 'reply_to_message' => ['message_id' => 9, 'from' => ['id' => $bot], 'forum_topic_created' => ['name' => 'تیکت‌ها']]] + $inTopic),
            "someone else's" => $message(['text' => 'سلام', 'reply_to_message' => ['message_id' => 30, 'from' => ['id' => 8]]] + $inTopic),
        ] as $talk => $update) {
            self::assertNull($update->repliedToBot($bot), $talk);
            self::assertFalse($update->isForBot($bot), $talk);
        }
        self::assertFalse($reply->isForBot(null), 'without a token the bot has no messages to reply to');
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

    public function testThePictureAMessageCarriesIsAPhotoOrAFileJudgedOne(): void
    {
        $message = static fn(array $fields): Update => new Update(['update_id' => 1, 'message' => $fields + ['message_id' => 1, 'chat' => ['id' => 1, 'type' => 'private']]]);
        $isPicture = static fn(array $document): bool => ($document['mime_type'] ?? null) === 'image/png';

        self::assertSame(['file_id' => 'big', 'name' => null], $message(['photo' => [['file_id' => 'small'], ['file_id' => 'big']]])->picture($isPicture), 'the largest size');
        self::assertSame(['file_id' => 'd', 'name' => 'receipt.png'], $message(['document' => ['file_id' => 'd', 'file_name' => 'receipt.png', 'mime_type' => 'image/png']])->picture($isPicture));
        self::assertNull($message(['document' => ['file_id' => 'd', 'mime_type' => 'application/pdf']])->picture($isPicture), 'a file that is no picture');
        self::assertNull($message(['animation' => ['file_id' => 'a'], 'document' => ['file_id' => 'a', 'mime_type' => 'image/png']])->picture($isPicture), 'a GIF is an animation, not a picture');
        self::assertNull($message(['text' => 'hi'])->picture($isPicture));

        self::assertSame('receipt', $message(['photo' => [['file_id' => 'p']], 'caption' => 'receipt'])->caption());
        self::assertNull($message(['text' => 'hi'])->caption());
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

    public function testAnEnumsCaseIsItsPlaceAmongTheCases(): void
    {
        self::assertSame([0, 1, 3], [CallbackData::place(null), CallbackData::place(RenewalMethod::Extend), CallbackData::place(RenewalMethod::ResetTime)]);
        self::assertSame([ClientNameMode::Random, RenewalMethod::ResetTraffic], [CallbackData::caseAt(ClientNameMode::class, 2), CallbackData::caseAt(RenewalMethod::class, 2)]);
        self::assertSame([null, null, null], [CallbackData::caseAt(RenewalMethod::class, 0), CallbackData::caseAt(RenewalMethod::class, 4), CallbackData::caseAt(RenewalMethod::class, -1)], 'none, or no case there');

        foreach (ClientNameMode::cases() as $mode) {
            self::assertSame($mode, CallbackData::caseAt(ClientNameMode::class, CallbackData::place($mode)), 'read back as written');
        }
    }
}
