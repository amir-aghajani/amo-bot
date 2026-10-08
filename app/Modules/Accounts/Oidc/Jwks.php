<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Oidc;

use App\Core\Support\Files;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * An OpenID provider's signing keys, as it publishes them (its JWK set), read through the shop's outgoing client and
 * kept in a file of the container's `jwks.path` folder (a file per provider, written whole) for KEEP_SECONDS — a
 * sign-in does not ask the provider each time. A token signed with a key the kept set lacks — the provider turned its
 * keys over — has the set read again, but not sooner than REFRESH_SECONDS after the last read: made-up key ids cannot
 * have the shop ask on every request. A provider out of reach leaves the kept set in use, however old; with none kept,
 * a sign-in cannot be checked (ProviderUnreachableException).
 *
 * Only keys that sign, by an asymmetric algorithm, are taken: a set's symmetric key or a key for encryption is left
 * out, as is one this cannot read.
 */
final class Jwks
{
    private const KEEP_SECONDS = 3600;

    private const REFRESH_SECONDS = 60;

    /** The algorithms a signing key may use: asymmetric ones — a published key is public, never a shared secret. */
    private const ALGORITHMS = ['RS256', 'RS384', 'RS512', 'PS256', 'ES256', 'ES256K', 'ES384', 'EdDSA'];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly LoggerInterface $logger,
        /** Where the sets are kept (the container's `jwks.path`). */
        private readonly string $directory,
    ) {}

    /**
     * The provider's signing keys by their id (`kid`) — read again first when the kept set is older than KEEP_SECONDS,
     * or lacks `$kid` and was read REFRESH_SECONDS ago.
     *
     * @return array<string, Key>
     * @throws ProviderUnreachableException when no set can be had
     */
    public function keys(OidcProvider $provider, ?string $kid = null): array
    {
        $kept = $this->kept($provider);
        if ($kept !== null) {
            $age = now()->getTimestamp() - $kept['read_at'];
            $keys = self::parse($kept['set']);
            if ($age < self::KEEP_SECONDS && ($kid === null || isset($keys[$kid]) || $age < self::REFRESH_SECONDS)) {
                return $keys;
            }
        }

        try {
            $set = $this->fetch($provider);
        } catch (ProviderUnreachableException $e) {
            if ($kept === null) {
                throw $e;
            }
            $this->logger->warning('The signing keys of {issuer} could not be read again; the ones kept stand in: {message}', ['issuer' => $provider->name(), 'message' => $e->getMessage()]);

            return self::parse($kept['set']);
        }

        $this->keep($provider, $set);

        return self::parse($set);
    }

    /**
     * The provider's set as it publishes it now — one that holds no key this can use is none.
     *
     * @return array<string, mixed>
     * @throws ProviderUnreachableException
     */
    private function fetch(OidcProvider $provider): array
    {
        try {
            $response = $this->http->request('GET', $provider->jwksUri, ['headers' => ['Accept' => 'application/json']]);
        } catch (GuzzleException $e) {
            throw new ProviderUnreachableException("{$provider->jwksUri}: {$e->getMessage()}", 0, $e);
        }

        $set = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() !== 200 || !is_array($set) || self::parse($set) === []) {
            throw new ProviderUnreachableException("{$provider->jwksUri} answered {$response->getStatusCode()} without a signing key.");
        }

        return $set;
    }

    /** @return array{read_at: int, set: array<string, mixed>}|null The set kept for the provider, and when it was read */
    private function kept(OidcProvider $provider): ?array
    {
        $kept = json_decode((string) Files::read($this->file($provider)), true);

        return is_array($kept) && is_int($kept['read_at'] ?? null) && is_array($kept['set'] ?? null) ? ['read_at' => $kept['read_at'], 'set' => $kept['set']] : null;
    }

    /** @param array<string, mixed> $set */
    private function keep(OidcProvider $provider, array $set): void
    {
        try {
            Files::writeAtomically($this->file($provider), (string) json_encode(['read_at' => now()->getTimestamp(), 'set' => $set]));
        } catch (\RuntimeException $e) {
            // A folder the server may not write costs a read of the keys a sign-in, never the sign-in.
            $this->logger->warning('The signing keys of {issuer} could not be kept: {message}', ['issuer' => $provider->name(), 'message' => $e->getMessage()]);
        }
    }

    private function file(OidcProvider $provider): string
    {
        return $this->directory . '/' . hash('sha256', $provider->jwksUri) . '.json';
    }

    /**
     * The set's signing keys by their id (a key without one by its place in the set).
     *
     * @param array<string, mixed> $set
     * @return array<string, Key>
     */
    private static function parse(array $set): array
    {
        $keys = [];
        foreach (is_array($set['keys'] ?? null) ? $set['keys'] : [] as $index => $jwk) {
            if (!is_array($jwk) || !self::isText($jwk) || ($jwk['use'] ?? 'sig') !== 'sig') {
                continue;
            }
            $algorithm = $jwk['alg'] ?? self::algorithmOf($jwk);
            if (!in_array($algorithm, self::ALGORITHMS, true)) {
                continue;
            }
            try {
                $key = JWK::parseKey(['alg' => $algorithm] + $jwk);
            } catch (\UnexpectedValueException|\InvalidArgumentException|\DomainException) {
                continue;
            }
            if ($key !== null) {
                $keys[(string) ($jwk['kid'] ?? $index)] = $key;
            }
        }

        return $keys;
    }

    /**
     * Whether a key's members the library reads are text, as a JWK's are — anything else is no key to read.
     *
     * @param array<array-key, mixed> $jwk
     */
    private static function isText(array $jwk): bool
    {
        foreach (['kty', 'use', 'alg', 'kid', 'n', 'e', 'crv', 'x', 'y'] as $member) {
            if (isset($jwk[$member]) && !is_string($jwk[$member])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The algorithm a key that names none signs with, by its type and curve — null for a type that signs nothing here.
     *
     * @param array<array-key, mixed> $jwk
     */
    private static function algorithmOf(array $jwk): ?string
    {
        return match ($jwk['kty'] ?? null) {
            'RSA' => 'RS256',
            'EC' => match ($jwk['crv'] ?? null) {
                'P-256' => 'ES256',
                'P-384' => 'ES384',
                'secp256k1' => 'ES256K',
                default => null,
            },
            'OKP' => ($jwk['crv'] ?? null) === 'Ed25519' ? 'EdDSA' : null,
            default => null,
        };
    }
}
