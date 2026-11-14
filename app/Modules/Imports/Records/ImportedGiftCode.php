<?php

declare(strict_types=1);

namespace App\Modules\Imports\Records;

/**
 * A gift code of the other bot that puts Toman in the wallet of whoever redeems it. The shop's gift codes are spent once
 * and by anyone who has them: a code with one use left comes as one; one with more left, or one the other bot kept for
 * one customer, cannot, and is named in the report.
 */
final class ImportedGiftCode
{
    public function __construct(
        public readonly string $key,
        public readonly string $code,
        /** Toman, a decimal string. */
        public readonly string $amount,
        /** The redemptions it has left; null for no limit. */
        public readonly ?int $usesLeft = 1,
        /** When it stops; null for never. One already past is not brought. */
        public readonly ?\DateTimeImmutable $expiresAt = null,
        public readonly bool $enabled = true,
        /** The customer the other bot kept it for (ImportedCustomer::$key); null for anyone. */
        public readonly ?string $forCustomer = null,
    ) {}
}
