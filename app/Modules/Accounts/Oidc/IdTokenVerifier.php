<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Oidc;

use App\Modules\Auth\Exceptions\SignInRefusedException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;

/**
 * The one way an id_token is checked, whoever signed it: its signature against the provider's published keys (Jwks —
 * the key its header names, by the algorithm that key is for), then that the provider issued it (`iss`, one of the
 * provider's spellings) for this site (`aud`, alone or among others), and its times — issued (`iat`) and until (`exp`),
 * both required — with LEEWAY seconds of the clocks' difference. Anything wrong is the sign-in's refusal
 * (SignInRefusedException, a 401) in the customer's words; the token is never repeated in one, nor in the log.
 */
final class IdTokenVerifier
{
    private const LEEWAY = 60;

    /** The longest token read: an id_token is a few hundred characters; anything far longer is no id_token. */
    private const MAX_LENGTH = 16_384;

    public function __construct(private readonly Jwks $jwks) {}

    /**
     * The token's claims, once it is the provider's own for `$audience`.
     *
     * @return array<string, mixed>
     * @throws SignInRefusedException 401: not a token of the provider's for this site, or expired
     * @throws ProviderUnreachableException when the provider's keys cannot be had
     */
    public function verify(OidcProvider $provider, string $jwt, string $audience): array
    {
        $kid = self::keyIdOf($jwt);
        $keys = $this->jwks->keys($provider, $kid);
        $key = $kid !== null ? ($keys[$kid] ?? null) : (count($keys) === 1 ? reset($keys) : null);
        if ($key === null) {
            throw SignInRefusedException::tokenInvalid();
        }

        // The library's clock, for this check: the shop's (now(), as every time it keeps), with the leeway.
        [$leeway, $timestamp] = [JWT::$leeway, JWT::$timestamp];
        [JWT::$leeway, JWT::$timestamp] = [self::LEEWAY, now()->getTimestamp()];
        try {
            $claims = json_decode((string) json_encode(JWT::decode($jwt, $key)), true);
        } catch (ExpiredException) {
            throw SignInRefusedException::tokenExpired();
        } catch (\UnexpectedValueException|\DomainException|\InvalidArgumentException) {
            throw SignInRefusedException::tokenInvalid();
        } finally {
            [JWT::$leeway, JWT::$timestamp] = [$leeway, $timestamp];
        }

        if (!is_array($claims) || !is_numeric($claims['exp'] ?? null) || !is_numeric($claims['iat'] ?? null)) {
            throw SignInRefusedException::tokenInvalid();
        }
        if (!in_array($claims['iss'] ?? null, $provider->issuers, true) || !self::isFor($claims['aud'] ?? null, $audience)) {
            throw SignInRefusedException::tokenElsewhere();
        }

        return $claims;
    }

    /**
     * The id of the key the token's header names (`kid`) — null when it names none —, refused for what is no token: a
     * header whose algorithm or key id is not text never reaches the library.
     *
     * @throws SignInRefusedException
     */
    private static function keyIdOf(string $jwt): ?string
    {
        $parts = explode('.', $jwt);
        if (strlen($jwt) > self::MAX_LENGTH || count($parts) !== 3) {
            throw SignInRefusedException::tokenInvalid();
        }
        $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true);
        if (!is_array($header) || !is_string($header['alg'] ?? null) || (isset($header['kid']) && !is_string($header['kid']))) {
            throw SignInRefusedException::tokenInvalid();
        }

        return $header['kid'] ?? null;
    }

    /** Whether the token's audience — one, or a list — is this site (a numeric Client ID, written as a number or as text alike). */
    private static function isFor(mixed $audience, string $site): bool
    {
        $named = static fn(mixed $one): bool => (is_string($one) || is_int($one)) && (string) $one === $site;

        return is_array($audience) ? array_filter($audience, $named) !== [] : $named($audience);
    }
}
