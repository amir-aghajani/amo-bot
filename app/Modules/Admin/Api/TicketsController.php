<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Database\PageRequest;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Auth\Principal;
use App\Modules\Support\Exceptions\AttachmentUnavailableException;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Services\TicketAttachments;
use App\Modules\Support\Services\TicketDirectory;
use App\Modules\Support\Services\Tickets;
use App\Modules\Telegram\Api\TelegramUnreachableException;
use App\Support\Input;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The tickets screen (both panels, and the shop's website for its admins): the list, one ticket with its conversation,
 * and what support does with it — answer (JSON, or a form with its picture), close, reopen —, each
 * Support\Services\Tickets' (the customer told), the request's principal the one who did it; the answer is the ticket
 * as it stands after it.
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
        return $this->json($response, $this->tickets->search(PageRequest::fromQuery($request->getQueryParams()))->toArray('tickets'));
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
     * POST /tickets/{id}/messages — {body}, and `file` as a form: support's answer
     *
     * @param array<string, string> $args
     * @throws ValidationException 422
     */
    public function answer(Request $request, Response $response, array $args): Response
    {
        $file = $request->getUploadedFiles()['file'] ?? null;
        $this->support->answer($this->find($args), Principal::of($request)->actor(), $this->input($request), $file instanceof UploadedFileInterface ? $file : null);

        return $this->ticket($response, $args);
    }

    /**
     * POST /tickets/{id}/close — the customer told
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
        $message = TicketMessage::query()->where('ticket_id', $ticket->id)->findOrFail(Input::integerOf($args['message'] ?? null) ?? 0);
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
