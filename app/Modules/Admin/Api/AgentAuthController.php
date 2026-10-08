<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Http\ApiController;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Principal;
use App\Modules\Auth\Services\AgentAuth;
use App\Modules\Auth\Services\SessionPresenter;
use App\Modules\Auth\Services\SignInThrottle;
use App\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * An agent's panel session (/api/agent): signing in with the one-time link their account in the main bot gave them
 * («نمایندگی» ← «ورود به پنل») — failures throttled per address —, who is signed in and in which shop — their bot's —,
 * ending every other session of theirs, signing out.
 */
final class AgentAuthController extends ApiController
{
    private const WAY = 'link';

    public function __construct(
        private readonly AgentAuth $auth,
        private readonly SignInThrottle $throttle,
        private readonly SessionPresenter $sessions,
    ) {}

    /**
     * POST /auth/link — {code, replace?}: `replace` lets the link take the place of another agent's session open in this
     * browser, which it is refused without (the panel asks first).
     *
     * @throws SignInRefusedException 401: a code no link of now carries; 409: another agent's session is open here
     * @throws TooManyAttemptsException 429 while this address must wait
     */
    public function link(Request $request, Response $response): Response
    {
        $this->throttle->check(self::WAY, $request);

        $input = $this->input($request);
        $principal = $this->auth->attemptCode(Input::text($input, 'code'), Input::truthy($input['replace'] ?? false));
        if ($principal === null) {
            $this->throttle->failed(self::WAY, $request);

            throw SignInRefusedException::linkRefused();
        }
        $this->throttle->passed(self::WAY, $request);

        return $this->session($response, $principal);
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
