<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Texts\TelegramHtml;
use App\Modules\Telegram\Texts\TextCatalog;
use PHPUnit\Framework\TestCase;

/**
 * The catalog describes every text the bot sends, and each default is a wording the admin could have
 * saved: it passes the same checks, and filled with the screen's sample values it is still Telegram HTML
 * with no variable left over.
 */
final class TextCatalogTest extends TestCase
{
    public function testEveryTextIsDescribedInAGroupOfTheScreen(): void
    {
        $groups = [];
        foreach (BotText::cases() as $text) {
            $spec = $text->spec();
            self::assertArrayHasKey($spec->group, TextCatalog::GROUPS, $text->value);
            self::assertNotSame('', $spec->title, $text->value);
            self::assertNotSame('', $spec->description, $text->value);
            $groups[$spec->group] = true;
        }

        self::assertSame(array_keys(TextCatalog::GROUPS), array_keys(array_intersect_key(TextCatalog::GROUPS, $groups)), 'no group is empty');
    }

    public function testEveryVariableMeansOneThingAndWhatIsRequiredIsOffered(): void
    {
        foreach (BotText::cases() as $text) {
            $spec = $text->spec();
            foreach ($spec->variables as $name => $description) {
                self::assertArrayHasKey($name, TextCatalog::VARIABLES, "{$text->value}: %{$name}%");
            }
            foreach ($spec->required as $name) {
                self::assertArrayHasKey($name, $spec->variables, "{$text->value} requires %{$name}%");
            }
        }
    }

    public function testEveryVariableOfTheDictionaryIsOfferedBySomeText(): void
    {
        $offered = [];
        foreach (BotText::cases() as $text) {
            $offered += $text->spec()->variables;
        }

        self::assertSame([], array_values(array_diff(array_keys(TextCatalog::VARIABLES), array_keys($offered))), 'a variable no text offers is a dead entry');
    }

    public function testEveryDefaultIsAWordingTheAdminCouldSave(): void
    {
        foreach (BotText::cases() as $text) {
            self::assertNull(BotTexts::problem($text->spec(), $text->spec()->default), $text->value);
        }
    }

    public function testACommandOrAHandleInAPersianDefaultReadsAsTyped(): void
    {
        $bare = [];
        foreach (BotText::cases() as $text) {
            // After a space: «/menu», «@BotFather» — not a link's «t.me/…» nor an address's «/…».
            if (preg_match('/(?<=\s)[\/@][A-Za-z]/u', $text->spec()->default) === 1) {
                $bare[] = $text->value;
            }
        }

        self::assertSame([], $bare, 'a left-to-right mark before each: right to left, «/menu» reads «menu/», «@BotFather» «BotFather@»');
        self::assertStringContainsString("\u{200E}/menu", BotText::ReceiptWaiting->spec()->default);
        self::assertStringContainsString("\u{200E}@BotFather با دستور \u{200E}/newbot", BotText::AgencyBotNone->spec()->default);
    }

    public function testARowNumberAfterWordsInADefaultReadsAsTyped(): void
    {
        $bare = [];
        $leading = [];
        foreach (BotText::cases() as $text) {
            $spec = $text->spec();
            $samples = [];
            foreach (array_keys($spec->variables) as $name) {
                $samples[$name] = TextCatalog::VARIABLES[$name][1];
            }
            // As written («#%order%») and as the customer gets it, filled with the samples (the orders' own «#142»).
            foreach ([$spec->default, Messages::fill($spec->default, $samples)] as $wording) {
                foreach (explode("\n", $wording) as $line) {
                    preg_match_all('/(?<!&)#(?=[%0-9۰-۹])/u', $line, $hashes, PREG_OFFSET_CAPTURE);
                    foreach ($hashes[0] as [, $at]) {
                        $before = substr($line, 0, $at);
                        if (self::hasWords($before) && !str_ends_with($before, "\u{200E}")) {
                            $bare[] = $text->value;
                        }
                    }
                    preg_match_all('/\x{200E}/u', $line, $marks, PREG_OFFSET_CAPTURE);
                    foreach ($marks[0] as [, $at]) {
                        if (!self::hasWords(substr($line, 0, $at))) {
                            $leading[] = $text->value;
                        }
                    }
                }
            }
        }

        self::assertSame([], array_values(array_unique($bare)), 'a left-to-right mark right before each «#» with words before it: right to left, «سفارش #142» reads «142# سفارش»');
        self::assertSame([], array_values(array_unique($leading)), 'never a mark with no words before it on its line: the line takes the direction of its first letter, and it would be the mark');
        self::assertStringContainsString("سفارش \u{200E}#%order%", BotText::PayProcessing->spec()->default);
        self::assertStringContainsString("<b>تیکت \u{200E}#%ticket%</b>", BotText::TicketScreen->spec()->default);
        self::assertStringNotContainsString("\u{200E}", BotText::TicketButtonOpen->spec()->default, 'a ticket\'s button starts with its number, which reads right as it is');
    }

    public function testEveryDefaultFilledWithTheSamplesIsStillTelegramHtml(): void
    {
        foreach (BotText::cases() as $text) {
            $spec = $text->spec();
            $samples = [];
            foreach (array_keys($spec->variables) as $name) {
                $samples[$name] = TextCatalog::VARIABLES[$name][1];
            }

            $filled = Messages::fill($spec->default, $samples);
            self::assertDoesNotMatchRegularExpression('/%[A-Za-z_][A-Za-z0-9_]*%/', $filled, $text->value);
            if ($spec->kind->html()) {
                self::assertNull(TelegramHtml::problem($filled), $text->value);
            }
        }
    }

    /** Whether words come before, as the customer sees the line: its tags and variables aside. */
    private static function hasWords(string $before): bool
    {
        return preg_match('/\p{L}/u', (string) preg_replace(['/<[^>]*>/', '/%[A-Za-z_][A-Za-z0-9_]*%/'], '', $before)) === 1;
    }
}
