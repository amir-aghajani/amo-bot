<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Support\Enums\TicketStatus;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * The queues that wait on a human (GET /queues, both panels — the numbers the sidebar shows beside the payments, the
 * orders, the tickets and the reviews): the receipts to review, the orders paid and not delivered — those whose delivery
 * failed and, with time alone, those nobody is delivering, while what paid them stands —, the support tickets waiting on
 * an answer and the customers' reviews waiting on support. Each shop its own; the dashboard's attention card counts the
 * same.
 */
final class AdminQueuesApiTest extends HttpTestCase
{
    public function testTheQueuesAreTheReceiptsToReviewTheOrdersPaidAndNotDeliveredTheTicketsAndTheReviewsWaitingOnSupport(): void
    {
        $this->telegram();
        $this->loginAsAdmin();
        $customer = $this->customer();
        $card = $this->cardMethod();
        $paid = ['status' => PaymentStatus::Paid, 'paid_at' => now()];
        $this->receipt($this->cardPayment($this->topUpOrder($customer, '10000.00'), $card));
        $this->cardPayment($this->topUpOrder($customer, '20000.00', ['status' => OrderStatus::Failed]), $card, $paid);
        $this->cardPayment($this->topUpOrder($customer, '30000.00', ['status' => OrderStatus::Paid]), $card, $paid);
        $this->cardPayment($this->topUpOrder($customer, '40000.00', ['status' => OrderStatus::Fulfilled]), $card, $paid);
        // Its money given back: nothing for support to deliver.
        $this->cardPayment($this->topUpOrder($customer, '50000.00', ['status' => OrderStatus::Failed]), $card, ['status' => PaymentStatus::Refunded] + $paid);
        // Waiting on support; answered, it waits on the customer; closed, on nobody.
        $this->ticket($customer);
        $this->ticket($customer, overrides: ['status' => TicketStatus::Answered]);
        $this->ticket($customer, overrides: ['status' => TicketStatus::Closed, 'closed_at' => now()]);
        // Waiting on support; decided, on nobody.
        $this->review();
        $this->review(overrides: ['status' => ReviewStatus::Approved]);
        $this->review(overrides: ['status' => ReviewStatus::Rejected]);
        $queues = fn(string $panel = 'admin'): array => $this->decode($this->get("/api/{$panel}/queues"))['queues'];

        self::assertSame(['payments_to_review' => 1, 'stuck_orders' => 1, 'open_tickets' => 1, 'pending_reviews' => 1], $queues());

        Carbon::setTestNow(now()->addMinutes(OrderService::STALE_PROCESSING_MINUTES));
        self::assertSame(['payments_to_review' => 1, 'stuck_orders' => 2, 'open_tickets' => 1, 'pending_reviews' => 1], $queues(), 'a paid order nobody is delivering joins them with time alone');
        $attention = $this->decode($this->get('/api/admin/dashboard'))['attention'];
        self::assertSame([1, 2, 1, 1], [$attention['payments_to_review'], $attention['stuck_orders'], $attention['open_tickets'], $attention['pending_reviews']], "the dashboard's attention card counts the same");

        $this->loginAsAgent($this->agentBot());
        self::assertSame(['payments_to_review' => 0, 'stuck_orders' => 0, 'open_tickets' => 0, 'pending_reviews' => 0], $queues('agent'), "an agent's shop is its own");
    }
}
