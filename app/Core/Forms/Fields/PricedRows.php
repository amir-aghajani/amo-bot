<?php

declare(strict_types=1);

namespace App\Core\Forms\Fields;

use App\Core\Forms\FieldType;
use App\Support\Input;
use App\Support\Money;
use App\Support\Persian;

/**
 * A list of amounts each at a price, as rows of {<amount key>, price}: each amount a whole number from 1 to `$max`, each
 * price whole Toman from 1 to `$priceMax` — or, where the subclass sells an amount at a price kept elsewhere
 * (blankPrice()), left blank. Empty rows are dropped, no amount twice, at most `$most`, kept smallest first. A
 * subclass names the amount's key and words its refusals.
 *
 * @template TRow of array<string, int|string|null>
 * @extends Field<list<TRow>>
 */
abstract class PricedRows extends Field
{
    public function __construct(
        string $name,
        string $key,
        /** What the refusals call them. */
        public readonly string $label,
        protected readonly int $max,
        /** What an amount is counted in: «گیگابایت», «روز». */
        protected readonly string $unit,
        protected readonly int $priceMax,
        /** The row's key for its amount: `from`, `quantity`. */
        private readonly string $amountKey,
        private readonly int $most,
    ) {
        parent::__construct($name, $key, []);
    }

    /** What is sent that is no list of rows. */
    abstract protected function notAList(): string;

    /** An amount that is no whole number within 1 and `$max`. */
    abstract protected function amountOutside(): string;

    /** `$amount` sent twice. */
    abstract protected function twice(int $amount): string;

    /** The price of `$amount` outside its bounds (or blank where it must be set). */
    abstract protected function priceOutside(int $amount): string;

    /** More than `$most` rows. */
    abstract protected function tooMany(): string;

    /** Whether a row's price may be left blank (null): its amount sold at a price kept elsewhere. */
    protected function blankPrice(): bool
    {
        return false;
    }

    public function type(): FieldType
    {
        return FieldType::List;
    }

    public function read(array $input, mixed $kept): array
    {
        $rows = $input[$this->name] ?? [];
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new FieldRefused($this->notAList());
        }

        $priced = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new FieldRefused($this->notAList());
            }
            $price = $row['price'] ?? null;
            if (self::blank($row[$this->amountKey] ?? null) && self::blank($price)) {
                continue;
            }

            $amount = Input::wholeOf($row[$this->amountKey] ?? null);
            if ($amount === null || $amount < 1 || $amount > $this->max) {
                throw new FieldRefused($this->amountOutside());
            }
            if (array_key_exists($amount, $priced)) {
                throw new FieldRefused($this->twice($amount));
            }
            if (self::blank($price) && $this->blankPrice()) {
                $priced[$amount] = null;

                continue;
            }
            $priced[$amount] = $this->price($price) ?? throw new FieldRefused($this->priceOutside($amount));
        }

        if (count($priced) > $this->most) {
            throw new FieldRefused($this->tooMany());
        }

        return $this->listed($priced);
    }

    public function cast(mixed $kept): array
    {
        if (!is_array($kept) || count($kept) > $this->most) {
            return $this->default;
        }

        $priced = [];
        foreach ($kept as $row) {
            $amount = is_array($row) ? Input::integerOf($row[$this->amountKey] ?? null) : null;
            if ($amount === null || $amount < 1 || $amount > $this->max || array_key_exists($amount, $priced)) {
                return $this->default;
            }
            $own = $row['price'] ?? null;
            $price = $own === null && $this->blankPrice() ? null : $this->price($own);
            if ($price === null && ($own !== null || !$this->blankPrice())) {
                return $this->default;
            }
            $priced[$amount] = $price;
        }

        return $this->listed($priced);
    }

    protected function bounds(): array
    {
        return [1, $this->max];
    }

    protected function unit(): string
    {
        return $this->unit;
    }

    /** An amount in words: «۵۰ گیگابایت». */
    protected function words(int $amount): string
    {
        return Persian::number($amount) . ' ' . $this->unit;
    }

    /** A price as kept, while it is whole Toman within its bounds; null otherwise. */
    private function price(mixed $sent): ?string
    {
        $price = Input::amountOf($sent);

        return $price === null || $price < 1 || $price > $this->priceMax ? null : Money::normalize($price);
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * @param array<int, string|null> $priced
     * @return list<TRow>
     */
    private function listed(array $priced): array
    {
        ksort($priced);
        $listed = [];
        foreach ($priced as $amount => $price) {
            $listed[] = [$this->amountKey => $amount, 'price' => $price];
        }

        /** @var list<TRow> $listed */
        return $listed;
    }
}
