<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Emoji\PremiumEmojiStatus;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;
use Tests\Support\FakeTelegram;

/**
 * A bot may send premium emoji only while its owner has Telegram Premium. A message Telegram turns down over them goes
 * again with the plain ones, and that "no" is remembered — each bot its own, in the database, so the poller, the cron
 * and the webhook all heed it: for a while every message goes plain from the start instead of paying a refused call
 * each, then premium emoji are tried again.
 */
final class PremiumEmojiTest extends DatabaseTestCase
{
    private const PREMIUM = '<tg-emoji emoji-id="111">🔥</tg-emoji> سلام';
    private const PLAIN = '🔥 سلام';

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram();
    }

    public function testARefusalIsRememberedAndMessagesGoPlainUntilTheHoldIsOver(): void
    {
        $this->telegram()->fail(400, 'Bad Request: DOCUMENT_INVALID');
        $this->api()->sendMessage(42, self::PREMIUM);

        self::assertSame(['sendMessage', 'sendMessage'], $this->telegram()->calls(), 'turned down, then sent plain');
        self::assertSame(self::PLAIN, $this->telegram()->params(1)['text']);
        self::assertTrue($this->service(PremiumEmojiStatus::class)->refusedLately());

        $this->telegram()->reset();
        $this->api()->sendMessage(42, self::PREMIUM);
        self::assertSame(['sendMessage'], $this->telegram()->calls(), 'plain from the start: no refused call to pay for');
        self::assertSame(self::PLAIN, $this->telegram()->params(0)['text']);

        Carbon::setTestNow(now()->addSeconds(PremiumEmojiStatus::HOLD_SECONDS + 1));
        $this->telegram()->reset();
        $this->api()->sendMessage(42, self::PREMIUM);
        self::assertSame(self::PREMIUM, $this->telegram()->params(0)['text'], 'the hold is over: tried again');
    }

    public function testEachBotIsToldNoForItselfAndHeedsOnlyItsOwnNo(): void
    {
        $bot = $this->agentBot();

        // The agent's bot is turned down: its owner has no Premium.
        $this->telegram()->fail(400, 'Bad Request: DOCUMENT_INVALID');
        CurrentBot::run($bot, fn() => $this->api()->sendMessage(42, self::PREMIUM));
        self::assertSame([FakeTelegram::AGENT_TOKEN, FakeTelegram::AGENT_TOKEN], [$this->telegram()->tokenOf(0), $this->telegram()->tokenOf(1)]);
        self::assertSame(self::PLAIN, $this->telegram()->params(1)['text']);

        self::assertTrue(CurrentBot::run($bot, fn(): bool => $this->service(PremiumEmojiStatus::class)->refusedLately()), 'remembered for that bot');
        self::assertNull($this->service(PremiumEmojiStatus::class)->current(), 'the main bot was never told no');
        self::assertSame(self::PREMIUM, $this->sentBy(null), 'the main bot has an owner of its own');
        self::assertSame(self::PLAIN, $this->sentBy($bot), "and the agent's bot heeds its own no from the start");
    }

    private function api(): BotApi
    {
        return $this->service(BotApi::class);
    }

    /** What a premium message became on its way out of this bot (the main one when null). */
    private function sentBy(?Bot $bot): string
    {
        $this->telegram()->reset();
        CurrentBot::run($bot ?? Bot::MAIN, fn() => $this->api()->sendMessage(42, self::PREMIUM));
        self::assertCount(1, $this->telegram()->calls(), 'one call: no refusal paid for');

        return $this->telegram()->params(0)['text'];
    }
}
