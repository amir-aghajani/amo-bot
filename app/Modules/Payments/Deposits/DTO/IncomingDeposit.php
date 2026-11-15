<?php

declare(strict_types=1);

namespace App\Modules\Payments\Deposits\DTO;

use Illuminate\Support\Carbon;

/** A bank deposit as its source sent it — a phone app's forwarded SMS, Rikhtam's word —, read by it (Contracts\DepositSource). */
final readonly class IncomingDeposit
{
    public function __construct(
        /** Its source's own id for it: the same deposit sent again carries the same one. */
        public string $externalId,
        /** The sum it said, in Rial; null when its source read none. */
        public ?int $amountRial,
        /** Whether it is money that came in: true a deposit, false a withdrawal, null when its source could not tell. */
        public ?bool $isDeposit,
        /** Its source's own test: the connection works, nothing else. */
        public bool $test,
        /** When the phone got the bank's SMS. */
        public Carbon $receivedAt,
        public ?string $bank,
        public ?string $sender,
        public string $body,
        /**
         * Why its source cannot vouch it is money a bank took in (support reads it, nothing is approved on it); null when
         * it can. ForApp doubts an SMS it did not read as a known bank's deposit; Rikhtam, which sends only money that
         * came in, never does.
         */
        public ?string $doubt = null,
    ) {}
}
