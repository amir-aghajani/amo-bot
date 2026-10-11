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
use App\Modules\Accounts\Http\Customer;
use App\Modules\Accounts\Oidc\OidcProvider;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\Customers;
use App\Support\Email;
use App\Support\Input;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Signing in on the website with a Google account: Google Identity Services hands the page an id_token for a nonce the
 * shop gave (ProviderSignIn::nonced()), under the website's OAuth client id. The token's `sub` is the Google account.
 *
 * Its `email` is trusted only when Google is the address's own authority right now (trustedEmail()): verified, and an
 * @gmail.com address or a Workspace account's (an `hd` claim). Any other address Google once verified may belong to
 * someone else today, so it finds, signs in to and merges no account, and no newcomer keeps it.
 */
final class GoogleSignIn
{
    /** The longest Google account id kept: the column's. */
    private const SUB_MAX = 191;

    /** The domain whose every address is a Google account's own. */
    private const GMAIL = 'gmail.com';

    public function __construct(
        private readonly ProviderSignIn $provider,
        private readonly Customers $customers,
        private readonly CustomerNotifier $notifier,
    ) {}

    /**
     * Sign the customer in: the account that Google account signs in, else the one with the trusted address (which takes
     * the Google account on and is notified), else a newcomer with the site's `referral_code` (Customers::byGoogle()).
     *
     * @param array<string, mixed> $input {id_token, nonce, referral_code?}
     * @throws SignInRefusedException 401 the proof does not hold; 403 banned; 409 the address's account takes no Google account on this way; 422 Google sign-in not set up; 502 Google out of reach
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
            // The account found by its address took the Google account on: one more way into it.
            $this->notifier->wayInAdded($user, WayIn::Google->label());
        }

        return $this->provider->open($user, $request, SignInMethod::Google);
    }

    /**
     * The Google account the id_token proves — what a sign-in signs in by, and what a signed-in customer (`$holder`)
     * links to their account or proves theirs again. Run as a sign-in attempt (ProviderSignIn::prove()).
     *
     * @param array<string, mixed> $input {id_token, nonce}
     * @throws SignInRefusedException 401 the proof does not hold (a sign-in's); 422 Google sign-in not set up; 502 Google out of reach
     * @throws ValidationException 422 a field missing; on `id_token` when a signed-in customer's proof does not hold
     * @throws TooManyAttemptsException 429 while this address must wait
     */
    public function account(SignInSite $site, ServerRequestInterface $request, array $input, ?Customer $holder = null): GoogleAccount
    {
        $clientId = $site->googleClientId() ?? throw SignInRefusedException::googleOff();
        if (Input::text($input, 'id_token') === '') {
            throw ValidationException::on('id_token', 'id_token گوگل را بفرستید؛ همان credential که Google به صفحه داد.');
        }

        $claims = $this->provider->prove(OidcProvider::google(), $request, $holder !== null, 'id_token', function () use ($clientId, $input): array {
            $claims = $this->provider->nonced(OidcProvider::google(), $clientId, $input);
            $sub = $claims['sub'] ?? null;
            if (!is_string($sub) || $sub === '' || strlen($sub) > self::SUB_MAX) {
                throw SignInRefusedException::tokenInvalid();
            }

            return $claims;
        }, SignInRefusedException::googleUnreachable(...));

        return new GoogleAccount((string) $claims['sub'], self::trustedEmail($claims), Customers::profile(null, $claims['given_name'] ?? $claims['name'] ?? null, $claims['family_name'] ?? null));
    }

    /**
     * The address Google speaks for: `email` while `email_verified` is true (JSON's true or its text) and it is an
     * @gmail.com address or a Workspace account's (`hd`). Null otherwise.
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
