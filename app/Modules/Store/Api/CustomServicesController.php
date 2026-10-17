<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Catalog\Services\CustomServices;
use App\Modules\Discounts\Exceptions\DiscountCodeRefusedException;
use App\Modules\Orders\Exceptions\RequestKeyReusedException;
use App\Modules\Store\Exceptions\CheckoutRefusedException;
use App\Modules\Store\Http\IdempotencyKey;
use App\Modules\Store\Presenters\CustomServicePresenter;
use App\Modules\Store\Services\CustomerCheckout;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * «سرویس دلخواه» on the website (Catalog\Services\CustomServices): what may be built — anyone may read it —, and for a
 * signed-in customer its price and its checkout (Store\Services\CustomerCheckout), the ordering request with its
 * Idempotency-Key, read before anything else.
 */
final class CustomServicesController extends ApiController
{
    public function __construct(
        private readonly CustomServices $custom,
        private readonly CustomerCheckout $checkout,
    ) {}

    /** GET /custom-services — whether the builder is offered, each quantity's tiers, bounds and buttons, the devices, the servers. */
    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, ['custom_services' => CustomServicePresenter::offer($this->custom)]);
    }

    /**
     * POST /custom-services/quote — {gb, days, server_id?, discount_code?}: what it would cost the customer, nothing ordered.
     *
     * @throws ValidationException 422 on `status`, `gb`, `days` or `server_id`
     * @throws DiscountCodeRefusedException 422 on `discount_code`
     * @throws TooManyAttemptsException 429: too many codes that did not hold
     */
    public function quote(Request $request, Response $response): Response
    {
        return $this->json($response, ['quote' => $this->checkout->customQuote(Customer::of($request)->user, $this->input($request))]);
    }

    /**
     * POST /custom-services/orders — {gb, days, server_id, method_id, name_mode?, client_name?, discount_code?,
     * expected_amount?}: a service built, paid.
     *
     * @throws ValidationException 422
     * @throws RequestKeyReusedException 422 on `idempotency_key`
     * @throws CheckoutRefusedException 422 / 409; 503 the shop takes no orders while its bot is switched off
     */
    public function buy(Request $request, Response $response): Response
    {
        $key = IdempotencyKey::of($request);
        $customer = Customer::of($request)->user;

        return $this->json($response, ['checkout' => $this->checkout->answer($customer, $this->checkout->buildService($customer, $this->input($request), $key))]);
    }
}
