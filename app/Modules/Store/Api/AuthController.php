<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Captcha\CaptchaUnavailableException;
use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Core\Mail\MailFailedException;
use App\Modules\Accounts\DTO\SignedIn;
use App\Modules\Accounts\DTO\TwoFactorChallenge;
use App\Modules\Accounts\Exceptions\SignInsBusyException;
use App\Modules\Accounts\Services\AccountPresenter;
use App\Modules\Accounts\Services\AuthChallenges;
use App\Modules\Accounts\Services\EmailSignIn;
use App\Modules\Accounts\Services\GoogleSignIn;
use App\Modules\Accounts\Services\TelegramSignIn;
use App\Modules\Accounts\Services\TwoFactor;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Store\Http\StoreMiddleware;
use App\Modules\Telegram\Api\TelegramUnreachableException;
use App\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * How a customer signs in on the shop's website (the Store API's `/auth/*`): a nonce for an id_token; Telegram's
 * sign-in — its popup's id_token, or its redirect's authorization address and then the code it brings back —; Google's;
 * and an email with a password — a sign-up and its code, a sign-in, a forgotten password and its code —, and the second
 * step an account with two-factor sign-in on is asked for. A sign-in answers the customer's bearer token, shown this
 * once, and their account — or, its second step asked, its challenge (202); what sends a code answers how long it waits
 * (202), whether or not the address has an account.
 */
final class AuthController extends ApiController
{
    public function __construct(
        private readonly AuthChallenges $challenges,
        private readonly SignInThrottle $throttle,
        private readonly TelegramSignIn $telegram,
        private readonly GoogleSignIn $google,
        private readonly EmailSignIn $email,
        private readonly TwoFactor $twoFactor,
    ) {}

    /**
     * POST /auth/nonce — for an id_token sign-in: the site hands it to the provider, the sign-in brings it back once.
     *
     * @throws TooManyAttemptsException 429 while this address asked too much
     * @throws SignInsBusyException 503 while the shop keeps as many as it takes
     */
    public function nonce(Request $request, Response $response): Response
    {
        $this->throttle->issuing($request);

        return $this->json($response, ['nonce' => $this->challenges->nonce(), 'expires_in' => AuthChallenges::NONCE_SECONDS]);
    }

    /**
     * POST /auth/telegram/authorize — {redirect_uri, code_challenge}: where to send the browser to sign in with Telegram,
     * back to the site — the PKCE challenge of the verifier the site keeps.
     *
     * @throws SignInRefusedException 422 Telegram sign-in, or its redirect flow, not set up
     * @throws ValidationException 422 on `redirect_uri`, on `code_challenge`
     * @throws TooManyAttemptsException 429
     * @throws SignInsBusyException 503
     */
    public function authorize(Request $request, Response $response): Response
    {
        $input = $this->input($request);
        $url = $this->telegram->authorize(StoreMiddleware::website($request), $request, Input::text($input, 'redirect_uri'), Input::text($input, 'code_challenge'));

        return $this->json($response, ['url' => $url]);
    }

    /**
     * POST /auth/telegram — {id_token, nonce, referral_code?} (popup) or {code, state, code_verifier, referral_code?} (redirect).
     *
     * @throws SignInRefusedException 401 not signed in, 403 banned, 422 not set up
     * @throws TooManyAttemptsException 429 while this address must wait
     * @throws TelegramUnreachableException 502
     */
    public function telegram(Request $request, Response $response): Response
    {
        return $this->signedIn($response, $this->telegram->signIn(StoreMiddleware::website($request), $request, $this->input($request)));
    }

    /**
     * POST /auth/google — {id_token, nonce, referral_code?}.
     *
     * @throws SignInRefusedException 401 not signed in, 403 banned, 422 not set up, 502 Google out of reach
     * @throws TooManyAttemptsException 429 while this address must wait
     */
    public function google(Request $request, Response $response): Response
    {
        return $this->signedIn($response, $this->google->signIn(StoreMiddleware::website($request), $request, $this->input($request)));
    }

    /**
     * POST /auth/register — {first_name, last_name?, email, password, referral_code?, captcha?}: a code to the address.
     *
     * @throws SignInRefusedException 422 email sign-up off, 503 no email goes out
     * @throws ValidationException 422 under the fields
     * @throws CaptchaUnavailableException 503
     * @throws TooManyAttemptsException 429
     * @throws MailFailedException 502
     */
    public function register(Request $request, Response $response): Response
    {
        return $this->json($response, ['expires_in' => $this->email->register(StoreMiddleware::website($request), $request, $this->input($request))], 202);
    }

    /**
     * POST /auth/register/verify — {email, code}: the account made, signed in.
     *
     * @throws SignInRefusedException 409 the address has an account since, 422 email sign-up off
     * @throws ValidationException 422 on `email`, on `code`
     * @throws TooManyAttemptsException 429
     */
    public function verify(Request $request, Response $response): Response
    {
        return $this->signedIn($response, $this->email->verify(StoreMiddleware::website($request), $request, $this->input($request)));
    }

    /**
     * POST /auth/login — {email, password, captcha?}: signed in, or the second step's challenge (202).
     *
     * @throws SignInRefusedException 401 not the address and password of an account, 403 banned
     * @throws ValidationException 422 under the fields
     * @throws CaptchaUnavailableException 503
     * @throws TooManyAttemptsException 429
     */
    public function login(Request $request, Response $response): Response
    {
        return $this->signedIn($response, $this->email->login(StoreMiddleware::website($request), $request, $this->input($request)));
    }

    /**
     * POST /auth/login/2fa — {challenge, code}: the second step, its app's code or a recovery code.
     *
     * @throws SignInRefusedException 401 the challenge opens nothing any more, 403 banned
     * @throws ValidationException 422 a field missing, the code wrong
     * @throws TooManyAttemptsException 429
     */
    public function twoFactor(Request $request, Response $response): Response
    {
        return $this->signedIn($response, $this->twoFactor->signIn($request, $this->input($request)));
    }

    /**
     * POST /auth/password/forgot — {email, captcha?}: a code to the address, when it has an account.
     *
     * @throws SignInRefusedException 503 no email goes out
     * @throws ValidationException 422 on `email`, on `captcha`
     * @throws CaptchaUnavailableException 503
     * @throws TooManyAttemptsException 429
     * @throws MailFailedException 502
     */
    public function forgot(Request $request, Response $response): Response
    {
        return $this->json($response, ['expires_in' => $this->email->forgot(StoreMiddleware::website($request), $request, $this->input($request))], 202);
    }

    /**
     * POST /auth/password/reset — {email, code, password}: the new password kept, every other session ended, signed in —
     * or the second step's challenge (202).
     *
     * @throws SignInRefusedException 403 banned
     * @throws ValidationException 422 under the fields
     * @throws TooManyAttemptsException 429
     */
    public function reset(Request $request, Response $response): Response
    {
        return $this->signedIn($response, $this->email->reset(StoreMiddleware::website($request), $request, $this->input($request)));
    }

    /**
     * A sign-in's answer: the bearer token, this once, and the account — or, the account asking a second step, its
     * challenge (202), no session opened yet.
     */
    private function signedIn(Response $response, SignedIn|TwoFactorChallenge $signedIn): Response
    {
        if ($signedIn instanceof TwoFactorChallenge) {
            return $this->json($response, ['two_factor' => ['challenge' => $signedIn->challenge, 'expires_in' => $signedIn->expiresIn]], 202);
        }

        return $this->json($response, ['token' => $signedIn->token, 'customer' => AccountPresenter::present($signedIn->session->user)]);
    }
}
