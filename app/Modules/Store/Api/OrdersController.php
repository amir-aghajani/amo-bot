<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Database\PageRequest;
use App\Core\Http\ApiController;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Store\Presenters\OrderPresenter;
use App\Modules\Store\Services\CustomerOrders;
use App\Support\Input;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** A signed-in customer's orders on the shop's website, with their payments (Store\Services\CustomerOrders). */
final class OrdersController extends ApiController
{
    public function __construct(
        private readonly CustomerOrders $orders,
        private readonly OrderPresenter $presenter,
    ) {}

    /** GET /orders — ?status=&type=&page=, newest first. */
    public function index(Request $request, Response $response): Response
    {
        $page = $this->orders->page(Customer::of($request)->user, PageRequest::fromQuery($request->getQueryParams()));

        return $this->json($response, $page->toArray('orders'));
    }

    /**
     * GET /orders/{id} — another customer's is a 404.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $order = $this->orders->find(Customer::of($request)->user, Input::integerOf($args['id'] ?? null) ?? 0);

        return $this->json($response, ['order' => $this->presenter->present($order)]);
    }
}
