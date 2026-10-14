<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Enums;

/**
 * How a buyer has their new service named on its panel (Services\ClientNaming): by the shop's pattern, at random, or by
 * a name they type — the latter two while the shop offers them (Services\ClientNameSettings).
 */
enum ClientNameMode: string
{
    /** By the shop's pattern («ترتیبی»): the default, and the only way while the shop offers no other. */
    case Sequential = 'sequential';
    /** A random name («رندوم»). */
    case Random = 'random';
    /** The name the buyer typed («دلخواه»). */
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Sequential => 'ترتیبی',
            self::Random => 'رندوم',
            self::Custom => 'دلخواه',
        };
    }
}
