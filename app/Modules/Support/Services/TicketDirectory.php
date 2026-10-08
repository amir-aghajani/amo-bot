<?php

declare(strict_types=1);

namespace App\Modules\Support\Services;

use App\Core\Database\Page;
use App\Core\Database\PageRequest;
use App\Modules\Auth\Services\Reviewers;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Presenters\TicketFields;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\UserDirectory;
use Illuminate\Database\Eloquent\Builder;

/**
 * The tickets screen («پشتیبانی», both panels): every ticket of the shop, the latest activity first — by status (the open
 * ones the queue waiting on support, counted), one customer's (`user`, their page links here), searched by the customer,
 * the subject or a number —, and one ticket with its whole conversation: who of support wrote what (Reviewers — the
 * owner's login is «پشتیبانی» in an agent's shop) and where each message was written.
 */
final class TicketDirectory
{
    /** What a row shows, loaded with it. */
    public const RELATIONS = ['user', 'subscription'];

    public function __construct(private readonly Reviewers $reviewers) {}

    /** One status (a tab) or all, one customer's, searched — the latest activity first —; with the open ones counted. */
    public function search(PageRequest $list): Page
    {
        $query = Ticket::query();
        $status = $list->enum('status', TicketStatus::class);
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        $user = $list->id('user');
        if ($user !== null) {
            $query->where('user_id', $user);
        }
        $list->search($query, self::applySearch(...));

        return Page::fetch($query->with(self::RELATIONS)->withCount('messages')->orderByDesc('last_message_at')->orderByDesc('id'), $list, $this->present(...))
            ->with(['open' => Ticket::query()->where('status', TicketStatus::Open->value)->count()]);
    }

    /** @return array<string, mixed> One row of the screen, its messages counted with it (withCount()). */
    public function present(Ticket $ticket): array
    {
        return $this->row($ticket, (int) $ticket->getAttribute('messages_count'));
    }

    /** @return array<string, mixed> The ticket with every message — its relations and messages loaded. */
    public function detail(Ticket $ticket): array
    {
        return $this->row($ticket, $ticket->messages->count()) + [
            'rating_note' => $ticket->rating_note,
            'messages' => $ticket->messages->map(fn(TicketMessage $message): array => TicketFields::message($message) + [
                'reviewer' => $this->reviewers->present($message->reviewer),
                'channel' => $message->channel->value,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> What every screen says of it, with who the customer is and how many messages it has. */
    private function row(Ticket $ticket, int $messages): array
    {
        return TicketFields::ticket($ticket) + [
            'customer' => UserDirectory::presentRef($ticket->user),
            'messages_count' => $messages,
        ];
    }

    /**
     * By the customer — name, handle, email, Telegram id, the way every list searches its customers —, the subject, or a
     * bare ticket number. («#12» is the ticket numbered 12 alone: PageRequest::search().) The customers are found once
     * (User::idsMatching()), so the tickets are read through their index on the customer.
     *
     * @param Builder<Ticket> $query
     */
    private static function applySearch(Builder $query, string $term, ?int $number): void
    {
        $users = User::idsMatching($term);

        $query->where(static function (Builder $q) use ($users, $term, $number): void {
            $q->whereIn('user_id', $users);
            Page::orWhereContains($q, 'subject', $term);
            if ($number !== null) {
                $q->orWhere('id', $number);
            }
        });
    }
}
