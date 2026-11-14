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
 * Accepts receipts nobody reviewed within their card method's review window, as support would (PaymentActions::
 * autoApprove()). Only receipts sent in the bot, or uploaded by a customer whose account is a Telegram account (the mini
 * app's, a website sign-in with Telegram — Payment::selfApproving()): one an email or a phone number alone vouches for
 * — a throwaway account, maybe — always waits for support. Every minute, in every shop.
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

    /** Approves the method's receipts that may approve on their own and wait past its window, oldest first. */
    private function approve(PaymentMethod $method, int $window): void
    {
        $due = Payment::whereSelfApproving(Payment::query())
            ->where('payment_method_id', $method->id)
            ->where('status', PaymentStatus::AwaitingReview->value)
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
