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
use App\Modules\Telegram\Keyboard\KeyboardLayouts;
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
        self::assertNotSame([], $empty['emojis'], 'the default texts carry emoji');
        unset($empty['emojis']);
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

    /**
     * Every emoji the shop's texts carry, most carried first, each with the premium emoji swapped in — the map's emoji no
     * text carries any more after them —, so the admin swaps from the list.
     */
    public function testTheEmojiTheTextsCarryAreListedMostCarriedFirstWithWhatEachIsSwappedFor(): void
    {
        $emojis = $this->emojis();
        $carried = array_map(static fn(array $row): int => $row['texts'] + $row['menu'], $emojis);
        $sorted = $carried;
        rsort($sorted);
        self::assertSame($sorted, $carried, 'most carried first');
        self::assertSame([0], array_values(array_unique(array_column($emojis, 'menu'))), 'a fresh shop\'s menu is a keyboard under the text field: no icons');
        $check = $this->row($emojis, '✅');
        self::assertNotNull($check);
        self::assertSame([null, 0], [$check['emoji_id'], $check['menu']], 'nothing swapped in yet');
        self::assertGreaterThan(10, $check['texts']);

        $this->putJson('/api/admin/bot/premium-texts', ['items' => [['emoji' => '✅', 'emoji_id' => '111'], ['emoji' => '🦄', 'emoji_id' => '222']]]);
        $swapped = $this->emojis();
        self::assertSame(array_column($emojis, 'emoji'), array_slice(array_column($swapped, 'emoji'), 0, count($emojis)), 'the list stays in its order');
        self::assertSame('111', $this->row($swapped, '✅')['emoji_id'] ?? null);
        self::assertSame(['emoji' => '🦄', 'texts' => 0, 'menu' => 0, 'emoji_id' => '222'], $swapped[array_key_last($swapped)], 'carried by no text: last');
    }

    /**
     * Only where the map would draw it: the shop's own wording — the admin's included, a heart found once with or without
     * its variation selector —, never inside a premium emoji, code or a link, nor in a popup; and an inline start menu's
     * buttons with no icon of their own.
     */
    public function testTheListHasEachEmojiOnlyWhereTheMapWouldDrawIt(): void
    {
        $texts = $this->service(BotTexts::class);
        $texts->save(BotText::PhoneVerified, 'ثبت شد 🦄 و ❤ و <code>🐙</code> و <a href="https://x.test">🐙</a> و <tg-emoji emoji-id="999">🐙</tg-emoji>');
        $texts->save(BotText::Welcome, 'سلام %name% ❤️ 🦄');
        $emojis = $this->emojis();
        self::assertSame(2, $this->row($emojis, '🦄')['texts'] ?? null);
        self::assertNull($this->row($emojis, '🐙'), 'never drawn there');
        self::assertSame('❤️', $this->row($emojis, '❤')['emoji'] ?? null, 'one heart, shown with its variation selector');

        $layouts = $this->service(KeyboardLayouts::class);
        $layouts->save('start', ['type' => 'inline', 'rows' => [[
            ['action' => 'support', 'label' => '🦀 پشتیبانی', 'style' => null],
            ['action' => 'wallet', 'label' => '🦞 کیف پول', 'style' => null, 'icon' => '111'],
        ]]]);
        self::assertSame(['texts' => 0, 'menu' => 1], array_intersect_key($this->row($this->emojis(), '🦀') ?? [], ['texts' => 0, 'menu' => 0]));
        self::assertNull($this->row($this->emojis(), '🦞'), 'its own icon stays');

        $layouts->save('start', ['type' => 'reply', 'rows' => [[['action' => 'support', 'label' => '🦀 پشتیبانی', 'style' => null]]]]);
        self::assertNull($this->row($this->emojis(), '🦀'), 'a keyboard under the text field keeps its labels');
    }

    /**
     * The menu counted as a customer is shown it: a button of a program switched off is in no menu, and a layout with
     * nothing else left shows the built-in one.
     */
    public function testTheMenuIsCountedAsACustomerIsShownIt(): void
    {
        $this->service(KeyboardLayouts::class)->save('start', ['type' => 'inline', 'rows' => [[
            ['action' => 'wheel', 'label' => '🎠 گردونه', 'style' => null],
        ]]]);

        $emojis = $this->emojis();
        self::assertNull($this->row($emojis, '🎠'), 'the wheel is off: its button is in no menu');
        self::assertSame(1, $this->row($emojis, '🔐')['menu'] ?? null, 'the built-in menu shown in its place');

        // A label whose emoji a half-space follows gets no icon (EmojiMap::icon()): no button it is counted in.
        $this->service(KeyboardLayouts::class)->save('start', ['type' => 'inline', 'rows' => [[
            ['action' => 'support', 'label' => "🦀\u{200C}پشتیبانی", 'style' => null],
        ]]]);
        self::assertSame([], array_filter($this->emojis(), static fn(array $row): bool => str_starts_with($row['emoji'], '🦀')));
    }

    /**
     * A sign of the emoji blocks that is no emoji (a line's ━, ★, ✓, ●, №) is none to swap: left out of the list, and
     * refused new to the map — a bare ❤ is one. A half-space typed after an emoji is
     * no part of it: the emoji is the one listed and drawn, the half-space kept after its tag.
     */
    public function testOnlyEmojiDrawnAsEmojiAreListedAndAHalfSpaceAfterOneIsNoPartOfIt(): void
    {
        $texts = $this->service(BotTexts::class);
        $texts->save(BotText::PhoneVerified, 'ثبت شد ━━━ ★ ✓ ● № و 🦄‌ثبت');
        $emojis = $this->emojis();
        foreach (['━', '★', '✓', '●', '№'] as $sign) {
            self::assertNull($this->row($emojis, $sign), "{$sign} is no emoji to swap");
        }
        self::assertSame(1, $this->row($emojis, '🦄')['texts'] ?? null);
        self::assertCount(1, array_filter($emojis, static fn(array $row): bool => str_starts_with($row['emoji'], '🦄')), 'one unicorn, the half-space no part of it');

        $put = fn(array $items) => $this->putJson('/api/admin/bot/premium-texts', ['items' => $items]);
        $this->assertRefusedUnder('items.0.emoji', EmojiMap::NOT_EMOJI, $put([['emoji' => '★', 'emoji_id' => '111']]));
        $saved = $this->decode($put([['emoji' => "🦄\u{200C}", 'emoji_id' => '111']]))['premium_texts'];
        self::assertSame('🦄', $saved['items'][0]['emoji'], 'saved without the half-space');

        $this->service(PremiumEmojiStatus::class)->record(true);
        $this->putJson('/api/admin/bot/premium-texts', ['enabled' => true]);
        self::assertStringContainsString("<tg-emoji emoji-id=\"111\">🦄</tg-emoji>\u{200C}ثبت", $texts->get(BotText::PhoneVerified));

        // One saved before this rule stays as it was saved, though it would be refused new.
        $this->service(Settings::class)->set('bot.premium_texts', ['enabled' => true, 'items' => [['emoji' => '★', 'emoji_id' => '111']]]);
        self::assertSame(['emoji' => '★', 'texts' => 1, 'menu' => 0, 'emoji_id' => '111'], $this->row($this->emojis(), '★'), 'listed where the texts carry it, as it is drawn');
        self::assertSame(200, $put([['emoji' => '★', 'emoji_id' => '111'], ['emoji' => '✅', 'emoji_id' => '222']])->getStatusCode());
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

    /** @return list<array{emoji: string, texts: int, menu: int, emoji_id: ?string}> The list as the panel reads it. */
    private function emojis(): array
    {
        return $this->decode($this->get('/api/admin/bot/premium-texts'))['premium_texts']['emojis'];
    }

    /**
     * @param list<array{emoji: string, texts: int, menu: int, emoji_id: ?string}> $emojis
     * @return array{emoji: string, texts: int, menu: int, emoji_id: ?string}|null The row of `$emoji` (❤ and ❤️ one).
     */
    private function row(array $emojis, string $emoji): ?array
    {
        foreach ($emojis as $row) {
            if (EmojiMap::key($row['emoji']) === EmojiMap::key($emoji)) {
                return $row;
            }
        }

        return null;
    }

    /** `$telegramId` linked to the panel as the owner's account (OwnerLinks), as redeeming its link does. */
    private function link(int $telegramId): void
    {
        $this->service(Settings::class)->set('owner.telegram_ids', [$telegramId], Bot::MAIN);
        self::assertSame([$telegramId], $this->service(OwnerLinks::class)->ids());
    }
}
