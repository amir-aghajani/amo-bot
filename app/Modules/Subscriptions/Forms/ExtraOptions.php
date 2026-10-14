<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Forms;

use App\Core\Forms\Fields\PricedRows;
use App\Support\Money;

/**
 * The amounts of an extra offered as buttons, as a list of {quantity, price}: each quantity a whole number from 1 to
 * `$max`, each price whole Toman above zero or blank for the unit price (`$unitPrice`) (PricedRows). At most MOST. An
 * amount without its own price needs a unit price (conflict()).
 *
 * @extends PricedRows<array{quantity: int, price: string|null}>
 */
final class ExtraOptions extends PricedRows
{
    /** How many amounts a price list offers as buttons at most — the custom builder's lists too. */
    public const MOST = 8;

    public function __construct(string $name, string $key, string $label, int $max, string $unit, private readonly UnitPrice $unitPrice, int $priceMax)
    {
        parent::__construct($name, $key, $label, $max, $unit, $priceMax, 'quantity', self::MOST);
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

    protected function blankPrice(): bool
    {
        return true;
    }

    protected function notAList(): string
    {
        return sprintf('%s باید فهرستی از مقدار و قیمت باشد.', $this->label);
    }

    protected function amountOutside(): string
    {
        return sprintf('%s باید بین 1 تا %d %s باشند.', $this->label, $this->max, $this->unit);
    }

    protected function twice(int $amount): string
    {
        return sprintf('%s دو بار آمده است.', $this->words($amount));
    }

    protected function priceOutside(int $amount): string
    {
        return sprintf('قیمت %s باید مبلغی بین %s تا %s باشد، یا خالی بماند تا به %s فروخته شود.', $this->words($amount), Money::format(1), Money::format($this->priceMax), $this->unitPrice->label);
    }

    protected function tooMany(): string
    {
        return sprintf('%s حداکثر %d مورد است.', $this->label, self::MOST);
    }
}
