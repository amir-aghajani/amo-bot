<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Http\ApiController;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Store\Services\StoreCatalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * What the shop sells, to anyone (Store\Services\StoreCatalog): the plans as its bot offers them now, one plan, and how
 * their servers stand. A bearer token, when sent (OptionalCustomerMiddleware), prices the plans for that customer.
 */
final class CatalogController extends ApiController
{
    public function __construct(private readonly StoreCatalog $catalog) {}

    /** GET /plans — the categories in the bot's order, each with its plans; the rest last, under none. */
    public function plans(Request $request, Response $response): Response
    {
        return $this->json($response, ['groups' => $this->catalog->groups(Customer::optional($request)?->user)]);
    }

    /**
     * GET /plans/{id}
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404 for a plan the shop does not sell now
     */
    public function plan(Request $request, Response $response, array $args): Response
    {
        return $this->json($response, ['plan' => $this->catalog->plan($this->routeId($args), Customer::optional($request)?->user)]);
    }

    /** GET /status — the servers the plans on sale are on, and whether each takes a new customer now. */
    public function status(Request $request, Response $response): Response
    {
        return $this->json($response, ['servers' => $this->catalog->status()]);
    }
}
