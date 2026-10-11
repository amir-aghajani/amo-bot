<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Http\ApiController;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Store\Services\CustomerReferral;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** A signed-in customer's part in the referral program (Store\Services\CustomerReferral). */
final class ReferralController extends ApiController
{
    public function __construct(private readonly CustomerReferral $referral) {}

    /** GET /referral */
    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, $this->referral->present(Customer::of($request)->user));
    }
}
