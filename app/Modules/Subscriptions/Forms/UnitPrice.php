<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Forms;

use App\Core\Forms\Fields\Field;
use App\Core\Forms\Fields\FieldRefused;
use App\Core\Forms\FieldType;
use App\Support\Input;
use App\Support\Money;

/**
 * The price of one unit of an extra (a GB of traffic, a day of time), or none: blank sells only the amounts with their
 * own price, and nothing typed. Whole Toman as an admin types it (Input::amountOf()), from 1 to `$max` — free extras are
 * support's extension, not a sale.
 *
 * @extends Field<string|null>
 */
final class UnitPrice extends Field
{
    public function __construct(
        string $name,
        string $key,
        /** What the refusals call it. */
        public readonly string $label,
        private readonly int $max,
    ) {
        parent::__construct($name, $key, null);
    }

    public function type(): FieldType
    {
        return FieldType::Amount;
    }

    public function read(array $input, mixed $kept): ?string
    {
        $typed = $input[$this->name] ?? null;
        if ($typed === null || (is_string($typed) && trim($typed) === '')) {
            return null;
        }

        $price = Input::amountOf($typed);
        if ($price === null || $price < 1 || $price > $this->max) {
            throw new FieldRefused(sprintf('%s باید مبلغی بین %s تا %s باشد، یا خالی بماند.', $this->label, Money::format(1), Money::format($this->max)));
        }

        return Money::normalize($price);
    }

    public function cast(mixed $kept): ?string
    {
        $price = $kept === null ? null : Input::amountOf($kept);

        return $price !== null && $price >= 1 && $price <= $this->max ? Money::normalize($price) : null;
    }

    protected function bounds(): array
    {
        return [1, $this->max];
    }
}
