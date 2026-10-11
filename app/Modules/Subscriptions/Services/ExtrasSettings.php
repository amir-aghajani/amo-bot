<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;
use App\Modules\Subscriptions\DTO\ExtraOffer;
use App\Modules\Subscriptions\DTO\ExtraPrices;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Forms\ExtraOptions;
use App\Modules\Subscriptions\Forms\UnitPrice;

/**
 * «حجم و زمان اضافه» settings (the `extras` group of each shop's bot settings): the switch — off by default, and off
 * nothing is sold whatever a plan says — and the shop's price list, which a plan may replace with its own
 * (`plans.extras`, PlanService). A price list has the same fields wherever it is kept: per kind the unit price (blank
 * for none), the amounts offered as buttons (each at its own price or the unit price), and the least and most a
 * customer may type.
 */
final class ExtrasSettings implements DeclaresSettings
{
    /** The most one extra may bring: GB of traffic, days of time. */
    public const MAX_GB = 10_000;
    public const MAX_DAYS = 365;

    /** The most a unit, or an amount offered, may cost — Toman. */
    public const PRICE_MAX = 100_000_000;

    /** Prefixes of the shop's price list fields: the bot settings screen is one flat map of every group's fields. */
    private const NAMES = 'extra_';
    private const KEYS = 'extras.';

    private readonly Toggle $enabled;

    public function __construct(private readonly Settings $settings)
    {
        $this->enabled = new Toggle('extras_enabled', 'extras.enabled', default: false);
    }

    public function groups(): array
    {
        return [new Form('extras', [$this->enabled, ...self::priceForm(self::NAMES, self::KEYS)->fields])];
    }

    /** Whether the shop sells extras at all, at its prices or a plan's own. */
    public function enabled(): bool
    {
        return $this->settings->read($this->enabled);
    }

    /** The shop's price list. */
    public function prices(): ExtraPrices
    {
        return self::pricesOf(self::NAMES, self::KEYS, fn(Field $field): mixed => $this->settings->get($field->key));
    }

    /** A plan's own price list; null when the plan sells at the shop's. */
    public static function planPrices(Plan $plan): ?ExtraPrices
    {
        $own = $plan->extras;

        return $own === null ? null : self::pricesOf('', '', static fn(Field $field): mixed => $own[$field->key] ?? null);
    }

    /** The form of a plan's own price list: the bare field names, kept under the same keys in `plans.extras`. */
    public static function planForm(): Form
    {
        return self::priceForm('', '');
    }

    /** The fields of a price list, every name and key after its prefix. */
    private static function priceForm(string $names, string $keys): Form
    {
        return new Form('extra_prices', [...array_values(self::fields(ExtraKind::Traffic, $names, $keys)), ...array_values(self::fields(ExtraKind::Time, $names, $keys))]);
    }

    /**
     * A price list read from where it is kept.
     *
     * @param \Closure(Field<mixed>): mixed $kept What is kept under a field's key (null: nothing)
     */
    private static function pricesOf(string $names, string $keys, \Closure $kept): ExtraPrices
    {
        $offer = static function (ExtraKind $kind) use ($names, $keys, $kept): ExtraOffer {
            ['price' => $price, 'options' => $options, 'min' => $min, 'max' => $max] = self::fields($kind, $names, $keys);
            $offered = [];
            foreach ($options->cast($kept($options)) as $option) {
                $offered[$option['quantity']] = $option['price'];
            }

            return new ExtraOffer($price->cast($kept($price)), $offered, $min->cast($kept($min)), $max->cast($kept($max)));
        };

        return new ExtraPrices($offer(ExtraKind::Traffic), $offer(ExtraKind::Time));
    }

    /**
     * One kind's fields of a price list: the price of a unit, the amounts offered, and the least and the most typed.
     *
     * @return array{price: UnitPrice, options: ExtraOptions, min: Number, max: Number}
     */
    private static function fields(ExtraKind $kind, string $names, string $keys): array
    {
        $name = static fn(string $field): string => "{$names}{$kind->value}_{$field}";
        $key = static fn(string $field): string => "{$keys}{$kind->value}_{$field}";
        [$priceLabel, $optionsLabel, $minLabel, $maxLabel, $most, $defaultMax] = match ($kind) {
            ExtraKind::Traffic => ['قیمت هر گیگابایت', 'حجم‌های پیشنهادی', 'کمترین حجم دلخواه', 'بیشترین حجم دلخواه', self::MAX_GB, 100],
            ExtraKind::Time => ['قیمت هر روز', 'زمان‌های پیشنهادی', 'کمترین زمان دلخواه', 'بیشترین زمان دلخواه', self::MAX_DAYS, 30],
        };
        $price = new UnitPrice($name('price'), $key('price'), $priceLabel, self::PRICE_MAX);
        $least = new Number($name('min'), $key('min'), default: 1, label: $minLabel, min: 1, max: $most, unit: $kind->unit());

        return [
            'price' => $price,
            'options' => new ExtraOptions($name('options'), $key('options'), $optionsLabel, $most, $kind->unit(), $price, self::PRICE_MAX),
            'min' => $least,
            'max' => new Number($name('max'), $key('max'), default: $defaultMax, label: $maxLabel, min: 1, max: $most, unit: $kind->unit(), atLeast: $least),
        ];
    }
}
