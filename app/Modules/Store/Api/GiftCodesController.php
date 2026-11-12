<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Http\ApiController;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Store\Http\StoreMiddleware;
use App\Modules\Store\Services\CustomerGates;
use App\Modules\Store\Services\CustomerGiftCodes;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A signed-in customer redeems a gift code (Store\Services\CustomerGiftCodes) — through the mini app, once the bot's gates
 * let them (Store\Services\CustomerGates::holdBack()).
 */
final class GiftCodesController extends ApiController
{
    public function __construct(
        private readonly CustomerGiftCodes $codes,
        private readonly CustomerGates $gates,
    ) {}

    /** POST /gift-codes/redeem — {code} */
    public function redeem(Request $request, Response $response): Response
    {
        $customer = Customer::of($request)->user;
        $this->gates->holdBack(StoreMiddleware::door($request), $customer);

        return $this->json($response, ['gift' => $this->codes->redeem($customer, $this->input($request), $request)]);
    }
}
