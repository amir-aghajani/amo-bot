<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Database\PageRequest;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Store\Services\CustomerNotifications;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A signed-in customer's notices on the shop's website — everything the shop told them, whether it reached them in
 * Telegram, by email or not at all (Store\Services\CustomerNotifications) —, and marking them read.
 */
final class NotificationsController extends ApiController
{
    public function __construct(private readonly CustomerNotifications $notifications) {}

    /** GET /notifications — ?unread=&page=, newest first, how many are unread in its meta. */
    public function index(Request $request, Response $response): Response
    {
        $page = $this->notifications->page(Customer::of($request)->user, PageRequest::fromQuery($request->getQueryParams()));

        return $this->json($response, $page->toArray('notifications'));
    }

    /**
     * POST /notifications/read — {ids?}: those of theirs read (every one, without `ids`); how many are left unread.
     *
     * @throws ValidationException 422 on `ids`
     */
    public function read(Request $request, Response $response): Response
    {
        return $this->json($response, ['unread' => $this->notifications->markRead(Customer::of($request)->user, $this->input($request))]);
    }
}
