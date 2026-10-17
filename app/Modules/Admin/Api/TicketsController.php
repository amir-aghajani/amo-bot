<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\KeyReusedException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Auth\Principal;
use App\Modules\Store\Http\IdempotencyKey;
use App\Modules\Support\Exceptions\AttachmentUnavailableException;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Services\TicketAttachments;
use App\Modules\Support\Services\TicketDirectory;
use App\Modules\Support\Services\Tickets;
use App\Modules\Telegram\Api\TelegramUnreachableException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The tickets screen (both panels, and the website's admin API): the list, a ticket with its conversation, and support's
 * answer (JSON, or a form with a picture), close and reopen — each Support\Services\Tickets', by the request's principal.
 * An operation answers the ticket as it stands after it.
 */
final class TicketsController extends ApiController
{
    public function __construct(
        private readonly TicketDirectory $tickets,
        private readonly Tickets $support,
        private readonly TicketAttachments $attachments,
    ) {}

    /** GET /tickets?status=&user=&search=&page= */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, $this->tickets->search($this->pageRequest($request))->toArray('tickets'));
    }

    /**
     * GET /tickets/{id}
     *
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->ticket($response, $args);
    }

    /**
     * POST /tickets/{id}/messages — {body}, and `file` as a form: support's answer; with an Idempotency-Key if the caller
     * sends one, the same answer again finds the one the key wrote.
     *
     * @param array<string, string> $args
     * @throws ValidationException 422
     * @throws KeyReusedException 422 on `idempotency_key`: the key wrote another answer
     */
    public function answer(Request $request, Response $response, array $args): Response
    {
        $this->support->answer($this->find($args), Principal::of($request)->actor(), $this->input($request), $this->uploadedFile($request), IdempotencyKey::optional($request));

        return $this->ticket($response, $args);
    }

    /**
     * POST /tickets/{id}/close — the customer is notified
     *
     * @param array<string, string> $args
     * @throws ValidationException 422 on `status`
     */
    public function close(Request $request, Response $response, array $args): Response
    {
        $this->support->close($this->find($args), Principal::of($request)->actor());

        return $this->ticket($response, $args);
    }

    /**
     * POST /tickets/{id}/reopen
     *
     * @param array<string, string> $args
     * @throws ValidationException 422 on `status`
     */
    public function reopen(Request $request, Response $response, array $args): Response
    {
        $this->support->reopen($this->find($args), Principal::of($request)->actor());

        return $this->ticket($response, $args);
    }

    /**
     * GET /tickets/{id}/messages/{message}/attachment — a message's picture
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     * @throws AttachmentUnavailableException 404
     * @throws TelegramUnreachableException 502
     */
    public function attachment(Request $request, Response $response, array $args): Response
    {
        $ticket = $this->load(Ticket::class, $args);
        $message = TicketMessage::query()->where('ticket_id', $ticket->id)->findOrFail($this->routeId($args, 'message'));
        $file = $this->attachments->fetch($message);

        return $this->bytes($response, $file['body'], $file['mime'], 'private, max-age=300', $file['name']);
    }

    /**
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     */
    private function find(array $args): Ticket
    {
        return $this->load(Ticket::class, $args, TicketDirectory::RELATIONS);
    }

    /**
     * The ticket as it stands now, read again with its conversation.
     *
     * @param array<string, string> $args
     */
    private function ticket(Response $response, array $args): Response
    {
        return $this->json($response, ['ticket' => $this->tickets->detail($this->load(Ticket::class, $args, [...TicketDirectory::RELATIONS, 'messages']))]);
    }
}
