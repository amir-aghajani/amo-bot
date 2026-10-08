<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Enums;

/** What a short-lived secret of the sign-in flows is for (`auth_challenges.purpose`): a secret is spent for its purpose only. */
enum ChallengePurpose: string
{
    /** A nonce a site hands an OpenID provider, which signs it into the id_token it gives back (POST /auth/nonce). */
    case Nonce = 'nonce';

    /** The `state` of an authorization-code sign-in, keeping the PKCE challenge the site made of its verifier, the return address and a nonce. */
    case OidcState = 'oidc_state';

    /** The code emailed to a new address, keeping the account the sign-up makes once it is typed back. */
    case SignUp = 'sign_up';

    /** The code emailed to a customer's address for a new password. */
    case PasswordReset = 'password_reset';

    /** The code emailed to an address a signed-in customer adds to their account, keeping the password they chose for it. */
    case LinkEmail = 'link_email';

    /** A password sign-in's second step, waiting for the code of the account's authenticator app (or a recovery code). */
    case TwoFactor = 'two_factor';

    /** An authenticator app's new secret, waiting for its first code to turn two-factor sign-in on — one a customer. */
    case TotpSetup = 'totp_setup';

    /** A merge offered to a customer: the account that asked, the other one, and what the account that stays takes. */
    case Merge = 'merge';

    /**
     * Whether anyone may have one issued, without an account to stand for it or an email it costs — a stranger asking
     * address after address: what a shop keeps at most so many of at once (AuthChallenges::LIVE_MAX).
     */
    public function anyonesToAsk(): bool
    {
        return $this === self::Nonce || $this === self::OidcState;
    }
}
