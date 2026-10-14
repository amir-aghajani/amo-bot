<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Forms;

use App\Core\Forms\Fields\PricedRows;
use App\Support\Money;

/**
 * A price by tiers (DTO\PriceTiers) as a list of {from, price}: each `from` a whole number from 1 to `$max`, each price
 * whole Toman above zero per unit (PricedRows). At most MOST tiers.
 *
 * @extends PricedRows<array{from: int, price: string}>
 */
final class TierPrices extends PricedRows
{
    /** How many tiers a price list holds at most. */
    public const MOST = 10;

    public function __construct(string $name, string $key, string $label, int $max, string $unit, int $priceMax)
    {
        parent::__construct($name, $key, $label, $max, $unit, $priceMax, 'from', self::MOST);
    }

    protected function notAList(): string
    {
        return sprintf('%s باید فهرستی از «از» و «قیمت» باشد.', $this->label);
    }

    protected function amountOutside(): string
    {
        return sprintf('شروع هر پله %s باید بین 1 تا %d %s باشد.', $this->label, $this->max, $this->unit);
    }

    protected function twice(int $amount): string
    {
        return sprintf('پله‌ای از %s دو بار آمده است.', $this->words($amount));
    }

    protected function priceOutside(int $amount): string
    {
        return sprintf('قیمت پله از %s باید مبلغی بین %s تا %s باشد.', $this->words($amount), Money::format(1), Money::format($this->priceMax));
    }

    protected function tooMany(): string
    {
        return sprintf('%s حداکثر %d پله است.', $this->label, self::MOST);
    }
}
