<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Form;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Support\PanelConnection;
use Tests\TestCase;

/**
 * Where a panel is and how to reach it, whatever the panel — the fields every connector's form takes for it: an http(s)
 * address with a host and nothing secret or stray in it, the connector's own pages kept out of it; a timeout of 5 to
 * 120 seconds; a full address for a subscription prefix, or none —, how a stored server's options read back, and the one
 * test a kept secret must pass to go anywhere: the same scheme, host and port.
 */
final class PanelConnectionTest extends TestCase
{
    private const OPTIONS = ['verify_tls' => true, 'timeout' => 30, 'subscription_url' => ''];

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
