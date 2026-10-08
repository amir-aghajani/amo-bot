<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Database\PageRequest;
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
use App\Support\Input;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * A signed-in customer's support tickets on the shop's website (Store\Services\CustomerTickets — their own only) and
 * what they write in them (Support\Services\Tickets, the bot's and the panels' one service): open one, write, read it —
 * and say they read it —, see a picture, close it, rate it. A message comes as JSON, or as a form with its picture
 * (`file`): a picture is held to the customer's one budget of uploads, and none is taken while the host's disk is full
 * (a 503).
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
        $page = $this->tickets->page(Customer::of($request)->user, PageRequest::fromQuery($request->getQueryParams()));

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
        $ticket = $this->support->open($customer, $this->input($request), $this->file($request), TicketChannel::Web);

        return $this->json($response, ['ticket' => TicketPresenter::detail($this->tickets->find($customer, $ticket->id))], 201);
    }

    /**
     * GET /tickets/{id} — the ticket with its conversation, as it stands: reading it changes nothing (POST …/read says
     * the customer read it).
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->ticket($request, $response, $args);
    }

    /**
     * POST /tickets/{id}/read — the website showed the customer the ticket: support's latest words read, and the notices
     * about it in their feed.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     */
    public function read(Request $request, Response $response, array $args): Response
    {
        $this->support->markRead($this->tickets->find(Customer::of($request)->user, self::id($args), messages: false));

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
        $this->support->write($this->tickets->find($customer, self::id($args), messages: false), $this->input($request), $this->file($request), TicketChannel::Web);

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
        $message = $this->tickets->picture(Customer::of($request)->user, self::id($args), Input::integerOf($args['message'] ?? null) ?? 0);
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
        $this->support->close($this->tickets->find(Customer::of($request)->user, self::id($args), messages: false), null);

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
        $this->support->rate($this->tickets->find(Customer::of($request)->user, self::id($args), messages: false), $this->input($request));

        return $this->ticket($request, $response, $args);
    }

    /**
     * The ticket as it stands now, read again with its conversation.
     *
     * @param array<string, string> $args
     */
    private function ticket(Request $request, Response $response, array $args): Response
    {
        return $this->json($response, ['ticket' => TicketPresenter::detail($this->tickets->find(Customer::of($request)->user, self::id($args)))]);
    }

    /** The picture a form carried (`file`); none for JSON. */
    private function file(Request $request): ?UploadedFileInterface
    {
        $file = $request->getUploadedFiles()['file'] ?? null;

        return $file instanceof UploadedFileInterface ? $file : null;
    }

    /** @param array<string, string> $args */
    private static function id(array $args): int
    {
        return Input::integerOf($args['id'] ?? null) ?? 0;
    }
}
