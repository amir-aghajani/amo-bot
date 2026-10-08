<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Session\SessionStore;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use Tests\BotTestCase;

/**
 * How a handler answers in its chat (Telegram\Context): a tap answered once; a screen edited in place of the tapped
 * message — or, where Telegram will not edit it (a picture, a message past editing), the old one replaced, so the chat
 * keeps one message —; only the buttons redrawn when a switch flips; and the customer's own message taken off, never
 * the bot's.
 */
final class ContextTest extends BotTestCase
{
    /** The message under the tests' taps, and a message of the customer's. */
    private const POST = 1234;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->customer();
    }

    public function testATapIsAnsweredOnce(): void
    {
        $ctx = $this->context($this->tap('x'));
        self::assertFalse($ctx->answered());

        $ctx->answer('first');
        $ctx->answer('second', alert: true);

        self::assertTrue($ctx->answered());
        self::assertSame(['answerCallbackQuery'], $this->calls(), 'Telegram takes one answer per tap');
        self::assertSame('first', $this->popup());
    }

    public function testAMessageHasNoTapToAnswer(): void
    {
        $ctx = $this->context($this->message('hi'));

        $ctx->answer('x');

        self::assertFalse($ctx->answered());
        self::assertSame([], $this->calls());
    }

    public function testAScreenGoesInPlaceOfTheTappedMessage(): void
    {
        $this->context($this->tap('x', message: ['message_id' => self::POST]))->edit('screen');

        self::assertSame(['editMessageText'], $this->calls());
        self::assertSame([(string) self::POST, 'screen'], [$this->params(0)['message_id'], $this->params(0)['text']]);
    }

    public function testAPictureIsReplacedByTheScreenSoTheChatKeepsOneMessage(): void
    {
        $this->context($this->tap('x', message: ['message_id' => self::POST, 'photo' => [['file_id' => 'qr']]]))->edit('screen');

        self::assertSame(['deleteMessage', 'sendMessage'], $this->calls(), 'a photo cannot be edited into text: it goes, the text comes');
        self::assertSame((string) self::POST, $this->params(0)['message_id']);
        self::assertSame('screen', $this->params(1)['text']);
    }

    public function testAMessagePastEditingGetsTheScreenAsAFreshOne(): void
    {
        $this->telegram()->fail(400, "Bad Request: message can't be edited");

        $this->context($this->tap('x'))->edit('screen');

        self::assertSame(['editMessageText', 'sendMessage'], $this->calls());
        self::assertSame('screen', $this->params(1)['text']);
    }

    public function testAnEditRefusedForAnyOtherReasonFails(): void
    {
        $this->telegram()->fail(502, 'Bad Gateway');

        $this->expectException(TelegramApiException::class);
        $this->context($this->tap('x'))->edit('screen');
    }

    public function testAMessageIsAnsweredWithAMessage(): void
    {
        $this->context($this->message('hi'))->edit('screen');

        self::assertSame(['sendMessage'], $this->calls());
    }

    public function testOnlyTheButtonsAreRedrawn(): void
    {
        $markup = ['inline_keyboard' => [[['text' => 'on', 'callback_data' => 'x']]]];

        $this->context($this->tap('x', message: ['message_id' => self::POST]))->editKeyboard($markup);
        self::assertSame(['editMessageReplyMarkup'], $this->calls());
        self::assertSame([(string) self::POST, $markup], [$this->params(0)['message_id'], $this->markup(0)]);

        $this->telegram()->reset();
        $this->context($this->message('hi'))->editKeyboard($markup);
        self::assertSame([], $this->calls(), "a customer's message carries no buttons of the bot's");
    }

    public function testTheEndOfAFlowReplacesTheTappedMessage(): void
    {
        // Telegram refuses to delete a message older than two days: the text goes all the same.
        $this->telegram()->fail(400, 'Bad Request: message to delete not found');

        $this->context($this->tap('x'))->replace('done');

        self::assertSame(['deleteMessage', 'sendMessage'], $this->calls());
        self::assertSame('done', $this->params(1)['text']);
    }

    public function testTheCustomersOwnMessageIsTakenOffNeverTheBots(): void
    {
        $this->context($this->message('123:secret-token', ['message_id' => self::POST]))->deleteIncoming();
        self::assertSame(['deleteMessage'], $this->calls());
        self::assertSame((string) self::POST, $this->params(0)['message_id']);

        $this->telegram()->reset();
        $this->context($this->tap('x'))->deleteIncoming();
        self::assertSame([], $this->calls(), "a tap's message is the bot's own");
    }

    public function testAPictureIsEditedInOrTakesTheMessagesPlace(): void
    {
        $this->context($this->tap('x'))->editPhoto('jpeg', 'qr.jpg', ['caption' => 'link']);
        self::assertSame(['editMessageMedia'], $this->calls());

        $this->telegram()->reset();
        $this->telegram()->fail(400, 'Bad Request: there is no media in the message to edit');
        $this->context($this->tap('x'))->editPhoto('jpeg', 'qr.jpg', ['caption' => 'link']);
        self::assertSame(['editMessageMedia', 'deleteMessage', 'sendPhoto'], $this->calls());
        self::assertSame(['link', 'jpeg'], [$this->params(2)['caption'], $this->telegram()->files(2)['photo']]);
    }

    private function context(Update $update): Context
    {
        return new Context($update, $this->service(BotApi::class), $this->customer, $this->service(SessionStore::class)->load(self::CHAT));
    }
}
