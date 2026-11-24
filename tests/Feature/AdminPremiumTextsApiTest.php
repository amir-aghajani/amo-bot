<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\ValidationException;
use App\Modules\Admin\Api\PremiumTextsController;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Admin\OwnerLinks;
use App\Modules\Telegram\Emoji\EmojiMap;
use App\Modules\Telegram\Emoji\PremiumCheck;
use App\Modules\Telegram\Emoji\PremiumEmojiStatus;
use App\Modules\Telegram\Models\CustomEmoji;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;
use Tests\Support\FakeTelegram;

/**
 * «ایموجی پرمیوم متن‌ها» from the panels: the map and how many texts each emoji is in; the switch, turned on only once
 * the bot was found able to send premium emoji; a premium emoji new to the map one the bot knows, each plain emoji and
 * each premium one once; and the check — sent by the bot of the shop on screen to a chat of its own, read, recorded,
 * deleted —, which says where it cannot go.
 */
final class AdminPremiumTextsApiTest extends HttpTestCase
{
    private const OWNER_CHAT = 7001;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
        $this->telegram();
        CustomEmoji::query()->create(['emoji_id' => '111', 'emoji' => '✅']);
        CustomEmoji::query()->create(['emoji_id' => '222', 'emoji' => '❤️']);
    }

    public function testTheMapIsTurnedOnOnlyOnceTheBotWasFoundAble(): void
    {
        $empty = $this->decode($this->get('/api/admin/bot/premium-texts'))['premium_texts'];
        self::assertSame(['enabled' => false, 'items' => [], 'status' => null, 'can_enable' => false, 'max' => EmojiMap::MAX], $empty);

        $this->assertRefusedUnder('enabled', EmojiMap::NOT_ABLE, $this->putJson('/api/admin/bot/premium-texts', ['enabled' => true, 'items' => []]), message: 'never checked');
        $saved = $this->decode($this->putJson('/api/admin/bot/premium-texts', ['enabled' => false, 'items' => [['emoji' => '✅', 'emoji_id' => '111']]]))['premium_texts'];
        self::assertSame([['emoji' => '✅', 'emoji_id' => '111', 'texts' => $saved['items'][0]['texts']]], $saved['items'], 'laid out before it may be on');
        self::assertGreaterThan(10, $saved['items'][0]['texts'], 'how many texts it is in');

        $this->service(PremiumEmojiStatus::class)->record(false);
        $this->assertRefusedUnder('enabled', EmojiMap::NOT_ABLE, $this->putJson('/api/admin/bot/premium-texts', ['enabled' => true]), message: 'found unable');

        $this->service(PremiumEmojiStatus::class)->record(true);
        $on = $this->decode($this->putJson('/api/admin/bot/premium-texts', ['enabled' => true]))['premium_texts'];
        self::assertTrue($on['enabled'] && $on['can_enable']);
        self::assertStringContainsString('<tg-emoji emoji-id="111">✅</tg-emoji>', $this->service(BotTexts::class)->get(BotText::PhoneVerified));

        // Left on, the map may change though the bot was found unable since.
        $this->service(PremiumEmojiStatus::class)->record(false);
        $kept = $this->decode($this->putJson('/api/admin/bot/premium-texts', ['items' => [['emoji' => '✅', 'emoji_id' => '111'], ['emoji' => '❤', 'emoji_id' => '222']]]))['premium_texts'];
        self::assertSame([true, ['✅', '❤']], [$kept['enabled'], array_column($kept['items'], 'emoji')]);
    }

    /** A map over the most is refused whole, before each emoji is looked at (the API description holds the panels to it too). */
    public function testAMapOverTheMostIsRefusedWhole(): void
    {
        $items = array_map(static fn(int $n): array => ['emoji' => '✅', 'emoji_id' => (string) (1000 + $n)], range(0, EmojiMap::MAX));

        try {
            $this->service(EmojiMap::class)->save(['items' => $items]);
            self::fail('Refused.');
        } catch (ValidationException $e) {
            self::assertSame(['items' => ['حداکثر ' . EmojiMap::MAX . ' ایموجی.']], $e->errors());
        }
    }

    public function testEachEmojiAndEachPremiumOneOnceAndOnlyOnesTheBotKnows(): void
    {
        $put = fn(array $items) => $this->putJson('/api/admin/bot/premium-texts', ['items' => $items]);

        $this->assertRefusedUnder('items.0.emoji', EmojiMap::NOT_EMOJI, $put([['emoji' => 'قلب', 'emoji_id' => '111']]));
        $this->assertRefusedUnder('items.0.emoji', EmojiMap::NOT_EMOJI, $put([['emoji' => '✅✅', 'emoji_id' => '111']]), message: 'one emoji');
        $this->assertRefusedUnder('items.1.emoji', EmojiMap::TWICE_EMOJI, $put([['emoji' => '❤️', 'emoji_id' => '111'], ['emoji' => '❤', 'emoji_id' => '222']]), message: '❤ and ❤️ are one');
        $this->assertRefusedUnder('items.1.emoji_id', EmojiMap::TWICE_ID, $put([['emoji' => '✅', 'emoji_id' => '111'], ['emoji' => '❤️', 'emoji_id' => '111']]));
        $this->assertRefusedUnder('items.0.emoji_id', EmojiMap::ID_REFUSED, $put([['emoji' => '✅', 'emoji_id' => 'abc']]));
        $this->assertRefusedUnder('items.0.emoji_id', EmojiMap::NOT_KNOWN, $put([['emoji' => '✅', 'emoji_id' => '999']]), message: 'never seen by the bot');

        self::assertSame(200, $put([['emoji' => '✅', 'emoji_id' => '111']])->getStatusCode());
        // Off the picker since: the map keeps the one it had.
        CustomEmoji::query()->where('emoji_id', '111')->delete();
        self::assertSame(200, $put([['emoji' => '✅', 'emoji_id' => '111'], ['emoji' => '❤️', 'emoji_id' => '222']])->getStatusCode());
    }

    public function testTheCheckIsSentToTheOwnersLinkedAccountReadAndDeleted(): void
    {
        $this->assertRefusedUnder('check', PremiumTextsController::NOWHERE, $this->postJson('/api/admin/bot/premium-texts/check'), message: 'no account linked');

        $this->link(self::OWNER_CHAT);
        $this->telegram()->reply(['message_id' => 9, 'chat' => ['id' => self::OWNER_CHAT, 'type' => 'private'], 'text' => 'x', 'entities' => [['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => '222']]], true);
        $checked = $this->decode($this->postJson('/api/admin/bot/premium-texts/check'))['premium_texts'];

        self::assertSame([true, true], [$checked['status']['ok'], $checked['can_enable']]);
        self::assertSame(['sendMessage', 'deleteMessage'], $this->telegram()->calls());
        self::assertSame((string) self::OWNER_CHAT, $this->telegram()->params(0)['chat_id']);
        self::assertStringContainsString('<tg-emoji emoji-id="222">❤️</tg-emoji>', $this->telegram()->params(0)['text'], 'the newest one the bot knows');

        // Telegram dropped it: the bot may not.
        $this->telegram()->reset();
        $this->telegram()->reply(['message_id' => 10, 'chat' => ['id' => self::OWNER_CHAT, 'type' => 'private'], 'text' => 'x'], true);
        $checked = $this->decode($this->postJson('/api/admin/bot/premium-texts/check'))['premium_texts'];
        self::assertSame([false, false], [$checked['status']['ok'], $checked['can_enable']]);
    }

    public function testTheCheckSaysWhyItCannotGo(): void
    {
        CustomEmoji::query()->delete();
        $this->link(self::OWNER_CHAT);
        $this->assertRefusedUnder('check', PremiumCheck::NOTHING_TO_TEST, $this->postJson('/api/admin/bot/premium-texts/check'), message: 'no premium emoji to test with');

        CustomEmoji::query()->create(['emoji_id' => '111', 'emoji' => '✅']);
        $this->telegram()->fail(403, 'Forbidden: bot was blocked by the user');
        $this->assertRefusedUnder('check', PremiumTextsController::NOT_SENT, $this->postJson('/api/admin/bot/premium-texts/check'));
        self::assertNull($this->service(PremiumEmojiStatus::class)->current(), 'not sent: nothing found');

        // Found able before: a test not sent finds nothing new, and the switch may still be turned on.
        $status = $this->service(PremiumEmojiStatus::class);
        $status->record(true);
        $before = $status->current();
        Carbon::setTestNow(now()->addHour());
        $this->telegram()->fail(403, 'Forbidden: bot was blocked by the user');
        $this->assertRefusedUnder('check', PremiumTextsController::NOT_SENT, $this->postJson('/api/admin/bot/premium-texts/check'));
        self::assertSame($before, $status->current(), 'what was found before stays, as of when it was found');
        self::assertTrue($this->decode($this->putJson('/api/admin/bot/premium-texts', ['enabled' => true]))['premium_texts']['enabled']);

        // An agent's shop from the owner's panel: its bot does not write to the owner.
        $bot = $this->agentBot();
        $this->openShop($bot);
        $this->assertRefusedUnder('check', PremiumTextsController::OTHER_BOT, $this->postJson('/api/admin/bot/premium-texts/check'));
    }

    public function testTheAgentsCheckGoesFromTheirBotToThemselves(): void
    {
        $bot = $this->agentBot();
        CurrentBot::run($bot, static fn() => CustomEmoji::query()->create(['emoji_id' => '333', 'emoji' => '🎁']));
        $this->loginAsAgent($bot);
        $this->telegram()->reply(['message_id' => 3, 'chat' => ['id' => self::AGENT_TELEGRAM_ID, 'type' => 'private'], 'text' => 'x', 'entities' => [['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => '333']]], true);

        $checked = $this->decode($this->postJson('/api/agent/bot/premium-texts/check'))['premium_texts'];

        self::assertTrue($checked['status']['ok']);
        self::assertSame([(string) self::AGENT_TELEGRAM_ID, FakeTelegram::AGENT_TOKEN], [$this->telegram()->params(0)['chat_id'], $this->telegram()->tokenOf(0)]);
    }

    /** `$telegramId` linked to the panel as the owner's account (OwnerLinks), as redeeming its link does. */
    private function link(int $telegramId): void
    {
        $this->service(Settings::class)->set('owner.telegram_ids', [$telegramId], Bot::MAIN);
        self::assertSame([$telegramId], $this->service(OwnerLinks::class)->ids());
    }
}
