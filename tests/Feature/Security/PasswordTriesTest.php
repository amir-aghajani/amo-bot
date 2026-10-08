<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Modules\Auth\Services\SignInThrottle;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\HttpTestCase;

/**
 * A password is counted as it is tried, before it is judged (SignInThrottle::password()): guesses sent at the same
 * moment each find the ones before them counted, so a burst never gets more tries than the limit — and a right password
 * gives its count back, the limit being the wrong ones'.
 */
final class PasswordTriesTest extends HttpTestCase
{
    public function testATryIsCountedWhileItIsJudgedSoOneMoreAtTheSameMomentIsRefused(): void
    {
        $throttle = $this->service(SignInThrottle::class);
        $request = self::from('203.0.113.7');
        for ($i = 1; $i < SignInThrottle::WEBSITE_ATTEMPTS; $i++) {
            $throttle->failed(SignInThrottle::WEBSITE, $request);
        }

        // The last try the limit allows is being judged when another arrives from the same address.
        $other = null;
        $right = $throttle->password(SignInThrottle::WEBSITE, $request, 'sara@example.com', static function () use ($throttle, $request, &$other): bool {
            try {
                $throttle->password(SignInThrottle::WEBSITE, $request, 'sara@example.com', static fn(): bool => false);
                $other = 'judged';
            } catch (TooManyAttemptsException) {
                $other = 'refused';
            }

            return false;
        });

        self::assertFalse($right);
        self::assertSame('refused', $other, 'counted before it is judged: the one more at the same moment finds the limit spent');
        self::assertGreaterThan(0, $throttle->wait(SignInThrottle::WEBSITE, $request));
    }

    public function testARightPasswordGivesItsCountBackAndAWrongOneKeepsIt(): void
    {
        $throttle = $this->service(SignInThrottle::class);
        $request = self::from('203.0.113.8');

        for ($i = 1; $i <= SignInThrottle::WEBSITE_ATTEMPTS; $i++) {
            self::assertTrue($throttle->password(SignInThrottle::WEBSITE, $request, 'ali@example.com', static fn(): bool => true));
        }
        self::assertSame(0, $throttle->wait(SignInThrottle::WEBSITE, $request, 'ali@example.com'), 'right ones cost nothing');

        for ($i = 1; $i <= SignInThrottle::WEBSITE_ATTEMPTS; $i++) {
            self::assertFalse($throttle->password(SignInThrottle::WEBSITE, $request, 'ali@example.com', static fn(): bool => false));
        }
        self::assertGreaterThan(0, $throttle->wait(SignInThrottle::WEBSITE, $request), 'wrong ones are counted');

        $this->expectException(TooManyAttemptsException::class);
        $throttle->password(SignInThrottle::WEBSITE, $request, 'ali@example.com', static fn(): bool => self::fail('a refused try is not judged'));
    }

    private static function from(string $address): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/store/v1/0123456789abcdef01234567/auth/login', ['REMOTE_ADDR' => $address]);
    }
}
