<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Oidc;

/**
 * An OpenID Connect provider customers sign in through: who signs its id_tokens (`issuers` — the `iss` a token of its
 * may carry; one, or the spellings it uses), where its signing keys are published (`jwksUri`, a JWK set), where a
 * browser is sent to sign in (`authorizationEndpoint`) and where a sign-in's code is exchanged for its tokens
 * (`tokenEndpoint`).
 */
final class OidcProvider
{
    /** @param non-empty-list<string> $issuers The first is its own name, the one the log says */
    public function __construct(
        public readonly array $issuers,
        public readonly string $jwksUri,
        public readonly string $authorizationEndpoint,
        public readonly string $tokenEndpoint,
    ) {}

    /**
     * Telegram's (Log In With Telegram, https://core.telegram.org/bots/telegram-login): the Client ID is the one
     * @BotFather shows for the site; the id_token's `id` claim is the Telegram user's numeric id.
     */
    public static function telegram(): self
    {
        return new self(
            issuers: ['https://oauth.telegram.org'],
            jwksUri: 'https://oauth.telegram.org/.well-known/jwks.json',
            authorizationEndpoint: 'https://oauth.telegram.org/auth',
            tokenEndpoint: 'https://oauth.telegram.org/token',
        );
    }

    /**
     * Google's (Sign in with Google — Google Identity Services hands the site an id_token, its `credential`): the
     * audience is the site's OAuth client id; it signs as either spelling of its issuer.
     */
    public static function google(): self
    {
        return new self(
            issuers: ['https://accounts.google.com', 'accounts.google.com'],
            jwksUri: 'https://www.googleapis.com/oauth2/v3/certs',
            authorizationEndpoint: 'https://accounts.google.com/o/oauth2/v2/auth',
            tokenEndpoint: 'https://oauth2.googleapis.com/token',
        );
    }

    /** Its name in the log: its first issuer. */
    public function name(): string
    {
        return $this->issuers[0];
    }
}
