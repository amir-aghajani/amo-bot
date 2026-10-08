<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\Limits;
use App\Modules\Telegram\Api\Refusal;
use App\Modules\Telegram\Api\TelegramApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\NullSleeper;
use Tests\Fakes\RecordingSleeper;
use Tests\Support\FakeTelegram;

/**
 * The Bot API client on its own: how parameters and files travel, a short flood wait honoured once, Telegram's refusals
 * as typed exceptions, a transport failure that never carries the token, the courtesies that never throw, and a message
 * Telegram turns down over its premium emoji sent again with the plain ones. (Remembering that "no" is the shop's —
 * PremiumEmojiTest.)
 */
final class BotApiTest extends TestCase
{
    private FakeTelegram $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram = new FakeTelegram();
    }

    public function testEncodesNestedParametersAndBooleans(): void
    {
        $this->telegram->api()->sendMessage(42, 'hi', ['reply_markup' => ['inline_keyboard' => []], 'disable_notification' => true, 'reply_to_message_id' => null]);

        $params = $this->telegram->params(0);
        self::assertSame('{"inline_keyboard":[]}', $params['reply_markup']);
        self::assertSame('true', $params['disable_notification']);
        self::assertArrayNotHasKey('reply_to_message_id', $params);
        self::assertSame('HTML', $params['parse_mode']);
    }

    public function testPlainTextGoesWithoutAParseMode(): void
    {
        $this->telegram->api()->sendText(42, '<b>یک & دو</b>', ['message_thread_id' => 7]);

        self::assertSame(['chat_id' => '42', 'text' => '<b>یک & دو</b>', 'message_thread_id' => '7'], $this->telegram->params(0), 'shown exactly as written');
    }

    public function testAPhotoGoesMultipartWithTheFieldsEncodedTheSameWay(): void
    {
        $this->telegram->api()->sendPhoto(42, 'JPEG-BYTES', 'service.jpg', ['caption' => 'سلام', 'reply_markup' => ['inline_keyboard' => []], 'disable_notification' => false, 'reply_to_message_id' => null]);

        $request = $this->telegram->history[0]['request'];
        self::assertStringStartsWith('multipart/form-data; boundary=', $request->getHeaderLine('Content-Type'));
        $body = (string) $request->getBody();
        self::assertStringContainsString("name=\"chat_id\"\r\n\r\n42\r\n", $body);
        self::assertStringContainsString("name=\"caption\"\r\n\r\nسلام\r\n", $body);
        self::assertStringContainsString("name=\"reply_markup\"\r\n\r\n{\"inline_keyboard\":[]}\r\n", $body);
        self::assertStringContainsString("name=\"disable_notification\"\r\n\r\nfalse\r\n", $body);
        self::assertStringNotContainsString('reply_to_message_id', $body);
        self::assertMatchesRegularExpression('/name="photo"; filename="service\.jpg"\r\n(?:[^\r\n]+\r\n)*\r\nJPEG-BYTES\r\n/', $body, 'the file part carries the bytes under its filename');
        self::assertSame(['photo' => 'JPEG-BYTES'], $this->telegram->files(0));
    }

    public function testAPictureTakesAMessagesPlaceWithItsCaptionInsideTheMedia(): void
    {
        $this->telegram->api()->editMessageMedia(42, 77, 'JPEG-BYTES', 'service.jpg', ['caption' => 'سلام', 'reply_markup' => ['inline_keyboard' => []]]);

        self::assertSame(['editMessageMedia'], $this->telegram->calls());
        $params = $this->telegram->params(0);
        self::assertSame(['42', '77'], [$params['chat_id'], $params['message_id']]);
        self::assertSame(['type' => 'photo', 'media' => 'attach://photo', 'parse_mode' => 'HTML', 'caption' => 'سلام'], json_decode($params['media'], true), 'the caption rides inside the media, where the Bot API wants it');
        self::assertArrayNotHasKey('caption', $params);
        self::assertSame('{"inline_keyboard":[]}', $params['reply_markup']);
        self::assertSame(['photo' => 'JPEG-BYTES'], $this->telegram->files(0), 'uploaded under the name the media points at');
    }

    public function testRetriesOnceAfterShortFloodWait(): void
    {
        $sleeper = new RecordingSleeper();
        $this->telegram->raw(FakeTelegram::flood(1));
        $this->telegram->reply(true);

        self::assertTrue($this->telegram->api($sleeper)->answerCallbackQuery('x'));
        self::assertCount(2, $this->telegram->calls());
        self::assertSame([1], $sleeper->seconds, 'waited what Telegram asked, through the sleeper');
    }

    public function testAPhotoIsRetriedAfterAFloodWaitToo(): void
    {
        $this->telegram->raw(FakeTelegram::flood(2));
        $this->telegram->reply(['message_id' => 9]);

        self::assertSame(['message_id' => 9], $this->telegram->api()->sendPhoto(42, 'BYTES', 'a.jpg'));
        self::assertSame(['sendPhoto', 'sendPhoto'], $this->telegram->calls());
        self::assertSame('BYTES', $this->telegram->files(1)['photo'], 'the second attempt carries the file again');
    }

    public function testALongFloodWaitIsNotWaitedOut(): void
    {
        $sleeper = new RecordingSleeper();
        $this->telegram->raw(FakeTelegram::flood(60));

        try {
            $this->telegram->api($sleeper)->sendMessage(42, 'hi');
            self::fail('expected exception');
        } catch (TelegramApiException $e) {
            self::assertTrue($e->is(Refusal::FloodWait));
            self::assertSame(60, $e->retryAfter());
        }
        self::assertCount(1, $this->telegram->calls());
        self::assertSame([], $sleeper->seconds, 'the caller decides what a long wait means');
    }

    /** @param \Closure(BotApi): mixed $call */
    #[DataProvider('callsThatFailOnTheWay')]
    public function testATransportFailureIsTelegramOutOfReachAndNeverCarriesTheToken(string $failing, \Closure $call): void
    {
        $token = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw0';
        $this->telegram->on('getFile', static fn(array $params): array => ['file_id' => $params['file_id'], 'file_path' => 'photos/1.jpg']);
        // What cURL says when the connection fails names the whole address — the token in its path.
        $this->telegram->on($failing, static fn(array $params, string $asked): never => throw new ConnectException(
            "cURL error 28: Operation timed out after 10001 milliseconds for https://api.telegram.org/bot{$asked}/{$failing}",
            new Request('POST', "https://api.telegram.org/bot{$asked}/{$failing}"),
        ));

        try {
            $call($this->telegram->api()->forToken($token));
            self::fail('The call went through.');
        } catch (TelegramApiException $e) {
            self::assertSame(Refusal::Unreachable, $e->refusal());
            self::assertStringNotContainsString($token, $e->getMessage(), 'redacted where it is raised, before any log or answer sees it');
            self::assertStringNotContainsString('AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw0', $e->getMessage());
            self::assertStringContainsString('Operation timed out', $e->getMessage(), 'the reason stays');
        }
    }

    /** @return iterable<string, array{string, \Closure(BotApi): mixed}> The method that fails, and the call that meets it */
    public static function callsThatFailOnTheWay(): iterable
    {
        yield 'a call' => ['sendMessage', static fn(BotApi $api): array => $api->sendMessage(42, 'hi')];
        yield 'a file download' => ['1.jpg', static fn(BotApi $api): ?string => $api->fileBytes('f1')];
        yield 'a long poll' => ['getUpdates', static fn(BotApi $api): mixed => $api->getUpdatesAsync()->wait()];
    }

    public function testApiErrorsBecomeTypedExceptions(): void
    {
        $this->telegram->fail(403, 'Forbidden: bot was blocked by the user');

        try {
            $this->telegram->api()->sendMessage(42, 'hi');
            self::fail('expected exception');
        } catch (TelegramApiException $e) {
            self::assertSame(403, $e->errorCode);
            self::assertSame(Refusal::Forbidden, $e->refusal());
            self::assertStringContainsString('blocked', $e->getMessage());
        }
    }

    public function testAnsweringAPressIsACourtesyCutToWhatTelegramShows(): void
    {
        $api = $this->telegram->api();

        self::assertTrue($api->answerCallbackQuery('cb-1', str_repeat('ب', 250), alert: true));
        self::assertSame(Limits::POPUP, mb_strlen($this->telegram->params(0)['text']), 'cut rather than refused');
        self::assertSame('true', $this->telegram->params(0)['show_alert']);

        // Telegram counts its 200 in UTF-16 units: an emoji takes two.
        self::assertTrue($api->answerCallbackQuery('cb-3', str_repeat('✅ ', 30) . str_repeat('😀', 100)));
        self::assertSame(Limits::POPUP, Limits::length($this->telegram->params(1)['text']));

        $this->telegram->fail(400, 'Bad Request: query is too old and response timeout expired or query ID is invalid');
        self::assertFalse($api->answerCallbackQuery('cb-2', 'دیر شد'), 'too old to answer: said, never thrown');
    }

    public function testAMessageThatCannotBeDeletedSaysSo(): void
    {
        $this->telegram->fail(400, "Bad Request: message can't be deleted");

        self::assertFalse($this->telegram->api()->deleteMessage(42, 77));
        self::assertTrue($this->telegram->api()->deleteMessage(42, 78));
    }

    public function testAnEditToWhatIsThereAlreadyIsDoneAndAnyOtherRefusalStands(): void
    {
        $api = $this->telegram->api();

        $this->telegram->fail(400, 'Bad Request: message is not modified: specified new message content and reply markup are exactly the same as a current content and reply markup of the message');
        $api->editMessageText(42, 77, 'همان');
        $this->telegram->fail(400, 'Bad Request: message is not modified');
        $api->editMessageReplyMarkup(42, 77, ['inline_keyboard' => []]);

        $this->telegram->fail(400, 'Bad Request: message to edit not found');
        try {
            $api->editMessageText(42, 78, 'دیگری');
            self::fail('expected exception');
        } catch (TelegramApiException $e) {
            self::assertSame(Refusal::BadRequest, $e->refusal());
        }
        self::assertSame(['editMessageText', 'editMessageReplyMarkup', 'editMessageText'], $this->telegram->calls(), 'none sent again');
    }

    public function testAFilesBytesAreFetchedByItsId(): void
    {
        $this->telegram->reply(['file_id' => 'f1', 'file_path' => 'photos/1.jpg']);
        $this->telegram->raw(new Response(200, [], 'JPEG-BYTES'));

        self::assertSame('JPEG-BYTES', $this->telegram->api()->fileBytes('f1'));
        self::assertSame(['getFile', '1.jpg'], $this->telegram->calls());
        self::assertStringEndsWith('/file/bot' . FakeTelegram::TOKEN . '/photos/1.jpg', (string) $this->telegram->history[1]['request']->getUri());

        $this->telegram->reply(['file_id' => 'f2']);
        self::assertNull($this->telegram->api()->fileBytes('f2'), 'no download offered');
    }

    public function testAFileLargerThanAskedIsNotFetched(): void
    {
        $this->telegram->reply(['file_id' => 'f1', 'file_path' => 'videos/1.mp4', 'file_size' => BotApi::MAX_DOWNLOAD + 1]);
        self::assertNull($this->telegram->api()->fileBytes('f1'), 'past the Bot API\'s own limit');

        $this->telegram->reply(['file_id' => 'f2', 'file_path' => 'documents/2.jpg', 'file_size' => 2048]);
        self::assertNull($this->telegram->api()->fileBytes('f2', 1024), 'past the caller\'s, by the size Telegram knows');
        self::assertSame(['getFile', 'getFile'], $this->telegram->calls(), 'neither downloaded');
    }

    /**
     * @param \Closure(BotApi): mixed $send
     * @param \Closure(array<string, string>): mixed $carrier What of a call carries the premium emoji
     */
    #[DataProvider('premiumMessages')]
    public function testAMessageTelegramTurnsDownOverItsPremiumEmojiGoesAgainWithThePlainOnes(\Closure $send, \Closure $carrier, mixed $premium, mixed $plain): void
    {
        $this->telegram->fail(400, 'Bad Request: CUSTOM_EMOJI_INVALID');

        $send($this->telegram->api());

        self::assertCount(2, $this->telegram->calls(), 'turned down, then sent again');
        self::assertSame($premium, $carrier($this->telegram->params(0)));
        self::assertSame($plain, $carrier($this->telegram->params(1)), 'the same message, each plain emoji in its premium one\'s place, each button — label, colour — without its icon');
    }

    /** @return iterable<string, array{\Closure(BotApi): mixed, \Closure(array<string, string>): mixed, mixed, mixed}> */
    public static function premiumMessages(): iterable
    {
        $text = static fn(array $params): string => $params['text'];
        yield 'a text' => [static fn(BotApi $api): array => $api->sendMessage(42, '<tg-emoji emoji-id="1">👍</tg-emoji> سلام <b>علی</b>'), $text, '<tg-emoji emoji-id="1">👍</tg-emoji> سلام <b>علی</b>', '👍 سلام <b>علی</b>'];
        yield "a picture's caption" => [static fn(BotApi $api): array => $api->sendPhoto(42, 'JPEG-BYTES', 'service.jpg', ['caption' => '<tg-emoji emoji-id="2">🔥</tg-emoji> لینک']), static fn(array $params): string => $params['caption'], '<tg-emoji emoji-id="2">🔥</tg-emoji> لینک', '🔥 لینک'];
        yield "an edited picture's caption" => [
            static function (BotApi $api): void {
                $api->editMessageMedia(42, 77, 'JPEG-BYTES', 'service.jpg', ['caption' => '<tg-emoji emoji-id="2">🔥</tg-emoji> لینک']);
            },
            static fn(array $params): mixed => json_decode($params['media'], true)['caption'],
            '<tg-emoji emoji-id="2">🔥</tg-emoji> لینک',
            '🔥 لینک',
        ];

        $buttons = static fn(string $kind): \Closure => static fn(array $params): mixed => json_decode($params['reply_markup'], true)[$kind];
        yield "an inline button's icon" => [
            static fn(BotApi $api): array => $api->sendMessage(42, 'منو', ['reply_markup' => ['inline_keyboard' => [[['text' => 'خرید', 'callback_data' => 'buy', 'style' => 'success', 'icon_custom_emoji_id' => '42']]]]]),
            $buttons('inline_keyboard'),
            [[['text' => 'خرید', 'callback_data' => 'buy', 'style' => 'success', 'icon_custom_emoji_id' => '42']]],
            [[['text' => 'خرید', 'callback_data' => 'buy', 'style' => 'success']]],
        ];
        yield "a reply button's icon" => [
            static fn(BotApi $api): array => $api->sendMessage(42, 'منو', ['reply_markup' => ['keyboard' => [[['text' => 'خرید', 'style' => 'primary', 'icon_custom_emoji_id' => '42']]]]]),
            $buttons('keyboard'),
            [[['text' => 'خرید', 'style' => 'primary', 'icon_custom_emoji_id' => '42']]],
            [[['text' => 'خرید', 'style' => 'primary']]],
        ];
    }

    public function testOnlyARefusalOverPremiumEmojiIsTriedAgain(): void
    {
        $api = $this->telegram->api();

        $this->telegram->fail(400, 'Bad Request: message is too long');
        try {
            $api->sendMessage(42, '<tg-emoji emoji-id="1">👍</tg-emoji> بلند');
            self::fail('expected exception');
        } catch (TelegramApiException $e) {
            self::assertSame(Refusal::BadRequest, $e->refusal());
        }

        $this->telegram->fail(400, "Bad Request: can't use custom emoji");
        try {
            $api->sendMessage(42, 'بدون ایموجی پرمیوم');
            self::fail('expected exception');
        } catch (TelegramApiException $e) {
            self::assertSame(Refusal::CustomEmoji, $e->refusal());
        }

        self::assertSame(['sendMessage', 'sendMessage'], $this->telegram->calls(), 'neither was sent again: plain emoji would not change their fate');
    }

    public function testNonJsonBodiesAreRejected(): void
    {
        $this->telegram->raw(new Response(502, [], '<html>Bad gateway</html>'));

        try {
            $this->telegram->api()->getMe();
            self::fail('expected exception');
        } catch (TelegramApiException $e) {
            self::assertSame(Refusal::Unreachable, $e->refusal());
        }
    }

    public function testMissingTokenFailsFastForCallsAndDownloadsAlike(): void
    {
        $api = new BotApi(new Client(), static fn(): string => '', 'https://api.telegram.org', new NullSleeper());

        self::assertFalse($api->hasToken());
        self::assertNull($api->botId());
        foreach ([fn() => $api->getMe(), fn() => $api->fileBytes('f1')] as $ask) {
            try {
                $ask();
                self::fail('expected exception');
            } catch (TelegramApiException $e) {
                self::assertStringContainsString('token', $e->getMessage());
            }
        }
    }
}
