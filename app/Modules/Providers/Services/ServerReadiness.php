<?php

declare(strict_types=1);

namespace App\Modules\Providers\Services;

use App\Modules\Providers\Models\Server;
use App\Modules\Providers\ProviderRegistry;

/**
 * Whether a server can take a new client right now, and if not why, in the admin's words: its connector is installed,
 * it is switched on, it serves subscription links (the one thing a customer gets — a server never checked is not known
 * to) and it has room. What a plan's entry adds on top (something sellable on it) is Catalog\Services\ServerSelector's.
 * The servers screen shows the reason; a sale and a move are refused with it.
 */
final class ServerReadiness
{
    /** A server left from an installation that had its connector: nothing here talks to its panel. */
    public const NO_CONNECTOR = 'کانکتور این سرور در این نصب وجود ندارد.';

    public function __construct(private readonly ProviderRegistry $providers) {}

    public function problem(Server $server): ?string
    {
        return match (true) {
            !$this->providers->has($server->driver) => self::NO_CONNECTOR,
            !$server->is_active => 'سرور غیرفعال است.',
            $server->serves_subscriptions === null => 'سرور هنوز بررسی نشده است؛ تا معلوم نشود لینک اشتراک می‌دهد، به مشتری نشان داده نمی‌شود.',
            !$server->servesSubscriptions() => 'سرور لینک اشتراک نمی‌دهد؛ سرور اشتراک پنل را روشن کنید و سرور را دوباره بررسی کنید.',
            !$server->hasCapacity() => 'ظرفیت سرور پر است.',
            default => null,
        };
    }
}
