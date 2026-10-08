<?php

declare(strict_types=1);

namespace App\Core\Forms\Fields;

use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;
use App\Support\Input;

/**
 * A short list of whole numbers — the amounts a screen offers as buttons —, sent as a list or as text the way an admin
 * types it ("50000, 100000"، or a space between; thousands set apart as Number reads them, «50,000, 100,000»:
 * Input::numbersOf()): each between two bounds, kept without repeats and smallest first, at most so many. With
 * `atLeast`, none may be below another field's number (the presets of a minimum).
 *
 * @extends Field<list<int>>
 */
final class Numbers extends Field
{
    /** @param list<int> $default */
    public function __construct(
        string $name,
        string $key,
        array $default,
        /** What the messages call them */
        public readonly string $label,
        private readonly int $min,
        private readonly int $max,
        /** How many there may be */
        private readonly int $most,
        /** Said after the bounds: «تومان», «گیگابایت» */
        private readonly string $unit = '',
        private readonly ?Number $atLeast = null,
        ?FieldSpec $spec = null,
    ) {
        parent::__construct($name, $key, $default, $spec);
    }

    public function type(): FieldType
    {
        return FieldType::List;
    }

    public function read(array $input, mixed $kept): array
    {
        $raw = $input[$this->name] ?? [];
        $items = is_string($raw) ? Input::numbersOf($raw) : $raw;
        if (!is_array($items)) {
            throw new FieldRefused($this->outOfRange());
        }

        $numbers = [];
        foreach ($items as $item) {
            // A blank item of a list sent is nothing, not a wrong number.
            if (is_string($item) && trim($item) === '') {
                continue;
            }
            $number = Input::wholeOf($item);
            if ($number === null || $number < $this->min || $number > $this->max) {
                throw new FieldRefused($this->outOfRange());
            }
            $numbers[] = $number;
        }

        $numbers = self::sorted($numbers);
        if (count($numbers) > $this->most) {
            throw new FieldRefused(sprintf('%s حداکثر %d عدد است.', $this->label, $this->most));
        }

        return $numbers;
    }

    public function cast(mixed $kept): array
    {
        if (!is_array($kept) || count($kept) > $this->most) {
            return $this->default;
        }

        $numbers = [];
        foreach ($kept as $item) {
            $number = Input::integerOf($item);
            if ($number === null || $number < $this->min || $number > $this->max) {
                return $this->default;
            }
            $numbers[] = $number;
        }

        return self::sorted($numbers);
    }

    public function conflict(array $values): ?string
    {
        $floor = $this->atLeast === null ? null : $values[$this->atLeast->name] ?? null;
        $numbers = $values[$this->name] ?? [];

        return is_int($floor) && is_array($numbers) && $numbers !== [] && $numbers[0] < $floor
            ? sprintf('هیچ‌کدام از %s نمی‌تواند از %s کمتر باشد.', $this->label, $this->atLeast?->label)
            : null;
    }

    protected function bounds(): array
    {
        return [$this->min, $this->max];
    }

    protected function unit(): ?string
    {
        return $this->unit === '' ? null : $this->unit;
    }

    private function outOfRange(): string
    {
        return sprintf('%s باید عددهایی بین %d تا %d%s باشند.', $this->label, $this->min, $this->max, $this->unit === '' ? '' : ' ' . $this->unit);
    }

    /**
     * @param list<int> $numbers
     * @return list<int>
     */
    private static function sorted(array $numbers): array
    {
        $numbers = array_values(array_unique($numbers));
        sort($numbers);

        return $numbers;
    }
}
