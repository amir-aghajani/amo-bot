<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Accounts\Exceptions\AccountRefusedException;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Accounts\Services\AccountPresenter;
use App\Modules\Accounts\Services\TwoFactor;
use App\Modules\Store\Http\StoreMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A signed-in customer's two-factor sign-in (Accounts\Services\TwoFactor): a new secret for their authenticator app,
 * turned on with its first code — the recovery codes shown that once —, and off again with their password.
 */
final class TwoFactorController extends ApiController
{
    public function __construct(private readonly TwoFactor $twoFactor) {}

    /**
     * POST /me/2fa/setup — the secret and its otpauth:// address, waiting for the app's first code.
     *
     * @throws AccountRefusedException 422 no email and password, or it is on already
     */
    public function setup(Request $request, Response $response): Response
    {
        return $this->json($response, $this->twoFactor->setup(StoreMiddleware::website($request), Customer::of($request)->user));
    }

    /**
     * POST /me/2fa/enable — {code}: on; its recovery codes, this once.
     *
     * @throws AccountRefusedException 422 no email and password, or it is on already
     * @throws ValidationException 422 on `code`
     */
    public function enable(Request $request, Response $response): Response
    {
        return $this->json($response, ['recovery_codes' => $this->twoFactor->enable(Customer::of($request)->user, $this->input($request))]);
    }

    /**
     * POST /me/2fa/disable — {password}: off.
     *
     * @throws AccountRefusedException 422 it is not on
     * @throws ValidationException 422 on `password`
     * @throws TooManyAttemptsException 429
     */
    public function disable(Request $request, Response $response): Response
    {
        $user = $this->twoFactor->disable(Customer::of($request)->user, $request, $this->input($request));

        return $this->json($response, ['customer' => AccountPresenter::present($user)]);
    }
}
