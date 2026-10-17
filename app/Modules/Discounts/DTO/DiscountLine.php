<?php

declare(strict_types=1);

namespace App\Modules\Discounts\DTO;

use App\Modules\Discounts\Enums\DiscountSource;
use App\Support\Money;

/**
 * One discount a price took (a line of Orders\DTO\Quote, kept on the order as `orders.discounts`): its source, the
 * customer's words for it («فروش ویژه نوروز ۲۰٪», «کد NOWRUZ»), the Toman it took off, its percent, the row it came from
 * (none for a customer's own percent), a code's text — and whether that code was for a first purchase only, which the
 * order's delivery asks again (Orders\Services\OrderService) —, and when a sale ends.
 */
final readonly class DiscountLine
{
    public function __construct(
        public DiscountSource $source,
        public string $label,
        /** Toman taken off, normalised */
        public string $amount,
        public ?int $percent = null,
        public ?int $id = null,
        public ?\DateTimeInterface $endsAt = null,
        public ?string $code = null,
        public bool $firstOnly = false,
    ) {}

    /**
     * As an order keeps it (`endsAt` is not kept; `first_only` only when set).
     *
     * @return array{source: string, label: string, amount: string, percent: int|null, id: int|null, code?: string, first_only?: true}
     */
    public function toArray(): array
    {
        $kept = [
            'source' => $this->source->value,
            'label' => $this->label,
            'amount' => Money::normalize($this->amount),
            'percent' => $this->percent,
            'id' => $this->id,
        ];

        return $kept + ($this->code === null ? [] : ['code' => $this->code]) + ($this->firstOnly ? ['first_only' => true] : []);
    }

    /** A line an order kept (toArray()); null for one that does not read as one. */
    public static function fromArray(mixed $kept): ?self
    {
        if (!is_array($kept) || !is_string($kept['source'] ?? null) || !is_string($kept['label'] ?? null) || !is_numeric($kept['amount'] ?? null)) {
            return null;
        }
        $source = DiscountSource::tryFrom($kept['source']);

        return $source === null ? null : new self(
            $source,
            $kept['label'],
            Money::normalize((string) $kept['amount']),
            is_int($kept['percent'] ?? null) ? $kept['percent'] : null,
            is_int($kept['id'] ?? null) ? $kept['id'] : null,
            code: is_string($kept['code'] ?? null) ? $kept['code'] : null,
            firstOnly: ($kept['first_only'] ?? false) === true,
        );
    }

    /**
     * The lines an order kept, in order.
     *
     * @return list<self>
     */
    public static function listOf(mixed $kept): array
    {
        return is_array($kept) ? array_values(array_filter(array_map(self::fromArray(...), $kept))) : [];
    }

    /**
     * As the APIs answer it.
     *
     * @return array{source: string, label: string, amount: string, percent: int|null}
     */
    public function present(): array
    {
        return ['source' => $this->source->value, 'label' => $this->label, 'amount' => Money::normalize($this->amount), 'percent' => $this->percent];
    }

    /**
     * Lines — a quote's, an order's — as the APIs answer them (present()), in order.
     *
     * @param list<self> $lines
     * @return list<array{source: string, label: string, amount: string, percent: int|null}>
     */
    public static function presentAll(array $lines): array
    {
        return array_map(static fn(self $line): array => $line->present(), $lines);
    }
}
