<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTO;

use App\Modules\Catalog\DTO\Package;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\ManualPayment;
use App\Modules\Providers\Models\Server;
use App\Support\Traffic;

/**
 * A purchase support makes by hand for a customer (Services\ManualOrders), as it was asked: a plan, or a service of
 * support's own numbers (`built`: its GB — 0 for unlimited —, days — 0 for never ending — and devices — 0 for
 * unlimited), on the server picked, named as support typed (null: by the shop's pattern), paid as `payment` says at
 * `amount` Toman (0 when free), with support's note and the name of who made it.
 */
final readonly class ManualOrder
{
    /** @param array{gb: int, days: int, devices: int}|null $built Null on a plan */
    public function __construct(
        public ?Plan $plan,
        public ?array $built,
        public Server $server,
        public ?string $clientName,
        public ManualPayment $payment,
        public int $amount,
        public ?string $note,
        public string $by,
    ) {}

    /** What it gives its service: the plan's package, or support's numbers. */
    public function package(): Package
    {
        if ($this->plan !== null) {
            return $this->plan->package();
        }
        $built = $this->built ?? throw new \LogicException('A manual order of neither a plan nor numbers of its own.');

        return Package::built($built['gb'], $built['days'], $built['devices']);
    }

    /**
     * What tells the request apart, as an order made of it keeps it: what a request made again with its key must ask again
     * (OrderService::openManual()).
     *
     * @return array<string, int|string|null>
     */
    public function shape(): array
    {
        return [
            'plan_id' => $this->plan?->id,
            'server_id' => $this->server->id,
            'traffic_bytes' => $this->built === null ? null : Traffic::bytesOfGb($this->built['gb']),
            'days' => $this->built['days'] ?? null,
            'devices' => $this->built['devices'] ?? null,
            'client_name' => $this->clientName,
            'amount' => $this->amount,
        ];
    }
}
