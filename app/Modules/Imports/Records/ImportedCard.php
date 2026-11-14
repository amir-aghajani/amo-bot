<?php

declare(strict_types=1);

namespace App\Modules\Imports\Records;

/**
 * A bank card the other bot showed for card-to-card payments: a card of the shop's card-to-card method here, always
 * switched off for the owner to check first — a stale card, or one the other bot kept for a few customers, must never
 * start taking every customer's payments.
 */
final class ImportedCard
{
    public function __construct(
        public readonly string $key,
        /** Its digits as the other bot kept them (the core keeps a 16-digit card number only). */
        public readonly string $number,
        public readonly string $holder,
        public readonly ?string $bank = null,
    ) {}
}
