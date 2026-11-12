<?php

declare(strict_types=1);

namespace App\Core\Sms;

/** What an SMS account has left to send with, in its provider's own unit (IPPanel keeps Rial). */
final class SmsCredit
{
    public function __construct(
        /** Whole units: a fraction of a Rial is nothing anyone pays with. */
        public readonly int $amount,
        /** The unit in Persian («ریال»). */
        public readonly string $unit,
    ) {}

    /** @return array{amount: int, unit: string} */
    public function toArray(): array
    {
        return ['amount' => $this->amount, 'unit' => $this->unit];
    }
}
