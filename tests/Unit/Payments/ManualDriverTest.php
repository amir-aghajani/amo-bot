<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use App\Core\Forms\Form;
use App\Modules\Payments\Drivers\Manual\ManualDriver;
use App\Modules\Payments\Drivers\Manual\ManualGateway;
use App\Modules\Payments\DTO\CardTransfer;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Support\Persian;
use App\Support\Validation;
use Tests\TestCase;

/**
 * The card-to-card driver: its form — the card's number by BankCard's rule, whose card it is, the note under it, and the
 * review window in minutes up to a week, blank by hand —, a kept card read back by it, the line a card method shows, and
 * the gateway it makes of a method's settings.
 */
final class ManualDriverTest extends TestCase
{
    private const CARD = '6037997700001119';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app(); // the models' casts
    }

    public function testItIsACardTransferWhoseFormIsTheCardAndItsReviewWindow(): void
    {
        $described = (new ManualDriver())->describe()->toArray();

        self::assertSame(['manual', 'کارت به کارت'], [$described['key'], $described['label']]);
        self::assertEquals((object) ['kind' => 'manual', 'builtin' => false], $described['traits']);
        $fields = array_column($described['fields'], null, 'name');
        self::assertSame(['card_number', 'card_holder', 'instructions', 'auto_approve_after'], array_keys($fields));
        self::assertSame(['card', true, true], [$fields['card_number']['type'], $fields['card_number']['required'], $fields['card_number']['ltr']], "a card's number, Latin digits drawn left to right");
        self::assertSame(['text', true], [$fields['card_holder']['type'], $fields['card_holder']['required']]);
        self::assertSame(['textarea', false], [$fields['instructions']['type'], $fields['instructions']['required']]);
        $window = $fields['auto_approve_after'];
        self::assertSame(['number', false, 0, 7 * 24 * 60, 'دقیقه', 0], [$window['type'], $window['required'], $window['min'], $window['max'], $window['unit'], $window['default']]);
    }

    public function testACardIsItsSixteenDigitsAndEveryRefusalComesAtOnce(): void
    {
        $form = self::form();

        self::assertSame(
            ['card_number' => self::CARD, 'card_holder' => 'سارا احمدی', 'instructions' => '', 'auto_approve_after' => 0],
            $form->check(['card_number' => '۶۰۳۷-۹۹۷۷ ۰۰۰۰ ۱۱۱۹', 'card_holder' => ' سارا احمدی '], self::nothingKept()),
            'Persian digits, spaces and dashes normalised away; no window: by hand',
        );

        try {
            $form->check(['card_number' => '6037 9977 0000 1111', 'instructions' => str_repeat('ت', 1001), 'auto_approve_after' => '20000'], self::nothingKept());
            self::fail('refused');
        } catch (ValidationException $e) {
            self::assertSame([
                'card_number' => ['شماره کارت باید 16 رقم و معتبر باشد.'],
                'card_holder' => ['نام صاحب کارت را وارد کنید.'],
                'instructions' => [Validation::tooLong('توضیحات', 1000)],
                'auto_approve_after' => ['مهلت تایید خودکار باید بر حسب دقیقه و حداکثر ' . Persian::minutes(7 * 24 * 60) . ' باشد؛ 0 یعنی فقط تایید دستی.'],
            ], $e->errors());
        }

        try {
            $form->check(['card_number' => 'abc', 'card_holder' => 'AmoBot'], self::nothingKept());
            self::fail('no digits, no card');
        } catch (ValidationException $e) {
            self::assertSame(['card_number' => ['شماره کارت را وارد کنید.']], $e->errors());
        }
    }

    public function testTheReviewWindowIsWholeMinutesWithinAWeekBlankByHand(): void
    {
        $window = static fn(mixed $typed): mixed => self::form()->check(['card_number' => self::CARD, 'card_holder' => 'AmoBot', 'auto_approve_after' => $typed], self::nothingKept())['auto_approve_after'];

        self::assertSame([0, 0, 30, 30, 10080], [$window(''), $window(null), $window('۳۰'), $window(30), $window('10080')]);
        foreach (['-5', 'abc', '1.5', '10081'] as $refused) {
            try {
                $window($refused);
                self::fail("{$refused} is no window");
            } catch (ValidationException $e) {
                self::assertSame(['auto_approve_after'], array_keys($e->errors()), $refused);
            }
        }
    }

    public function testAKeptCardReadsBackOneTheFieldWouldRefuseAsNothing(): void
    {
        $read = static fn(array $config): array => self::form()->values(static fn(Field $field): mixed => $config[$field->key] ?? null);

        self::assertSame(
            ['card_number' => self::CARD, 'card_holder' => 'AmoBot', 'instructions' => '', 'auto_approve_after' => 0],
            $read(['card_number' => self::CARD, 'card_holder' => 'AmoBot']),
            'a row kept before the window existed: by hand',
        );
        $byHand = $read(['card_number' => '1234', 'auto_approve_after' => 99999]);
        self::assertSame(['', 0], [$byHand['card_number'], $byHand['auto_approve_after']], 'written by hand, refused by the fields: none');

        self::assertSame(45, ManualGateway::reviewWindow(new PaymentMethod(['driver' => 'manual', 'config' => ['auto_approve_after' => '45']])));
        self::assertSame(0, ManualGateway::reviewWindow(new PaymentMethod(['driver' => 'manual', 'config' => []])));
    }

    public function testACardMethodsLineIsItsMaskedCardAndItsHolder(): void
    {
        $driver = new ManualDriver();

        self::assertSame('6037 •••• •••• 1119 · AmoBot', $driver->summary(['card_number' => self::CARD, 'card_holder' => 'AmoBot']));
        self::assertSame('6037 •••• •••• 1119', $driver->summary(['card_number' => self::CARD, 'card_holder' => '']));
        self::assertNull($driver->summary(['card_number' => '', 'card_holder' => 'AmoBot']), 'no card, no line');
    }

    public function testItsGatewayHasTheCustomerPayToTheCardAndAnApprovedTransferSettles(): void
    {
        $gateway = (new ManualDriver())->gateway(['card_number' => self::CARD, 'card_holder' => 'AmoBot', 'instructions' => 'رسید را بفرستید.', 'auto_approve_after' => 0]);

        $initiation = $gateway->initiate();
        self::assertFalse($initiation->isInstant());
        self::assertEquals(new CardTransfer(self::CARD, 'AmoBot', 'رسید را بفرستید.'), $initiation->transfer);
        self::assertTrue($gateway->settle(new Payment())->success);
    }

    private static function form(): Form
    {
        return (new ManualDriver())->describe()->form;
    }

    /** @return \Closure(Field<mixed>): mixed A method not made yet: nothing kept */
    private static function nothingKept(): \Closure
    {
        return static fn(Field $field): mixed => null;
    }
}
