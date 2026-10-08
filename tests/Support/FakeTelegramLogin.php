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
use Tests\DatabaseTestCase;

/**
 * A stand-in for Telegram's sign-in service (oauth.telegram.org, Log In With Telegram): it publishes a signing key of
 * its own — the tests' RSA key (SigningKey) — as its JWK set, signs the id_tokens a test hands the site (idToken(), from
 * claims()), and answers the token endpoint's code exchange with what the test queued (answerCode(), refuseCode()) —
 * a refusal when nothing was. down() takes it out of reach. Records every request. A Guzzle handler:
 * TestCase::telegramLogin() makes it the transport of the shop's outgoing client.
 */
final class FakeTelegramLogin
{
    /** The id of the key its set publishes and its tokens name. */
    public const KID = 'test-key-1';

    /** The Telegram user its tokens sign in by default — the fixtures' customer (Fixtures::TELEGRAM_ID). */
    public const USER_ID = DatabaseTestCase::TELEGRAM_ID;

    /** @var list<array{method: string, url: string, request: RequestInterface}> */
    public array $history = [];

    /** @var list<Response> */
    private array $codes = [];

    private bool $down = false;

    /** @param array<string, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $url = (string) $request->getUri()->withQuery('');
        $this->history[] = ['method' => $request->getMethod(), 'url' => $url, 'request' => $request];
        if ($this->down) {
            return new RejectedPromise(new ConnectException("oauth.telegram.org is out of reach in this test: {$url}", $request));
        }

        $provider = OidcProvider::telegram();

        return new FulfilledPromise(match ("{$request->getMethod()} {$url}") {
            "GET {$provider->jwksUri}" => self::json(200, ['keys' => [SigningKey::jwk(self::KID)]]),
            "POST {$provider->tokenEndpoint}" => array_shift($this->codes) ?? self::json(400, ['error' => 'invalid_grant']),
            default => throw new \LogicException("FakeTelegramLogin has no {$request->getMethod()} {$url}."),
        });
    }

    /**
     * What an id_token of Telegram's says, for the site `$clientId` and `$nonce`: issued now, for an hour, signing in
     * USER_ID — Ali, @ali —, with `$overrides` merged last (null leaves a claim out).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function claims(string $clientId, string $nonce, array $overrides = []): array
    {
        $claims = $overrides + [
            'iss' => OidcProvider::telegram()->issuers[0],
            'aud' => $clientId,
            'sub' => 'oidc-' . self::USER_ID,
            'iat' => now()->getTimestamp(),
            'exp' => now()->addHour()->getTimestamp(),
            'nonce' => $nonce,
            'id' => self::USER_ID,
            'name' => 'Ali Rezaei',
            'given_name' => 'Ali',
            'family_name' => 'Rezaei',
            'preferred_username' => 'ali',
        ];

        return array_filter($claims, static fn(mixed $claim): bool => $claim !== null);
    }

    /**
     * The claims signed as Telegram signs them — by its published key —, `$header` over the token's header (a `kid`
     * naming another key).
     *
     * @param array<string, mixed> $claims
     * @param array<string, string> $header
     */
    public static function idToken(array $claims, array $header = []): string
    {
        return JWT::encode($claims, SigningKey::private(), 'RS256', $header['kid'] ?? self::KID, $header);
    }

    /** The next code exchange answers with this id_token. */
    public function answerCode(string $idToken): void
    {
        $this->codes[] = self::json(200, ['access_token' => 'access', 'token_type' => 'Bearer', 'expires_in' => 3600, 'id_token' => $idToken]);
    }

    /** The next code exchange is refused, as a code that is spent or another site's is. */
    public function refuseCode(): void
    {
        $this->codes[] = self::json(400, ['error' => 'invalid_grant', 'error_description' => 'The code is spent.']);
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

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }
}
