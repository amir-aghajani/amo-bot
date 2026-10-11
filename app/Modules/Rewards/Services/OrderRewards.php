<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Services;

use App\Modules\Bots\CurrentBot;
use App\Modules\Discounts\Services\Loyalty;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Orders\Models\Order;
use Psr\Log\LoggerInterface;

/**
 * What a delivered order earns its customer, run by OrderService after the delivery's commit: its cashback, and the
 * news of a loyalty tier reached. A failure of either is logged and never affects the delivery.
 */
final class OrderRewards
{
    public function __construct(
        private readonly Cashback $cashback,
        private readonly Loyalty $loyalty,
        private readonly CustomerNotifier $notifier,
        private readonly LoggerInterface $logger,
    ) {}

    /** Credits the order's cashback and announces a tier it reached; never throws. */
    public function delivered(Order $order): void
    {
        try {
            $this->cashback->earn($order);
        } catch (\Throwable $e) {
            $this->logger->error('The cashback of order {id} failed: {message}', ['id' => $order->id, 'message' => $e->getMessage(), 'exception' => $e]);
        }

        try {
            $tier = CurrentBot::run($order->shop(), fn() => $this->loyalty->promotedBy($order));
            if ($tier !== null) {
                $this->notifier->loyaltyTierUp($order->user, $tier);
            }
        } catch (\Throwable $e) {
            $this->logger->error('The loyalty tier of order {id} was not told: {message}', ['id' => $order->id, 'message' => $e->getMessage(), 'exception' => $e]);
        }
    }
}
