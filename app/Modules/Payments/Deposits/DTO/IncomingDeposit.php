<?php

declare(strict_types=1);

namespace App\Modules\Payments\Deposits\DTO;

use Illuminate\Support\Carbon;

/** A bank SMS as a phone app forwarded it, read by its source (Contracts\DepositSource). */
final readonly class IncomingDeposit
{
    public function __construct(
        /** The app's own id for it: the same SMS sent again carries the same one. */
        public string $externalId,
        /** The sum it said, in Rial; null when the app read none. */
        public ?int $amountRial,
        /** Whether it is money that came in: true a deposit, false a withdrawal, null when the app could not tell. */
        public ?bool $isDeposit,
        /** The app's own test: the connection works, nothing else. */
        public bool $test,
        /** When the phone got it. */
        public Carbon $receivedAt,
        public ?string $bank,
        public ?string $sender,
        public string $body,
    ) {}
}
