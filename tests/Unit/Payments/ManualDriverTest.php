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
use App\Modules\Payments\Models\PaymentCard;
use App\Modules\Payments\Models\PaymentMethod;
use App\Support\Persian;
use App\Support\Validation;
use Tests\TestCase;

/**
 * The card-to-card driver: its form (a note, a review window up to a week — the cards are rows of their own), settings
 * kept read back, no summary line of its own, and the gateway it makes, which shows a payment the card it was given.
 */
final class ManualDriverTest extends TestCase
{
    private const CARD = '6037997700001119';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app(); // the models' casts
    }

    public function testItIsACardTransferWhoseFormIsItsNoteAndItsReviewWindow(): void
    {
        $described = (new ManualDriver())->describe()->toArray();

        self::assertSame(['manual', 'کارت به کارت'], [$described['key'], $described['label']]);
        self::assertEquals((object) ['kind' => 'manual', 'builtin' => false], $described['traits']);
        $fields = array_column($described['fields'], null, 'name');
        self::assertSame(['instructions', 'auto_approve_after'], array_keys($fields), 'its cards are kept as a list of their own (PaymentCards)');
        self::assertSame(['textarea', false], [$fields['instructions']['type'], $fields['instructions']['required']]);
        $window = $fields['auto_approve_after'];
        self::assertSame(['number', false, 0, 7 * 24 * 60, 'دقیقه', 0], [$window['type'], $window['required'], $window['min'], $window['max'], $window['unit'], $window['default']]);
    }

    public function testEveryRefusalComesAtOnce(): void
    {
        $form = self::form();

        self::assertSame(['instructions' => '', 'auto_approve_after' => 0], $form->check([], self::nothingKept()), 'no note, no window: by hand');

        try {
            $form->check(['instructions' => str_repeat('ت', 1001), 'auto_approve_after' => '20000'], self::nothingKept());
            self::fail('refused');
        } catch (ValidationException $e) {
            self::assertSame([
                'instructions' => [Validation::tooLong('توضیحات', 1000)],
                'auto_approve_after' => ['مهلت تایید خودکار باید بر حسب دقیقه و حداکثر ' . Persian::minutes(7 * 24 * 60) . ' باشد؛ 0 یعنی فقط تایید دستی.'],
            ], $e->errors());
        }
    }

    public function testTheReviewWindowIsWholeMinutesWithinAWeekBlankByHand(): void
    {
        $window = static fn(mixed $typed): mixed => self::form()->check(['auto_approve_after' => $typed], self::nothingKept())['auto_approve_after'];

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

    public function testKeptSettingsTheFieldsWouldRefuseReadBackAsNothing(): void
    {
        $read = static fn(array $config): array => self::form()->values(static fn(Field $field): mixed => $config[$field->key] ?? null);

        self::assertSame(['instructions' => '', 'auto_approve_after' => 0], $read(['card_number' => self::CARD, 'card_holder' => 'AmoBot']), 'a row kept before the window existed, its card still in it: by hand, the card no setting');
        self::assertSame(0, $read(['auto_approve_after' => 99999])['auto_approve_after'], 'written by hand, refused by the field: none');

        self::assertSame(45, ManualGateway::reviewWindow(new PaymentMethod(['driver' => 'manual', 'config' => ['auto_approve_after' => '45']])));
        self::assertSame(0, ManualGateway::reviewWindow(new PaymentMethod(['driver' => 'manual', 'config' => []])));
    }

    public function testACardMethodHasNoLineOfItsOwnItsCardsDo(): void
    {
        self::assertNull((new ManualDriver())->summary(['instructions' => 'رسید را بفرستید.']));

        $card = new PaymentCard(['number' => self::CARD, 'holder' => 'AmoBot', 'bank' => 'ملت']);
        self::assertSame('6037 •••• •••• 1119 · AmoBot · ملت', $card->summary());
        self::assertSame('6037 •••• •••• 1119 · AmoBot', (new PaymentCard(['number' => self::CARD, 'holder' => 'AmoBot']))->summary(), 'no bank, no third part');
    }

    public function testItsGatewayHasTheCustomerPayToTheCardThePaymentWasShownAndAnApprovedTransferSettles(): void
    {
        $gateway = (new ManualDriver())->gateway(['instructions' => 'رسید را بفرستید.', 'auto_approve_after' => 0]);
        $payment = (new Payment())->setRelation('card', new PaymentCard(['number' => self::CARD, 'holder' => 'AmoBot']));

        $initiation = $gateway->initiate($payment);
        self::assertEquals(new CardTransfer(self::CARD, 'AmoBot', 'رسید را بفرستید.'), $initiation->transfer);
        self::assertTrue($gateway->settle(new Payment())->success);

        // Shown no card, taken in support's private chat: no card, the note all the same.
        self::assertEquals(CardTransfer::toSupport('رسید را بفرستید.'), $gateway->initiate((new Payment())->setRelation('card', null))->transfer);
        self::assertNull(CardTransfer::toSupport('')->card);
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
