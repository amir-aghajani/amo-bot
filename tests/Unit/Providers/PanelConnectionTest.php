<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use App\Core\Forms\Form;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Support\PanelConnection;
use Tests\TestCase;

/**
 * The connection fields every connector's form shares: an http(s) address with a host and nothing secret in it, the
 * connector's own pages refused; a timeout of 5 to 120 seconds; an optional full subscription prefix; a SOCKS5 proxy —
 * a host and a port, a login whose password is kept only for the same proxy. Also how a stored server's options read
 * back (its proxy as a socks5h address), and the rule a kept secret must pass to be sent: the same scheme, host and port.
 */
final class PanelConnectionTest extends TestCase
{
    private const OPTIONS = ['verify_tls' => true, 'timeout' => 30, 'subscription_url' => '', 'proxy_enabled' => false];

    public function testTheAddressIsAWebAddressWithAHostAndNothingElseInIt(): void
    {
        foreach ([
            'nothing' => '',
            'no scheme' => 'panel.example.com',
            'another scheme' => 'ftp://panel.example.com',
            'no host' => 'https://',
            'credentials in it' => 'https://admin:secret@panel.example.com',
            'a query' => 'https://panel.example.com/AbCdEf?token=1',
            'a fragment' => 'https://panel.example.com/AbCdEf#/login',
            'too long' => 'https://panel.example.com/' . str_repeat('a', 250),
        ] as $case => $url) {
            self::assertArrayHasKey('base_url', self::errors(['base_url' => $url] + self::OPTIONS), $case);
        }
        self::assertStringContainsString('در فیلدهای خودشان', self::errors(['base_url' => 'https://admin:secret@panel.example.com'] + self::OPTIONS)['base_url'][0], 'credentials have fields of their own');

        self::assertSame('https://panel.example.com:2053/AbCdEf', self::check(['base_url' => ' https://panel.example.com:2053/AbCdEf/ '] + self::OPTIONS)['base_url'], 'trimmed, without the trailing slash');
    }

    public function testAConnectorKeepsItsPanelsOwnPagesOutOfTheAddress(): void
    {
        self::assertSame(['base_url' => ['the base, please']], self::errors(['base_url' => 'https://panel.example.com/AbCdEf/panel/inbounds'] + self::OPTIONS));
        self::assertSame('https://panel.example.com/panelist', self::check(['base_url' => 'https://panel.example.com/panelist'] + self::OPTIONS)['base_url'], 'a path that merely begins so');
    }

    public function testATimeoutIsFiveToAHundredAndTwentySeconds(): void
    {
        foreach (['4' => true, '5' => false, '۱۲۰' => false, '121' => true, 'soon' => true, '' => true] as $timeout => $refused) {
            $errors = self::errors(['base_url' => 'https://panel.example.com', 'timeout' => (string) $timeout] + self::OPTIONS);
            self::assertSame($refused, isset($errors['timeout']), (string) $timeout);
        }
    }

    public function testASubscriptionPrefixIsAFullAddressOrNone(): void
    {
        foreach (['sub.example.com/sub', 'https://user:pw@sub.example.com/sub', 'https://sub.example.com/sub?token='] as $prefix) {
            self::assertArrayHasKey('subscription_url', self::errors(['base_url' => 'https://panel.example.com', 'subscription_url' => $prefix] + self::OPTIONS), $prefix);
        }

        self::assertSame('https://sub.example.com/sub', self::check(['base_url' => 'https://panel.example.com', 'subscription_url' => 'https://sub.example.com/sub/'] + self::OPTIONS)['meta.subscription_url']);
        self::assertSame('', self::check(['base_url' => 'https://panel.example.com'] + self::OPTIONS)['meta.subscription_url'], 'blank: the panel\'s own links');
    }

    public function testAStoredServersOptionsReadBackWithTheirDefaults(): void
    {
        $this->app(); // the encrypter the Server's casts need

        $fresh = PanelConnection::of(new Server(['base_url' => 'https://panel.example.com/AbCdEf/']));
        self::assertSame(['https://panel.example.com/AbCdEf', true, 30, null], [$fresh->baseUrl, $fresh->verifyTls, $fresh->timeout, $fresh->subscriptionUrl]);

        $kept = PanelConnection::of(new Server(['base_url' => 'https://panel.example.com', 'meta' => ['timeout' => 60, 'verify_tls' => false, 'subscription_url' => 'https://sub.example.com/sub']]));
        self::assertSame([false, 60, 'https://sub.example.com/sub'], [$kept->verifyTls, $kept->timeout, $kept->subscriptionUrl]);

        self::assertSame(['https://panel.example.com/AbCdEf', ''], PanelConnection::addresses(new Server(['base_url' => 'https://panel.example.com/AbCdEf/'])), 'the address and the prefix alone');
        self::assertSame(['https://panel.example.com', 'https://sub.example.com/sub'], PanelConnection::addresses(new Server(['base_url' => 'https://panel.example.com', 'meta' => ['subscription_url' => 'https://sub.example.com/sub']])));
    }

    public function testASecretGoesWithTheSameSchemeHostAndPort(): void
    {
        $origin = PanelConnection::bound()[PanelConnection::ADDRESS];
        $same = static fn(string $url): bool => $origin($url) === $origin('https://Panel.Example.com/AbCdEf');

        self::assertTrue($same('https://panel.example.com:443/another-path'), 'the default port spelled out, the host in any case, any path');
        self::assertFalse($same('http://panel.example.com/AbCdEf'), 'another scheme');
        self::assertFalse($same('https://panel.example.com:8443/AbCdEf'), 'another port');
        self::assertFalse($same('https://elsewhere.example.com/AbCdEf'), 'another host');
    }

    public function testAProxyIsAHostAndAPortWithAnOptionalLogin(): void
    {
        $proxy = ['base_url' => 'https://panel.example.com', 'proxy_enabled' => true, 'proxy_host' => 'proxy.example.com', 'proxy_port' => '1080'] + self::OPTIONS;

        foreach ([
            'nothing' => '',
            'a scheme' => 'socks5://proxy.example.com',
            'a port in it' => 'proxy.example.com:1080',
            'a login in it' => 'shop:pw@proxy.example.com',
            'a path' => 'proxy.example.com/socks',
            'a space' => 'proxy example.com',
            'no IPv4 address' => '203.0.113.300',
            'too long' => str_repeat('a', 63) . '.' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 63) . '.com',
        ] as $case => $host) {
            self::assertArrayHasKey('proxy_host', self::errors(['proxy_host' => $host] + $proxy), $case);
        }
        self::assertStringContainsString('بدون socks5://', self::errors(['proxy_host' => 'socks5://proxy.example.com'] + $proxy)['proxy_host'][0], 'what to leave out, said');

        foreach (['Proxy.Example.COM' => 'proxy.example.com', '203.0.113.10' => '203.0.113.10', '[2001:DB8::1]' => '2001:db8::1', ' localhost ' => 'localhost'] as $typed => $kept) {
            self::assertSame($kept, self::check(['proxy_host' => $typed] + $proxy)['proxy_host'], $typed);
        }

        foreach (['0' => true, '1' => false, '۱۰۸۰' => false, '65535' => false, '65536' => true, 'socks' => true, '' => true] as $port => $refused) {
            self::assertSame($refused, isset(self::errors(['proxy_port' => (string) $port] + $proxy)['proxy_port']), "port {$port}");
        }

        self::assertSame(['proxy_password'], array_keys(self::errors(['proxy_password' => 'pw'] + $proxy)), 'a password alone is no login');
        self::assertStringContainsString('نام کاربری پراکسی', self::errors(['proxy_password' => 'pw'] + $proxy)['proxy_password'][0]);
        $login = self::check(['proxy_username' => 'shop', 'proxy_password' => 'p@ss'] + $proxy);
        self::assertSame(['shop', 'p@ss', 1080], [$login['proxy_username'], $login['proxy_password'], $login['proxy_port']]);
        self::assertSame('', self::check(['proxy_username' => 'shop'] + $proxy)['proxy_password'], 'a username without a password');
    }

    public function testAProxySwitchedOffAsksNothingAndKeepsNothing(): void
    {
        $values = self::check(['base_url' => 'https://panel.example.com', 'proxy_host' => 'socks5://not checked', 'proxy_port' => 'none'] + self::OPTIONS);

        self::assertFalse($values['proxy_enabled']);
        self::assertSame([], array_values(array_intersect(array_keys($values), ['proxy_host', 'proxy_port', 'proxy_username', 'proxy_password'])), 'its fields are not read: a server keeps them empty');
        self::assertArrayHasKey('proxy_enabled', self::errors(['base_url' => 'https://panel.example.com', 'verify_tls' => true, 'timeout' => 30]), 'the switch is sent, never read as off');
    }

    public function testAProxysPasswordIsKeptOnlyWhileItsHostAndPortStay(): void
    {
        $stored = ['base_url' => 'https://panel.example.com', 'proxy_enabled' => true, 'proxy_host' => 'proxy.example.com', 'proxy_port' => 1080, 'proxy_username' => 'shop', 'proxy_password' => 'kept-pw'];
        $kept = static fn(Field $field): mixed => $stored[$field->key] ?? null;
        $form = ['proxy_password' => ''] + $stored + self::OPTIONS;

        self::assertSame('kept-pw', self::form()->check($form, $kept)['proxy_password'], 'left blank: the stored one');
        self::assertSame('new-pw', self::form()->check(['proxy_password' => 'new-pw'] + $form, $kept)['proxy_password']);
        self::assertSame('', self::form()->check(['clear_proxy_password' => true] + $form, $kept)['proxy_password'], 'cleared');

        foreach (['another host' => ['proxy_host' => 'proxy2.example.com'], 'another port' => ['proxy_port' => 1081]] as $case => $moved) {
            try {
                self::form()->check($moved + $form, $kept);
                self::fail("{$case}: the stored password would go to another proxy");
            } catch (ValidationException $e) {
                self::assertSame(['proxy_password'], array_keys($e->errors()), $case);
                self::assertStringContainsString('دوباره وارد کنید', $e->errors()['proxy_password'][0]);
            }
        }
        self::assertSame('kept-pw', self::form()->check(['proxy_username' => 'other'] + $form, $kept)['proxy_password'], 'another username on the same proxy keeps it');
    }

    public function testTheProxyIsASocks5hAddressWithItsLoginEncoded(): void
    {
        self::assertSame('socks5h://proxy.example.com:1080', PanelConnection::proxy('proxy.example.com', 1080));
        self::assertSame('socks5h://shop@203.0.113.10:9050', PanelConnection::proxy('203.0.113.10', 9050, 'shop'), 'a username without a password');
        self::assertSame('socks5h://shop%40ir:p%40ss%3Aw%2Frd%20x@proxy.example.com:1080', PanelConnection::proxy('proxy.example.com', 1080, 'shop@ir', 'p@ss:w/rd x'));
        self::assertSame('socks5h://proxy.example.com:1080', PanelConnection::proxy('proxy.example.com', 1080, '', 'pw'), 'a password alone is never sent');
        self::assertSame('socks5h://[2001:db8::1]:1080', PanelConnection::proxy('2001:db8::1', 1080), 'an IPv6 host in brackets');
    }

    public function testAStoredServersProxyIsARequestOptionWhileItIsSwitchedOn(): void
    {
        $this->app(); // the encrypter the Server's casts need

        $direct = PanelConnection::of(new Server(['base_url' => 'https://panel.example.com']));
        self::assertNull($direct->proxy);
        self::assertArrayNotHasKey('proxy', $direct->requestOptions(), 'straight to the panel');

        $proxy = ['base_url' => 'https://panel.example.com', 'proxy_enabled' => true, 'proxy_host' => 'proxy.example.com', 'proxy_port' => 1080, 'proxy_username' => 'shop', 'proxy_password' => 'p@ss'];
        $options = PanelConnection::of(new Server($proxy))->requestOptions();
        self::assertSame('socks5h://shop:p%40ss@proxy.example.com:1080', $options['proxy'] ?? null);
        self::assertSame([true, 30.0, 10.0], [$options['verify'], $options['timeout'], $options['connect_timeout']], 'beside the TLS rule and the timeouts');

        self::assertNull(PanelConnection::of(new Server(['proxy_enabled' => false] + $proxy))->proxy, 'switched off');
    }

    /** The connection's fields as a connector's form has them, its pages /panel. */
    private static function form(): Form
    {
        return new Form('panel', [
            PanelConnection::address(hint: '', placeholder: '', pages: '#/panel(/|$)#', pagesRefusal: 'the base, please'),
            ...PanelConnection::options(subscriptionHint: '', subscriptionPlaceholder: ''),
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function check(array $input): array
    {
        return self::form()->check($input, static fn(): mixed => null);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, list<string>>
     */
    private static function errors(array $input): array
    {
        try {
            self::check($input);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }
}
