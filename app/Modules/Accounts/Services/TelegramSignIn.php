<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\Origin;
use App\Modules\Accounts\Contracts\SignInSite;
use App\Modules\Accounts\DTO\SignedIn;
use App\Modules\Accounts\DTO\TelegramAccount;
use App\Modules\Accounts\Enums\ChallengePurpose;
use App\Modules\Accounts\Enums\SignInMethod;
use App\Modules\Accounts\Exceptions\SignInsBusyException;
use App\Modules\Accounts\Http\Customer;
use App\Modules\Accounts\Oidc\CodeExchange;
use App\Modules\Accounts\Oidc\IdTokenVerifier;
use App\Modules\Accounts\Oidc\OidcProvider;
use App\Modules\Accounts\Oidc\ProviderUnreachableException;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Telegram\Api\TelegramUnreachableException;
use App\Modules\Users\Services\Customers;
use App\Support\Input;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Signing in on the website with a Telegram account (Log In With Telegram, OpenID Connect), under the Client ID
 * @BotFather shows for the site. The id_token's `id` claim is the numeric Telegram id the bot knows its customers by, so
 * a sign-in finds the bot's own customer, with their services and wallet, or registers a newcomer the way the bot does.
 *
 * Two flows, the site picks:
 * - popup: Telegram's telegram-login.js hands the page an id_token for a nonce the shop gave (ProviderSignIn::nonced()).
 * - redirect: authorization code + PKCE. The browser makes and keeps the PKCE verifier and sends the shop only its S256
 *   challenge; authorize() keeps that challenge, the return address and a nonce under a one-time `state`. The code comes
 *   back with the state and the verifier and is exchanged with the site's client secret. The shop never holds a verifier,
 *   so a code and state carried to another browser (a crafted link) open nothing.
 *
 * A state belongs to whoever asked for it: a sign-in's (issued to nobody) is spent only by a sign-in, and a signed-in
 * customer's (POST /me/telegram/authorize, linking or proving their Telegram account) only by the very session that asked.
 */
final class TelegramSignIn
{
    /** What the redirect flow asks for: the sign-in, the profile (with the `id` claim), and the bot's right to write to them. */
    public const SCOPE = 'openid profile telegram:bot_access';

    /** How long a redirect sign-in may take, from the authorization address to the code brought back. */
    private const STATE_SECONDS = 600;

    /** A PKCE S256 challenge: the base64url of a SHA-256, without padding. */
    private const CHALLENGE = '/^[A-Za-z0-9_-]{43}$/';

    /** A PKCE verifier (RFC 7636): 43 to 128 unreserved characters. */
    private const VERIFIER = '/^[A-Za-z0-9._~-]{43,128}$/';

    public function __construct(
        private readonly IdTokenVerifier $verifier,
        private readonly ProviderSignIn $provider,
        private readonly CodeExchange $exchange,
        private readonly AuthChallenges $challenges,
        private readonly Customers $customers,
        private readonly SignInThrottle $throttle,
    ) {}

    /**
     * Telegram's authorization address for the redirect flow, back to `$redirectUri` (on one of the site's origins), for
     * the PKCE challenge the site made of its verifier. With `$holder` the state is that customer's session's own. Each
     * call is counted against the asking address (SignInThrottle::issuing()).
     *
     * @throws SignInRefusedException 422: Telegram sign-in off, or no client secret to exchange a code with
     * @throws ValidationException 422 on `redirect_uri`, on `code_challenge`
     * @throws TooManyAttemptsException 429 while this address asked too much
     * @throws SignInsBusyException 503 while the shop holds as many redirect states as it takes
     */
    public function authorize(SignInSite $site, ServerRequestInterface $request, string $redirectUri, string $codeChallenge, ?Customer $holder = null): string
    {
        $clientId = $site->telegramClientId() ?? throw SignInRefusedException::telegramOff();
        if (!$site->hasTelegramSecret()) {
            throw SignInRefusedException::redirectOff();
        }
        $errors = [];
        $redirectUri = trim($redirectUri);
        $origin = Origin::of($redirectUri);
        if ($origin === null || parse_url($redirectUri, PHP_URL_FRAGMENT) !== null || !$site->allowsOrigin($origin)) {
            $errors['redirect_uri'][] = 'آدرس بازگشت باید یک آدرس http یا https روی آدرس وب‌سایت یا یکی از Originهای مجاز آن باشد، بدون #.';
        }
        if (preg_match(self::CHALLENGE, $codeChallenge) !== 1) {
            $errors['code_challenge'][] = 'code_challenge را بفرستید: SHA-256 کد verifier که وب‌سایت ساخته و نگه داشته است، به base64url و بدون = (روش S256، 43 کاراکتر).';
        }
        ValidationException::ifAny($errors);
        $this->throttle->issuing($request);

        $nonce = bin2hex(random_bytes(16));
        $state = $this->challenges->issue(ChallengePurpose::OidcState, $holder === null ? null : self::askedFrom($holder), $holder?->user, ['challenge' => $codeChallenge, 'redirect_uri' => $redirectUri, 'nonce' => $nonce], self::STATE_SECONDS);

        return OidcProvider::telegram()->authorizationEndpoint . '?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'nonce' => $nonce,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Sign the customer in by the popup's id_token and nonce or the redirect's code and state: the bot's customer with
     * that Telegram account, or a newcomer registered with the site's `referral_code`.
     *
     * @param array<string, mixed> $input {id_token, nonce, referral_code?} or {code, state, code_verifier, referral_code?}
     * @throws SignInRefusedException 401 the proof does not hold; 403 banned; 422 Telegram sign-in not set up
     * @throws ValidationException 422 a field missing
     * @throws TooManyAttemptsException 429 while this address must wait
     * @throws TelegramUnreachableException 502
     */
    public function signIn(SignInSite $site, ServerRequestInterface $request, array $input): SignedIn
    {
        $account = $this->account($site, $request, $input);
        $code = Input::text($input, 'referral_code');
        $user = $this->customers->byTelegram($account->id, $account->profile, $code === '' ? null : $code);

        return $this->provider->open($user, $request, SignInMethod::Telegram);
    }

    /**
     * The Telegram account the input proves — what a sign-in signs in by, and what a signed-in customer (`$holder`)
     * links to their account or proves theirs again. Run as a sign-in attempt (ProviderSignIn::prove()).
     *
     * @param array<string, mixed> $input {id_token, nonce} or {code, state, code_verifier}
     * @throws SignInRefusedException 401 the proof does not hold (a sign-in's); 422 Telegram sign-in not set up
     * @throws ValidationException 422 a field missing; on the proof's field when a signed-in customer's proof does not hold
     * @throws TooManyAttemptsException 429 while this address must wait
     * @throws TelegramUnreachableException 502
     */
    public function account(SignInSite $site, ServerRequestInterface $request, array $input, ?Customer $holder = null): TelegramAccount
    {
        $clientId = $site->telegramClientId() ?? throw SignInRefusedException::telegramOff();

        [$id, $claims] = $this->provider->prove(OidcProvider::telegram(), $request, $holder !== null, self::proofField($input), function () use ($site, $clientId, $input, $holder): array {
            $claims = Input::text($input, 'id_token') !== ''
                ? $this->provider->nonced(OidcProvider::telegram(), $clientId, $input)
                : $this->redirect($site, $clientId, $input, $holder);

            return [self::telegramIdOf($claims) ?? throw SignInRefusedException::noTelegramId(), $claims];
        }, static fn(ProviderUnreachableException $e): \Throwable => new TelegramUnreachableException($e));

        return new TelegramAccount($id, Customers::profile($claims['preferred_username'] ?? null, $claims['given_name'] ?? $claims['name'] ?? null, $claims['family_name'] ?? null));
    }

    /**
     * The field a proof comes under — the popup's `id_token`, else the redirect's `code` —: where a signed-in customer's
     * refused proof is reported.
     *
     * @param array<string, mixed> $input
     */
    public static function proofField(array $input): string
    {
        return Input::text($input, 'id_token') !== '' ? 'id_token' : 'code';
    }

    /**
     * The redirect's claims: the state spent (only by whoever it was issued to), the posted verifier matched to the
     * challenge the state keeps, the code exchanged with that verifier, and the token's nonce matched to the kept one.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function redirect(SignInSite $site, string $clientId, array $input, ?Customer $holder): array
    {
        $code = Input::text($input, 'code');
        if ($code === '') {
            throw ValidationException::on('id_token', 'id_token تلگرام (ورود با popup)، یا code، state و code_verifier (ورود با redirect) را بفرستید.');
        }
        $errors = [];
        $state = Input::text($input, 'state');
        if ($state === '') {
            $errors['state'][] = 'state ورود را هم بفرستید؛ همان که تلگرام همراه code برگرداند.';
        }
        $verifier = Input::text($input, 'code_verifier');
        if (preg_match(self::VERIFIER, $verifier) !== 1) {
            $errors['code_verifier'][] = 'code_verifier را هم بفرستید: همان که وب‌سایت پیش از ورود ساخت و challenge آن را به POST /auth/telegram/authorize داد.';
        }
        ValidationException::ifAny($errors);
        $secret = $site->telegramClientSecret() ?? throw SignInRefusedException::redirectOff();

        $kept = $this->challenges->spend(ChallengePurpose::OidcState, $state, $holder?->user, $holder === null ? null : self::askedFrom($holder));
        if (!is_string($kept['challenge'] ?? null) || !is_string($kept['redirect_uri'] ?? null) || !is_string($kept['nonce'] ?? null)) {
            throw SignInRefusedException::signInSpent();
        }
        // Only the browser that began the sign-in has the verifier: a code carried elsewhere opens nothing.
        if (!hash_equals($kept['challenge'], self::base64Url(hash('sha256', $verifier, true)))) {
            throw SignInRefusedException::signInSpent();
        }

        $provider = OidcProvider::telegram();
        $idToken = $this->exchange->idToken($provider, $clientId, $secret, $code, $kept['redirect_uri'], $verifier) ?? throw SignInRefusedException::codeRefused();
        $claims = $this->verifier->verify($provider, $idToken, $clientId);
        if (!ProviderSignIn::carries($claims, $kept['nonce'])) {
            throw SignInRefusedException::signInSpent();
        }

        return $claims;
    }

    /**
     * The Telegram user's numeric id (the `id` claim, a number or its digits); null when the token has none — the site
     * did not ask for the profile.
     *
     * @param array<string, mixed> $claims
     */
    private static function telegramIdOf(array $claims): ?int
    {
        $id = $claims['id'] ?? null;
        if (is_string($id) && preg_match('/^[1-9]\d{0,18}$/', $id) === 1) {
            $id = (int) $id;
        }

        return is_int($id) && $id > 0 ? $id : null;
    }

    /** The subject of a signed-in customer's redirect state: the session that asked, which alone may spend it. */
    private static function askedFrom(Customer $holder): string
    {
        return 'session:' . $holder->session->id;
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
