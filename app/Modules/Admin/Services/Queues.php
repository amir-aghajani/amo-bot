<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Models\Review;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Ticket;

/**
 * The shop's queues that wait on a human, counted: the receipts to review, the orders paid and not delivered
 * (Order::stuck()), the support tickets waiting on an answer and the customers' reviews waiting on support — the numbers
 * the panels' sidebar shows beside «پرداخت‌ها», «سفارش‌ها», «پشتیبانی» and «نظرات», and the first rows of the dashboard's
 * attention card. The current shop's.
 */
final class Queues
{
    /** @return array{payments_to_review: int, stuck_orders: int, open_tickets: int, pending_reviews: int} */
    public static function counts(): array
    {
        return [
            'payments_to_review' => Payment::query()->where('status', PaymentStatus::AwaitingReview->value)->count(),
            'stuck_orders' => Order::stuck()->count(),
            'open_tickets' => Ticket::query()->where('status', TicketStatus::Open->value)->count(),
            'pending_reviews' => Review::query()->where('status', ReviewStatus::Pending->value)->count(),
        ];
    }
}
