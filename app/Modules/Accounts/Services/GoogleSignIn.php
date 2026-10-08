<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Modules\Accounts\Contracts\SignInSite;
use App\Modules\Accounts\DTO\GoogleAccount;
use App\Modules\Accounts\DTO\SignedIn;
use App\Modules\Accounts\Enums\SignInMethod;
use App\Modules\Accounts\Enums\WayIn;
use App\Modules\Accounts\Oidc\OidcProvider;
use App\Modules\Accounts\Oidc\ProviderUnreachableException;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\Customers;
use App\Support\Email;
use App\Support\Input;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Signing in on the shop's website with a Google account (Sign in with Google: Google Identity Services hands the page an
 * id_token, for a nonce the site asked the shop for first — POST /auth/nonce), under the OAuth client id the website
 * keeps. The token is checked the one way, its nonce spent once (IdTokens); its `sub` is the Google account, and its
 * `email` the address Google speaks for — the one place that is judged (trustedEmail()): `email_verified`, and Google the
 * address's own authority, by Google's word — an @gmail.com address, or a Workspace account's (an `hd` claim). Any other
 * address Google verified once and may not own now: it finds, signs in to, takes on and merges no account, and no
 * newcomer keeps it (account(): what a signed-in customer links to their account, or proves theirs again, too —
 * Identities, Reauthentication). A sign-in's customer is the shop's with that Google account, else the one with that
 * address, else a newcomer registered as every newcomer is (Customers::byGoogle()), with the site's `referral_code`. A
 * proof that does not hold counts against the address (SignInThrottle) — a 401 for a sign-in, a 422 on `id_token` for a
 * signed-in customer, whose session it does not end —; Google out of reach is a 502 that counts for nothing.
 */
final class GoogleSignIn
{
    /** The longest Google account id kept: the column's. */
    private const SUB_MAX = 191;

    /** The domain whose every address is a Google account's own. */
    private const GMAIL = 'gmail.com';

    public function __construct(
        private readonly IdTokens $idTokens,
        private readonly Customers $customers,
        private readonly CustomerSessions $sessions,
        private readonly SignInThrottle $throttle,
        private readonly CustomerNotifier $notifier,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The customer signed in — a session opened on the device the request came from — by Google's id_token and the nonce
     * it was asked for.
     *
     * @param array<string, mixed> $input {id_token, nonce, referral_code?}
     * @throws SignInRefusedException 401 the token or the nonce does not sign them in; 403 banned; 409 the address is an account's that takes no Google account on by it; 422 Google sign-in not set up; 502 Google out of reach
     * @throws ValidationException 422 a field missing
     * @throws TooManyAttemptsException 429 while this address must wait
     */
    public function signIn(SignInSite $site, ServerRequestInterface $request, array $input): SignedIn
    {
        $account = $this->account($site, $request, $input);
        $code = Input::text($input, 'referral_code');
        $known = User::query()->where('google_sub', $account->sub)->exists();
        $user = $this->customers->byGoogle($account->sub, $account->email, $account->profile, $code === '' ? null : $code);
        if (!$known && !$user->wasRecentlyCreated) {
            // An account its address took the Google account on: one more way into it.
            $this->notifier->wayInAdded($user, WayIn::Google->label());
        }
        if ($user->isBanned()) {
            throw SignInRefusedException::banned();
        }
        if (!$user->wasRecentlyCreated) {
            $this->customers->seen($user);
        }

        return $this->sessions->open($user, $request, SignInMethod::Google);
    }

    /**
     * The Google account the id_token proves, posted with the nonce it was asked for and checked the one way: what a
     * sign-in signs in by, and what a signed-in customer (`$holder`) links to their account or proves theirs again. A
     * proof that does not hold counts against the address (SignInThrottle) — a signed-in customer's is a 422 on
     * `id_token`, never the 401 that ends a session; Google out of reach counts for nothing.
     *
     * @param array<string, mixed> $input {id_token, nonce}
     * @throws SignInRefusedException 401 the token or the nonce proves nothing (a sign-in's); 422 Google sign-in not set up; 502 Google out of reach
     * @throws ValidationException 422 a field missing; on `id_token`, a signed-in customer's proof that does not hold
     * @throws TooManyAttemptsException 429 while this address must wait
     */
    public function account(SignInSite $site, ServerRequestInterface $request, array $input, ?User $holder = null): GoogleAccount
    {
        $clientId = $site->googleClientId() ?? throw SignInRefusedException::googleOff();
        if (Input::text($input, 'id_token') === '') {
            throw ValidationException::on('id_token', 'id_token گوگل را بفرستید؛ همان credential که Google به صفحه داد.');
        }
        $this->throttle->check(SignInThrottle::WEBSITE, $request);

        try {
            $claims = $this->idTokens->nonced(OidcProvider::google(), $clientId, $input);
            $sub = $claims['sub'] ?? null;
            if (!is_string($sub) || $sub === '' || strlen($sub) > self::SUB_MAX) {
                throw SignInRefusedException::tokenInvalid();
            }
        } catch (SignInRefusedException $e) {
            if ($e->failedSignIn()) {
                $this->throttle->failed(SignInThrottle::WEBSITE, $request);
                if ($holder !== null) {
                    throw ValidationException::on('id_token', $e->getMessage());
                }
            }

            throw $e;
        } catch (ProviderUnreachableException $e) {
            // The owner's to look into when it lasts: a host that may not reach www.googleapis.com signs nobody in.
            $this->logger->warning('A Google sign-in on the website could not ask Google: {message}', ['message' => $e->getMessage()]);

            throw SignInRefusedException::googleUnreachable($e);
        }

        return new GoogleAccount($sub, self::trustedEmail($claims), Customers::profile(null, $claims['given_name'] ?? $claims['name'] ?? null, $claims['family_name'] ?? null));
    }

    /**
     * The address Google speaks for, as the shop keeps one: `email` while `email_verified` is true (as JSON's true, or its
     * text) and Google is the address's own authority — an @gmail.com address, or a Google Workspace account's (the token
     * carries its domain, `hd`), Google's own rule for when the address proves its owner now. Null otherwise: an address
     * of another domain Google checked once — whoever owns it today may not be the one signing in.
     *
     * @param array<string, mixed> $claims
     */
    private static function trustedEmail(array $claims): ?string
    {
        $verified = $claims['email_verified'] ?? false;
        $email = is_string($claims['email'] ?? null) ? Email::of($claims['email']) : null;
        if (($verified !== true && $verified !== 'true') || $email === null) {
            return null;
        }
        $workspace = is_string($claims['hd'] ?? null) && trim($claims['hd']) !== '';

        return str_ends_with($email, '@' . self::GMAIL) || $workspace ? $email : null;
    }
}
