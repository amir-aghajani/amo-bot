<?php

declare(strict_types=1);

namespace App\Core\Forms\Fields;

use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;
use App\Support\Input;

/**
 * A whole number between two bounds, typed with Persian digits or thousands separators too (Input::wholeOf()). Its one
 * refusal names the bounds («تعداد روز باید عددی بین 1 تا 30 باشد.»). `atLeast` holds it to another field's number (a
 * range's most to its least).
 *
 * @extends Field<int>
 */
final class Number extends Field
{
    public function __construct(
        string $name,
        string $key,
        int $default,
        /** What the messages call it */
        public readonly string $label,
        public readonly int $min,
        public readonly int $max,
        /** Said after the bounds: «ثانیه», «گیگابایت» */
        private readonly string $unit = '',
        ?FieldSpec $spec = null,
        private readonly ?self $atLeast = null,
    ) {
        parent::__construct($name, $key, $default, $spec);
    }

    public function type(): FieldType
    {
        return FieldType::Number;
    }

    public function required(): bool
    {
        return true;
    }

    public function read(array $input, mixed $kept): int
    {
        $value = Input::wholeOf($input[$this->name] ?? null);
        if ($value === null || $value < $this->min || $value > $this->max) {
            throw new FieldRefused(sprintf('%s باید عددی بین %d تا %d%s باشد.', $this->label, $this->min, $this->max, $this->unit === '' ? '' : ' ' . $this->unit));
        }

        return $value;
    }

    public function cast(mixed $kept): int
    {
        $value = Input::integerOf($kept);

        return $value !== null && $value >= $this->min && $value <= $this->max ? $value : $this->default;
    }

    public function conflict(array $values): ?string
    {
        $floor = $this->atLeast === null ? null : $values[$this->atLeast->name] ?? null;
        $value = $values[$this->name] ?? null;

        return is_int($floor) && is_int($value) && $value < $floor ? sprintf('%s نمی‌تواند از %s کمتر باشد.', $this->label, $this->atLeast?->label) : null;
    }

    protected function bounds(): array
    {
        return [$this->min, $this->max];
    }

    protected function unit(): ?string
    {
        return $this->unit === '' ? null : $this->unit;
    }
}
