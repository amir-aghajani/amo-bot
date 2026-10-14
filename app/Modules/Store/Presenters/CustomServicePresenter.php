<?php

declare(strict_types=1);

namespace App\Modules\Store\Presenters;

use App\Modules\Catalog\Enums\CustomQuantity;
use App\Modules\Catalog\Services\CustomServices;
use App\Modules\Providers\Models\Server;

/**
 * «سرویس دلخواه» as a website shows it before a customer builds one: whether it is offered now, each quantity's tiers,
 * bounds and buttons, the devices a built service gets, and the servers it is built on (by name, never their address).
 * While it is not offered — switched off, in an agent's shop, or no server able to take it — no prices or buttons show.
 */
final class CustomServicePresenter
{
    /**
     * @return array{offered: bool, traffic: array{tiers: list<array{from: int, price: string}>, min: int, max: int, options: list<int>}, days: array{tiers: list<array{from: int, price: string}>, min: int, max: int, options: list<int>}, devices: int, servers: list<array{id: int, name: string}>}
     */
    public static function offer(CustomServices $custom): array
    {
        $servers = $custom->servers();
        $offered = $servers !== [];

        return [
            'offered' => $offered,
            'traffic' => self::quantity($custom, CustomQuantity::Traffic, $offered),
            'days' => self::quantity($custom, CustomQuantity::Days, $offered),
            'devices' => $custom->devices(),
            'servers' => array_map(static fn(Server $server): array => ['id' => $server->id, 'name' => $server->name], $servers),
        ];
    }

    /**
     * A quantity's tiers, bounds and buttons; no tiers or buttons while nothing is `$offered`.
     *
     * @return array{tiers: list<array{from: int, price: string}>, min: int, max: int, options: list<int>}
     */
    private static function quantity(CustomServices $custom, CustomQuantity $quantity, bool $offered): array
    {
        [$least, $most] = $custom->bounds($quantity);

        return ['tiers' => $offered ? $custom->tiers($quantity)->tiers : [], 'min' => $least, 'max' => $most, 'options' => $offered ? $custom->options($quantity) : []];
    }
}
