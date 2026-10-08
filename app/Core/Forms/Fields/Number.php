<?php

declare(strict_types=1);

namespace App\Core\Forms\Fields;

use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;
use App\Support\Input;

/**
 * A whole number between two bounds — Persian digits accepted, as everywhere a form takes a number, and its thousands set
 * apart as an amount's are («10,000»: Input::wholeOf()). Its one refusal names the bounds: «تعداد روز باید عددی بین 1 تا
 * 30 باشد.» (with the unit after them when it has one).
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

    protected function bounds(): array
    {
        return [$this->min, $this->max];
    }

    protected function unit(): ?string
    {
        return $this->unit === '' ? null : $this->unit;
    }
}
