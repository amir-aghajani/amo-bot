<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Database\PageRequest;
use App\Core\Http\ApiController;
use App\Modules\Auth\Principal;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderActions;
use App\Modules\Orders\Services\OrderDirectory;
use App\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The orders screen: the list, and what its modal can do with an order — deliver it again when its delivery failed,
 * drop it while nobody has paid — each OrderActions', which tells the customer; the answer is the order's row as it
 * stands after it.
 */
final class OrdersController extends ApiController
{
    public function __construct(
        private readonly OrderDirectory $orders,
        private readonly OrderActions $actions,
    ) {}

    /** GET /orders?search=&status=&type=&user=&from=&to=&sort=&dir=&page= */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, $this->orders->search(PageRequest::fromQuery($request->getQueryParams()))->toArray('orders'));
    }

    /**
     * GET /orders/{id} — one order as the list shows it: the modal keeps it fresh while it is open
     *
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->row($response, $this->find($args));
    }

    /**
     * POST /orders/{id}/retry — deliver a paid order whose delivery failed
     *
     * @param array<string, string> $args
     */
    public function retry(Request $request, Response $response, array $args): Response
    {
        $order = $this->find($args);
        $this->actions->retry($order);

        return $this->row($response, $order);
    }

    /**
     * POST /orders/{id}/cancel — {note?}: the order with its unpaid payments; the note goes to the customer
     *
     * @param array<string, string> $args
     */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $order = $this->find($args);
        $this->actions->cancel($order, Principal::of($request)->actor(), Input::note($this->input($request), 'note'));

        return $this->row($response, $order);
    }

    /** @param array<string, string> $args */
    private function find(array $args): Order
    {
        return $this->load(Order::class, $args, OrderDirectory::RELATIONS);
    }

    /** The order's row as it stands now — re-read, as an operation left it. */
    private function row(Response $response, Order $order): Response
    {
        return $this->json($response, ['order' => $this->orders->present($order->load(OrderDirectory::RELATIONS))]);
    }
}
