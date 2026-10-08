<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Accounts\Oidc\OidcProvider;
use Firebase\JWT\JWT;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A stand-in for Google's sign-in (Google Identity Services): it publishes the tests' RSA key (SigningKey) as Google's
 * JWK set, and signs the id_tokens — the `credential` Google hands a page — a test posts to the site (idToken(), from
 * claims()). down() takes it out of reach. Records every request. A Guzzle handler: TestCase::googleLogin() makes it
 * the transport of the shop's outgoing client.
 */
final class FakeGoogleLogin
{
    /** The id of the key its set publishes and its tokens name. */
    public const KID = 'google-key-1';

    /** The Google account its tokens sign in by default. */
    public const SUB = '109876543210987654321';

    /** That account's address, verified by default. */
    public const EMAIL = 'amobot.test.customer@gmail.com';

    /** @var list<array{method: string, url: string}> */
    public array $history = [];

    private bool $down = false;

    /** @param array<string, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $url = (string) $request->getUri()->withQuery('');
        $this->history[] = ['method' => $request->getMethod(), 'url' => $url];
        if ($this->down) {
            return new RejectedPromise(new ConnectException("www.googleapis.com is out of reach in this test: {$url}", $request));
        }

        return new FulfilledPromise(match ("{$request->getMethod()} {$url}") {
            'GET ' . OidcProvider::google()->jwksUri => new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['keys' => [SigningKey::jwk(self::KID)]])),
            default => throw new \LogicException("FakeGoogleLogin has no {$request->getMethod()} {$url}."),
        });
    }

    /**
     * What an id_token of Google's says, for the site `$clientId` and `$nonce`: issued now, for an hour, signing in SUB —
     * Ali Rezaei, EMAIL verified —, with `$overrides` merged last (null leaves a claim out).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function claims(string $clientId, string $nonce, array $overrides = []): array
    {
        $claims = $overrides + [
            'iss' => 'https://accounts.google.com',
            'azp' => $clientId,
            'aud' => $clientId,
            'sub' => self::SUB,
            'email' => self::EMAIL,
            'email_verified' => true,
            'name' => 'Ali Rezaei',
            'given_name' => 'Ali',
            'family_name' => 'Rezaei',
            'iat' => now()->getTimestamp(),
            'exp' => now()->addHour()->getTimestamp(),
            'nonce' => $nonce,
        ];

        return array_filter($claims, static fn(mixed $claim): bool => $claim !== null);
    }

    /** @param array<string, mixed> $claims The claims signed as Google signs them — by its published key */
    public static function idToken(array $claims): string
    {
        return JWT::encode($claims, SigningKey::private(), 'RS256', self::KID);
    }

    /** Out of reach from now on (or back). */
    public function down(bool $down = true): void
    {
        $this->down = $down;
    }

    /** @return list<string> The requests made, in order, as "METHOD url". */
    public function calls(): array
    {
        return array_map(static fn(array $call): string => "{$call['method']} {$call['url']}", $this->history);
    }
}
