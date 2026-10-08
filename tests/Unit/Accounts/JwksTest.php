<?php

declare(strict_types=1);

namespace Tests\Unit\Accounts;

use App\Modules\Accounts\Oidc\Jwks;
use App\Modules\Accounts\Oidc\OidcProvider;
use App\Modules\Accounts\Oidc\ProviderUnreachableException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\FakeTelegramLogin;

/**
 * A provider's published key set as a sign-in is checked with: only keys that sign, by an asymmetric algorithm, are
 * taken — a shared secret a set might carry, a key for encryption, a key the library cannot read are left out —, a key
 * that names no algorithm gets its type's, and a set with nothing to sign with is no set: the provider could not be
 * asked what a sign-in needs.
 */
final class JwksTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/amobot-jwks-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function testOnlyKeysThatSignAsymmetricallyAreTaken(): void
    {
        $good = self::publishedKey();
        $set = ['keys' => [
            ['kty' => 'oct', 'alg' => 'HS256', 'kid' => 'shared', 'k' => 'c2VjcmV0LXNoYXJlZC1ieS1ldmVyeW9uZS0xMjM0NTY3OA'],
            ['use' => 'enc', 'kid' => 'encrypting'] + $good,
            ['kid' => 'unreadable', 'n' => ['not', 'text']] + $good,
            ['kid' => 'no-algorithm'] + array_diff_key($good, ['alg' => true]),
            $good,
        ]];

        $keys = $this->jwks($set)->keys(OidcProvider::telegram());

        self::assertSame(self::sorted([FakeTelegramLogin::KID, 'no-algorithm']), self::sorted(array_keys($keys)));
        self::assertSame('RS256', $keys['no-algorithm']->getAlgorithm(), "an RSA key's own");
        self::assertCount(1, glob($this->directory . '/*.json') ?: [], 'kept for the next sign-in');
    }

    public function testASetWithNothingToSignWithIsNone(): void
    {
        $this->expectException(ProviderUnreachableException::class);

        $this->jwks(['keys' => [['kty' => 'oct', 'alg' => 'HS256', 'kid' => 'shared', 'k' => 'c2VjcmV0']]])->keys(OidcProvider::telegram());
    }

    /** @param array<string, mixed> $set What the provider publishes */
    private function jwks(array $set): Jwks
    {
        $client = new Client(['handler' => MockHandler::createWithMiddleware([new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($set))])]);

        return new Jwks($client, new NullLogger(), $this->directory);
    }

    /** @return array<string, string> The JWK the fake Telegram publishes */
    private static function publishedKey(): array
    {
        $answer = (new FakeTelegramLogin())(new Request('GET', OidcProvider::telegram()->jwksUri), [])->wait();
        $set = json_decode((string) $answer->getBody(), true);

        return $set['keys'][0];
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private static function sorted(array $names): array
    {
        sort($names);

        return $names;
    }
}
