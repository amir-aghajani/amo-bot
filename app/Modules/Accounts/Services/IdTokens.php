<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Exceptions\ValidationException;
use App\Modules\Accounts\Enums\ChallengePurpose;
use App\Modules\Accounts\Oidc\IdTokenVerifier;
use App\Modules\Accounts\Oidc\OidcProvider;
use App\Modules\Accounts\Oidc\ProviderUnreachableException;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Support\Input;

/**
 * The one way an id_token a provider's own sign-in handed the site — Telegram's popup, Google Identity Services — signs
 * a customer in: it came for a nonce the shop gave the site first (POST /auth/nonce, AuthChallenges::nonce()). The token
 * is checked the one way (IdTokenVerifier) before anything is spent — a provider out of reach leaves the nonce for a
 * retry —, then the nonce is spent, once, and must be the token's own.
 */
final class IdTokens
{
    public function __construct(
        private readonly IdTokenVerifier $verifier,
        private readonly AuthChallenges $challenges,
    ) {}

    /**
     * The claims of the provider's token for the site `$audience`, posted with the nonce it was asked for.
     *
     * @param array<string, mixed> $input {id_token, nonce}
     * @return array<string, mixed>
     * @throws ValidationException 422 on `nonce`: none sent
     * @throws SignInRefusedException 401: not the provider's token for this site, expired, or its nonce not one the shop gave and nobody spent
     * @throws ProviderUnreachableException when the provider's keys cannot be had
     */
    public function nonced(OidcProvider $provider, string $audience, array $input): array
    {
        $nonce = Input::text($input, 'nonce');
        if ($nonce === '') {
            throw ValidationException::on('nonce', 'nonce ورود را هم بفرستید؛ همان که POST /auth/nonce داد.');
        }

        $claims = $this->verifier->verify($provider, Input::text($input, 'id_token'), $audience);
        if ($this->challenges->spend(ChallengePurpose::Nonce, $nonce) === null || !self::carries($claims, $nonce)) {
            throw SignInRefusedException::signInSpent();
        }

        return $claims;
    }

    /**
     * Whether the token's claims carry `$nonce` — the one its sign-in began with.
     *
     * @param array<string, mixed> $claims
     */
    public static function carries(array $claims, string $nonce): bool
    {
        return is_string($claims['nonce'] ?? null) && hash_equals($nonce, $claims['nonce']);
    }
}
