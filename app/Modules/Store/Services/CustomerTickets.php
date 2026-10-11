<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Core\Database\Page;
use App\Core\Database\PageRequest;
use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Security\RateLimiter;
use App\Modules\Store\Presenters\TicketPresenter;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * A customer's own support tickets on the website (another's is a 404): the list with the unread count, one ticket with
 * its conversation, and a message's picture — PICTURES per PICTURE_WINDOW, since each may cost a Telegram download.
 * Writing in them is Support\Services\Tickets'.
 */
final class CustomerTickets
{
    /** Pictures a customer's website may fetch per PICTURE_WINDOW seconds: a few pages of a long conversation, with room to spare. */
    public const PICTURES = 120;
    public const PICTURE_WINDOW = 3600;

    public function __construct(private readonly RateLimiter $limiter) {}

    /**
     * A page of the customer's tickets, latest activity first, narrowed to a `status` when named; `meta.unread` counts all
     * of theirs with support's words unread (a badge).
     */
    public function page(User $customer, PageRequest $request): Page
    {
        $query = Ticket::of($customer)->with('subscription')->orderByDesc('last_message_at')->orderByDesc('id');
        $status = $request->enum('status', TicketStatus::class);
        if ($status !== null) {
            $query->where('status', $status->value);
        }

        return Page::fetch($query, $request, TicketPresenter::present(...))
            ->with(['unread' => Ticket::of($customer)->where('customer_unread', true)->count()]);
    }

    /**
     * One of the customer's tickets, with its conversation when `$messages`; another's is a 404.
     *
     * @throws ModelNotFoundException 404
     */
    public function find(User $customer, int $id, bool $messages = true): Ticket
    {
        return Ticket::of($customer)->with($messages ? ['subscription', 'messages'] : ['subscription'])->findOrFail($id)->setRelation('user', $customer);
    }

    /**
     * A message of one of the customer's tickets, for its picture. Every ask counts against PICTURES, a refused one too.
     *
     * @throws TooManyAttemptsException 429
     * @throws ModelNotFoundException 404: no ticket of theirs, or no such message in it
     */
    public function picture(User $customer, int $ticketId, int $messageId): TicketMessage
    {
        $this->limiter->enforce([['tickets|pictures|' . $customer->id, self::PICTURES, self::PICTURE_WINDOW]], 'تصویر زیادی خواسته‌اید');
        $ticket = Ticket::of($customer)->findOrFail($ticketId);

        return TicketMessage::query()->where('ticket_id', $ticket->id)->findOrFail($messageId);
    }
}
