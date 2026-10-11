<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Http\ApiController;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Principal;
use App\Modules\Auth\Services\AgentAuth;
use App\Modules\Auth\Services\SessionPresenter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * An agent's panel session (/api/agent, AgentAuth): signing in with the one-time link the main bot gave them, who is
 * signed in, ending their other sessions, signing out. Their shop is always their own bot's.
 */
final class AgentAuthController extends ApiController
{
    public function __construct(
        private readonly AgentAuth $auth,
        private readonly SessionPresenter $sessions,
    ) {}

    /**
     * POST /auth/link — {code, replace?} (AgentAuth::login()): `replace` lets the link take the place of another agent's
     * session open in this browser, which it is refused without (the panel asks first).
     *
     * @throws SignInRefusedException 401: a code no link of now carries; 409: another agent's session is open here
     * @throws TooManyAttemptsException 429 while this address must wait
     */
    public function link(Request $request, Response $response): Response
    {
        return $this->session($response, $this->auth->login($this->input($request), $request));
    }

    /** GET /auth/me */
    public function me(Request $request, Response $response): Response
    {
        return $this->session($response, Principal::of($request));
    }

    /**
     * POST /auth/sessions/end — every other browser signed in to the agent's panel is signed out (an old link opened on
     * another device, a phone lost); this one stays.
     *
     * @throws SignInRefusedException 401: this session was ended meanwhile by another of theirs
     */
    public function endSessions(Request $request, Response $response): Response
    {
        $this->auth->endOthers(Principal::of($request));

        return $this->noContent($response);
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
