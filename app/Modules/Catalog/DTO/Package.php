<?php

declare(strict_types=1);

namespace App\Modules\Catalog\DTO;

use App\Support\Traffic;

/**
 * What a sale gives a service — its term, its traffic and how many devices may use it at once —, whatever it was sold
 * as: a plan (Plan::package()), or a service the customer built (the custom builder's gigabytes and days). A purchase
 * makes a service of it and a renewal adds it (Subscriptions\Services\ServiceTerms), so neither needs to know which.
 */
final readonly class Package
{
    /** A day of a term, in the hours a term is counted in. */
    public const HOURS_A_DAY = 24;

    /**
     * The most a sale by the unit may give at once — an extra (Subscriptions\Services\ExtrasSettings), a service the
     * customer builds (Services\CustomServiceSettings) —: GB of traffic, days of term.
     */
    public const MAX_GB = 10_000;
    public const MAX_DAYS = 365;

    /** The most a unit of such a sale, or an amount of it offered as a button, may cost — Toman. */
    public const PRICE_MAX = 100_000_000;

    public function __construct(
        /** The term in hours, counted from the first connection; 0 = never ends. */
        public int $hours,
        /** The traffic in bytes; 0 = unlimited. */
        public int $bytes,
        /** Devices at once; 0 = unlimited. */
        public int $ipLimit,
    ) {}

    /** A service the customer built: `$gb` gigabytes for `$days` days, `$ipLimit` devices at once. */
    public static function built(int $gb, int $days, int $ipLimit): self
    {
        return new self($days * self::HOURS_A_DAY, Traffic::bytesOfGb($gb), $ipLimit);
    }
}
