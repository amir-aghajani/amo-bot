<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\Models\Bot;
use App\Modules\Telegram\Api\BotToken;
use App\Modules\Telegram\Exceptions\WebhookAddressException;
use App\Modules\Telegram\Services\BotHealth;
use App\Modules\Telegram\Services\BotWebhooks;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;
use Tests\Support\FakeTelegram;

/**
 * The owner puts every bot the shop runs on webhooks, or takes them off — from the panel, since a shared host has no
 * shell for bot:webhook:set: the work of the commands, at APP_URL, which Telegram calls only over HTTPS. One bot
 * Telegram refuses keeps it from none of the others, and the answer says what happened to each in the owner's words —
 * never a token, nor the secret a webhook's address ends with.
 */
final class AdminWebhooksApiTest extends HttpTestCase
{
    private Bot $agentBot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram();
        $this->config(['app.url' => 'https://shop.example', 'telegram.webhook_secret' => 'main-webhook-secret']);
        $this->agentBot = $this->agentBot();
        $this->loginAsAdmin();
    }

    public function testEveryBotIsPutOnItsWebhookAndTheSystemSaysSo(): void
    {
        $response = $this->postJson('/api/admin/system/webhook');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([
            ['bot' => ['id' => Bot::MAIN, 'username' => null], 'done' => true, 'message' => BotWebhooks::ENABLED],
            ['bot' => ['id' => $this->agentBot->id, 'username' => 'agent_shop_bot'], 'done' => true, 'message' => BotWebhooks::ENABLED],
        ], $this->decode($response)['bots']);
        self::assertSame([FakeTelegram::TOKEN, FakeTelegram::AGENT_TOKEN], $this->tokensOf('setWebhook'), 'the main bot first, each as itself');
        self::assertSame('https://shop.example/webhooks/telegram/main-webhook-secret', $this->telegram()->params(0)['url']);
        self::assertSame('webhook', $this->decode($this->get('/api/admin/system'))['system']['bot']['mode']);
        $this->assertNoSecretIn($response);
    }

    public function testABotTelegramRefusesKeepsItFromNoneOfTheOthersAndEachIsToldWhy(): void
    {
        $this->telegram()->on('setWebhook', static fn(array $params, string $token): mixed => $token === FakeTelegram::AGENT_TOKEN
            ? FakeTelegram::error(401, 'Unauthorized')
            : FakeTelegram::error(400, 'Bad Request: bad webhook: Failed to resolve host https://shop.example/webhooks/telegram/main-webhook-secret'));

        $response = $this->postJson('/api/admin/system/webhook');

        self::assertSame(200, $response->getStatusCode());
        [$main, $agent] = $this->decode($response)['bots'];
        self::assertFalse($main['done']);
        self::assertStringStartsWith('تلگرام نپذیرفت: Bad Request: bad webhook', $main['message'], "Telegram's own diagnosis for the owner");
        self::assertSame([false, BotHealth::TOKEN_REFUSED], [$agent['done'], $agent['message']], 'the reason the agent reads too');
        self::assertSame(BotHealth::TOKEN_REFUSED, $this->agentBot->refresh()->problem, "kept on the agent's bot");
        $this->assertNoSecretIn($response);
    }

    public function testAMainTokenRefusedOrTelegramOutOfReachIsWordedAsTheSettingsWordIt(): void
    {
        $this->telegram()->on('setWebhook', static fn(array $params, string $token): mixed => $token === FakeTelegram::TOKEN ? FakeTelegram::error(401, 'Unauthorized') : FakeTelegram::error(502, 'Bad Gateway'));

        [$main, $agent] = $this->decode($this->postJson('/api/admin/system/webhook'))['bots'];

        self::assertSame([false, BotToken::REFUSED], [$main['done'], $main['message']], "the main bot's token is the owner's to replace");
        self::assertSame([false, BotToken::UNREACHABLE], [$agent['done'], $agent['message']]);
        self::assertNull($this->agentBot->refresh()->problem, 'a moment of Telegram is not the bot\'s trouble');
    }

    public function testAnAddressTelegramDoesNotCallIsRefusedAndNoBotIsTouched(): void
    {
        $this->config(['app.url' => 'http://shop.example']);

        $response = $this->postJson('/api/admin/system/webhook');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame((new WebhookAddressException())->getMessage(), $this->decode($response)['message']);
        self::assertSame([], $this->telegram()->calls());
    }

    public function testEveryBotIsTakenOffItsWebhook(): void
    {
        $this->postJson('/api/admin/system/webhook');
        $this->telegram()->reset();

        $response = $this->deleteJson('/api/admin/system/webhook');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([true, true], array_column($this->decode($response)['bots'], 'done'));
        self::assertSame([BotWebhooks::DISABLED, BotWebhooks::DISABLED], array_column($this->decode($response)['bots'], 'message'));
        self::assertSame([FakeTelegram::TOKEN, FakeTelegram::AGENT_TOKEN], $this->tokensOf('deleteWebhook'));
        self::assertNotSame('webhook', $this->decode($this->get('/api/admin/system'))['system']['bot']['mode']);
    }

    public function testWithoutABotToRunThereIsNothingToSwitch(): void
    {
        $this->config(['telegram.token' => '']);
        $this->agentBot->forceFill(['token' => null])->save();

        $response = $this->postJson('/api/admin/system/webhook');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->decode($response)['bots']);
        self::assertSame([], $this->telegram()->calls());
    }

    /** @return list<string> The tokens `$method` was called with, in order. */
    private function tokensOf(string $method): array
    {
        $tokens = [];
        foreach ($this->telegram()->calls() as $i => $called) {
            if ($called === $method) {
                $tokens[] = $this->telegram()->tokenOf($i);
            }
        }

        return $tokens;
    }

    private function assertNoSecretIn(ResponseInterface $response): void
    {
        $body = (string) $response->getBody();
        foreach ([FakeTelegram::TOKEN, FakeTelegram::AGENT_TOKEN, 'main-webhook-secret', (string) $this->agentBot->refresh()->webhook_secret] as $secret) {
            self::assertTrue($secret === '' || !str_contains($body, $secret), 'the answer holds no token and no webhook secret');
        }
    }
}
