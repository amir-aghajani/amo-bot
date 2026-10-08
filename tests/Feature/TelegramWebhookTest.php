<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Enums\BotStatus;
use App\Modules\Bots\Models\Bot;
use App\Modules\Telegram\Controllers\WebhookController;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Telegram\Update\AgentWebhookBudget;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Monolog\Handler\TestHandler;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;
use Tests\Support\FakeTelegram;

/**
 * POST /webhooks/telegram/{secret}: Telegram's pushes reach the dispatcher only with the configured
 * secret in the path (and, when Telegram sends it, in the header), and a dispatched update is
 * answered 200 so nothing is replayed. An agent's bot posts to /webhooks/telegram/bot/{id}/{secret},
 * its own secret, and is served in its own shop by its own token.
 */
final class TelegramWebhookTest extends HttpTestCase
{
    private const SECRET = 'wh-secret-1';

    /** A /start from the customer, as Telegram posts it. */
    private const START = [
        'update_id' => 1,
        'message' => ['message_id' => 1, 'text' => '/start', 'chat' => ['id' => self::TELEGRAM_ID, 'type' => 'private'], 'from' => ['id' => self::TELEGRAM_ID, 'first_name' => 'Ali']],
    ];

    private TestHandler $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.webhook_secret' => self::SECRET]);
        $this->log = $this->logs();
        $this->telegram();
    }

    public function testAValidUpdateIsDispatchedAndAnsweredOk(): void
    {
        $response = $this->send('POST', '/webhooks/telegram/' . self::SECRET, self::START, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', (string) $response->getBody());
        self::assertSame(['sendMessage'], $this->telegram()->calls(), 'the bot answered /start');
        self::assertTrue(User::query()->where('telegram_id', self::TELEGRAM_ID)->exists());
    }

    public function testAnUpdateTelegramPostsAgainIsAnsweredOkAndNotServedTwice(): void
    {
        $post = fn() => $this->send('POST', '/webhooks/telegram/' . self::SECRET, self::START, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET]);

        self::assertSame(200, $post()->getStatusCode());
        // The first answer came too late for Telegram: it posts the same update again.
        self::assertSame(200, $post()->getStatusCode(), 'answered, so Telegram stops posting it');

        self::assertSame(['sendMessage'], $this->telegram()->calls(), 'one welcome');
    }

    public function testWhatTheUpdateQueuedForTheReportGroupGoesOutWithItsAnswer(): void
    {
        $threads = $this->reportGroup();

        $response = $this->send('POST', '/webhooks/telegram/' . self::SECRET, self::START);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['sendMessage', 'sendMessage'], $this->telegram()->calls(), 'the customer answered first, then the newcomer reported');
        self::assertSame((string) self::REPORT_GROUP, $this->telegram()->params(1)['chat_id']);
        self::assertSame((string) $threads[Topic::Users->value], $this->telegram()->params(1)['message_thread_id'], 'in the users topic');
    }

    public function testTheWrongSecretInThePathIsRefusedAndLogged(): void
    {
        $response = $this->send('POST', '/webhooks/telegram/not-it', self::START);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->telegram()->calls());
        self::assertTrue($this->log->hasWarningThatContains('invalid secret'));
    }

    /** Anyone may call with a wrong secret as often as they like: the log hears of a few in a window, never all of them. */
    public function testRefusedCallsAreToldToTheLogAFewAWindow(): void
    {
        for ($i = 0; $i < WebhookController::REFUSALS_LOGGED + 5; $i++) {
            self::assertSame(403, $this->send('POST', '/webhooks/telegram/not-it-' . $i, self::START)->getStatusCode());
        }

        $told = array_filter($this->log->getRecords(), static fn($record): bool => str_contains($record->message, 'invalid secret'));
        self::assertCount(WebhookController::REFUSALS_LOGGED + 1, $told, 'a few, then once that the rest go untold');
        self::assertStringContainsString('go unlogged', end($told)->message);
    }

    public function testAHeaderThatDoesNotMatchIsRefusedEvenWithTheRightPath(): void
    {
        $response = $this->send('POST', '/webhooks/telegram/' . self::SECRET, self::START, ['X-Telegram-Bot-Api-Secret-Token' => 'forged']);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->telegram()->calls());
    }

    public function testABodyWithoutAnUpdateIsABadRequest(): void
    {
        self::assertSame(400, $this->send('POST', '/webhooks/telegram/' . self::SECRET, ['message' => ['text' => 'hi']])->getStatusCode());
        self::assertSame(400, $this->send('POST', '/webhooks/telegram/' . self::SECRET)->getStatusCode(), 'no body at all');
        self::assertSame([], $this->telegram()->calls());
    }

    public function testWithoutAConfiguredSecretTheEndpointIsClosed(): void
    {
        $this->config(['telegram.webhook_secret' => '']);

        self::assertSame(403, $this->send('POST', '/webhooks/telegram/anything', self::START)->getStatusCode());
        self::assertSame([], $this->telegram()->calls());
    }

    public function testAnAgentsBotIsServedInItsOwnShopByItsOwnToken(): void
    {
        $bot = $this->agentBot(overrides: ['webhook_secret' => 'agent-secret']);

        $response = $this->send('POST', "/webhooks/telegram/bot/{$bot->id}/agent-secret", self::START, ['X-Telegram-Bot-Api-Secret-Token' => 'agent-secret']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['sendMessage'], $this->telegram()->calls());
        self::assertSame(FakeTelegram::AGENT_TOKEN, $this->telegram()->tokenOf(0), "answered as the agent's bot");
        $customer = User::query()->withoutGlobalScope(CurrentBot::SCOPE)->where('telegram_id', self::TELEGRAM_ID)->sole();
        self::assertSame($bot->id, $customer->bot_id, "a customer of the agent's shop");
        self::assertFalse(User::query()->where('telegram_id', self::TELEGRAM_ID)->exists(), "not the main bot's");
    }

    public function testAnAgentsBotNeedsItsOwnSecretAndToRun(): void
    {
        $bot = $this->agentBot(overrides: ['webhook_secret' => 'agent-secret']);

        self::assertSame(403, $this->send('POST', "/webhooks/telegram/bot/{$bot->id}/" . self::SECRET, self::START)->getStatusCode(), "the main bot's secret is not its");
        self::assertSame(403, $this->send('POST', "/webhooks/telegram/bot/{$bot->id}/agent-secret", self::START, ['X-Telegram-Bot-Api-Secret-Token' => 'forged'])->getStatusCode());

        // Its agent's agency ended: the bot is off with it.
        $bot->agent?->forceFill(['agency_level_id' => null])->save();
        self::assertSame(BotStatus::Disabled, $bot->refresh()->status());
        self::assertSame(200, $this->send('POST', "/webhooks/telegram/bot/{$bot->id}/agent-secret", self::START)->getStatusCode(), 'a bot switched off: answered, so Telegram stops, and nothing done');
        self::assertSame([], $this->telegram()->calls());
    }

    /** The secret is asked first: a wrong one is refused alike whether there is such a bot, or it serves — the answers name no bot. */
    public function testAWrongSecretTellsNothingOfWhichBotsAreHere(): void
    {
        $serving = $this->agentBot(overrides: ['webhook_secret' => 'agent-secret']);
        $off = $this->agentBot($this->agent(overrides: ['telegram_id' => 6363]), overrides: ['webhook_secret' => 'other-secret', 'token' => '888000:AAother-agent-bot-token-for-the-tests-01', 'telegram_id' => 888000]);
        $off->agent?->forceFill(['agency_level_id' => null])->save();

        foreach (["bot/{$serving->id}", "bot/{$off->id}", 'bot/999', 'bot/1'] as $webhook) {
            self::assertSame(403, $this->send('POST', "/webhooks/telegram/{$webhook}/guessed-secret", self::START)->getStatusCode(), $webhook);
        }
        self::assertSame([], $this->telegram()->calls());
    }

    /** A webhook's refused calls are told a few a window, its own: calls at one bot's never hide those at another's. */
    public function testRefusedCallsAreCountedAWebhookAtATime(): void
    {
        $bot = $this->agentBot(overrides: ['webhook_secret' => 'agent-secret']);
        for ($i = 0; $i < WebhookController::REFUSALS_LOGGED + 5; $i++) {
            $this->send('POST', '/webhooks/telegram/bot/999/guess-' . $i, self::START);
        }

        $this->send('POST', "/webhooks/telegram/bot/{$bot->id}/guess", self::START);
        $this->send('POST', '/webhooks/telegram/guess', self::START);

        $told = array_values(array_filter($this->log->getRecords(), static fn($record): bool => str_contains($record->message, 'invalid secret')));
        self::assertCount(WebhookController::REFUSALS_LOGGED + 3, $told);
        self::assertSame(["bot #{$bot->id}", 'bot #1'], array_map(static fn($record): string => (string) preg_replace('/^Telegram webhook of (bot #\d+).*$/', '$1', $record->message), array_slice($told, -2)), 'still told: their windows are their own');
    }

    /** A private chat is its one person's: an update saying otherwise is no update of Telegram's, and nothing is done with it. */
    public function testAPrivateUpdateWhoseChatIsNotItsSendersIsLeftAlone(): void
    {
        $bot = $this->agentBot(overrides: ['webhook_secret' => 'agent-secret']);
        $forged = self::START;
        $forged['message']['chat']['id'] = 999_000;

        self::assertSame(200, $this->agentUpdate($bot, $forged)->getStatusCode(), 'acknowledged');

        self::assertSame([], $this->telegram()->calls());
        self::assertFalse(User::query()->withoutGlobalScope(CurrentBot::SCOPE)->where('telegram_id', self::TELEGRAM_ID)->exists(), 'nobody registered');
    }

    /**
     * What an agent can post to its own bot's webhook is held to its bot's budget: so many updates a window, so many from
     * someone it has never seen — past either, acknowledged and dropped, the log told once. Its customers go on being
     * served within the second, and the main bot is never held to an agent's.
     */
    public function testAnAgentsBotTakesSoManyUpdatesAWindowAndSoManyNewcomers(): void
    {
        $bot = $this->agentBot(overrides: ['webhook_secret' => 'agent-secret']);
        $budget = $this->service(AgentWebhookBudget::class);
        $known = CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => 7171]));
        $update = static fn(int $from, int $id): Update => new Update(['update_id' => $id, 'message' => ['message_id' => 1, 'text' => 'سلام', 'chat' => ['id' => $from, 'type' => 'private'], 'from' => ['id' => $from, 'first_name' => 'Ali']]]);

        // Newcomers up to the window's room…
        for ($i = 1; $i <= AgentWebhookBudget::NEWCOMERS; $i++) {
            self::assertTrue(CurrentBot::run($bot, static fn(): bool => $budget->admits($bot, $update(100_000 + $i, $i))));
        }
        // …then one more is acknowledged and dropped, while a customer the bot knows is still served.
        self::assertSame(200, $this->agentUpdate($bot, self::START)->getStatusCode());
        self::assertSame([], $this->telegram()->calls(), 'the newcomer is not registered');
        self::assertSame(200, $this->agentUpdate($bot, $update(7171, 2)->raw)->getStatusCode());
        self::assertSame(['sendMessage'], $this->telegram()->calls(), 'the customer answered');
        self::assertSame(1, $this->countWarnings('newcomers'), 'told once');

        // Updates up to the window's room, then nothing more is served — the bot's own customers neither.
        for ($i = AgentWebhookBudget::NEWCOMERS + 2; $i <= AgentWebhookBudget::UPDATES; $i++) {
            CurrentBot::run($bot, static fn(): bool => $budget->admits($bot, $update($known->telegram_id, $i)));
        }
        $this->telegram()->reset();
        self::assertSame(200, $this->agentUpdate($bot, $update(7171, 3)->raw)->getStatusCode());
        self::assertSame([], $this->telegram()->calls());
        self::assertSame(1, $this->countWarnings('updates'));

        // The main bot's webhook is Telegram's alone, never held to an agent's budget.
        self::assertSame(200, $this->send('POST', '/webhooks/telegram/' . self::SECRET, self::START)->getStatusCode());
        self::assertSame(['sendMessage'], $this->telegram()->calls());

        // A window is a while: once it is over, the bot is served again.
        Carbon::setTestNow(now()->addSeconds(AgentWebhookBudget::UPDATE_SECONDS + 1));
        $this->telegram()->reset();
        $this->agentUpdate($bot, $update(7171, 4)->raw);
        self::assertSame(['sendMessage'], $this->telegram()->calls());
    }

    /**
     * An update posted to the agent's bot's webhook, its secret right.
     *
     * @param array<string, mixed> $update
     */
    private function agentUpdate(Bot $bot, array $update): ResponseInterface
    {
        return $this->send('POST', "/webhooks/telegram/bot/{$bot->id}/agent-secret", $update, ['X-Telegram-Bot-Api-Secret-Token' => 'agent-secret']);
    }

    /** The warnings the log heard that an agent's bot was sent more `$what` than its window takes. */
    private function countWarnings(string $what): int
    {
        return count(array_filter($this->log->getRecords(), static fn($record): bool => str_contains($record->message, " {$what} in ")));
    }
}
