<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Auth\Exceptions\ShopRefusedException;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Principal;
use App\Modules\Auth\Services\LoginRecovery;
use App\Modules\Auth\Services\OwnerAuth;
use App\Modules\Auth\Services\SessionPresenter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The owner's panel session (/api/admin, OwnerAuth): signing in with the login config.php keeps, recovering a lost
 * login with a key from the host's files, changing it, who is signed in (in the shop the request names), the shops there
 * are, signing out.
 */
final class OwnerAuthController extends ApiController
{
    public function __construct(
        private readonly OwnerAuth $auth,
        private readonly SessionPresenter $sessions,
        private readonly LoginRecovery $recovery,
    ) {}

    /**
     * POST /auth/login — {username, password}: signed in, in the shop the request names (the tab's).
     *
     * @throws ValidationException 422: a field left blank
     * @throws SignInRefusedException 401 wrong credentials, 503 no login set up yet
     * @throws ShopRefusedException 404: the right credentials, at the address of a shop that is not there (nothing opened)
     * @throws TooManyAttemptsException 429 while this address, or this username, must wait
     */
    public function login(Request $request, Response $response): Response
    {
        return $this->session($response, $this->auth->login($this->input($request), $request));
    }

    /**
     * PUT /auth/credentials — {current_password, username, password, password_confirmation}: the owner changes the login
     * (OwnerAuth::changeLogin()). Every other session ends; this one goes on under the new login.
     *
     * @throws ValidationException 422: the form's refusals, or a config.php the server may not write (`file`)
     * @throws TooManyAttemptsException 429 while this address, or the account, must wait
     */
    public function credentials(Request $request, Response $response): Response
    {
        return $this->session($response, $this->auth->changeLogin($this->input($request), $request, Principal::of($request)->shop));
    }

    /**
     * POST /auth/recovery/key — a lost login's recovery begins: a one-time key written to storage/recovery-key.txt, which
     * only someone with the host's files can read. Answers where it is and until when it works.
     */
    public function recoveryKey(Request $request, Response $response): Response
    {
        return $this->json($response, $this->recovery->start($request));
    }

    /**
     * POST /auth/recovery — {key, username, password, password_confirmation}: a new login set with that key; this browser
     * signed in under it, every other session out.
     *
     * @throws ValidationException 422: the form's refusals, a wrong key, or a config.php the server may not write (`file`)
     * @throws TooManyAttemptsException 429 while this address must wait
     */
    public function recover(Request $request, Response $response): Response
    {
        return $this->session($response, $this->recovery->recover($request, $this->input($request)));
    }

    /** GET /auth/me — who is signed in, in the shop the request names. */
    public function me(Request $request, Response $response): Response
    {
        return $this->session($response, Principal::of($request));
    }

    /** GET /shops — every shop the owner may open: the main bot's, then each agent's. */
    public function shops(Request $request, Response $response): Response
    {
        return $this->json($response, ['shops' => array_map($this->sessions->shop(...), $this->auth->shops())]);
    }

    /** POST /auth/logout */
    public function logout(Request $request, Response $response): Response
    {
        $this->auth->logout();

        return $this->noContent($response);
    }

    private function session(Response $response, Principal $principal): Response
    {
        return $this->json($response, ['session' => $this->sessions->present($principal)]);
    }
}
