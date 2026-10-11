<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\DTO;

use App\Modules\Subscriptions\Enums\ExtraKind;

/** A price list of the extras — the shop's or a plan's own (Services\ExtrasSettings): what it sells of each kind. */
final readonly class ExtraPrices
{
    public function __construct(
        public ExtraOffer $traffic,
        public ExtraOffer $time,
    ) {}

    public function of(ExtraKind $kind): ExtraOffer
    {
        return match ($kind) {
            ExtraKind::Traffic => $this->traffic,
            ExtraKind::Time => $this->time,
        };
    }
}
