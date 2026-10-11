<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Forms;

use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\FieldRefused;
use App\Core\Forms\FieldType;
use App\Support\Input;
use App\Support\Money;
use App\Support\Persian;

/**
 * The amounts of an extra offered as buttons, as a list of {quantity, price}: each quantity a whole number from 1 to
 * `$max`, each price whole Toman above zero or blank for the unit price (`$unitPrice`). Empty rows are dropped, no
 * quantity twice, at most MOST, kept smallest first. An amount without its own price needs a unit price (conflict()).
 *
 * @extends Field<list<array{quantity: int, price: string|null}>>
 */
final class ExtraOptions extends Field
{
    /** How many amounts a price list offers as buttons at most. */
    public const MOST = 8;

    public function __construct(
        string $name,
        string $key,
        /** What the refusals call them. */
        public readonly string $label,
        private readonly int $max,
        /** What a quantity is counted in: «گیگابایت», «روز». */
        private readonly string $unit,
        private readonly UnitPrice $unitPrice,
        private readonly int $priceMax,
    ) {
        parent::__construct($name, $key, []);
    }

    public function type(): FieldType
    {
        return FieldType::List;
    }

    public function read(array $input, mixed $kept): array
    {
        $rows = $input[$this->name] ?? [];
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new FieldRefused(sprintf('%s باید فهرستی از مقدار و قیمت باشد.', $this->label));
        }

        $options = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new FieldRefused(sprintf('%s باید فهرستی از مقدار و قیمت باشد.', $this->label));
            }
            if (self::blank($row['quantity'] ?? null) && self::blank($row['price'] ?? null)) {
                continue;
            }

            $quantity = Input::wholeOf($row['quantity'] ?? null);
            if ($quantity === null || $quantity < 1 || $quantity > $this->max) {
                throw new FieldRefused(sprintf('%s باید بین 1 تا %d %s باشند.', $this->label, $this->max, $this->unit));
            }
            if (array_key_exists($quantity, $options)) {
                throw new FieldRefused(sprintf('%s دو بار آمده است.', $this->words($quantity)));
            }

            $price = null;
            if (!self::blank($row['price'] ?? null)) {
                $price = Input::amountOf($row['price']);
                if ($price === null || $price < 1 || $price > $this->priceMax) {
                    throw new FieldRefused(sprintf('قیمت %s باید مبلغی بین %s تا %s باشد، یا خالی بماند تا به %s فروخته شود.', $this->words($quantity), Money::format(1), Money::format($this->priceMax), $this->unitPrice->label));
                }
            }
            $options[$quantity] = $price === null ? null : Money::normalize($price);
        }

        if (count($options) > self::MOST) {
            throw new FieldRefused(sprintf('%s حداکثر %d مورد است.', $this->label, self::MOST));
        }

        return self::listed($options);
    }

    public function cast(mixed $kept): array
    {
        if (!is_array($kept) || count($kept) > self::MOST) {
            return $this->default;
        }

        $options = [];
        foreach ($kept as $row) {
            if (!is_array($row)) {
                return $this->default;
            }
            $quantity = Input::integerOf($row['quantity'] ?? null);
            $own = $row['price'] ?? null;
            $price = $own === null ? null : Input::amountOf($own);
            if ($quantity === null || $quantity < 1 || $quantity > $this->max || array_key_exists($quantity, $options)) {
                return $this->default;
            }
            if ($own !== null && ($price === null || $price < 1 || $price > $this->priceMax)) {
                return $this->default;
            }
            $options[$quantity] = $price === null ? null : Money::normalize($price);
        }

        return self::listed($options);
    }

    public function conflict(array $values): ?string
    {
        // A refused unit price reports itself: there is nothing to judge the amounts by.
        if (!array_key_exists($this->unitPrice->name, $values) || $values[$this->unitPrice->name] !== null) {
            return null;
        }

        foreach ($values[$this->name] ?? [] as $option) {
            if (is_array($option) && ($option['price'] ?? null) === null) {
                return sprintf('%s قیمت ندارد؛ قیمت آن، یا %s را بنویسید.', $this->words((int) $option['quantity']), $this->unitPrice->label);
            }
        }

        return null;
    }

    protected function bounds(): array
    {
        return [1, $this->max];
    }

    protected function unit(): string
    {
        return $this->unit;
    }

    /** A quantity in words: «۱۰ گیگابایت». */
    private function words(int $quantity): string
    {
        return Persian::number($quantity) . ' ' . $this->unit;
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * @param array<int, string|null> $options
     * @return list<array{quantity: int, price: string|null}>
     */
    private static function listed(array $options): array
    {
        ksort($options);
        $listed = [];
        foreach ($options as $quantity => $price) {
            $listed[] = ['quantity' => $quantity, 'price' => $price];
        }

        return $listed;
    }
}
