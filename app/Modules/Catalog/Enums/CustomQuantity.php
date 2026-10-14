<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

use App\Modules\Catalog\DTO\Package;

/**
 * One of the two quantities a customer builds a service of («سرویس دلخواه», Services\CustomServices): its traffic in GB
 * or its term in days — each priced by tiers of its own, within bounds of its own, offered as buttons of its own
 * (Services\CustomServiceSettings, the fields `custom_{value}_…`).
 */
enum CustomQuantity: string
{
    case Traffic = 'traffic';
    case Days = 'days';

    /** What a refusal calls it: «حجم», «مدت». */
    public function noun(): string
    {
        return match ($this) {
            self::Traffic => 'حجم',
            self::Days => 'مدت',
        };
    }

    /** What it is counted in. */
    public function unit(): string
    {
        return match ($this) {
            self::Traffic => 'گیگابایت',
            self::Days => 'روز',
        };
    }

    /** The website's request field for it (`gb`, `days`), where its refusals go. */
    public function field(): string
    {
        return match ($this) {
            self::Traffic => 'gb',
            self::Days => 'days',
        };
    }

    /** The most a built service may hold of it (DTO\Package). */
    public function max(): int
    {
        return match ($this) {
            self::Traffic => Package::MAX_GB,
            self::Days => Package::MAX_DAYS,
        };
    }
}
