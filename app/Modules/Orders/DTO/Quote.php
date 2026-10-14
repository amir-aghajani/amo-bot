<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTO;

use App\Modules\Discounts\DTO\DiscountLine;
use App\Modules\Orders\Enums\OrderPricing;
use App\Support\Money;

/**
 * What a customer pays and why (Services\Pricing): the base price, less the automatic discount and then the code's,
 * each a DiscountLine; amount() never goes below zero. attributes() is what an order made at it keeps.
 */
final readonly class Quote
{
    public function __construct(
        /** Toman before any discount, normalised */
        public string $base,
        public OrderPricing $pricing,
        public ?DiscountLine $automatic = null,
        public ?DiscountLine $code = null,
    ) {}

    /** What is paid: the base less every discount, never below zero. */
    public function amount(): string
    {
        $amount = Money::subtract($this->base, $this->discountAmount());

        return Money::compare($amount, 0) < 0 ? Money::normalize(0) : $amount;
    }

    /** The Toman all discounts took off. */
    public function discountAmount(): string
    {
        $off = Money::normalize(0);
        foreach ($this->lines() as $line) {
            $off = Money::add($off, $line->amount);
        }

        return $off;
    }

    /** What the automatic discount left — what a code applies to. */
    public function afterAutomatic(): string
    {
        return $this->automatic === null ? Money::normalize($this->base) : Money::subtract($this->base, $this->automatic->amount);
    }

    /**
     * The discounts taken, the automatic one first.
     *
     * @return list<DiscountLine>
     */
    public function lines(): array
    {
        return array_values(array_filter([$this->automatic, $this->code]));
    }

    /** The same quote with the code's line — or none. */
    public function withCode(?DiscountLine $code): self
    {
        return new self($this->base, $this->pricing, $this->automatic, $code);
    }

    /** The code it was made with, by its id; null for none. */
    public function codeId(): ?int
    {
        return $this->code?->id;
    }

    /**
     * As the APIs answer a price before anything is ordered: the base, each discount line, what is left to pay.
     *
     * @return array{base: string, discounts: list<array{source: string, label: string, amount: string, percent: int|null}>, amount: string}
     */
    public function present(): array
    {
        return ['base' => $this->base, 'discounts' => DiscountLine::presentAll($this->lines()), 'amount' => $this->amount()];
    }

    /**
     * What an order made at this quote keeps.
     *
     * @return array{amount: string, pricing: OrderPricing, discount_amount: string, discounts: list<array<string, mixed>>|null, discount_code_id: int|null}
     */
    public function attributes(): array
    {
        $lines = array_map(static fn(DiscountLine $line): array => $line->toArray(), $this->lines());

        return [
            'amount' => $this->amount(),
            'pricing' => $this->pricing,
            'discount_amount' => $this->discountAmount(),
            'discounts' => $lines === [] ? null : $lines,
            'discount_code_id' => $this->codeId(),
        ];
    }
}
