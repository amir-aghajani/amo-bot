<?php

declare(strict_types=1);

namespace App\Core\Forms\Fields;

use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;
use App\Support\Input;
use App\Support\Money;

/**
 * An amount of Toman between two bounds, kept as Money keeps it (a normalised decimal string); thousands separators and
 * Persian digits are accepted.
 *
 * @extends Field<string>
 */
final class Amount extends Field
{
    public function __construct(
        string $name,
        string $key,
        string $default,
        /** What the messages call it */
        public readonly string $label,
        private readonly string $min,
        private readonly string $max,
        ?FieldSpec $spec = null,
    ) {
        parent::__construct($name, $key, Money::normalize($default), $spec);
    }

    public function type(): FieldType
    {
        return FieldType::Amount;
    }

    public function required(): bool
    {
        return true;
    }

    public function read(array $input, mixed $kept): string
    {
        // Whole Toman, the shop's only unit: a fraction is refused like any other amount out of place.
        $value = Input::amount($input, $this->name);
        if ($value === null || !$this->within((string) $value)) {
            throw new FieldRefused(sprintf('%s باید مبلغی بین %s تا %s باشد.', $this->label, Money::format($this->min), Money::format($this->max)));
        }

        return Money::normalize((string) $value);
    }

    public function cast(mixed $kept): string
    {
        $value = Input::amountOf($kept);

        return $value !== null && $this->within((string) $value) ? Money::normalize((string) $value) : $this->default;
    }

    protected function bounds(): array
    {
        return [(float) $this->min, (float) $this->max];
    }

    private function within(string $value): bool
    {
        return Money::compare($value, $this->min) >= 0 && Money::compare($value, $this->max) <= 0;
    }
}
