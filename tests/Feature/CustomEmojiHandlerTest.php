<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Emoji\CustomEmojis;
use App\Modules\Telegram\Emoji\PremiumEmojiStatus;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Models\CustomEmoji;
use App\Modules\Telegram\Session\SessionStore;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;

/**
 * /emoji: an admin shows the bot a message with premium emoji — composed in Telegram as a customer should see it — and
 * the bot keeps its premium emoji for the panel's picker, sends it back written by itself (whether its premium emoji
 * survive is whether the bot may use them, and is remembered), and answers with it as a template for a bot text.
 */
final class CustomEmojiHandlerTest extends BotTestCase
{
    /** The plain emoji Telegram says each premium one stands for. */
    private const STICKER_EMOJI = ['111' => '🔥', '222' => '👨‍💻', '333' => '🎁'];

    /** Whether Telegram keeps the premium emoji of the bot's own messages (its owner has Premium). */
    private bool $premiumOwner = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin();
        $this->telegram()->on('getCustomEmojiStickers', static fn(array $params): array => array_map(
            static fn(string $id): array => ['file_id' => "sticker-{$id}", 'type' => 'custom_emoji', 'custom_emoji_id' => $id, 'emoji' => self::STICKER_EMOJI[$id] ?? '⭐', 'is_animated' => true, 'is_video' => false, 'thumbnail' => ['file_id' => "thumb-{$id}"]],
            json_decode($params['custom_emoji_ids'], true),
        ));
        // A message the bot sends comes back with its premium emoji only while Telegram lets the bot use them.
        $this->telegram()->on('sendMessage', fn(array $params): array => [
            'message_id' => random_int(1000, 9999),
            'chat' => ['id' => (int) $params['chat_id'], 'type' => 'private'],
            'entities' => $this->premiumOwner && str_contains($params['text'], '<tg-emoji') ? [['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => '111']] : [],
        ]);
    }

    public function testTheAdminsMessageIsKeptSentBackAndGivenAsATemplate(): void
    {
        $this->send($this->message('/emoji'));
        self::assertSame(['sendMessage'], $this->calls());
        self::assertSame(Messages::EMOJI_ASK, $this->params(0)['text']);

        $this->send($this->message('🔥 خرید %client%', ['entities' => [
            ['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => '111'],
            ['type' => 'bold', 'offset' => 3, 'length' => 4],
        ]]));

        self::assertSame(['getCustomEmojiStickers', 'sendMessage', 'sendMessage'], $this->calls());
        self::assertSame('<tg-emoji emoji-id="111">🔥</tg-emoji> <b>خرید</b> %client%', $this->params(1)['text'], 'the message, written by the bot');
        $summary = $this->params(2)['text'];
        self::assertStringStartsWith('✅ ۱ ایموجی پرمیوم ذخیره شد (۱ تازه)', $summary);
        self::assertStringContainsString(Messages::EMOJI_WORKS, $summary);
        self::assertStringEndsWith('<pre>&lt;tg-emoji emoji-id="111"&gt;🔥&lt;/tg-emoji&gt; &lt;b&gt;خرید&lt;/b&gt; %client%</pre>', $summary, 'the template, a tap copies it');

        $kept = CustomEmoji::query()->sole();
        self::assertSame(['111', '🔥', 'thumb-111'], [$kept->emoji_id, $kept->emoji, $kept->file_id]);
        $status = $this->service(PremiumEmojiStatus::class)->current();
        self::assertNotNull($status);
        self::assertTrue($status['ok']);
        self::assertNull($this->service(SessionStore::class)->load(self::CHAT)->state(), 'done: the next message is an ordinary one');
    }

    public function testTelegramTakingThePremiumEmojiOffTheBotsMessageIsSaidAndRemembered(): void
    {
        $this->premiumOwner = false;

        $this->send($this->message('/emoji'));
        $this->send($this->message('🔥', ['entities' => [['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => '111']]]));

        self::assertStringContainsString(Messages::EMOJI_BLOCKED, $this->params(2)['text']);
        $status = $this->service(PremiumEmojiStatus::class)->current();
        self::assertNotNull($status);
        self::assertFalse($status['ok']);
        self::assertSame(1, CustomEmoji::query()->count(), 'kept all the same, for when the owner has Premium');
    }

    public function testAnEarlierNoIsForgottenSoAPremiumBoughtMeanwhileIsSeen(): void
    {
        $this->service(PremiumEmojiStatus::class)->record(false);

        $this->send($this->message('/emoji'));
        $this->send($this->message('🔥', ['entities' => [['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => '111']]]));

        self::assertSame('<tg-emoji emoji-id="111">🔥</tg-emoji>', $this->params(1)['text'], 'the copy tries the premium emoji, not the plain ones the "no" held to');
        self::assertStringContainsString(Messages::EMOJI_WORKS, $this->params(2)['text']);
        self::assertTrue($this->service(PremiumEmojiStatus::class)->current()['ok'] ?? null);
        self::assertFalse($this->service(PremiumEmojiStatus::class)->refusedLately(), 'messages carry them again');
    }

    public function testAMessageWithoutPremiumEmojiIsAskedForAgain(): void
    {
        $this->send($this->message('/emoji'));

        $this->send($this->message('سلام 👍'));
        self::assertSame(['sendMessage'], $this->calls());
        self::assertSame(Messages::EMOJI_NONE, $this->params(0)['text']);
        self::assertSame(0, CustomEmoji::query()->count());

        $this->send($this->message('👨‍💻', ['entities' => [['type' => 'custom_emoji', 'offset' => 0, 'length' => 5, 'custom_emoji_id' => '222']]]));
        self::assertSame('👨‍💻', CustomEmoji::query()->sole()->emoji, 'still waiting for one');
    }

    public function testAPicturesCaptionCarriesThemToo(): void
    {
        $this->send($this->message('/emoji'));

        $this->send($this->message(null, [
            'photo' => [['file_id' => 'p1', 'file_unique_id' => 'u1', 'width' => 90, 'height' => 90]],
            'caption' => '🎁 هدیه',
            'caption_entities' => [['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => '333']],
        ]));

        self::assertSame('<tg-emoji emoji-id="333">🎁</tg-emoji> هدیه', $this->params(1)['text']);
        self::assertSame('333', CustomEmoji::query()->sole()->emoji_id);
    }

    public function testSomeoneWhoIsNotAnAdminGetsNoAnswer(): void
    {
        $this->customer(['telegram_id' => 6161]);

        $this->send($this->message('/emoji', [], 6161));

        self::assertSame([], $this->calls());
    }

    public function testTheLibraryKeepsTheLatestAndAnEmojiSeenAgainIsNotNew(): void
    {
        $emojis = $this->service(CustomEmojis::class);

        self::assertSame(2, $emojis->remember([['id' => '111', 'emoji' => '🔥'], ['id' => '222', 'emoji' => '👨‍💻']]));
        self::assertSame(0, $emojis->remember([['id' => '111', 'emoji' => '🔥']]), 'seen before');

        $many = [];
        for ($i = 1; $i <= CustomEmojis::MAX + 5; $i++) {
            $many[] = ['id' => (string) (900000 + $i), 'emoji' => '⭐'];
        }
        $emojis->remember($many);

        self::assertSame(CustomEmojis::MAX, CustomEmoji::query()->count(), 'the oldest go');
        self::assertSame(array_fill(0, 4, 'getCustomEmojiStickers'), $this->telegram()->calls(), 'once a time, the last in two: Telegram answers for 200 at most');
        self::assertCount(5, json_decode($this->telegram()->params(3)['custom_emoji_ids'], true));
    }

    public function testOneSeenAgainMovesToTheFrontOfThePicker(): void
    {
        $emojis = $this->service(CustomEmojis::class);
        Carbon::setTestNow('2026-10-06 10:00:00');
        $emojis->remember([['id' => '111', 'emoji' => '🔥']]);
        Carbon::setTestNow('2026-10-06 10:01:00');
        $emojis->remember([['id' => '222', 'emoji' => '👨‍💻']]);
        self::assertSame(['222', '111'], array_column($emojis->present(), 'id'), 'the latest seen first');

        Carbon::setTestNow('2026-10-06 10:02:00');
        $emojis->remember([['id' => '111', 'emoji' => '🔥']]);

        self::assertSame(['111', '222'], array_column($emojis->present(), 'id'));
    }
}
