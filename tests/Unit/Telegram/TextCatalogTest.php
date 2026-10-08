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
}
