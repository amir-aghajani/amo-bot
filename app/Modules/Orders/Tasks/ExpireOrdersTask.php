<?php

declare(strict_types=1);

namespace App\Modules\Orders\Tasks;

use App\Core\Scheduling\Task;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\RequestKey;
use App\Modules\Payments\Services\PaymentService;

/**
 * Orders nobody finished paying: a checkout the customer left after picking a card — or came back to with no receipt
 * that stood — is dropped with its unpaid payments once nothing happened to it for EXPIRE_HOURS, quietly: the customer
 * walked away (PaymentService::expire()). One whose receipt waits for review is support's to decide, whatever its age.
 * And the website's request keys RequestKey::KEEP_DAYS old are forgotten: a request made again after that is a new one.
 * Runs hourly, in every shop.
 */
final class ExpireOrdersTask implements Task
{
    public const EXPIRE_HOURS = 48;

    public function __construct(private readonly PaymentService $payments) {}

    public function run(): void
    {
        $idleSince = now()->subHours(self::EXPIRE_HOURS);

        foreach (Order::payable()->where('updated_at', '<=', $idleSince)->get() as $order) {
            $this->payments->expire($order, $idleSince);
        }

        RequestKey::query()->where('created_at', '<', now()->subDays(RequestKey::KEEP_DAYS))->delete();
    }
}
