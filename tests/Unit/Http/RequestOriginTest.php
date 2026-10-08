<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Core\Http\RequestOrigin;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Who the browser is: REMOTE_ADDR, unless it is a proxy the shop trusts — then the X-Forwarded-For chain, read back past
 * the trusted hops only, so a header the browser wrote itself names nobody. And whether it came over HTTPS.
 */
final class RequestOriginTest extends TestCase
{
    public function testWithNoTrustedProxyTheConnectionIsTheBrowser(): void
    {
        $origin = new RequestOrigin();

        self::assertSame('203.0.113.7', $origin->clientIp(self::request('203.0.113.7', '198.51.100.1')), 'a forwarding header from anyone is ignored');
        self::assertSame('', $origin->clientIp((new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/')), 'the command line has none');
    }

    public function testATrustedProxysChainIsReadBackPastTheTrustedHopsOnly(): void
    {
        $origin = RequestOrigin::trusting('127.0.0.1, 173.245.48.0/20');

        self::assertSame('198.51.100.1', $origin->clientIp(self::request('127.0.0.1', '198.51.100.1')), 'nginx in front of PHP');
        self::assertSame('198.51.100.1', $origin->clientIp(self::request('173.245.48.9', '6.6.6.6, 198.51.100.1')), 'Cloudflare appends the visitor to what the visitor wrote');
        self::assertSame('198.51.100.1', $origin->clientIp(self::request('127.0.0.1', '6.6.6.6, 198.51.100.1, 173.245.48.9')), 'Cloudflare, then nginx');
        self::assertSame('127.0.0.1', $origin->clientIp(self::request('127.0.0.1', '')), 'a proxy that names nobody is the address');
        self::assertSame('127.0.0.1', $origin->clientIp(self::request('127.0.0.1', '6.6.6.6, not-an-address')), 'the chain is not read past a hop it cannot read');
        self::assertSame('173.245.48.20', $origin->clientIp(self::request('127.0.0.1', '173.245.48.20')), 'trusted all the way: the farthest one');
    }

    public function testRangesOfEitherFamily(): void
    {
        $origin = RequestOrigin::trusting('2400:cb00::/32 10.0.0.0/8 garbage 192.0.2.1/40');

        self::assertSame('2001:db8::5', $origin->clientIp(self::request('2400:cb00:2049::1', '2001:db8::5')));
        self::assertSame('2001:db8::5', $origin->clientIp(self::request('10.20.30.40', '2001:db8::5')));
        self::assertSame('2400:cb01::1', $origin->clientIp(self::request('2400:cb01::1', '2001:db8::5')), 'outside the range');
        self::assertSame('192.0.2.1', $origin->clientIp(self::request('192.0.2.1', '2001:db8::5')), 'a range that cannot be is trusted for nothing');
    }

    public function testHttpsIsTheSchemeOrWhatAProxySays(): void
    {
        $origin = new RequestOrigin();
        $factory = new ServerRequestFactory();

        self::assertFalse($origin->isHttps($factory->createServerRequest('GET', 'http://localhost/')));
        self::assertTrue($origin->isHttps($factory->createServerRequest('GET', 'https://localhost/')));
        self::assertTrue($origin->isHttps($factory->createServerRequest('GET', 'http://localhost/')->withHeader('X-Forwarded-Proto', 'https, http')));
        self::assertTrue($origin->isHttps($factory->createServerRequest('GET', 'http://localhost/')->withHeader('CF-Visitor', '{"scheme":"https"}')));
        self::assertTrue($origin->isHttps($factory->createServerRequest('GET', 'http://localhost/', ['REQUEST_SCHEME' => 'https'])));
        self::assertTrue($origin->isHttps($factory->createServerRequest('GET', 'http://localhost/', ['SERVER_PORT' => '443'])));
        self::assertFalse($origin->isHttps($factory->createServerRequest('GET', 'http://localhost/')->withHeader('X-Forwarded-Proto', 'http')));
    }

    private static function request(string $remote, string $forwardedFor): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/api/admin/auth/login', ['REMOTE_ADDR' => $remote]);

        return $forwardedFor === '' ? $request : $request->withHeader('X-Forwarded-For', $forwardedFor);
    }
}
