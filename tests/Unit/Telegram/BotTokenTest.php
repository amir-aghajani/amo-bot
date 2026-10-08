<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Modules\Telegram\Api\BotToken;
use App\Modules\Telegram\Api\BotTokenException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeTelegram;

/**
 * The one rule for a bot's token, wherever one is handed over — the settings screen, the installer, an agent in the
 * main bot: its shape first, then Telegram asked whose it is, each failure in the same words.
 */
final class BotTokenTest extends TestCase
{
    private const TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw0';

    public function testItsShapeAndTheBotItNames(): void
    {
        self::assertTrue(BotToken::isWellFormed(self::TOKEN));
        foreach (['', '123456789', '123456789:short', 'abc:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw0', self::TOKEN . ' extra', 'bot' . self::TOKEN] as $token) {
            self::assertFalse(BotToken::isWellFormed($token), $token);
        }

        self::assertSame(123456789, BotToken::botId(self::TOKEN));
        self::assertNull(BotToken::botId('no-colon'));
        self::assertNull(BotToken::botId('abc:secret'));
    }

    public function testTelegramSaysWhoseItIs(): void
    {
        $telegram = new FakeTelegram();
        $telegram->reply(['id' => 123456789, 'is_bot' => true, 'first_name' => '  فروشگاه  ', 'username' => 'shop_bot']);

        self::assertSame(['id' => 123456789, 'username' => 'shop_bot', 'name' => 'فروشگاه'], BotToken::identify($telegram->api(), ' ' . self::TOKEN . "\n"));
        self::assertSame(['getMe'], $telegram->calls());
        self::assertSame(self::TOKEN, $telegram->tokenOf(0), 'asked as the bot the token is, not as the shop');
    }

    public function testEachFailureHasItsWords(): void
    {
        $telegram = new FakeTelegram();

        self::assertSame(BotToken::MALFORMED, $this->failure(static fn() => BotToken::identify($telegram->api(), 'not a token')));
        self::assertSame([], $telegram->calls(), 'Telegram is not asked about what is no token');

        $telegram->fail(401, 'Unauthorized');
        self::assertSame(BotToken::REFUSED, $this->failure(static fn() => BotToken::identify($telegram->api(), self::TOKEN)));

        $telegram->raw(new Response(502, [], '<html>Bad Gateway</html>'));
        self::assertSame(BotToken::UNREACHABLE, $this->failure(static fn() => BotToken::identify($telegram->api(), self::TOKEN)));

        $telegram->on('getMe', static fn(array $params, string $token): never => throw new ConnectException('cURL error 6: Could not resolve host: api.telegram.org', new Request('POST', "https://api.telegram.org/bot{$token}/getMe")));
        self::assertSame(BotToken::UNREACHABLE, $this->failure(static fn() => BotToken::identify($telegram->api(), self::TOKEN)), 'no network says the same');
    }

    private function failure(\Closure $identify): string
    {
        try {
            $identify();
        } catch (BotTokenException $e) {
            return $e->getMessage();
        }

        self::fail('The token was taken.');
    }
}
