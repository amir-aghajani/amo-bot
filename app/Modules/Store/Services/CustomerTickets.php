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
 * A customer's support tickets on the shop's website — their own only (Ticket::of()), anyone else's not there at all —:
 * the list, the latest activity first, with how many hold support's words they have not read; one with its conversation;
 * and a message's picture, PICTURES an hour at most — each one Telegram may be asked for, each bytes the host sends (a
 * browser keeps one five minutes). What is written in them is Support\Services\Tickets'.
 */
final class CustomerTickets
{
    /** The pictures a customer's website asks for in a window of PICTURE_WINDOW seconds — a few pages of a long conversation, and room to spare. */
    public const PICTURES = 120;
    public const PICTURE_WINDOW = 3600;

    public function __construct(private readonly RateLimiter $limiter) {}

    /**
     * The customer's tickets, the latest activity first — in one state (`status`, a TicketStatus) or all —, a page of them,
     * and how many of all of theirs hold support's words they have not read (`unread`: a website's badge).
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
     * One of the customer's tickets — with its conversation when `$messages` —: another's is not there, as one that never
     * was.
     *
     * @throws ModelNotFoundException 404
     */
    public function find(User $customer, int $id, bool $messages = true): Ticket
    {
        return Ticket::of($customer)->with($messages ? ['subscription', 'messages'] : ['subscription'])->findOrFail($id)->setRelation('user', $customer);
    }

    /**
     * A message of one of the customer's tickets, for its picture: every ask counts, a refused one too — PICTURES in
     * PICTURE_WINDOW, then the wait.
     *
     * @throws TooManyAttemptsException 429
     * @throws ModelNotFoundException 404: no ticket of theirs, or no such message in it
     */
    public function picture(User $customer, int $ticketId, int $messageId): TicketMessage
    {
        $wait = $this->limiter->attempt([['tickets|pictures|' . $customer->id, self::PICTURES, self::PICTURE_WINDOW]]);
        if ($wait > 0) {
            throw TooManyAttemptsException::wait('تصویر زیادی خواسته‌اید', $wait);
        }
        $ticket = Ticket::of($customer)->findOrFail($ticketId);

        return TicketMessage::query()->where('ticket_id', $ticket->id)->findOrFail($messageId);
    }
}
