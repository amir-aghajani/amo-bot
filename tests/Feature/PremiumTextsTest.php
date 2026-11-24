<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Emoji\EmojiMap;
use App\Modules\Telegram\Keyboard\KeyboardLayouts;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Texts\PremiumText;
use App\Modules\Telegram\Texts\TelegramHtml;
use App\Modules\Telegram\Texts\TextKind;
use Tests\BotTestCase;

/**
 * «ایموجی پرمیوم متن‌ها» (Emoji\EmojiMap): a plain emoji the shop gave a premium one is drawn premium in every text of the
 * bot's — the default wordings and the admin's alike —, in the shop's own words only (a name, a link, what a customer
 * typed stay as they are), never inside code, a link, a date or a premium emoji a wording carries itself (which wins). A
 * button's leading one becomes its icon — unless a name's own premium emoji is the icon —, a menu button's too, whose
 * tap then still routes; a popup draws none. Off, or with no map, everything is as it was.
 */
final class PremiumTextsTest extends BotTestCase
{
    private const CHECK = '<tg-emoji emoji-id="111">✅</tg-emoji>';

    public function testAMappedEmojiIsDrawnPremiumInADefaultTextAndInAReworded(): void
    {
        $texts = $this->service(BotTexts::class);
        self::assertSame(self::text(BotText::PhoneVerified), $texts->get(BotText::PhoneVerified), 'no map: as it was');

        $this->map(['✅' => '111']);
        self::assertSame(self::CHECK . ' شماره شما تایید شد.', $texts->get(BotText::PhoneVerified));

        $texts->save(BotText::PhoneVerified, 'ثبت شد ✅ و <code>✅</code> و <a href="https://x.test">✅</a> و <tg-emoji emoji-id="999">✅</tg-emoji>');
        self::assertSame(
            'ثبت شد ' . self::CHECK . ' و <code>✅</code> و <a href="https://x.test">✅</a> و <tg-emoji emoji-id="999">✅</tg-emoji>',
            $texts->get(BotText::PhoneVerified),
            'the admin\'s words too — never in code or a link, and a premium emoji of their own wins',
        );

        $this->map(['✅' => '111'], enabled: false);
        self::assertSame('ثبت شد ✅ و <code>✅</code> و <a href="https://x.test">✅</a> و <tg-emoji emoji-id="999">✅</tg-emoji>', $texts->get(BotText::PhoneVerified), 'switched off: as written');
    }

    public function testTheVariationSelectorDoesNotMatterAndAFilledValueIsLeftAsItIs(): void
    {
        $this->map(['❤' => '222']);
        $texts = $this->service(BotTexts::class);
        $texts->save(BotText::Welcome, 'سلام %name% ❤️');

        self::assertSame('سلام ❤️ دوست <tg-emoji emoji-id="222">❤️</tg-emoji>', $texts->welcome('❤️ دوست'), '❤ and ❤️ are one heart; the name, a value, stays plain');
    }

    public function testAButtonsLeadingEmojiBecomesItsIconUnlessANamesOwnPremiumEmojiIs(): void
    {
        $this->map(['⬅️' => '333', '🌍' => '444']);
        $texts = $this->service(BotTexts::class);

        self::assertSame('<tg-emoji emoji-id="333">⬅️</tg-emoji> بازگشت', $texts->get(BotText::Back));
        self::assertSame('<tg-emoji emoji-id="444">🌍</tg-emoji> 🇩🇪 آلمان', $texts->render(BotText::ServerButton, ['server' => PremiumText::name('🇩🇪 آلمان', null)]));
        self::assertSame(
            '🌍 <tg-emoji emoji-id="555">🇩🇪</tg-emoji> آلمان',
            $texts->render(BotText::ServerButton, ['server' => PremiumText::name('🇩🇪 آلمان', '555')]),
            "the server's own premium emoji is the icon: the label's own emoji stays plain",
        );
        self::assertSame(self::text(BotText::BotOff), $texts->get(BotText::BotOff), 'a popup draws no premium emoji');
    }

    /**
     * Every default wording, every emoji in them mapped: still HTML Telegram takes, showing the very same words, and
     * applied twice the same as once.
     */
    public function testEveryDefaultTextStaysTelegramHtmlWithTheSameWordsAndApplyingTwiceChangesNothing(): void
    {
        $emojis = [];
        foreach (BotText::cases() as $text) {
            preg_match_all('/\X/u', $text->spec()->default, $graphemes);
            foreach ($graphemes[0] as $grapheme) {
                if (TelegramHtml::isEmoji($grapheme)) {
                    $emojis[EmojiMap::key($grapheme)] = $grapheme;
                }
            }
        }
        self::assertGreaterThan(20, count($emojis), 'the defaults are full of emoji');
        $this->map(array_fill_keys(array_slice(array_values($emojis), 0, EmojiMap::MAX), '777'));
        $map = $this->service(EmojiMap::class);

        $drawn = 0;
        foreach (BotText::cases() as $text) {
            $spec = $text->spec();
            if (!$spec->kind->html()) {
                continue;
            }
            $once = $map->apply($spec->default, $spec->kind);
            $drawn += substr_count($once, '<tg-emoji emoji-id="777">');
            self::assertNull(TelegramHtml::problem($once), $text->value);
            self::assertSame(TelegramHtml::visible($spec->default), TelegramHtml::visible($once), $text->value);
            self::assertSame($once, $map->apply($once, $spec->kind), "{$text->value}: once is enough");
        }
        self::assertGreaterThan(100, $drawn, 'and they were drawn');
    }

    /**
     * A reply keyboard's tap sends its label without its icon, and those words alone would be taken for the button
     * wherever a customer typed them — so the shop's premium emoji leave it as it is: its own icon, set on the keyboard,
     * and its labels route as in 0.10.1.
     */
    public function testAReplyKeyboardIsLeftAsItIsSoTypedWordsStayWhatTheyWere(): void
    {
        $this->service(KeyboardLayouts::class)->save('start', ['type' => 'reply', 'rows' => [[
            ['action' => 'support', 'label' => '🎧 پشتیبانی', 'style' => null],
            ['action' => 'buy', 'label' => '🛒 خرید', 'style' => null, 'icon' => '42'],
        ]]]);
        $this->map(['🎧' => '666', '🛒' => '888']);

        $this->send($this->message('/start'));
        self::assertSame(
            [[['text' => '🛒 خرید', 'icon_custom_emoji_id' => '42'], ['text' => '🎧 پشتیبانی']]],
            $this->markup(0)['keyboard'] ?? null,
            'its own icon as set; no other from the map',
        );

        $this->send($this->message('پشتیبانی'));
        self::assertSame([self::text(BotText::Unknown)], $this->said(), 'the words alone are no tap: a stray text, as without the map');
        $this->send($this->message('🎧 پشتیبانی'));
        self::assertSame([self::text(BotText::SupportUnavailable)], $this->said(), 'its label routes');
    }

    /** An inline menu's button takes its icon too; a tap on it sends no words, so its words alone stay a stray text. */
    public function testAnInlineMenuButtonTakesItsIconAndItsWordsAloneRouteNowhere(): void
    {
        $this->map(['🎧' => '666']);
        $this->service(KeyboardLayouts::class)->save('start', ['type' => 'inline', 'rows' => [[
            ['action' => 'support', 'label' => '🎧 پشتیبانی', 'style' => null],
        ]]]);

        $this->send($this->message('/start'));
        $button = $this->inlineKeyboard(0)[0][0] ?? [];
        self::assertSame(['پشتیبانی', '666'], [$button['text'] ?? null, $button['icon_custom_emoji_id'] ?? null]);

        $this->send($this->message('پشتیبانی'));
        self::assertSame([self::text(BotText::Unknown)], $this->said(), 'typed, the words are no tap: a step in progress keeps them');
    }

    public function testAPartIsDrawnAndAPopupIsNot(): void
    {
        $this->map(['🎁' => '123']);
        $map = $this->service(EmojiMap::class);

        self::assertSame('<tg-emoji emoji-id="123">🎁</tg-emoji> x', $map->apply('🎁 x', TextKind::Part));
        self::assertSame('🎁 x', $map->apply('🎁 x', TextKind::Popup));
    }

    /** @param array<string, string> $items emoji => premium id */
    private function map(array $items, bool $enabled = true): void
    {
        $this->service(Settings::class)->set('bot.premium_texts', [
            'enabled' => $enabled,
            'items' => array_map(static fn(string $emoji, string $id): array => ['emoji' => $emoji, 'emoji_id' => $id], array_keys($items), array_values($items)),
        ]);
    }
}
