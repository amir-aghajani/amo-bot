<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Modules\Telegram\Messages;
use App\Support\Persian;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * How the bot words traffic, terms (in days, hours or both), when a service ends, shares, gifts and
 * placeholders.
 */
final class MessagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app(); // the shop's zone the dates are read in
        Carbon::setTestNow('2026-09-20 12:00:00');
    }

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
    }

    public function testATermReadsInDaysInHoursOrBoth(): void
    {
        self::assertSame(
            [Messages::UNLIMITED, '۶ ساعت', '۱ روز', '۱ روز و ۶ ساعت', '۳۰ روز'],
            array_map(Messages::duration(...), [0, 6, 24, 30, 720]),
        );
    }

    public function testATermWaitingForTheFirstConnectionSaysHowLongItRuns(): void
    {
        self::assertSame('⏳ در انتظار اولین اتصال (۶ ساعت)', Messages::expiry(null, 6));
        self::assertSame('⏳ در انتظار اولین اتصال (۳۰ روز)', Messages::expiry(null, 720));
        self::assertSame(Messages::UNLIMITED, Messages::expiry(null, 0));
    }

    public function testTheLastDayIsCountedInHoursWithTheTimeItEnds(): void
    {
        $soon = now()->addHours(4)->addMinutes(10);
        self::assertSame(Persian::date($soon, withTime: true) . ' (۵ ساعت مانده)', Messages::expiry($soon, 6), 'rounded up, with the time of day');

        $later = now()->addDays(3)->addHours(2);
        self::assertSame(Persian::date($later) . ' (۴ روز مانده)', Messages::expiry($later, 720));

        $gone = now()->subHour();
        self::assertSame(Persian::date($gone) . ' (تمام شده)', Messages::expiry($gone, 6));
    }

    public function testAPercentIsWholeAndRoundedDown(): void
    {
        self::assertSame('۸۳٪', Messages::percent(25, 30));
        self::assertSame('۱۰۰٪', Messages::percent(30, 30));
        self::assertSame('۰٪', Messages::percent(5, 0), 'nothing to share out');
    }

    public function testAGiftNamesWhatItBringsItsDaysAsATerm(): void
    {
        self::assertSame('۳ روز و ۱۰ گیگابایت', Messages::gift(3, 10 * Traffic::GIGABYTE));
        self::assertSame('۳ روز', Messages::gift(3, 0));
        self::assertSame('۱٫۵ گیگابایت', Messages::gift(0, (int) (1.5 * Traffic::GIGABYTE)));
    }

    public function testADeviceCountReadsWithItsUnitAndZeroIsUnlimited(): void
    {
        self::assertSame('۲ کاربر', Messages::devices(2), 'one word for the device limit: a plan, a service, «کاربر اضافه»');
        self::assertSame(Messages::UNLIMITED, Messages::devices(0));
    }

    public function testFillFillsEachVariableAndNeverReadsAValueAgain(): void
    {
        self::assertSame('صفحه ۲ از ۵', Messages::fill('صفحه %page% از %pages%', ['page' => '۲', 'pages' => '۵']));
        self::assertSame('پلن x %balance% · ۱۰', Messages::fill('پلن %plan% · %balance%', ['plan' => 'x %balance%', 'balance' => '۱۰']), 'a plan named after a variable stays that');
        self::assertSame('٪۵۰ و 50%', Messages::fill('٪۵۰ و 50%'), 'a percent sign alone is text');
    }
}
