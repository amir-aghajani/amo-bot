<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\Limits;
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

    public function testWhileTheNoHoldsALabelsPremiumEmojiGoesPlainInItAndNoIcon(): void
    {
        $this->service(PremiumEmojiStatus::class)->record(false);
        $markup = ['inline_keyboard' => [[['text' => '<tg-emoji emoji-id="111">🔥</tg-emoji> خرید', 'callback_data' => 'buy']]]];

        $this->api()->sendMessage(42, 'منو', ['reply_markup' => $markup]);

        self::assertSame(['sendMessage'], $this->telegram()->calls(), 'plain from the start');
        self::assertSame([[['text' => '🔥 خرید', 'callback_data' => 'buy']]], $this->telegram()->markup(0)['inline_keyboard']);

        $this->service(PremiumEmojiStatus::class)->forget();
        $this->telegram()->reset();
        $this->api()->sendMessage(42, 'منو', ['reply_markup' => $markup]);
        self::assertSame([[['text' => 'خرید', 'callback_data' => 'buy', 'icon_custom_emoji_id' => '111']]], $this->telegram()->markup(0)['inline_keyboard'], 'tried again: its icon');
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

    /** A message Telegram took but dropped the premium emoji of says the bot may not; one it kept them in says it may. */
    public function testWhatTelegramKeepsOfASentMessageIsLearnedAsItChanges(): void
    {
        $status = $this->service(PremiumEmojiStatus::class);

        $this->telegram()->reply(['message_id' => 1, 'chat' => ['id' => 42, 'type' => 'private'], 'text' => '🔥 سلام']);
        $this->api()->sendMessage(42, self::PREMIUM);
        self::assertFalse($status->current()['ok'] ?? null, 'dropped silently: may not');
        self::assertTrue($status->refusedLately());

        Carbon::setTestNow(now()->addSeconds(PremiumEmojiStatus::HOLD_SECONDS + 1));
        $this->telegram()->reply(['message_id' => 2, 'chat' => ['id' => 42, 'type' => 'private'], 'text' => '🔥 سلام', 'entities' => [['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => '111']]]);
        $this->api()->sendMessage(42, self::PREMIUM);
        self::assertTrue($status->current()['ok'] ?? null, 'kept: the owner has Premium now');

        // A channel is no chat Telegram lets a bot send them to: what it keeps there says nothing of the bot.
        $this->telegram()->reply(['message_id' => 3, 'chat' => ['id' => -100, 'type' => 'channel'], 'text' => '🔥 سلام']);
        $this->api()->sendMessage(-100, self::PREMIUM);
        self::assertTrue($status->current()['ok'] ?? null);
    }

    public function testAnyRefusalOfAMessageWithPremiumEmojiSendsItPlainOnceButOnlyTheirOwnSaysTheBotMayNot(): void
    {
        $this->telegram()->fail(400, "Bad Request: can't parse entities: Character '<' is reserved");
        $this->api()->sendMessage(42, self::PREMIUM);

        self::assertSame(['sendMessage', 'sendMessage'], $this->telegram()->calls(), 'refused, then sent plain: the customer still gets it');
        self::assertSame(self::PLAIN, $this->telegram()->params(1)['text']);
        self::assertNull($this->service(PremiumEmojiStatus::class)->current(), 'nothing about the bot being allowed');

        // An edit to what the message shows already is no refusal: sent plain, it would change the message.
        $this->telegram()->reset();
        $this->telegram()->fail(400, 'Bad Request: message is not modified');
        $this->api()->editMessageText(42, 7, self::PREMIUM);
        self::assertSame(['editMessageText'], $this->telegram()->calls());
    }

    public function testNoMoreThanTelegramDrawsInOneMessageGoPremium(): void
    {
        $this->api()->sendMessage(42, str_repeat(self::PREMIUM . ' ', Limits::CUSTOM_EMOJI + 5));

        $sent = $this->telegram()->params(0)['text'];
        self::assertSame(Limits::CUSTOM_EMOJI, substr_count($sent, '<tg-emoji'));
        self::assertSame(Limits::CUSTOM_EMOJI + 5, substr_count($sent, '🔥'), 'the rest their plain emoji');
    }

    /** A picture taking a message's place carries its caption inside the media: capped, and learned from, the same. */
    public function testAnEditedPicturesCaptionIsCappedAndLearnedFromToo(): void
    {
        $status = $this->service(PremiumEmojiStatus::class);
        $this->telegram()->reply(['message_id' => 7, 'chat' => ['id' => 42, 'type' => 'private'], 'caption' => '🔥', 'caption_entities' => [['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => '111']]]);

        $this->api()->editMessageMedia(42, 7, 'JPEG-BYTES', 'card.jpg', ['caption' => str_repeat(self::PREMIUM . ' ', Limits::CUSTOM_EMOJI + 5)]);

        $caption = json_decode($this->telegram()->params(0)['media'], true)['caption'];
        self::assertSame(Limits::CUSTOM_EMOJI, substr_count($caption, '<tg-emoji'), 'no more than Telegram draws in one');
        self::assertSame(Limits::CUSTOM_EMOJI + 5, substr_count($caption, '🔥'));
        self::assertTrue($status->current()['ok'] ?? null, 'kept in the caption: the bot may');
    }

    /**
     * A message shown as typed (no parse mode) reads no tag: words that spell a premium emoji's there — a name in a
     * report sent as plain words — are no premium emoji Telegram dropped.
     */
    public function testWordsShownAsTypedSayNothingOfPremiumEmoji(): void
    {
        $this->telegram()->reply(['message_id' => 1, 'chat' => ['id' => -100, 'type' => 'supergroup'], 'text' => self::PREMIUM]);

        $this->api()->sendText(-100, self::PREMIUM);

        self::assertNull($this->service(PremiumEmojiStatus::class)->current());
    }

    /**
     * A drop says "no" — every message plain for a while — only from a new message to a private chat, where it is known
     * to mean it: an edit, a picture's caption or a group's message dropping them says nothing; keeping them, any says yes.
     */
    public function testOnlyANewMessageToAPrivateChatDroppingThemSaysTheBotMayNot(): void
    {
        // Read afresh each time: what the bot learns changes between the reads.
        $found = fn(): ?bool => $this->service(PremiumEmojiStatus::class)->current()['ok'] ?? null;

        $this->telegram()->reply(['message_id' => 1, 'chat' => ['id' => 42, 'type' => 'private'], 'text' => '🔥 سلام']);
        $this->api()->editMessageText(42, 1, self::PREMIUM);
        $this->telegram()->reply(['message_id' => 2, 'chat' => ['id' => -100, 'type' => 'supergroup'], 'text' => '🔥 سلام']);
        $this->api()->sendMessage(-100, self::PREMIUM);
        self::assertNull($found(), 'an edit and a group\'s message say nothing');

        $this->telegram()->reply(['message_id' => 3, 'chat' => ['id' => -100, 'type' => 'supergroup'], 'text' => '🔥 سلام', 'entities' => [['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => '111']]]);
        $this->api()->sendMessage(-100, self::PREMIUM);
        self::assertTrue($found(), 'kept in a group: may');

        $this->telegram()->reply(['message_id' => 4, 'chat' => ['id' => 42, 'type' => 'private'], 'text' => '🔥 سلام']);
        $this->api()->sendMessage(42, self::PREMIUM);
        self::assertFalse($found(), 'dropped from a new private message: may not');
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
