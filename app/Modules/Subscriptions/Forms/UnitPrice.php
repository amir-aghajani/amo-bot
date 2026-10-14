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
 * own price, and nothing typed. Whole Toman as an admin types it (Input::optionalAmountOf()), from 1 to `$max` — free
 * extras are support's extension, not a sale.
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
        $price = Input::optionalAmountOf($input[$this->name] ?? null, 1, $this->max);
        if ($price === false) {
            throw new FieldRefused(sprintf('%s باید مبلغی بین %s تا %s باشد، یا خالی بماند.', $this->label, Money::format(1), Money::format($this->max)));
        }

        return $price;
    }

    public function cast(mixed $kept): ?string
    {
        $price = Input::optionalAmountOf($kept, 1, $this->max);

        return $price === false ? null : $price;
    }

    protected function bounds(): array
    {
        return [1, $this->max];
    }
}
