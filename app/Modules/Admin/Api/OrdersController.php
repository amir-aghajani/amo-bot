<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Auth\Principal;
use App\Modules\Orders\Exceptions\RequestKeyReusedException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\ManualOrders;
use App\Modules\Orders\Services\OrderActions;
use App\Modules\Orders\Services\OrderDirectory;
use App\Modules\Store\Http\IdempotencyKey;
use App\Modules\Subscriptions\Services\SubscriptionDirectory;
use App\Modules\Users\Models\User;
use App\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The orders screen: the list, and the modal's operations — retry a failed delivery, cancel an unpaid order — each
 * OrderActions', which tells the customer. An operation answers the order's row as it stands after it. And «افزودن
 * سفارش» on a customer's page: an order support makes for them by hand, paid and delivered at once (ManualOrders).
 */
final class OrdersController extends ApiController
{
    public function __construct(
        private readonly OrderDirectory $orders,
        private readonly OrderActions $actions,
        private readonly ManualOrders $manual,
        private readonly SubscriptionDirectory $subscriptions,
    ) {}

    /**
     * POST /users/{id}/orders — {plan_id? | traffic_gb + days + devices, server_id, client_name?, payment, amount?, note?}
     * with an Idempotency-Key: an order support makes for the customer, paid and delivered at once — the order's row and
     * the service it made (null while its delivery failed); 201 when made now, 200 for the key's order made before.
     *
     * @param array<string, string> $args
     * @throws ActorRefusedException 403 a shop admin's own account
     * @throws ValidationException 422 under the field refused
     * @throws RequestKeyReusedException 422 on `idempotency_key`
     */
    public function store(Request $request, Response $response, array $args): Response
    {
        $customer = $this->load(User::class, $args);
        $order = $this->manual->make($customer, Principal::of($request)->actor(), $this->input($request), IdempotencyKey::of($request));
        $made = $order->wasRecentlyCreated;
        $order->load(OrderDirectory::RELATIONS);
        $service = $order->subscription?->load(['user', 'plan', 'server']);

        return $this->json($response, [
            'order' => $this->orders->present($order),
            'subscription' => $service === null ? null : $this->subscriptions->present($service),
        ], $made ? 201 : 200);
    }

    /** GET /orders?search=&status=&type=&user=&from=&to=&sort=&dir=&page= */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, $this->orders->search($this->pageRequest($request))->toArray('orders'));
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
