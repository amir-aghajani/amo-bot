<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\ValidationException;
use App\Modules\Settings\Models\Setting;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Texts\TelegramHtml;
use App\Modules\Telegram\Texts\TextCatalog;
use App\Modules\Telegram\Texts\TextKind;
use App\Support\Validation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;

/**
 * The admin's wording of a bot text: stored under `bot.text.<key>` and used in place of the shop's until
 * it is reset — checked before it is stored, since a text Telegram refuses leaves the customer with nothing.
 */
final class BotTextsTest extends DatabaseTestCase
{
    private BotTexts $texts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->texts = $this->service(BotTexts::class);
    }

    public function testAWordingTakesThePlaceOfTheShopsUntilReset(): void
    {
        $this->texts->save(BotText::Welcome, "  درود %name% 🌸\r\nبه فروشگاه ما خوش آمدید.  ");

        self::assertTrue($this->texts->isCustomized(BotText::Welcome));
        self::assertSame("درود Ali 🌸\nبه فروشگاه ما خوش آمدید.", $this->texts->render(BotText::Welcome, ['name' => 'Ali']), 'trimmed, one kind of line break');

        $this->texts->reset(BotText::Welcome);

        self::assertFalse($this->texts->isCustomized(BotText::Welcome));
        self::assertSame(self::text(BotText::Welcome, ['name' => 'Ali']), $this->texts->render(BotText::Welcome, ['name' => 'Ali']));
    }

    public function testTheShopsOwnWordingIsNoWording(): void
    {
        $this->texts->save(BotText::Cancel, '❌ لغو');
        $this->texts->save(BotText::Cancel, BotText::Cancel->spec()->default);

        self::assertFalse($this->texts->isCustomized(BotText::Cancel), 'a default improved later still reaches the shop');
        self::assertSame(0, Setting::query()->where('key', 'bot.text.cancel')->count());
    }

    public function testALeftToRightMarkBeforeARowNumberIsTheAdminsToWriteAsTheDefaultsDo(): void
    {
        // The default carries one before «#»; the admin's wording may too: an invisible mark, no markup, no content.
        $this->texts->save(BotText::PayProcessing, BotText::PayProcessing->spec()->default);
        self::assertFalse($this->texts->isCustomized(BotText::PayProcessing), 'the default as it is, its mark and all');

        $wording = "✅ سفارش \u{200E}#%order% پرداخت شد؛ %outcome%.";
        $this->texts->save(BotText::PayProcessing, $wording);
        self::assertSame($wording, $this->texts->present(BotText::PayProcessing)['value'], 'kept as typed');
        self::assertSame(TelegramHtml::visibleLength(str_replace("\u{200E}", '', $wording)) + 1, TelegramHtml::visibleLength($wording), 'one unit, as Telegram counts it in its limit');
    }

    public function testAPartKeepsTheLineBreaksItStartsWithAndMayBeEmptied(): void
    {
        $this->texts->save(BotText::AdminNote, "\r\n\r\n💬 %comment%  \n");
        self::assertSame("\n\n💬 %comment%", $this->texts->present(BotText::AdminNote)['value']);

        $this->texts->save(BotText::ServiceRotateHint, '');
        self::assertSame('', $this->texts->present(BotText::ServiceRotateHint)['value'], 'the admin does not want the hint');
        self::assertSame("بالا\n\nپایین", BotTexts::paragraphs('بالا', $this->texts->part(BotText::ServiceRotateHint), 'پایین'), 'and it leaves no gap');
        self::assertSame("بالا\n\n💬 x", BotTexts::paragraphs('بالا', $this->texts->part(BotText::AdminNote, ['comment' => 'x'])), "a part's own leading breaks give way to the blank line between paragraphs");
    }

    public function testValuesArePlainTextEscapedOnceAndOnlyWhereTelegramReadsHtml(): void
    {
        self::assertSame(self::text(BotText::Welcome, ['name' => '&lt;Ali&gt; &amp; co']), $this->texts->welcome('<Ali> & co'), 'a message reads HTML: escaped');
        self::assertSame(self::text(BotText::JoinStillMissing, ['title' => 'A & <B>']), $this->texts->render(BotText::JoinStillMissing, ['title' => 'A & <B>']), 'a popup shows it as typed');
        self::assertSame(self::text(BotText::PlanButton, ['plan' => 'Pro <1>', 'traffic' => '۳۰ گیگابایت', 'duration' => '۳۰ روز', 'price' => '۱ تومان']), $this->texts->render(BotText::PlanButton, ['plan' => 'Pro <1>', 'traffic' => '۳۰ گیگابایت', 'duration' => '۳۰ روز', 'price' => '۱ تومان']), 'a button too');
    }

    public function testAPartGoesIntoAnotherTextAsTheHtmlItIs(): void
    {
        $this->texts->save(BotText::AdminNote, "\n<i>توضیح: %comment%</i>");

        $note = $this->texts->part(BotText::AdminNote, ['comment' => 'a < b']);
        self::assertSame("\n<i>توضیح: a &lt; b</i>", (string) $note, "the part's values escaped, its own markup kept");
        self::assertSame(
            self::text(BotText::ReceiptRejected, ['order' => '12', 'note' => "\n<i>توضیح: a &lt; b</i>"]),
            $this->texts->render(BotText::ReceiptRejected, ['order' => 12, 'note' => $note]),
            'and not escaped a second time',
        );
    }

    public function testPartsAndTextsAreNotMixedUp(): void
    {
        $refused = static function (\Closure $call): bool {
            try {
                $call();
            } catch (\LogicException) {
                return true;
            }

            return false;
        };

        self::assertTrue($refused(fn() => $this->texts->part(BotText::Welcome, ['name' => 'Ali'])), 'a message made a part');
        self::assertTrue($refused(fn() => $this->texts->get(BotText::ServiceStale)), 'a part sent on its own');
        self::assertTrue($refused(fn() => $this->texts->render(BotText::AdminNote, ['comment' => 'x'])), 'a part sent on its own, with values');
        self::assertTrue($refused(fn() => $this->texts->render(BotText::JoinStillMissing, ['title' => $this->texts->part(BotText::ServiceStale)])), 'markup in a popup');
    }

    public function testTheAgentsLoginLinkHasASampleOfItsOwn(): void
    {
        $variables = array_column($this->texts->present(BotText::AgencyLogin)['variables'], null, 'name');

        self::assertArrayHasKey('login', $variables);
        self::assertStringContainsString('/agent/login#code=', $variables['login']['sample'], "an agent's sign-in link, not a customer's invite");
        self::assertTrue($variables['login']['required']);
    }

    public function testAVariableTheBotFillsWithHtmlSaysSoAndItsSampleIsHtmlAsWell(): void
    {
        self::assertSame([], array_diff(TextCatalog::HTML_VARIABLES, array_keys(TextCatalog::VARIABLES)), 'every one a variable');
        foreach (TextCatalog::VARIABLES as $name => [, $sample]) {
            if (in_array($name, TextCatalog::HTML_VARIABLES, true)) {
                self::assertNull(TelegramHtml::problem($sample), "%{$name}%'s sample is Telegram HTML, as the bot fills it");
            } else {
                self::assertDoesNotMatchRegularExpression('/[<&]/', $sample, "%{$name}%'s sample is plain text: markup in it would read as typed");
            }
        }

        $variables = array_column($this->texts->present(BotText::PayOnlineCard)['variables'], 'html', 'name');
        self::assertSame(['card' => false, 'card_holder' => true, 'bank' => true, 'amount_digits' => false, 'amount_rial' => false], $variables, "a part's wording is HTML; a card number is not");
        self::assertSame("\n👤 به نام <b>سارا احمدی</b>", TextCatalog::VARIABLES['card_holder'][1]);
    }

    /** @return iterable<string, array{BotText, mixed, string}> */
    public static function refused(): iterable
    {
        yield 'nothing at all' => [BotText::Welcome, " \n ", 'نمی‌تواند خالی باشد'];
        // Blank as Telegram reads it, though trim() leaves it: Telegram refuses a message of nothing.
        yield 'a no-break space alone' => [BotText::MenuPrompt, "\u{00A0}", 'نمی‌تواند خالی باشد'];
        yield 'zero-width characters alone' => [BotText::MenuPrompt, "\u{200B}\u{200C}\u{FEFF}\n\u{2060}", 'نمی‌تواند خالی باشد'];
        yield 'direction marks alone' => [BotText::MenuPrompt, "\u{200E}\u{200F}", 'نمی‌تواند خالی باشد'];
        yield 'formatting around a space, and a space as an entity' => [BotText::MenuPrompt, "<b>\u{2009}</b>&#160;", 'نمی‌تواند خالی باشد'];
        yield 'a button of a thin space' => [BotText::Cancel, "\u{2009}", 'نمی‌تواند خالی باشد'];
        yield 'a popup of an ideographic space' => [BotText::Error, "\u{3000}", 'نمی‌تواند خالی باشد'];
        // The letters made to look empty (Text::blank()): a Hangul filler, the blank Braille pattern.
        yield 'a button of a Hangul filler' => [BotText::Cancel, "\u{3164}", 'نمی‌تواند خالی باشد'];
        yield 'a message of blank Braille in bold' => [BotText::MenuPrompt, "<b>\u{2800}\u{2800}</b>", 'نمی‌تواند خالی باشد'];
        // The HTML first: what strip_tags() would swallow is told as what it is, not as nothing.
        yield 'an unfinished tag alone' => [BotText::MenuPrompt, '<b پررنگ', 'تگ <b> با > بسته نشده است'];
        yield 'an empty premium emoji alone' => [BotText::MenuPrompt, '<tg-emoji emoji-id="5"></tg-emoji>', 'فقط یک ایموجی معمولی'];
        yield 'not a text' => [BotText::Welcome, ['x'], 'متن را وارد کنید'];
        yield 'a variable the text does not have' => [BotText::Welcome, 'سلام %plan%', 'متغیر %plan% در این متن وجود ندارد'];
        yield 'a variable in a text without any' => [BotText::MenuPrompt, 'سلام %name%', 'این متن متغیری ندارد'];
        yield 'the delivery without its link' => [BotText::PaySuccess, 'سرویس %client% آماده است', 'متغیر %subscription% باید در متن بماند'];
        yield 'HTML Telegram would refuse' => [BotText::Welcome, '<b>سلام %name%', 'بسته نشده'];
        yield 'a button on two lines' => [BotText::Cancel, "انصراف\nلغو", 'یک خط'];
        yield 'formatting in a popup' => [BotText::Error, '<b>خطا</b>', 'قالب‌بندی ندارد'];
        yield 'a button too long' => [BotText::Cancel, str_repeat('ا', TextKind::Button->limit() + 1), Validation::tooLong('متن', TextKind::Button->limit())];
        // As Telegram counts it: an emoji beyond the basic plane is two units — half as many fit.
        yield 'a button of emoji past its room' => [BotText::Cancel, str_repeat('😀', intdiv(TextKind::Button->limit(), 2) + 1), Validation::tooLong('متن', TextKind::Button->limit())];
        yield 'a message of emoji past its room' => [BotText::MenuPrompt, str_repeat('😀', intdiv(TextKind::Message->limit(), 2) + 1), Validation::tooLong('متن', TextKind::Message->limit())];
        // What the customer sees is counted: the tags cost nothing, the link's variable its own length.
        yield 'a caption too long' => [BotText::PaySuccess, str_repeat('ا', TextKind::Caption->limit() - 10) . ' <code>%subscription%</code>', Validation::tooLong('متن', TextKind::Caption->limit())];
    }

    #[DataProvider('refused')]
    public function testWhatTelegramOrTheCustomerCouldNotTakeIsRefused(BotText $text, mixed $value, string $says): void
    {
        try {
            $this->texts->save($text, $value);
            self::fail('saved');
        } catch (ValidationException $e) {
            self::assertStringContainsString($says, $e->errors()['value'][0]);
        }

        self::assertFalse($this->texts->isCustomized($text), 'nothing stored');
    }

    public function testATextIsFilledWithExactlyTheVariablesItOffers(): void
    {
        $this->expectException(\LogicException::class);

        $this->texts->render(BotText::Welcome, ['name' => 'Ali', 'plan' => 'x']);
    }

    public function testATextWithVariablesIsNeverSentUnfilled(): void
    {
        $this->expectException(\LogicException::class);

        $this->texts->get(BotText::Welcome);
    }

    public function testAPopupSentAsAMessageIsEscaped(): void
    {
        $this->texts->save(BotText::BotOff, 'ربات تا ساعت ۸ > خاموش است & بعد روشن می‌شود');

        self::assertSame('ربات تا ساعت ۸ &gt; خاموش است &amp; بعد روشن می‌شود', $this->texts->message(BotText::BotOff));
        self::assertSame('ربات تا ساعت ۸ > خاموش است & بعد روشن می‌شود', $this->texts->get(BotText::BotOff), 'a popup shows it as typed');
    }
}
