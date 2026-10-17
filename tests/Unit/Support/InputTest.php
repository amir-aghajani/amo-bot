<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Core\Exceptions\ValidationException;
use App\Support\Input;
use App\Support\Unit;
use App\Support\Validation;
use PHPUnit\Framework\TestCase;

/**
 * Loosely typed input read one way everywhere: text trimmed, numbers in any digits, amounts and switches as browsers,
 * JSON and the bot's customers send them — anything else read as nothing, never guessed at.
 */
final class InputTest extends TestCase
{
    public function testTextIsTrimmedAndAnythingButAScalarIsEmpty(): void
    {
        self::assertSame('علی', Input::text(['name' => '  علی '], 'name'));
        self::assertSame('12', Input::text(['name' => 12], 'name'));
        self::assertSame('', Input::text(['name' => ['علی']], 'name'));
        self::assertSame('', Input::text(['name' => null], 'name'));
        self::assertSame('', Input::text([], 'name'));
    }

    public function testTextThatIsNotUtf8IsRefusedUnderItsFieldWhateverReadsIt(): void
    {
        // A form's field or a query's value need not be UTF-8 (a JSON body always is): refused, never handed on.
        foreach (['text' => static fn() => Input::text(['body' => "سلام \xFF"], 'body'), 'a password' => static fn() => Input::password(['body' => "pass\xC3\x28word"], 'body'), 'a note' => static fn() => Input::note(['body' => "\xE2\x82"], 'body'), 'a secret' => static fn() => Input::secret(['body' => "\x80"], 'body', 'kept')] as $what => $read) {
            try {
                $read();
                self::fail("{$what}: taken");
            } catch (ValidationException $e) {
                self::assertSame(['body' => [Input::NOT_UTF8]], $e->errors(), $what);
            }
        }

        self::assertSame('سلام 😀', Input::text(['body' => ' سلام 😀 '], 'body'), 'UTF-8 of every plane is text');
    }

    public function testASwitchIsOnlyWhatBrowsersAndJsonSendAsOne(): void
    {
        foreach ([true, 1, '1', 'true', 'TRUE', 'on', 'yes'] as $on) {
            self::assertTrue(Input::truthy($on), var_export($on, true));
        }
        foreach ([false, 0, '0', 'false', 'off', '', null, 2, 'garbage'] as $off) {
            self::assertFalse(Input::truthy($off), var_export($off, true));
        }

        foreach ([true, false, 1, 0, '1', '0', 'true', 'False'] as $boolean) {
            self::assertTrue(Input::isBoolean($boolean), var_export($boolean, true));
        }
        foreach ([null, '', 'on', 'yes', 2, '2', [], 1.0] as $notOne) {
            self::assertFalse(Input::isBoolean($notOne), var_export($notOne, true) . ' is not an on/off: a switch sent so is refused, never read as "off"');
        }
    }

    public function testAWholeAmountTakesSeparatorsAUnitAndAZeroFractionButNotAFraction(): void
    {
        self::assertSame(150000, Input::amountOf('۱۵۰٬۰۰۰ تومان', Unit::Toman));
        self::assertSame(150000, Input::amountOf('150,000', Unit::Toman));
        self::assertSame(150000, Input::amountOf('150 000', Unit::Toman));
        self::assertSame(20, Input::amountOf('۲۰ گیگ', Unit::Gigabyte));
        self::assertSame(20, Input::amountOf('20گیگ', Unit::Gigabyte), 'the unit right after the number');
        self::assertSame(999_999_999_999, Input::amountOf('999999999999', Unit::Toman));
        self::assertSame(50000, Input::amountOf('50000.00', Unit::Toman), "the Store API's own form of an amount: a fraction of zero");
        self::assertSame(50000, Input::amountOf('50000.0', Unit::Toman));

        foreach (['1.5', '۱٫۵ گیگ', '10 20', 'تومان', '', '1,50', '۱٬۵', '1000000000000', '-5', 'ده هزار', '50000.50', '150.000', '50000.'] as $typed) {
            foreach (Unit::cases() as $unit) {
                self::assertNull(Input::amountOf($typed, $unit), "{$typed} ({$unit->name})");
            }
        }
        self::assertNull(Input::amountOf(['150000'], Unit::Toman));
    }

    /** «هزار» and «میلیون» multiply, as a customer means them; a word it does not know is no unit to read past. */
    public function testThousandsAndMillionsCountAndAnUnknownWordIsNoUnit(): void
    {
        self::assertSame(50_000, Input::amountOf('۵۰ هزار', Unit::Toman), 'never 50');
        self::assertSame(50_000, Input::amountOf('۵۰ هزار تومان', Unit::Toman));
        self::assertSame(50_000, Input::amountOf('50هزارتومن', Unit::Toman));
        self::assertSame(50_000, Input::amountOf("۵۰ هزار\u{200C}تومان", Unit::Toman), 'a zero-width non-joiner between the words');
        self::assertSame(2_000_000, Input::amountOf('۲ میليون', Unit::Toman), 'an Arabic keyboard\'s yeh');
        self::assertSame(1_500_000, Input::amountOf('1,500 هزار', Unit::Toman));
        self::assertSame(
            [30, 2, 2, 20, 20],
            [Input::amountOf('۳۰ روز', Unit::Day), Input::amountOf('۲ کاربره', Unit::Device), Input::amountOf('2 كاربر', Unit::Device), Input::amountOf('20 GB', Unit::Gigabyte), Input::amountOf('۲۰ گیگابایت', Unit::Gigabyte)],
            'each step\'s own unit',
        );
        self::assertSame(999_999_000_000, Input::amountOf('999999 میلیون', Unit::Toman));
        self::assertSame(
            [20, 2, 50_000, 50_000],
            [Input::amountOf('۲۰ گیگی', Unit::Gigabyte), Input::amountOf('۲ نفره', Unit::Device), Input::amountOf('۵۰ هزار تومانی', Unit::Toman), Input::amountOf('50000 تومنی', Unit::Toman)],
            'a unit as a customer says it of a service («سرویس ۲۰ گیگی»), as read before units were known',
        );

        foreach (['۵۰۰ ریال', '۲ ساعت', '۱ ماه', '50k', '۵۰ هزار ریال', '۵۰ تومان هزار', '1000000 میلیون', '۵۰ هزار هزار'] as $typed) {
            self::assertNull(Input::amountOf($typed, Unit::Toman), $typed);
        }
    }

    /** Each step reads its own unit's words and no other's: «۲۰ روز» typed where GB are awaited is no 20 GB. */
    public function testAnotherUnitsWordIsNoAmount(): void
    {
        $typed = [
            Unit::Toman->name => ['۲۰ تومان', '۲۰ ت', '20 toman'],
            Unit::Gigabyte->name => ['۲۰ گیگ', '۲۰ گیگا', '۲۰ گیگی', '20 GB', '20g'],
            Unit::Day->name => ['۲۰ روز', '۲۰ روزه', '20 days', '20 day'],
            Unit::Device->name => ['۲۰ کاربر', '۲۰ کاربره', '۲۰ نفر', '۲۰ نفره', '۲۰ دستگاه', '20 devices', '20 users'],
        ];
        foreach (Unit::cases() as $unit) {
            foreach ($typed as $words => $amounts) {
                foreach ($amounts as $amount) {
                    self::assertSame($words === $unit->name ? 20 : null, Input::amountOf($amount, $unit), "{$amount} read as {$unit->name}");
                }
            }
        }
    }

    /**
     * Money and GB are counted in thousands («۵ هزار گیگ»: an agent buys traffic so), money alone in millions; days and
     * devices are typed as digits.
     */
    public function testOnlyMoneyAndTrafficAreCountedInThousands(): void
    {
        self::assertSame(
            [5_000, 5_000, 2_000, 2_000, 2_000_000],
            [Input::amountOf('۵ هزار گیگ', Unit::Gigabyte), Input::amountOf('5هزارگیگابایت', Unit::Gigabyte), Input::amountOf('۲ هزار', Unit::Gigabyte), Input::amountOf('۲ هزار', Unit::Toman), Input::amountOf('۲ میلیون', Unit::Toman)],
        );

        foreach (['۲ میلیون گیگ' => Unit::Gigabyte, '۲ میلیون' => Unit::Gigabyte, '۵ هزار تومان' => Unit::Gigabyte, '۳ هزار روز' => Unit::Day, '۲ هزار کاربر' => Unit::Device, '۲ میلیون کاربر' => Unit::Device] as $typed => $unit) {
            self::assertNull(Input::amountOf($typed, $unit), $typed);
        }
        self::assertNull(Input::amountOf('999999999999 هزار گیگ', Unit::Gigabyte), 'past twelve digits once multiplied: no amount');
    }

    /** Whatever space a person pastes between groups of digits sets them apart as a plain one does. */
    public function testAnySpaceSetsDigitGroupsApart(): void
    {
        foreach (["150\u{00A0}000", "150\u{2009}000", "150\u{202F}000", "\u{00A0}150 000\u{00A0}"] as $typed) {
            self::assertSame(150_000, Input::amountOf($typed, Unit::Toman), json_encode($typed, JSON_THROW_ON_ERROR));
            self::assertSame(150_000, Input::wholeOf($typed));
            self::assertSame('150000', Input::decimalOf($typed));
        }
        self::assertSame(150_000, Input::amountOf("۱۵۰\u{00A0}۰۰۰\u{00A0}تومان", Unit::Toman));
        self::assertNull(Input::amountOf("15\u{00A0}00", Unit::Toman), 'a group of three, still');
        self::assertNull(Input::amountOf("150000\xFF", Unit::Toman), 'text that is not UTF-8 is no amount');
    }

    /** A JSON `true` is no id 1 — nor any number: a boolean reads as nothing wherever a number is read. */
    public function testABooleanIsNoNumber(): void
    {
        foreach ([true, false] as $boolean) {
            self::assertSame([null, null, null, null], [Input::integerOf($boolean), Input::amountOf($boolean, Unit::Toman), Input::wholeOf($boolean), Input::decimalOf($boolean)], var_export($boolean, true));
        }
        self::assertNull(Input::integer(['plan_id' => true], 'plan_id'));
        self::assertFalse(Input::optionalAmountOf(true, 1, 1000));
    }

    public function testMoneyTheAdminTypesIsAWholeAmountOfToman(): void
    {
        self::assertSame(120000, Input::amount(['price' => '120000'], 'price'));
        self::assertSame(120000, Input::amount(['price' => '۱۲۰٬۰۰۰ تومان'], 'price'));
        self::assertSame(120000, Input::amount(['price' => '120000.00'], 'price'), "the API's own form, sent back as it came");
        self::assertSame(0, Input::amount(['price' => '0'], 'price'), 'a free plan');
        self::assertSame(120000, Input::amount(['price' => 120000], 'price'));

        foreach (['99999.50', '99999.5', '120000.01', 99999.5, '1,5'] as $typed) {
            self::assertNull(Input::amount(['price' => $typed], 'price'), 'no fraction of a Toman, which the bot would round away: ' . $typed);
        }
        self::assertNull(Input::amount([], 'price'));
        foreach (['۲۰ گیگ', '۳۰ روز'] as $typed) {
            self::assertNull(Input::amount(['price' => $typed], 'price'), 'no price in another unit: ' . $typed);
            self::assertFalse(Input::optionalAmountOf($typed, 1, 1000), $typed);
        }
    }

    public function testMoneyLeftBlankIsNoneAndAnythingElseIsWholeTomanWithinItsBounds(): void
    {
        foreach ([null, '', '   '] as $blank) {
            self::assertNull(Input::optionalAmountOf($blank, 1, 1000), 'none: ' . var_export($blank, true));
        }
        self::assertSame('120.00', Input::optionalAmountOf('۱۲۰ تومان', 1, 1000));
        self::assertSame('1000.00', Input::optionalAmountOf(1000, 1, 1000), 'the most itself');
        self::assertSame('0.00', Input::optionalAmountOf('0', 0, 1000), 'zero, where the least allows it');

        foreach (['0', '1001', '12.5', 'abc', '-5', ['120']] as $refused) {
            self::assertFalse(Input::optionalAmountOf($refused, 1, 1000), 'refused: ' . var_export($refused, true));
        }
    }

    public function testIntegersAreNonNegativeWholeNumbersInAnyDigits(): void
    {
        self::assertSame(30, Input::integer(['days' => '30'], 'days'));
        self::assertSame(30, Input::integer(['days' => '۳۰'], 'days'));
        self::assertSame(30, Input::integer(['days' => ' ٣٠ '], 'days'), 'Arabic-Indic digits, trimmed');
        self::assertSame(30, Input::integer(['days' => 30], 'days'));
        self::assertSame(30, Input::integer(['days' => 30.0], 'days'), 'a whole float is the same number');
        self::assertNull(Input::integer(['days' => 30.5], 'days'), 'a fraction is not an integer, however it was sent');
        self::assertNull(Input::integer(['days' => -1], 'days'));
        self::assertNull(Input::integer(['days' => '-1'], 'days'));
        self::assertNull(Input::integer(['days' => '1234567890'], 'days'), 'ten digits is past what any field takes');
        self::assertNull(Input::integer(['days' => ['30']], 'days'));
        self::assertNull(Input::integer([], 'days'));
        self::assertSame(5, Input::integerOf('۵'));
        self::assertNull(Input::integerOf(null));
    }

    public function testATelegramIdIsAPositiveNumberOfUpTo16DigitsInAnyDigits(): void
    {
        self::assertSame([7070, 7070, 7070, 1234567890123456], [Input::telegramIdOf(7070), Input::telegramIdOf(' ۷۰۷۰ '), Input::telegramIdOf('٧٠٧٠'), Input::telegramIdOf('1234567890123456')]);
        foreach (['', '0', '-7070', '07070', '@ali', '70 70', '7070.0', '12345678901234567', '1e5'] as $typed) {
            self::assertNull(Input::telegramIdOf($typed), $typed);
        }
        self::assertSame([null, null, null, null], [Input::telegramIdOf(true), Input::telegramIdOf(7070.0), Input::telegramIdOf(null), Input::telegramIdOf(['7070'])], 'a boolean, a float, nothing or a list is no id');
    }

    public function testAWholeNumberAnAdminTypesSetsItsThousandsApartAsAnAmountDoes(): void
    {
        self::assertSame(10000, Input::wholeOf('10,000'));
        self::assertSame(10000, Input::wholeOf('۱۰٬۰۰۰'));
        self::assertSame(10000, Input::wholeOf('10 000'));
        self::assertSame(10000, Input::wholeOf(10000));
        self::assertSame(45, Input::wholeOf('۴۵'), 'what integerOf() takes, it takes');

        foreach (['1,5', '10,00', '1.5', '10000.00', '10000 تومان', '-5', 'ده', '', '1234567890', '1,234,567,890'] as $typed) {
            self::assertNull(Input::wholeOf($typed), $typed);
        }
    }

    public function testATypedListIsApartBySpacesPersianCommasAndCommasThatSetNoThousands(): void
    {
        self::assertSame(['50,000', '100,000'], Input::numbersOf('50,000, 100,000'));
        self::assertSame(['50000', '100000'], Input::numbersOf('50000,100000'), 'no group of three: the comma is between two numbers');
        self::assertSame(['50,000', '100,000'], Input::numbersOf('۵۰٬۰۰۰، ۱۰۰٬۰۰۰'));
        self::assertSame(['1', '5'], Input::numbersOf('1,5'), 'never 15');
        self::assertSame(['100', '20', '50'], Input::numbersOf(' 100، 20 ,, 50 '));
        self::assertSame([], Input::numbersOf(''));
        self::assertSame(["50\xFF, 60"], Input::numbersOf("50\xFF, 60"), 'text that is not UTF-8: one part no number reads, never an empty list that clears the field');
        self::assertNull(Input::wholeOf(Input::numbersOf("50\xFF, 60")[0]));
    }

    public function testDecimalsAreAmountsWithUpToTwoPlacesAndSeparatorsTolerated(): void
    {
        self::assertSame('120000', Input::decimal(['price' => '120,000'], 'price'));
        self::assertSame('120000', Input::decimal(['price' => '۱۲۰٬۰۰۰'], 'price'));
        self::assertSame('30.5', Input::decimal(['price' => '۳۰٫۵'], 'price'));
        self::assertSame('30.5', Input::decimal(['price' => 30.5], 'price'));
        self::assertSame('7', Input::decimal(['price' => 7], 'price'));
        self::assertNull(Input::decimal(['price' => 1e20], 'price'), 'a float in exponent form is not an amount');
        self::assertNull(Input::decimal(['price' => '1.234'], 'price'), 'three decimals');
        self::assertNull(Input::decimal(['price' => '-5'], 'price'));
        self::assertNull(Input::decimal(['price' => 'abc'], 'price'));
        self::assertSame('12.5', Input::decimalOf('12.5'));
        self::assertNull(Input::decimalOf(false));
    }

    public function testASeparatorStandsOnlyBetweenGroupsOfThreeDigits(): void
    {
        self::assertSame('1500000', Input::decimalOf('1,500,000'));
        self::assertSame('1500', Input::decimalOf('1 500'));
        self::assertSame('1500.5', Input::decimalOf('1,500.5'));
        self::assertSame('1500', Input::decimalOf('۱٬۵۰۰'));

        // A misplaced separator is no number — never one ten times as large (a plan's "2,5" GB is not 25).
        foreach (['2,5', '1,5', '۱٬۵', '15 00', '1,50,000', ',500', '1500,', '1,500,00'] as $typed) {
            self::assertNull(Input::decimalOf($typed), $typed);
        }
        self::assertNull(Input::decimalOf('1,234,567,890,123'), 'past twelve digits, set apart or not');
    }

    public function testANoteIsTrimmedNullWhenBlankAndRefusedPastItsLimit(): void
    {
        self::assertSame('تکراری بود', Input::note(['note' => ' تکراری بود '], 'note'));
        self::assertNull(Input::note(['note' => '   '], 'note'));
        self::assertNull(Input::note([], 'note'));
        self::assertSame(str_repeat('x', 300), Input::note(['note' => str_repeat('x', 300)], 'note'), 'the default limit is inclusive');

        try {
            Input::note(['reason' => str_repeat('x', 11)], 'reason', 10);
            self::fail('a note past the limit is refused');
        } catch (ValidationException $e) {
            self::assertSame(['reason' => [Validation::tooLong('توضیح', 10)]], $e->errors());
        }
    }

    public function testASecretLeftBlankKeepsTheStoredOneUnlessItIsCleared(): void
    {
        self::assertSame('stored', Input::secret([], 'password', 'stored'), 'not sent');
        self::assertSame('stored', Input::secret(['password' => '  '], 'password', 'stored'), 'left blank');
        self::assertSame('stored', Input::secret(['password' => ['x']], 'password', 'stored'), 'not a value at all');
        self::assertSame('new one', Input::secret(['password' => ' new one '], 'password', 'stored'));
        self::assertSame('1234', Input::secret(['password' => 1234], 'password', 'stored'), 'a number sent as one');
        self::assertSame('', Input::secret(['password' => 'ignored', 'clear_password' => true], 'password', 'stored'));
    }

    public function testTheActiveSwitchFallsBackToTheRowThenToOn(): void
    {
        self::assertFalse(Input::active(['is_active' => false], null));
        self::assertTrue(Input::active(['is_active' => 'on'], false));
        self::assertFalse(Input::active([], false), 'an edit that leaves the switch out keeps the row as it is');
        self::assertTrue(Input::active([], null), 'a new row starts active');
        self::assertTrue(Input::active(['is_active' => null], null), 'null is "not sent"');
    }

    /** A review's and a ticket's rating, one rule: 1 to 5 stars, in any digits. */
    public function testARatingIsOneToFiveStars(): void
    {
        self::assertSame([1, 5, 4], [Validation::rating(1), Validation::rating('5'), Validation::rating('۴')]);
        foreach ([0, 6, '3.5', true, null, '', [5]] as $refused) {
            self::assertNull(Validation::rating($refused), var_export($refused, true));
        }
    }

    public function testTooLongIsWordedOnceWithLatinDigits(): void
    {
        self::assertSame('نام حداکثر 60 کاراکتر است.', Validation::tooLong('نام', 60));
    }
}
