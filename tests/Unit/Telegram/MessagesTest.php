<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Modules\Telegram\Messages;
use App\Support\Traffic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one way the bot words traffic, terms and placeholders.
 */
final class MessagesTest extends TestCase
{
    /** @return iterable<string, array{int, string}> */
    public static function amounts(): iterable
    {
        yield 'gigabytes' => [30 * Traffic::GIGABYTE, '۳۰ گیگابایت'];
        yield 'with a fraction, Persian decimal mark' => [(int) (1.5 * Traffic::GIGABYTE), '۱٫۵ گیگابایت'];
        yield 'two decimals at most' => [(int) (1.2345 * Traffic::GIGABYTE), '۱٫۲۳ گیگابایت'];
        yield 'megabytes under a gigabyte' => [512 * Traffic::MEGABYTE, '۵۱۲ مگابایت'];
        yield 'under a megabyte' => [300 * 1024, '۰٫۲۹ مگابایت'];
        yield 'thousands' => [2048 * Traffic::GIGABYTE, '۲٬۰۴۸ گیگابایت'];
        yield 'nothing' => [0, '۰ مگابایت'];
    }

    #[DataProvider('amounts')]
    public function testBytesRead(int $bytes, string $expected): void
    {
        self::assertSame($expected, Messages::bytes($bytes));
    }

    public function testAQuotaReadsLikeAnAmountAndZeroIsUnlimited(): void
    {
        self::assertSame('۳۰ گیگابایت', Messages::traffic(30 * Traffic::GIGABYTE));
        self::assertSame('۵۱۲ مگابایت', Messages::traffic(512 * Traffic::MEGABYTE));
        self::assertSame(Messages::UNLIMITED, Messages::traffic(0));
        self::assertSame('۳۰ روز', Messages::duration(30));
        self::assertSame(Messages::UNLIMITED, Messages::duration(0));
    }

    public function testAPercentIsWholeAndRoundedDown(): void
    {
        self::assertSame('۸۳٪', Messages::percent(25, 30));
        self::assertSame('۱۰۰٪', Messages::percent(30, 30));
        self::assertSame('۰٪', Messages::percent(5, 0), 'nothing to share out');
    }

    public function testAGiftNamesWhatItBrings(): void
    {
        self::assertSame('۳ روز و ۱۰ گیگابایت', Messages::gift(3, 10 * Traffic::GIGABYTE));
        self::assertSame('۳ روز', Messages::gift(3, 0));
        self::assertSame('۱٫۵ گیگابایت', Messages::gift(0, (int) (1.5 * Traffic::GIGABYTE)));
    }

    public function testADeviceCountReadsWithItsUnitAndZeroIsUnlimited(): void
    {
        self::assertSame('۲ دستگاه', Messages::devices(2));
        self::assertSame(Messages::UNLIMITED, Messages::devices(0));
    }

    public function testFillFillsEachVariableAndNeverReadsAValueAgain(): void
    {
        self::assertSame('صفحه ۲ از ۵', Messages::fill('صفحه %page% از %pages%', ['page' => '۲', 'pages' => '۵']));
        self::assertSame('پلن x %balance% · ۱۰', Messages::fill('پلن %plan% · %balance%', ['plan' => 'x %balance%', 'balance' => '۱۰']), 'a plan named after a variable stays that');
        self::assertSame('٪۵۰ و 50%', Messages::fill('٪۵۰ و 50%'), 'a percent sign alone is text');
    }
}
