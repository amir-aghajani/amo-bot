<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Auth\Services\SignInThrottle;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\HttpTestCase;

/**
 * A host on IPv6 is handed a whole /64 and may send each try from another address of it: counted address by address,
 * it would never wait — and would leave a file behind in the throttle's folder for every try, until a shared host's
 * file quota ran out. An IPv6 address is counted by its /64; an IPv4 address — mapped into IPv6 too — by itself.
 */
final class SignInThrottleAddressesTest extends HttpTestCase
{
    private const WAY = 'link';

    public function testOneIpv6NetworkIsOneCountHoweverManyAddressesItTriesFrom(): void
    {
        $throttle = $this->service(SignInThrottle::class);
        for ($i = 1; $i <= SignInThrottle::MAX_ATTEMPTS; $i++) {
            $throttle->failed(self::WAY, self::from(sprintf('2001:db8:5:7:%x:%x::1', $i, random_int(1, 0xffff))));
        }

        self::assertGreaterThan(0, $throttle->wait(self::WAY, self::from('2001:db8:5:7:ffff::42')), 'any address of that /64 waits');
        self::assertSame(0, $throttle->wait(self::WAY, self::from('2001:db8:5:8::1')), 'the next /64 is someone else');
        self::assertCount(1, glob($this->app()->container()->get('throttle.path') . '/*.json') ?: [], 'one count, one file');
    }

    public function testIpv4AddressesAreCountedOneByOneMappedOrNot(): void
    {
        $throttle = $this->service(SignInThrottle::class);
        for ($i = 1; $i <= SignInThrottle::MAX_ATTEMPTS; $i++) {
            $throttle->failed(self::WAY, self::from('::ffff:198.51.100.7'));
        }

        self::assertGreaterThan(0, $throttle->wait(self::WAY, self::from('::ffff:198.51.100.7')));
        self::assertSame(0, $throttle->wait(self::WAY, self::from('::ffff:198.51.100.8')), 'mapped IPv4 addresses share no /64');
        self::assertSame(0, $throttle->wait(self::WAY, self::from('198.51.100.8')));
    }

    public function testAWebsitesAddressTakesMoreFailuresThanAPanelsForTheManyCustomersBehindOneOnAMobileNetwork(): void
    {
        $throttle = $this->service(SignInThrottle::class);
        for ($i = 1; $i <= SignInThrottle::MAX_ATTEMPTS; $i++) {
            $throttle->failed(self::WAY, self::from('203.0.113.9'));
            $throttle->failed(SignInThrottle::WEBSITE, self::from('203.0.113.9'));
        }

        self::assertGreaterThan(0, $throttle->wait(self::WAY, self::from('203.0.113.9')), "a panel's way in waits");
        self::assertSame(0, $throttle->wait(SignInThrottle::WEBSITE, self::from('203.0.113.9')), 'a website still takes tries from a carrier\'s shared address');
        for ($i = SignInThrottle::MAX_ATTEMPTS + 1; $i <= SignInThrottle::WEBSITE_ATTEMPTS; $i++) {
            $throttle->failed(SignInThrottle::WEBSITE, self::from('203.0.113.9'));
        }
        self::assertGreaterThan(0, $throttle->wait(SignInThrottle::WEBSITE, self::from('203.0.113.9')), 'until its own count is spent');
    }

    private static function from(string $address): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/agent/auth/link', ['REMOTE_ADDR' => $address]);
    }
}
