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
use App\Modules\Accounts\Http\Customer;
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
 * The website's sign-in routes (`/auth/*`): a nonce for an id_token, Telegram (popup or redirect), Google, and an email
 * with a password — sign-up, sign-in, its second step, a forgotten password. A sign-in answers the bearer token (shown
 * this once) and the account, or a 202 with the second step's challenge; what emails a code answers a 202 with how long
 * it waits, whether or not the address has an account.
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
     * POST /auth/nonce — the nonce the site hands the provider; the sign-in brings it back once.
     *
     * @throws SignInRefusedException 422 neither Telegram nor Google sign-in on: no sign-in would take it
     * @throws TooManyAttemptsException 429 while this address asked too much
     * @throws SignInsBusyException 503 while the shop holds as many as it takes
     */
    public function nonce(Request $request, Response $response): Response
    {
        $site = StoreMiddleware::website($request);
        if ($site->telegramClientId() === null && $site->googleClientId() === null) {
            throw SignInRefusedException::idTokenOff();
        }
        $this->throttle->issuing($request);

        return $this->json($response, ['nonce' => $this->challenges->nonce(), 'expires_in' => AuthChallenges::NONCE_SECONDS]);
    }

    /**
     * POST /auth/telegram/authorize and POST /me/telegram/authorize — {redirect_uri, code_challenge}: Telegram's
     * authorization address for the redirect flow. On the /me route (a signed-in customer linking or proving their
     * Telegram account) the state belongs to that session alone.
     *
     * @throws SignInRefusedException 422 Telegram sign-in, or its redirect flow, not set up
     * @throws ValidationException 422 on `redirect_uri`, on `code_challenge`
     * @throws TooManyAttemptsException 429
     * @throws SignInsBusyException 503
     */
    public function authorize(Request $request, Response $response): Response
    {
        $input = $this->input($request);
        $url = $this->telegram->authorize(StoreMiddleware::website($request), $request, Input::text($input, 'redirect_uri'), Input::text($input, 'code_challenge'), Customer::optional($request));

        return $this->json($response, ['url' => $url]);
    }

    /**
     * POST /auth/telegram — {id_token, nonce, referral_code?} (popup) or {code, state, code_verifier, referral_code?} (redirect).
     *
     * @throws SignInRefusedException 401 not signed in, 403 banned, 422 not set up
     * @throws TooManyAttemptsException 429
     * @throws TelegramUnreachableException 502
     */
    public function telegram(Request $request, Response $response): Response
    {
        return $this->signedIn($response, $this->telegram->signIn(StoreMiddleware::website($request), $request, $this->input($request)));
    }

    /**
     * POST /auth/google — {id_token, nonce, referral_code?}.
     *
     * @throws SignInRefusedException 401 not signed in, 403 banned, 409 the address's account takes no Google account this way, 422 not set up, 502 Google out of reach
     * @throws TooManyAttemptsException 429
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
     * POST /auth/register/verify — {email, code}: the account made and signed in.
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
     * POST /auth/login/2fa — {challenge, code}: the second step, with the app's code or a recovery code.
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
     * POST /auth/password/forgot — {email, captcha?}: a reset code to the address when it has an account.
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
     * POST /auth/password/reset — {email, code, password}: the new password set and signed in, or the second step's challenge (202).
     *
     * @throws SignInRefusedException 403 banned
     * @throws ValidationException 422 under the fields
     * @throws TooManyAttemptsException 429
     */
    public function reset(Request $request, Response $response): Response
    {
        return $this->signedIn($response, $this->email->reset(StoreMiddleware::website($request), $request, $this->input($request)));
    }

    /** A sign-in's answer: the bearer token and the account — or a 202 with the second step's challenge, no session yet. */
    private function signedIn(Response $response, SignedIn|TwoFactorChallenge $signedIn): Response
    {
        if ($signedIn instanceof TwoFactorChallenge) {
            return $this->json($response, ['two_factor' => ['challenge' => $signedIn->challenge, 'expires_in' => $signedIn->expiresIn]], 202);
        }

        return $this->json($response, ['token' => $signedIn->token, 'customer' => AccountPresenter::present($signedIn->session->user)]);
    }
}
