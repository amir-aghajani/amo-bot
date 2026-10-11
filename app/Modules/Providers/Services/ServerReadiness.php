<?php

declare(strict_types=1);

namespace App\Modules\Providers\Services;

use App\Modules\Providers\Models\Server;
use App\Modules\Providers\ProviderRegistry;

/**
 * Whether a server can take a new client right now, and if not, why (worded for the owner): its connector is installed,
 * it is switched on, it serves subscription links (a server never checked is not known to) and it has room. The servers
 * screen shows the reason; a sale and a move are refused with it. A plan entry's own rule (something sellable on it) is
 * Catalog\Services\ServerSelector's.
 */
final class ServerReadiness
{
    /** A server whose connector this installation does not have: nothing here can talk to its panel. */
    public const NO_CONNECTOR = 'کانکتور این سرور در این نصب وجود ندارد.';

    public function __construct(private readonly ProviderRegistry $providers) {}

    /** Why the server cannot take a new client now; null when it can. */
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
