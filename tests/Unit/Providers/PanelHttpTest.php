<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Modules\Providers\Enums\ConnectionFailure;
use App\Modules\Providers\Exceptions\ConnectionException;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\PanelHttp;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\FakePanel;

/**
 * A request to a panel through its SOCKS5 proxy: the proxy is the request's own option, and a transport failure is
 * named where it happened — a connection that could not be made is the proxy's (cURL connects to nothing else), a
 * timeout once connected or a certificate is the panel's — with no address or login of the proxy in what is said.
 */
final class PanelHttpTest extends TestCase
{
    private const PANEL = 'https://panel.test:2053/abc';

    private const PROXY = 'socks5h://shop:s3cret%40pw@proxy.example.com:1080';

    public function testTheProxyGoesWithTheRequest(): void
    {
        $panel = new FakePanel();
        $http = new PanelHttp($panel->client(), new NullLogger());

        $http->send(new PanelConnection(self::PANEL, proxy: self::PROXY), 'GET', '/panel/api/server/status');
        $http->send(new PanelConnection(self::PANEL), 'GET', '/panel/api/server/status');

        self::assertSame(self::PROXY, $panel->options(0)['proxy'] ?? null);
        self::assertArrayNotHasKey('proxy', $panel->options(1), 'a panel without one is reached directly');
    }

    public function testAFailureAtTheProxyIsTheProxys(): void
    {
        foreach ([
            'its name' => 'cURL error 5: Could not resolve proxy: proxy.example.com (see https://curl.se/libcurl/c/libcurl-errors.html) for https://panel.test:2053/abc/panel/api/server/status',
            // cURL 8 looks a SOCKS proxy's name up as the host it connects to: 6, never the panel's (socks5h).
            'its name, said by cURL 8' => 'cURL error 6: Could not resolve host: proxy.example.com (see https://curl.se/libcurl/c/libcurl-errors.html) for https://panel.test:2053/abc/x',
            'its login' => 'cURL error 97: User was rejected by the SOCKS5 server (1 1).',
            'no way to the panel' => "cURL error 97: Can't complete SOCKS5 connection to panel.test. (4)",
            'an older cURL\'s handshake' => "cURL error 7: Can't complete SOCKS5 connection to panel.test. (5)",
            'its port closed' => "cURL error 7: Failed to connect to proxy.example.com port 1080 after 3 ms: Couldn't connect to server",
            'no answer while connecting' => 'cURL error 28: Connection timed out after 10001 milliseconds',
            'none while connecting, said by cURL 8' => 'cURL error 28: Failed to connect to proxy.example.com port 1080 after 10002 ms: Timeout was reached',
        ] as $case => $message) {
            self::assertSame(ConnectionFailure::Proxy, $this->failure($message, proxied: true)->failure, $case);
        }

        self::assertTrue(ConnectionFailure::Proxy->beforeRequest(), 'a write that never got past the proxy did not happen');
        self::assertSame('cURL error 5: Could not resolve proxy: proxy.example.com', $this->failure('cURL error 5: Could not resolve proxy: proxy.example.com (see https://curl.se/libcurl/c/libcurl-errors.html) for https://panel.test:2053/abc/x', proxied: true)->detail);
    }

    public function testWhatHappensPastTheProxyIsThePanels(): void
    {
        self::assertSame(ConnectionFailure::Timeout, $this->failure('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received', proxied: true)->failure, 'connected, then slow: the panel');
        self::assertSame(ConnectionFailure::Tls, $this->failure('cURL error 60: SSL certificate problem: self-signed certificate', proxied: true)->failure, 'its certificate');
        self::assertSame(ConnectionFailure::Refused, $this->failure('cURL error 7: Failed to connect to panel.test port 2053 after 21 ms: Connection refused', proxied: false)->failure, 'without a proxy, a closed port is the panel\'s');
        self::assertSame(ConnectionFailure::Timeout, $this->failure('cURL error 28: Connection timed out after 10001 milliseconds', proxied: false)->failure);
        self::assertSame(ConnectionFailure::Dns, $this->failure('cURL error 6: Could not resolve host: panel.test', proxied: false)->failure, 'without a proxy, a name not found is the panel\'s');
    }

    public function testNoProxyAddressOrLoginIsEverSaid(): void
    {
        $failure = $this->failure('cURL error 97: proxy socks5h://shop:s3cret%40pw@proxy.example.com:1080 refused the login', proxied: true);

        self::assertSame('cURL error 97: proxy [address] refused the login', $failure->detail);
        self::assertStringNotContainsString('s3cret', $failure->getMessage());
        self::assertStringNotContainsString('shop:', $failure->getMessage());
    }

    private function failure(string $message, bool $proxied): ConnectionException
    {
        $panel = new FakePanel();
        $panel->refuse(new ConnectException($message, new Request('GET', self::PANEL)));

        try {
            (new PanelHttp($panel->client(), new NullLogger()))->send(new PanelConnection(self::PANEL, proxy: $proxied ? self::PROXY : null), 'GET', '/panel/api/server/status');
        } catch (ConnectionException $e) {
            return $e;
        }

        self::fail("expected a ConnectionException for: {$message}");
    }
}
