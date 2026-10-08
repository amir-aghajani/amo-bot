<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Auth\Credentials;
use App\Modules\Auth\Exceptions\ShopRefusedException;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Principal;
use App\Modules\Auth\Services\AdminAccount;
use App\Modules\Auth\Services\LoginRecovery;
use App\Modules\Auth\Services\OwnerAuth;
use App\Modules\Auth\Services\SessionPresenter;
use App\Modules\Auth\Services\SignInThrottle;
use App\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The owner's panel session (/api/admin): signing in with the one account config.php keeps — failures throttled per
 * address and per username (SignInThrottle) —, setting it again with a key off the host's files when it is lost
 * (LoginRecovery), changing it from the panel, who is signed in and in which shop the request is worked — the one it
 * names (the tab's: the main bot's, or any agent's) —, the shops there are, signing out.
 */
final class OwnerAuthController extends ApiController
{
    public function __construct(
        private readonly OwnerAuth $auth,
        private readonly AdminAccount $account,
        private readonly SignInThrottle $throttle,
        private readonly SessionPresenter $sessions,
        private readonly LoginRecovery $recovery,
    ) {}

    /**
     * POST /auth/login — {username, password}: signed in, in the shop the request names (the tab's).
     *
     * @throws SignInRefusedException 401 wrong credentials, 503 no login set up yet
     * @throws ShopRefusedException 404: the right credentials, at the address of a shop that is not there (nothing opened)
     * @throws TooManyAttemptsException 429 while this address, or this username, must wait
     */
    public function login(Request $request, Response $response): Response
    {
        $input = $this->input($request);
        $username = Input::text($input, 'username');
        $password = Input::password($input, 'password');

        $this->throttle->check(AdminAccount::WAY, $request, $username === '' ? null : $username);

        $errors = [];
        if ($username === '') {
            $errors['username'][] = 'نام کاربری را وارد کنید.';
        }
        if ($password === '') {
            $errors['password'][] = 'رمز عبور را وارد کنید.';
        }
        ValidationException::ifAny($errors);

        if (!$this->account->configured()) {
            throw SignInRefusedException::notSetUp();
        }
        $principal = null;
        $opened = $this->throttle->password(AdminAccount::WAY, $request, $username, function () use ($username, $password, $request, &$principal): bool {
            $principal = $this->auth->attempt($username, $password, $request);

            return $principal !== null;
        });
        if (!$opened || $principal === null) {
            throw SignInRefusedException::wrongCredentials();
        }

        return $this->session($response, $principal);
    }

    /**
     * PUT /auth/credentials — {current_password, username, password, password_confirmation}: the owner changes the
     * panel's login. Their current password proves it is them, and a wrong one is a failed sign-in, counted with the
     * login's (per address and per account — AdminAccount::confirm()): no way around the throttle to guess it. The new
     * login is the installer's one rule; a blank password keeps the one kept, and a change that changes nothing is
     * refused. Every refusal of the form comes at once. Every other session ends with the old credentials; this one goes
     * on under the new ones.
     *
     * @throws ValidationException 422: the form's refusals, or a config.php the server may not write (`file`)
     * @throws TooManyAttemptsException 429 while this address, or the account, must wait
     */
    public function credentials(Request $request, Response $response): Response
    {
        $account = $this->account->username();
        $this->throttle->check(AdminAccount::WAY, $request, $account);

        $input = $this->input($request);
        [$credentials, $errors] = Credentials::fromInput($input, keepPassword: true);
        if ($credentials->password === null && $credentials->username === $account) {
            $errors['password'][] = 'رمز عبور جدید را وارد کنید، یا نام کاربری را عوض کنید.';
        }

        $unproven = $this->account->confirm($request, Input::password($input, 'current_password'));
        if ($unproven !== null) {
            $errors['current_password'][] = $unproven;
        }
        ValidationException::ifAny($errors);

        return $this->session($response, $this->auth->changeCredentials($credentials, Principal::of($request)->shop));
    }

    /**
     * POST /auth/recovery/key — the way back in for a lost login begins: a one-time key written to the host's files
     * (storage/recovery-key.txt), where only someone who can read them finds it; where, and until when it opens.
     */
    public function recoveryKey(Request $request, Response $response): Response
    {
        return $this->json($response, $this->recovery->start($request));
    }

    /**
     * POST /auth/recovery — {key, username, password, password_confirmation}: a new login, set with that key; signed in
     * under it, every other session out.
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
