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
use Psr\Log\LoggerInterface;

/**
 * Signing in on the shop's website with a Telegram account: Telegram's OpenID Connect (Log In With Telegram), under
 * the Client ID @BotFather shows for the site (SignInSite::telegramClientId()). Two flows, the site picks:
 * - popup — Telegram's telegram-login.js runs the sign-in itself and hands the page an id_token, for a nonce the site
 *   asked the shop for first (POST /auth/nonce, AuthChallenges::nonce()); the page posts both.
 * - redirect — authorization code + PKCE, the PKCE verifier the browser's own: the site makes it, keeps it where the
 *   page that began the sign-in can read it (its sessionStorage), and sends the shop only its challenge (S256);
 *   authorize() gives the site Telegram's authorization address, the challenge, return address and nonce kept here under
 *   a one-time `state`; Telegram sends the browser back to the site with a code and the state, which the site posts with
 *   the verifier — the one whose challenge the state keeps, else the sign-in began in another browser and opens nothing
 *   —, and the code is exchanged for the id_token with the site's client secret and that verifier (CodeExchange). The
 *   shop keeps no verifier: a code and state another browser brings back (a crafted link) come without it. The state is
 *   whoever asked for it too: a sign-in's (POST /auth/telegram/authorize) is spent by a sign-in alone, a signed-in
 *   customer's (POST /me/telegram/authorize, `$holder`) by that customer's very session alone (the challenge's holder
 *   and its subject) — another device of theirs adds no Telegram account to theirs, nor signs them in to somebody else's.
 * Either way the token is checked the one way (IdTokenVerifier; the popup's by IdTokens, the nonce rule of every
 * id_token sign-in), its nonce spent once, and its `id` claim — the Telegram user's numeric id, which the bot knows its
 * customers by — is the Telegram account it proves (account(): what a signed-in customer links to their account, or
 * proves theirs again, too — Identities, Reauthentication). A sign-in's customer is the shop's with that account: the
 * bot's, with their services and wallet; or a newcomer, registered as the bot registers one (Users\Services\Customers),
 * with the site's `referral_code`. A proof that does not hold counts against the address (SignInThrottle) — a 401 for a
 * sign-in, a 422 on the proof's field for a signed-in customer, whose session it does not end —; one that works clears
 * nobody's count. Telegram out of reach is a 502 that counts for nothing.
 */
final class TelegramSignIn
{
    /** What the redirect flow asks Telegram for: the sign-in, the profile (the `id` claim with it), and the bot's right to write to them. */
    public const SCOPE = 'openid profile telegram:bot_access';

    /** How long a redirect sign-in may take, from the authorization address to the code brought back. */
    private const STATE_SECONDS = 600;

    /** A PKCE challenge as S256 makes it: the base64url of a SHA-256, without its padding. */
    private const CHALLENGE = '/^[A-Za-z0-9_-]{43}$/';

    /** A PKCE verifier (RFC 7636): 43 to 128 of its unreserved characters. */
    private const VERIFIER = '/^[A-Za-z0-9._~-]{43,128}$/';

    public function __construct(
        private readonly IdTokenVerifier $verifier,
        private readonly IdTokens $idTokens,
        private readonly CodeExchange $exchange,
        private readonly AuthChallenges $challenges,
        private readonly Customers $customers,
        private readonly CustomerSessions $sessions,
        private readonly SignInThrottle $throttle,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Telegram's authorization address for the redirect flow, back to `$redirectUri` — an address of the site (its own
     * origin, or one it lists) —, for the PKCE challenge the site made of its verifier (`$codeChallenge`, S256), which
     * the state keeps. The state is a sign-in's, or — `$holder` — the signed-in customer's who asked, spent by the
     * session they asked from alone; it is counted against the address that asked (SignInThrottle::issuing()).
     *
     * @throws SignInRefusedException 422: Telegram sign-in off, or no client secret to exchange a code with
     * @throws ValidationException 422 on `redirect_uri`, on `code_challenge`
     * @throws TooManyAttemptsException 429 while this address asked too much
     * @throws SignInsBusyException 503 while the shop keeps as many redirect states as it takes
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
     * The customer signed in — a session opened on the device the request came from — by the popup's id_token and
     * nonce, or the redirect's code and state.
     *
     * @param array<string, mixed> $input {id_token, nonce, referral_code?} or {code, state, code_verifier, referral_code?}
     * @throws SignInRefusedException 401 the token, the code or the nonce/state does not sign them in; 403 banned; 422 Telegram sign-in not set up
     * @throws ValidationException 422 a field missing
     * @throws TooManyAttemptsException 429 while this address must wait
     * @throws TelegramUnreachableException 502 when Telegram could not be asked
     */
    public function signIn(SignInSite $site, ServerRequestInterface $request, array $input): SignedIn
    {
        $account = $this->account($site, $request, $input);
        $code = Input::text($input, 'referral_code');
        $user = $this->customers->byTelegram($account->id, $account->profile, $code === '' ? null : $code);
        if ($user->isBanned()) {
            throw SignInRefusedException::banned();
        }
        if (!$user->wasRecentlyCreated) {
            $this->customers->seen($user);
        }

        return $this->sessions->open($user, $request, SignInMethod::Telegram);
    }

    /**
     * The Telegram account the input proves — the popup's id_token and nonce, or the redirect's code and state, checked
     * the one way —: what a sign-in signs in by, and what a signed-in customer (`$holder`, whose own session's redirect
     * state alone it spends) links to their account or proves theirs again. A proof that does not hold counts against the address
     * (SignInThrottle) — a signed-in customer's is a 422 on its field (proofField()), never the 401 that ends a session;
     * Telegram out of reach counts for nothing.
     *
     * @param array<string, mixed> $input {id_token, nonce} or {code, state, code_verifier}
     * @throws SignInRefusedException 401 the token, the code or the nonce/state proves nothing (a sign-in's); 422 Telegram sign-in not set up
     * @throws ValidationException 422 a field missing; on the proof's field, a signed-in customer's proof that does not hold
     * @throws TooManyAttemptsException 429 while this address must wait
     * @throws TelegramUnreachableException 502 when Telegram could not be asked
     */
    public function account(SignInSite $site, ServerRequestInterface $request, array $input, ?Customer $holder = null): TelegramAccount
    {
        $clientId = $site->telegramClientId() ?? throw SignInRefusedException::telegramOff();
        $this->throttle->check(SignInThrottle::WEBSITE, $request);

        try {
            $claims = Input::text($input, 'id_token') !== '' ? $this->idTokens->nonced(OidcProvider::telegram(), $clientId, $input) : $this->redirect($site, $clientId, $input, $holder);
            $telegramId = self::telegramIdOf($claims) ?? throw SignInRefusedException::noTelegramId();
        } catch (SignInRefusedException $e) {
            if ($e->failedSignIn()) {
                $this->throttle->failed(SignInThrottle::WEBSITE, $request);
                if ($holder !== null) {
                    throw ValidationException::on(self::proofField($input), $e->getMessage());
                }
            }

            throw $e;
        } catch (ProviderUnreachableException $e) {
            // The owner's to look into when it lasts: a host that may not reach oauth.telegram.org signs nobody in.
            $this->logger->warning('A Telegram sign-in on the website could not ask Telegram: {message}', ['message' => $e->getMessage()]);

            throw new TelegramUnreachableException($e);
        }

        return new TelegramAccount($telegramId, Customers::profile($claims['preferred_username'] ?? null, $claims['given_name'] ?? $claims['name'] ?? null, $claims['family_name'] ?? null));
    }

    /**
     * The field a proof of a Telegram account comes under — the popup's `id_token`, else the redirect's `code` —: where a
     * signed-in customer's proof that does not hold is said.
     *
     * @param array<string, mixed> $input
     */
    public static function proofField(array $input): string
    {
        return Input::text($input, 'id_token') !== '' ? 'id_token' : 'code';
    }

    /**
     * The redirect's code, for the state authorize() kept for `$holder` and the session they asked from (a sign-in's:
     * nobody's), with the verifier of the challenge it keeps — the browser that began the sign-in's: the state spent, the
     * verifier held to the challenge, the code exchanged where it began with it, and the token's nonce the one kept.
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
        // The verifier of the browser that began it, or the code another browser brought back opens nothing.
        if (!hash_equals($kept['challenge'], self::base64Url(hash('sha256', $verifier, true)))) {
            throw SignInRefusedException::signInSpent();
        }

        $provider = OidcProvider::telegram();
        $idToken = $this->exchange->idToken($provider, $clientId, $secret, $code, $kept['redirect_uri'], $verifier) ?? throw SignInRefusedException::codeRefused();
        $claims = $this->verifier->verify($provider, $idToken, $clientId);
        if (!IdTokens::carries($claims, $kept['nonce'])) {
            throw SignInRefusedException::signInSpent();
        }

        return $claims;
    }

    /**
     * The Telegram user's numeric id — the `id` claim, a number or its digits —; null when the token carries none (the
     * site did not ask for the profile).
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

    /** What a signed-in customer's redirect state is about: the session it was asked from, which alone spends it. */
    private static function askedFrom(Customer $holder): string
    {
        return 'session:' . $holder->session->id;
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
