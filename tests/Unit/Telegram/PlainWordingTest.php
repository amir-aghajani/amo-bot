<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Modules\Telegram\Texts\PlainWording;
use App\Modules\Telegram\Texts\TelegramHtml;
use App\Modules\Telegram\Texts\TextKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A popup's or a button's wording with premium emoji: shown plain on a notice, kept in a message, a button's leading one
 * its icon — and what of them the admin is told to change.
 */
final class PlainWordingTest extends TestCase
{
    private const STAR = '<tg-emoji emoji-id="5368324170671202286">⭐</tg-emoji>';

    public function testANoticeShowsEachPremiumEmojisPlainOne(): void
    {
        self::assertSame('⭐ ثبت شد ⭐', PlainWording::shown(self::STAR . ' ثبت شد ' . self::STAR));
        self::assertSame('<b>همان</b> & <tg-emoji emoji-id=\'1\'>⭐</tg-emoji>', PlainWording::shown('<b>همان</b> & <tg-emoji emoji-id=\'1\'>⭐</tg-emoji>'), 'only what the picker writes');
    }

    public function testInAMessageTheWordsAreEscapedAndThePremiumEmojiKept(): void
    {
        self::assertSame(self::STAR . ' ۸ &gt; ۷ &amp; &lt;b&gt;', PlainWording::html(self::STAR . ' ۸ > ۷ & <b>'));
        self::assertSame('سلام', PlainWording::html('سلام'));
        self::assertNull(TelegramHtml::problem(PlainWording::html('<i>' . self::STAR . ' &nbsp; </tg-emoji>')), 'whatever the words, Telegram reads the message');
    }

    public function testAButtonsLeadingPremiumEmojiIsItsIcon(): void
    {
        self::assertSame(['5368324170671202286', 'انصراف'], PlainWording::icon(self::STAR . '  انصراف'));
        self::assertSame(['5368324170671202286', 'امتیاز ⭐'], PlainWording::icon(self::STAR . ' امتیاز ' . self::STAR), 'another in the label shows plain');
        self::assertSame([null, 'انصراف ⭐'], PlainWording::icon('انصراف ' . self::STAR), 'none but at the start');
        self::assertSame([null, '⭐'], PlainWording::icon(self::STAR), 'a label of it alone keeps it as words');
        self::assertSame([null, 'انصراف'], PlainWording::icon('انصراف'));
        // A name's after a wording's own symbol («🌍 %server%»): the icon in that symbol's place.
        self::assertSame(['7', 'آلمان'], PlainWording::icon('🌍 <tg-emoji emoji-id="7">🇩🇪</tg-emoji> آلمان'));
        self::assertSame([null, '🌍🇩🇪 آلمان'], PlainWording::icon('🌍<tg-emoji emoji-id="7">🇩🇪</tg-emoji> آلمان'), 'run into the symbol: plain');
        self::assertSame([null, 'سرور 🇩🇪 آلمان'], PlainWording::icon('سرور <tg-emoji emoji-id="7">🇩🇪</tg-emoji> آلمان'), 'after words: plain');
    }

    public function testAKeyboardsLabelsGoWithTheirIconsOrPlain(): void
    {
        $markup = ['inline_keyboard' => [[
            ['text' => self::STAR . ' خرید', 'callback_data' => 'buy', 'style' => 'success'],
            ['text' => self::STAR . ' منو', 'callback_data' => 'menu', 'icon_custom_emoji_id' => '42'],
            ['text' => 'بازگشت', 'callback_data' => 'back'],
        ]]];

        self::assertSame(['inline_keyboard' => [[
            ['text' => 'خرید', 'callback_data' => 'buy', 'style' => 'success', 'icon_custom_emoji_id' => '5368324170671202286'],
            ['text' => '⭐ منو', 'callback_data' => 'menu', 'icon_custom_emoji_id' => '42'],
            ['text' => 'بازگشت', 'callback_data' => 'back'],
        ]]], PlainWording::icons($markup), "a button's own icon stays, its label's premium emoji then plain");
        self::assertSame(['inline_keyboard' => [[
            ['text' => '⭐ خرید', 'callback_data' => 'buy', 'style' => 'success'],
            ['text' => '⭐ منو', 'callback_data' => 'menu', 'icon_custom_emoji_id' => '42'],
            ['text' => 'بازگشت', 'callback_data' => 'back'],
        ]]], PlainWording::plainLabels($markup));

        $reply = ['keyboard' => [[['text' => self::STAR . ' شماره', 'request_contact' => true]]], 'resize_keyboard' => true];
        self::assertSame(['keyboard' => [[['text' => 'شماره', 'request_contact' => true, 'icon_custom_emoji_id' => '5368324170671202286']]], 'resize_keyboard' => true], PlainWording::icons($reply));

        self::assertNull(PlainWording::icons(['inline_keyboard' => [[['text' => 'بازگشت', 'callback_data' => 'back']]]]), 'nothing to change');
        self::assertNull(PlainWording::plainLabels(['remove_keyboard' => true]));
    }

    /** @return iterable<string, array{TextKind, string, ?string}> */
    public static function wordings(): iterable
    {
        yield "a button's at the start" => [TextKind::Button, self::STAR . ' انصراف', null];
        yield "a button's before a variable" => [TextKind::Button, self::STAR . ' %rating%', null];
        yield 'a button without one' => [TextKind::Button, '⭐ انصراف', null];
        yield "a popup's anywhere, any number" => [TextKind::Popup, 'ثبت شد ' . self::STAR . ' ممنون ' . self::STAR, null];
        yield 'a popup of signs that are not tags' => [TextKind::Popup, '۸ < ۹ & <3 و ۱۰ > ۹', null];
        yield "a button's after its words" => [TextKind::Button, 'انصراف ' . self::STAR, PlainWording::BUTTON_PLACE];
        yield 'a button of two' => [TextKind::Button, self::STAR . self::STAR . ' انصراف', PlainWording::BUTTON_PLACE];
        yield "a button's alone" => [TextKind::Button, self::STAR, PlainWording::BUTTON_WORDS];
        yield "a button's beside spaces alone" => [TextKind::Button, self::STAR . "\u{00A0}", PlainWording::BUTTON_WORDS];
        yield 'one holding words' => [TextKind::Popup, '<tg-emoji emoji-id="5">ستاره</tg-emoji>', TelegramHtml::EMOJI_CONTENT];
        yield 'one holding nothing' => [TextKind::Button, '<tg-emoji emoji-id="5"></tg-emoji> انصراف', TelegramHtml::EMOJI_CONTENT];
        yield 'one with an id that is no number' => [TextKind::Button, '<tg-emoji emoji-id="x">⭐</tg-emoji> انصراف', PlainWording::FORM];
        yield 'one in capitals' => [TextKind::Popup, '<TG-EMOJI emoji-id="5">⭐</TG-EMOJI>', PlainWording::FORM];
        yield 'one left open' => [TextKind::Popup, '<tg-emoji emoji-id="5">⭐ ثبت شد', PlainWording::FORM];
        yield 'formatting' => [TextKind::Popup, '<b>ثبت شد</b>', PlainWording::NO_TAGS];
        yield 'formatting beside a premium emoji' => [TextKind::Button, self::STAR . ' <i>انصراف</i>', PlainWording::NO_TAGS];
    }

    #[DataProvider('wordings')]
    public function testWhatOfAPopupsOrAButtonsWordingIsRefused(TextKind $kind, string $value, ?string $refusal): void
    {
        self::assertSame($refusal, PlainWording::problem($kind, $value));
    }
}
