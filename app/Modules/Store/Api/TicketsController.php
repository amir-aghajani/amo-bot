<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Store\Presenters\TicketPresenter;
use App\Modules\Store\Services\CustomerTickets;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Exceptions\AttachmentUnavailableException;
use App\Modules\Support\Services\TicketAttachments;
use App\Modules\Support\Services\Tickets;
use App\Modules\Telegram\Api\TelegramUnreachableException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A signed-in customer's support tickets (Store\Services\CustomerTickets, their own only), worked through the one ticket
 * service the bot and the panels use (Support\Services\Tickets): open, write, read and mark read, see a picture, close,
 * rate. A message comes as JSON, or as a multipart form with its picture (`file`).
 */
final class TicketsController extends ApiController
{
    public function __construct(
        private readonly CustomerTickets $tickets,
        private readonly Tickets $support,
        private readonly TicketAttachments $attachments,
    ) {}

    /** GET /tickets — ?status=&page=, the latest activity first; how many hold support's unread words in `meta.unread`. */
    public function index(Request $request, Response $response): Response
    {
        $page = $this->tickets->page(Customer::of($request)->user, $this->pageRequest($request));

        return $this->json($response, $page->toArray('tickets'));
    }

    /**
     * POST /tickets — {subject, body, subscription_id?}, and `file` as a form: the ticket opened, 201.
     *
     * @throws ValidationException 422
     * @throws TooManyAttemptsException 429
     */
    public function open(Request $request, Response $response): Response
    {
        $customer = Customer::of($request)->user;
        $ticket = $this->support->open($customer, $this->input($request), $this->uploadedFile($request), TicketChannel::Web);

        return $this->json($response, ['ticket' => TicketPresenter::detail($this->tickets->find($customer, $ticket->id))], 201);
    }

    /**
     * GET /tickets/{id} — the ticket with its conversation. Reading changes nothing: POST …/read marks it read.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->ticket($request, $response, $args);
    }

    /**
     * POST /tickets/{id}/read — the customer saw the ticket: support's words and the ticket's notices are marked read.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     */
    public function read(Request $request, Response $response, array $args): Response
    {
        $this->support->markRead($this->tickets->find(Customer::of($request)->user, $this->routeId($args), messages: false));

        return $this->ticket($request, $response, $args);
    }

    /**
     * POST /tickets/{id}/messages — {body}, and `file` as a form: a closed ticket opens again.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     * @throws ValidationException 422
     * @throws TooManyAttemptsException 429
     */
    public function write(Request $request, Response $response, array $args): Response
    {
        $customer = Customer::of($request)->user;
        $this->support->write($this->tickets->find($customer, $this->routeId($args), messages: false), $this->input($request), $this->uploadedFile($request), TicketChannel::Web);

        return $this->ticket($request, $response, $args);
    }

    /**
     * GET /tickets/{id}/messages/{message}/attachment — a message's picture, CustomerTickets::PICTURES an hour.
     *
     * @param array<string, string> $args
     * @throws TooManyAttemptsException 429
     * @throws ModelNotFoundException 404
     * @throws AttachmentUnavailableException 404
     * @throws TelegramUnreachableException 502
     */
    public function attachment(Request $request, Response $response, array $args): Response
    {
        $message = $this->tickets->picture(Customer::of($request)->user, $this->routeId($args), $this->routeId($args, 'message'));
        $file = $this->attachments->fetch($message);

        return $this->bytes($response, $file['body'], $file['mime'], 'private, max-age=300', $file['name']);
    }

    /**
     * POST /tickets/{id}/close
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     * @throws ValidationException 422 on `status`: closed already
     */
    public function close(Request $request, Response $response, array $args): Response
    {
        $this->support->close($this->tickets->find(Customer::of($request)->user, $this->routeId($args), messages: false), null);

        return $this->ticket($request, $response, $args);
    }

    /**
     * POST /tickets/{id}/rating — {rating, note?}: once it is closed.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     * @throws ValidationException 422 on `rating`, `note` or `status`
     */
    public function rate(Request $request, Response $response, array $args): Response
    {
        $this->support->rate($this->tickets->find(Customer::of($request)->user, $this->routeId($args), messages: false), $this->input($request));

        return $this->ticket($request, $response, $args);
    }

    /**
     * The ticket as it stands now, read again with its conversation: what every route answers.
     *
     * @param array<string, string> $args
     */
    private function ticket(Request $request, Response $response, array $args): Response
    {
        return $this->json($response, ['ticket' => TicketPresenter::detail($this->tickets->find(Customer::of($request)->user, $this->routeId($args)))]);
    }
}
