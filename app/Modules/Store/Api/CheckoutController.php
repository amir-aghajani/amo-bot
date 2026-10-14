<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Exceptions\TooManyAttemptsException;
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
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Models\Subscription;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A signed-in customer's checkout (Store\Services\CustomerCheckout): the ways to pay, a purchase, a renewal, its
 * preview and the plans it may be on, a service's extras, a discount code checked, a wallet top-up — each ordering
 * request with its Idempotency-Key, read before anything else — and a card transfer's receipt
 * (Store\Services\CustomerReceipts).
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
     * GET /payment-methods — ?for=purchase|renewal|wallet_topup|extra_traffic|extra_time|extra_devices: the ways to pay
     * that kind of order.
     *
     * @throws ValidationException 422 on `for`
     */
    public function methods(Request $request, Response $response): Response
    {
        return $this->json($response, ['methods' => $this->checkout->methods($request->getQueryParams())]);
    }

    /**
     * POST /orders — {plan_id, server_id, method_id, discount_code?}: a purchase, paid.
     *
     * @throws ValidationException 422
     * @throws NoServerAvailableException 422 on `server_id`
     * @throws RequestKeyReusedException 422 on `idempotency_key`
     * @throws CheckoutRefusedException 422 / 409; 503 the shop takes no orders while its bot is switched off
     */
    public function purchase(Request $request, Response $response): Response
    {
        $key = IdempotencyKey::of($request);

        return $this->answer($request, $response, $this->checkout->purchase(Customer::of($request)->user, $this->input($request), $key));
    }

    /**
     * GET /subscriptions/{id}/renewal — ?plan_id= (or ?gb=&days=, a package built) for a service without a plan, ?method=
     * the renewal method: the renewal before it is paid.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     * @throws ValidationException 422 on `method`, `status`, `plan_id`, `gb` or `days`
     * @throws RenewalUnderWayException 422 on `status`
     */
    public function renewal(Request $request, Response $response, array $args): Response
    {
        $preview = $this->checkout->renewal($this->own($request, $args), $request->getQueryParams());

        return $this->json($response, ['renewal' => SubscriptionPresenter::renewal($preview)]);
    }

    /**
     * GET /subscriptions/{id}/renewal-plans — the plans the service may be renewed on now, at the customer's prices, and
     * whether a package the customer builds may renew it instead.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     */
    public function renewalPlans(Request $request, Response $response, array $args): Response
    {
        return $this->json($response, $this->checkout->renewalChoices(Customer::of($request)->user, $this->own($request, $args)));
    }

    /**
     * POST /subscriptions/{id}/renewal — {method_id, plan_id? | gb? + days?, method?, discount_code?}: the service renewed,
     * paid.
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
     * GET /subscriptions/{id}/extras — the extra traffic, time and devices the service may buy now, at its prices.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     */
    public function extras(Request $request, Response $response, array $args): Response
    {
        return $this->json($response, ['extras' => SubscriptionPresenter::extras($this->checkout->extras($this->own($request, $args)))]);
    }

    /**
     * POST /subscriptions/{id}/extra-traffic — {gb, method_id, discount_code?}: extra traffic, paid.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     * @throws ValidationException 422
     * @throws RequestKeyReusedException 422 on `idempotency_key`
     * @throws CheckoutRefusedException 422 / 409; 503 the shop takes no orders while its bot is switched off
     */
    public function extraTraffic(Request $request, Response $response, array $args): Response
    {
        return $this->extra($request, $response, $args, ExtraKind::Traffic);
    }

    /**
     * POST /subscriptions/{id}/extra-time — {days, method_id, discount_code?}: extra time, paid.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     * @throws ValidationException 422
     * @throws RequestKeyReusedException 422 on `idempotency_key`
     * @throws CheckoutRefusedException 422 / 409; 503 the shop takes no orders while its bot is switched off
     */
    public function extraTime(Request $request, Response $response, array $args): Response
    {
        return $this->extra($request, $response, $args, ExtraKind::Time);
    }

    /**
     * POST /subscriptions/{id}/extra-devices — {devices, method_id, discount_code?}: extra devices, paid at their price
     * prorated to what the service has left.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     * @throws ValidationException 422
     * @throws RequestKeyReusedException 422 on `idempotency_key`
     * @throws CheckoutRefusedException 422 / 409; 503 the shop takes no orders while its bot is switched off
     */
    public function extraDevices(Request $request, Response $response, array $args): Response
    {
        return $this->extra($request, $response, $args, ExtraKind::Devices);
    }

    /**
     * POST /discount-codes/check — {code, for, plan_id?, server_id?, subscription_id?, gb?, days?, devices?}: what the
     * code takes off, nothing ordered (a renewal of a service without a plan names the plan picked in `plan_id`, or the
     * package built in `gb` and `days`, and a renewal its `method`).
     *
     * @throws ValidationException 422 on `code` and the rest
     * @throws ModelNotFoundException 404: no service of theirs
     * @throws TooManyAttemptsException 429: too many codes that did not hold
     */
    public function checkCode(Request $request, Response $response): Response
    {
        return $this->json($response, ['quote' => $this->checkout->checkCode(Customer::of($request)->user, $this->input($request))]);
    }

    /**
     * POST /wallet/top-up — {amount, method_id}: the wallet charged.
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
     * POST /payments/{id}/receipt — multipart `file` and `note`: a card transfer's receipt; answers its order.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404: no payment of theirs
     * @throws ValidationException 422 on `status`, `note` or `file`
     * @throws CheckoutRefusedException 503 the shop takes no orders while its bot is switched off
     */
    public function receipt(Request $request, Response $response, array $args): Response
    {
        $order = $this->receipts->upload(Customer::of($request)->user, $this->routeId($args), $this->uploadedFile($request), $this->input($request));

        return $this->json($response, ['order' => $this->orders->present($order)]);
    }

    /**
     * `$kind` bought for the service the route names; the key is read first.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     * @throws ValidationException 422
     * @throws RequestKeyReusedException 422 on `idempotency_key`
     * @throws CheckoutRefusedException
     */
    private function extra(Request $request, Response $response, array $args, ExtraKind $kind): Response
    {
        $key = IdempotencyKey::of($request);
        $subscription = $this->own($request, $args);

        return $this->answer($request, $response, $this->checkout->extra(Customer::of($request)->user, $subscription, $kind, $this->input($request), $key));
    }

    /** @throws CheckoutRefusedException */
    private function answer(Request $request, Response $response, CheckoutResult $result): Response
    {
        return $this->json($response, ['checkout' => $this->checkout->answer(Customer::of($request)->user, $result)]);
    }

    /**
     * The customer's own service the route names.
     *
     * @param array<string, string> $args
     * @throws ModelNotFoundException 404
     */
    private function own(Request $request, array $args): Subscription
    {
        return $this->subscriptions->find(Customer::of($request)->user, $this->routeId($args));
    }
}
