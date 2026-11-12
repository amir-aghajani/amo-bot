<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Modules\Telegram\Texts\PremiumText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A name as the bot writes it — its leading emoji the premium one the shop chose — and what of a name and its premium
 * emoji the admin is told to change. Plain strings only: a model made here, before the database boots, would be booted
 * without its events — BelongsToBot's `creating` hook then missing for the rest of the run. A plan's and a category's
 * names are the bot's tests' (PremiumEmojiTest, BotPlanButtonsTest).
 */
final class PremiumTextTest extends TestCase
{
    public function testANamesLeadingEmojiIsDrawnPremiumWhenTheShopChoseOne(): void
    {
        self::assertSame('<tg-emoji emoji-id="5368324170671202286">🔥</tg-emoji> طلایی', PremiumText::name('🔥 طلایی', '5368324170671202286')->text);
        self::assertSame('<tg-emoji emoji-id="7">🛍️</tg-emoji>ویژه', PremiumText::name('🛍️ویژه', '7')->text, 'a symbol with its variation mark, whole');
        self::assertSame('🔥 طلایی', PremiumText::name('🔥 طلایی', null)->text, 'none chosen');
        self::assertSame('طلایی 🔥', PremiumText::name('طلایی 🔥', '7')->text, 'no emoji to stand for: as it is');
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function leading(): iterable
    {
        yield 'a pictograph' => ['🔥 طلایی', '🔥'];
        yield 'a symbol with its variation mark' => ['⬅️ قبلی', '⬅️'];
        yield 'a joined emoji' => ['👨‍💻 برنامه‌نویس', '👨‍💻'];
        yield 'a toned emoji' => ['👍🏽 خوب', '👍🏽'];
        yield 'a flag' => ['🇩🇪 آلمان', '🇩🇪'];
        yield 'a keycap' => ['1️⃣ اول', '1️⃣'];
        yield 'two, the first alone' => ['🔥🔥 داغ', '🔥'];
        yield 'words first' => ['طلایی 🔥', null];
        yield 'a digit' => ['1 ماهه', null];
        yield 'nothing' => ['', null];
    }

    #[DataProvider('leading')]
    public function testANamesLeadingEmojiIsOneAsAReaderSeesIt(string $name, ?string $emoji): void
    {
        self::assertSame($emoji, PremiumText::leadingEmoji($name));
    }

    public function testTheRequestsPremiumEmojiIsTakenKeptOrRefused(): void
    {
        $take = static function (array $input, string $name, ?string $had) use (&$errors): ?string {
            $errors = [];

            return PremiumText::nameEmoji($input, $name, $had, $errors);
        };

        self::assertSame('7', $take(['emoji_id' => '7'], '🔥 طلایی', null));
        self::assertSame([], $errors);
        self::assertNull($take(['emoji_id' => ''], '🔥 طلایی', '7'), 'blank: none');
        self::assertNull($take(['emoji_id' => null], '🔥 طلایی', '7'), 'null: none');
        self::assertSame('7', $take([], '🔥 طلایی', '7'), 'left out: its own');
        self::assertNull($take([], 'طلایی', '7'), 'left out, its name without an emoji now: nothing left to stand for');
        self::assertSame([], $errors);

        self::assertNull($take(['emoji_id' => '7'], 'طلایی', null));
        self::assertSame(['name' => [PremiumText::NO_EMOJI]], $errors);
        self::assertNull($take(['emoji_id' => 'x7'], '🔥 طلایی', null));
        self::assertSame(['emoji_id' => [PremiumText::ID_REFUSED]], $errors);
        self::assertNull($take(['emoji_id' => 7], '🔥 طلایی', null), 'an id is a string: a JSON number past 2^53 would not survive');
        self::assertSame(['emoji_id' => [PremiumText::ID_REFUSED]], $errors);
        self::assertNull($take([], '<tg-emoji emoji-id="7">🔥</tg-emoji> طلایی', null));
        self::assertSame(['name' => [PremiumText::TAG_IN_NAME]], $errors);
    }
}
