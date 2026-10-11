<?php

declare(strict_types=1);

namespace App\Modules\Rewards\DTO;

use App\Modules\Rewards\Models\GiftCode;

/** A redeemed gift code (Services\GiftCodes::redeem()) and what it gave. */
final readonly class Redeemed
{
    public function __construct(
        public GiftCode $code,
        public Given $given,
    ) {}
}
