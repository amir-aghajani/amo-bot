<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Store\Services\CustomerWheel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** A signed-in customer's lucky wheel («گردونه شانس», Store\Services\CustomerWheel): the wheel, and a spin. */
final class WheelController extends ApiController
{
    public function __construct(private readonly CustomerWheel $wheel) {}

    /**
     * GET /wheel
     *
     * @throws ValidationException 422 on `status`
     */
    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, $this->wheel->show(Customer::of($request)->user));
    }

    /**
     * POST /wheel/spin
     *
     * @throws ValidationException 422 on `status`
     */
    public function spin(Request $request, Response $response): Response
    {
        return $this->json($response, $this->wheel->spin(Customer::of($request)->user));
    }
}
