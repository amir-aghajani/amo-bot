<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Oidc;

/**
 * An OpenID Connect provider customers sign in through: who signs its id_tokens (`issuers`, every spelling of `iss` it
 * uses), where its signing keys are published (`jwksUri`), where a browser is sent to sign in and where a code is
 * exchanged for tokens.
 */
final class OidcProvider
{
    /**
     * @param string $label Its name in log lines: "Telegram", "Google"
     * @param non-empty-list<string> $issuers The first is the one the log names
     */
    public function __construct(
        public readonly string $label,
        public readonly array $issuers,
        public readonly string $jwksUri,
        public readonly string $authorizationEndpoint,
        public readonly string $tokenEndpoint,
    ) {}

    /**
     * Log In With Telegram (https://core.telegram.org/bots/telegram-login): the Client ID is the one @BotFather shows for
     * the site, and the id_token's `id` claim is the Telegram user's numeric id.
     */
    public static function telegram(): self
    {
        return new self(
            label: 'Telegram',
            issuers: ['https://oauth.telegram.org'],
            jwksUri: 'https://oauth.telegram.org/.well-known/jwks.json',
            authorizationEndpoint: 'https://oauth.telegram.org/auth',
            tokenEndpoint: 'https://oauth.telegram.org/token',
        );
    }

    /** Sign in with Google (Google Identity Services): the audience is the site's OAuth client id; it signs as either issuer spelling. */
    public static function google(): self
    {
        return new self(
            label: 'Google',
            issuers: ['https://accounts.google.com', 'accounts.google.com'],
            jwksUri: 'https://www.googleapis.com/oauth2/v3/certs',
            authorizationEndpoint: 'https://accounts.google.com/o/oauth2/v2/auth',
            tokenEndpoint: 'https://oauth2.googleapis.com/token',
        );
    }

    /** Its issuer, as the log names it. */
    public function name(): string
    {
        return $this->issuers[0];
    }
}
