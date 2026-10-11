<?php

declare(strict_types=1);

namespace App\Modules\Orders\Tasks;

use App\Core\Scheduling\Budget;
use App\Core\Scheduling\Task;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderActions;
use App\Modules\Orders\Services\OrderService;
use Psr\Log\LoggerInterface;

/**
 * Finishes deliveries whose process died (the bot restarted after a payment or mid-delivery): stale orders
 * (Order::stale()) are delivered as support's retry would (OrderActions::resume()), oldest first, within the run's time
 * budget. A failed delivery is never resumed: it waits for support. Every minute, in every shop.
 */
final class ResumeDeliveriesTask implements Task
{
    public function __construct(
        private readonly OrderActions $actions,
        private readonly Budget $budget,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(): void
    {
        $until = $this->budget->deadline();
        foreach (Order::stale()->with(OrderService::DELIVERY_RELATIONS)->orderBy('updated_at')->orderBy('id')->get() as $order) {
            if (microtime(true) >= $until) {
                return;
            }
            try {
                if ($this->actions->resume($order)) {
                    $this->logger->info('The delivery of order {id}, left by a process that died, was resumed: {status}', ['id' => $order->id, 'status' => $order->status->value]);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Resuming the delivery of order {id} failed: {message}', ['id' => $order->id, 'message' => $e->getMessage(), 'exception' => $e]);
            }
        }
    }
}
