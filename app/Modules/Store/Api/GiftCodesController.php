<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Http\ApiController;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Store\Services\CustomerGiftCodes;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** A signed-in customer redeems a gift code (Store\Services\CustomerGiftCodes). */
final class GiftCodesController extends ApiController
{
    public function __construct(private readonly CustomerGiftCodes $codes) {}

    /** POST /gift-codes/redeem — {code} */
    public function redeem(Request $request, Response $response): Response
    {
        return $this->json($response, ['gift' => $this->codes->redeem(Customer::of($request)->user, $this->input($request), $request)]);
    }
}
