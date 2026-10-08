<?php

declare(strict_types=1);

namespace App\Modules\Store\Presenters;

use App\Modules\Providers\Models\Server;

/**
 * A server as the shop's website shows its status: its name, whether it takes a new customer now and when the shop last
 * talked to its panel — never its address, its connector, nor what its panel said.
 */
final class ServerPresenter
{
    /**
     * `$available`: nothing stands against selling on it now (Providers\Services\ServerReadiness).
     *
     * @return array{id: int, name: string, available: bool, checked_at: string|null}
     */
    public static function status(Server $server, bool $available): array
    {
        return [
            'id' => $server->id,
            'name' => $server->name,
            'available' => $available,
            'checked_at' => $server->last_checked_at?->toIso8601String(),
        ];
    }
}
