<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Catalog\Exceptions\NoServerAvailableException;
use App\Modules\Orders\Exceptions\RenewalUnderWayException;
use App\Modules\Orders\Exceptions\RequestKeyReusedException;
use App\Modules\Payments\DTO\CheckoutResult;
use App\Modules\Store\Exceptions\CheckoutRefusedException;
use App\Modules\Store\Http\IdempotencyKey;
use App\Modules\Store\Presenters\OrderPresenter;
use App\Modules\Store\Presenters\SubscriptionPresenter;
use App\Modules\Store\Services\CustomerCheckout;
use App\Modules\Store\Services\CustomerReceipts;
use App\Modules\Store\Services\CustomerSubscriptions;
use App\Modules\Subscriptions\Models\Subscription;
use App\Support\Input;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * A signed-in customer's checkout on the shop's website (Store\Services\CustomerCheckout, over the shop's one checkout):
 * the ways to pay, a purchase, a service's renewal and its preview, a wallet top-up — each ordering request with its
 * Idempotency-Key — and a card transfer's receipt (Store\Services\CustomerReceipts).
 */
final class CheckoutController extends ApiController
{
    public function __construct(
        private readonly CustomerCheckout $checkout,
        private readonly CustomerReceipts $receipts,
        private readonly CustomerSubscriptions $subscriptions,
        private readonly OrderPresenter $orders,
    ) {}

    /**
     * GET /payment-methods — ?for=purchase|renewal|wallet_topup: the ways to pay that kind of order.
     *
     * @throws ValidationException 422 on `for`
     */
    public function methods(Request $request, Response $response): Response
    {
        return $this->json($response, ['methods' => $this->checkout->methods($request->getQueryParams())]);
    }

    /**
     * POST /orders — {plan_id, server_id, method_id} with its Idempotency-Key: a purchase, paid.
     *
     * @throws ValidationException 422
     * @throws NoServerAvailableException 422 on `server_id`
     * @throws RequestKeyReusedException 422 on `idempotency_key`
     * @throws CheckoutRefusedException 422 / 409; 503 the shop takes no orders while its bot is switched off
     */
    public function purchase(Request $request, Response $response): Response
    {
        $key = IdempotencyKey::of($request);
        $customer = Customer::of($request)->user;

        return $this->answer($request, $response, $this->checkout->purchase($customer, $this->input($request), $key));
    }

    /**
     * GET /subscriptions/{id}/renewal — the renewal before it is paid: the plan, its price, the service as it would leave it.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     * @throws ValidationException 422 on `status`
     * @throws RenewalUnderWayException 422 on `status`
     */
    public function renewal(Request $request, Response $response, array $args): Response
    {
        $preview = $this->checkout->renewal($this->own($request, $args));
        $plan = $preview->plan;

        return $this->json($response, ['renewal' => [
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'price' => $plan->price,
                'traffic_gb' => $plan->traffic_gb,
                'duration_days' => $plan->duration_days,
            ],
            'price' => $preview->price,
            'after' => [
                'term' => SubscriptionPresenter::term($preview->after),
                'traffic' => SubscriptionPresenter::traffic($preview->after),
                'next_period' => SubscriptionPresenter::nextPeriod($preview->after),
            ],
        ]]);
    }

    /**
     * POST /subscriptions/{id}/renewal — {method_id} with its Idempotency-Key: the service renewed, paid.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     * @throws ValidationException 422
     * @throws RenewalUnderWayException 422 on `status`
     * @throws RequestKeyReusedException 422 on `idempotency_key`
     * @throws CheckoutRefusedException 422 / 409; 503 the shop takes no orders while its bot is switched off
     */
    public function renew(Request $request, Response $response, array $args): Response
    {
        $key = IdempotencyKey::of($request);
        $subscription = $this->own($request, $args);

        return $this->answer($request, $response, $this->checkout->renew(Customer::of($request)->user, $subscription, $this->input($request), $key));
    }

    /**
     * POST /wallet/top-up — {amount, method_id} with its Idempotency-Key: the wallet charged.
     *
     * @throws ValidationException 422
     * @throws RequestKeyReusedException 422 on `idempotency_key`
     * @throws CheckoutRefusedException 422 / 409; 503 the shop takes no orders while its bot is switched off
     */
    public function topUp(Request $request, Response $response): Response
    {
        $key = IdempotencyKey::of($request);

        return $this->answer($request, $response, $this->checkout->topUp(Customer::of($request)->user, $this->input($request), $key));
    }

    /**
     * POST /payments/{id}/receipt — multipart: `file` (the picture), `note`: a card transfer's receipt; its order, the
     * payment awaiting review.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404: no payment of theirs
     * @throws ValidationException 422 on `status`, `note` or `file`
     * @throws CheckoutRefusedException 503 the shop takes no orders while its bot is switched off
     */
    public function receipt(Request $request, Response $response, array $args): Response
    {
        $file = $request->getUploadedFiles()['file'] ?? null;
        $order = $this->receipts->upload(
            Customer::of($request)->user,
            Input::integerOf($args['id'] ?? null) ?? 0,
            $file instanceof UploadedFileInterface ? $file : null,
            $this->input($request),
        );

        return $this->json($response, ['order' => $this->orders->present($order)]);
    }

    /** @throws CheckoutRefusedException */
    private function answer(Request $request, Response $response, CheckoutResult $result): Response
    {
        return $this->json($response, ['checkout' => $this->checkout->answer(Customer::of($request)->user, $result)]);
    }

    /**
     * The service the route names, the customer's own.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     */
    private function own(Request $request, array $args): Subscription
    {
        return $this->subscriptions->find(Customer::of($request)->user, Input::integerOf($args['id'] ?? null) ?? 0);
    }
}
