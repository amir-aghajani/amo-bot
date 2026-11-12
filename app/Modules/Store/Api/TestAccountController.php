<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Providers\Exceptions\PanelFailedException;
use App\Modules\Store\Exceptions\ProgramOffException;
use App\Modules\Store\Services\CustomerSubscriptions;
use App\Modules\Store\Services\CustomerTestAccounts;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A signed-in customer's free test account («اکانت تست», Store\Services\CustomerTestAccounts): what they may take, and
 * one taken.
 */
final class TestAccountController extends ApiController
{
    public function __construct(
        private readonly CustomerTestAccounts $tests,
        private readonly CustomerSubscriptions $subscriptions,
    ) {}

    /**
     * GET /test-account — the servers giving them a test now, or why none.
     *
     * @throws ProgramOffException 404
     */
    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, $this->tests->offers(Customer::of($request)->user));
    }

    /**
     * POST /test-account — {server_id}: a test taken there; a 201 with the service.
     *
     * @throws ProgramOffException 404
     * @throws ValidationException 422 on `server_id` or `status`
     * @throws PanelFailedException 502
     */
    public function take(Request $request, Response $response): Response
    {
        $test = $this->tests->take(Customer::of($request)->user, $this->input($request));

        return $this->json($response, ['subscription' => $this->subscriptions->present($test)], 201);
    }
}
