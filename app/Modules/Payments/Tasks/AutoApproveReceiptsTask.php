<?php

declare(strict_types=1);

namespace App\Modules\Payments\Tasks;

use App\Core\Scheduling\Task;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Drivers\Manual\ManualGateway;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentActions;
use Illuminate\Database\Eloquent\Relations\Relation;
use Psr\Log\LoggerInterface;

/**
 * Support may be away: a card-to-card method can carry a review window (`auto_approve_after` minutes) after which a
 * receipt nobody looked at is accepted on its own — the order is delivered and the customer told, as when support
 * approves (PaymentActions::autoApprove(), under the same rule: a receipt whose order was paid another way meanwhile is
 * support's to sort out). Only a receipt sent in the bot — by a Telegram account —: one uploaded from the website, whose
 * account an email address alone may have made, always waits for support. Runs every minute, in every shop.
 */
final class AutoApproveReceiptsTask implements Task
{
    public function __construct(
        private readonly PaymentActions $actions,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(): void
    {
        foreach (PaymentMethod::query()->where('driver', ManualGateway::key())->get() as $method) {
            $window = ManualGateway::reviewWindow($method);
            if ($window > 0) {
                $this->approve($method, $window);
            }
        }
    }

    /**
     * The method's receipts sent in the bot waiting past its window, oldest first — a method without one leaves every
     * receipt to support, and so does every method an uploaded one.
     */
    private function approve(PaymentMethod $method, int $window): void
    {
        $due = Payment::query()
            ->where('payment_method_id', $method->id)
            ->where('status', PaymentStatus::AwaitingReview->value)
            ->whereNotNull('receipt_file_id')
            ->where('receipt_at', '<=', now()->subMinutes($window))
            // What an approval reads: the method, and the order with what its delivery reads.
            ->with(['method', 'order' => static fn(Relation $order) => $order->with(OrderService::DELIVERY_RELATIONS)])
            ->oldest('receipt_at')
            ->get();

        foreach ($due as $payment) {
            try {
                if ($this->actions->autoApprove($payment)) {
                    $this->logger->info('Payment {id} auto-approved after its {minutes}-minute review window', ['id' => $payment->id, 'minutes' => $window]);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Auto-approval of payment {id} failed: {message}', ['id' => $payment->id, 'message' => $e->getMessage(), 'exception' => $e]);
            }
        }
    }
}
