<?php

declare(strict_types=1);

namespace Tests\Unit\Subscriptions;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Subscriptions\Forms\ExtraOptions;
use App\Modules\Subscriptions\Services\ExtrasSettings;
use Tests\TestCase;

/**
 * A «حجم و زمان اضافه» price list (the shop's or a plan's) as the admin types it: per kind the unit price, the amounts
 * offered (each at its own price or the unit price), the least and most typed; every refusal at once under its field; a
 * stored list the form would refuse reads as nothing sold.
 */
final class ExtraPricesFormTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app(); // the plan's casts
    }

    public function testAPriceListIsReadAsTheAdminTypesIt(): void
    {
        $kept = ExtrasSettings::planForm()->check([
            'traffic_price' => '۳٬۰۰۰',
            'traffic_options' => [['quantity' => '۲۰', 'price' => '50,000'], ['quantity' => '', 'price' => ' '], ['quantity' => 10, 'price' => null]],
            'traffic_min' => '5',
            'traffic_max' => '۲۰۰',
            'time_price' => '',
            'time_options' => [['quantity' => 30, 'price' => '۹۰٬۰۰۰ تومان'], ['quantity' => '7', 'price' => '25000']],
            'time_min' => 1,
            'time_max' => 30,
        ], self::nothingKept());

        self::assertSame([
            'traffic_price' => '3000.00',
            'traffic_options' => [['quantity' => 10, 'price' => null], ['quantity' => 20, 'price' => '50000.00']],
            'traffic_min' => 5,
            'traffic_max' => 200,
            'time_price' => null,
            'time_options' => [['quantity' => 7, 'price' => '25000.00'], ['quantity' => 30, 'price' => '90000.00']],
            'time_min' => 1,
            'time_max' => 30,
        ], $kept, 'Persian digits and separators, a row left blank is nothing, the smallest first; blank — no unit price');
    }

    public function testEveryRefusalIsSaidAtOnceUnderItsField(): void
    {
        $errors = self::refused([
            'traffic_price' => '0',
            'traffic_options' => [['quantity' => 10, 'price' => ''], ['quantity' => '۱۰', 'price' => '']],
            'traffic_min' => 50,
            'traffic_max' => 20,
            'time_price' => '',
            'time_options' => [['quantity' => 7, 'price' => ''], ['quantity' => 30, 'price' => '120000']],
            'time_min' => 1,
            'time_max' => 400,
        ]);

        self::assertSame([
            'time_max' => ['بیشترین زمان دلخواه باید عددی بین 1 تا 365 روز باشد.'],
            'time_options' => ['۷ روز قیمت ندارد؛ قیمت آن، یا قیمت هر روز را بنویسید.'],
            'traffic_max' => ['بیشترین حجم دلخواه نمی‌تواند از کمترین حجم دلخواه کمتر باشد.'],
            'traffic_options' => ['۱۰ گیگابایت دو بار آمده است.'],
            'traffic_price' => ['قیمت هر گیگابایت باید مبلغی بین ۱ تومان تا ۱۰۰٬۰۰۰٬۰۰۰ تومان باشد، یا خالی بماند.'],
        ], $errors);
    }

    public function testTheAmountsOfferedAreHeldToTheirBoundsTheirPricesAndTheirNumber(): void
    {
        $form = ['traffic_price' => '1000', 'traffic_min' => 1, 'traffic_max' => 100, 'time_price' => '1000', 'time_min' => 1, 'time_max' => 30];
        $options = static fn(array $rows): array => self::refused($form + ['traffic_options' => $rows, 'time_options' => []]);

        self::assertSame(['traffic_options' => ['حجم‌های پیشنهادی باید بین 1 تا 10000 گیگابایت باشند.']], $options([['quantity' => 10001, 'price' => '']]));
        self::assertSame(['traffic_options' => ['حجم‌های پیشنهادی باید بین 1 تا 10000 گیگابایت باشند.']], $options([['quantity' => '2.5', 'price' => '']]), 'whole GB');
        self::assertSame(['traffic_options' => ['قیمت ۱۰ گیگابایت باید مبلغی بین ۱ تومان تا ۱۰۰٬۰۰۰٬۰۰۰ تومان باشد، یا خالی بماند تا به قیمت هر گیگابایت فروخته شود.']], $options([['quantity' => 10, 'price' => '-5']]));
        self::assertSame(['traffic_options' => ['حجم‌های پیشنهادی باید فهرستی از مقدار و قیمت باشد.']], $options(['10 GB']));
        $nine = array_map(static fn(int $gb): array => ['quantity' => $gb, 'price' => ''], range(1, ExtraOptions::MOST + 1));
        self::assertSame(['traffic_options' => ['حجم‌های پیشنهادی حداکثر 8 مورد است.']], $options($nine));

        $refusedUnitPrice = self::refused(['traffic_price' => 'رایگان', 'traffic_options' => [['quantity' => 10, 'price' => '']]] + $form + ['time_options' => []]);
        self::assertSame(['traffic_price'], array_keys($refusedUnitPrice), 'a unit price refused says so itself: the amounts are not asked for one too');
    }

    public function testAListKeptThatTheFormWouldRefuseReadsAsNothingSold(): void
    {
        $plan = (new Plan())->forceFill(['extras' => [
            'traffic_price' => '0',
            'traffic_options' => [['quantity' => 20000, 'price' => null]],
            'traffic_min' => 5,
            'traffic_max' => 100,
            'time_price' => '1500.00',
            'time_options' => 'garbage',
            'time_min' => 'x',
            'time_max' => 30,
        ]]);

        $prices = ExtrasSettings::planPrices($plan) ?? self::fail('the plan has prices of its own');

        self::assertSame([null, []], [$prices->traffic->price, $prices->traffic->options], 'a unit price of nothing, an amount past the most');
        self::assertFalse($prices->traffic->sells());
        self::assertSame(['1500.00', [], 1, 30], [$prices->time->price, $prices->time->options, $prices->time->min, $prices->time->max], 'each field on its own: the rest stands');
        self::assertSame('15000.00', $prices->time->priceOf(10));

        self::assertNull(ExtrasSettings::planPrices((new Plan())->forceFill(['extras' => null])), "no list of its own: the shop's");
    }

    /**
     * What the plan's price list was refused with, by field, in the order of their names.
     *
     * @param array<string, mixed> $input
     * @return array<string, list<string>>
     */
    private static function refused(array $input): array
    {
        try {
            ExtrasSettings::planForm()->check($input, self::nothingKept());
        } catch (ValidationException $e) {
            $errors = $e->errors();
            ksort($errors);

            return $errors;
        }

        self::fail('The price list was taken.');
    }

    /** @return \Closure(Field<mixed>): mixed */
    private static function nothingKept(): \Closure
    {
        return static fn(Field $field): mixed => null;
    }
}
