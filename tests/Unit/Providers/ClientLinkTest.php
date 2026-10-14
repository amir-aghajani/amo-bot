<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Modules\Providers\DTO\ClientLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a customer's links say about whose service it is: a subscription link's last two path parts (the last first), a
 * config's credentials — vless, vmess, trojan, Shadowsocks in its three forms (a 2022 key whole and its user's half),
 * hysteria2, tuic —, every link of a message read together, and nothing too short or too long to be a panel's.
 */
final class ClientLinkTest extends TestCase
{
    private const UUID = 'B1C0E3F4-1234-4ABC-8DEF-0123456789AB';
    private const ID = 'b1c0e3f4-1234-4abc-8def-0123456789ab';

    /** A Shadowsocks 2022 server key and a user's key, as 3x-ui makes them. */
    private const SERVER_KEY = 'Kq3XJ8vR2mN9pL4wT6yB1A==';
    private const USER_KEY = 'Zx5Yc7Vb9Nm1Qw3Er5Ty7U==';

    /** @return array<string, array{string, list<string>}> */
    public static function configs(): array
    {
        $vmess = (string) json_encode(['v' => '2', 'ps' => 'Ali 🇩🇪', 'add' => 'de.example.com', 'port' => '443', 'id' => self::UUID, 'aid' => '0', 'net' => 'ws', 'tls' => 'tls']);
        $sip002 = rtrim(strtr(base64_encode('chacha20-ietf-poly1305:Sh4dow-Pass'), '+/', '-_'), '=');

        return [
            'vless, its id in lower case' => ['vless://' . self::UUID . '@1.2.3.4:443?type=tcp&security=reality&pbk=abc#Ali%20%F0%9F%87%A9%F0%9F%87%AA', [self::ID]],
            'a scheme in capitals' => ['VLESS://' . self::UUID . '@1.2.3.4:443', [self::ID]],
            'trojan, its password url-decoded' => ['trojan://p%40ss-w0rd-long@de.example.com:443?sni=de.example.com#Ali', ['p@ss-w0rd-long']],
            'trojan, an @ in the password before the host\'s' => ['trojan://p@ssword-long@de.example.com:443', ['p@ssword-long']],
            'hysteria2' => ['hysteria2://Auth-Secret-123@de.example.com:443/?sni=de.example.com&insecure=0#Ali', ['Auth-Secret-123']],
            'hy2, hysteria2\'s short scheme' => ['hy2://Auth-Secret-123@de.example.com:443?obfs=none', ['Auth-Secret-123']],
            'hysteria2, a user and password whole and apart' => ['hysteria2://user-name1:pass-word-1@de.example.com:443', ['user-name1:pass-word-1', 'user-name1', 'pass-word-1']],
            'tuic, its id and password whole and apart' => ['tuic://' . self::ID . ':pass-word-1@de.example.com:443?congestion_control=bbr#Ali', [self::ID . ':pass-word-1', self::ID, 'pass-word-1']],
            'vmess, base64 of its JSON' => ['vmess://' . base64_encode($vmess), [self::ID]],
            'vmess, url-safe base64 without padding' => ['vmess://' . rtrim(strtr(base64_encode($vmess), '+/', '-_'), '='), [self::ID]],
            'vmess, an id before the host' => ['vmess://' . self::UUID . '@de.example.com:443?type=ws#Ali', [self::ID]],
            'Shadowsocks SIP002: base64 of method:password before the host' => ["ss://{$sip002}@de.example.com:8388#Ali", ['Sh4dow-Pass']],
            'Shadowsocks SIP002 with a plugin' => ["ss://{$sip002}@de.example.com:8388/?plugin=obfs-local%3Bobfs%3Dhttp#Ali", ['Sh4dow-Pass']],
            'Shadowsocks 2022, method:password percent-encoded: whole and the user\'s half' => [
                'ss://2022-blake3-aes-128-gcm%3A' . rawurlencode(self::SERVER_KEY) . '%3A' . rawurlencode(self::USER_KEY) . '@de.example.com:443#Ali',
                [self::SERVER_KEY . ':' . self::USER_KEY, self::USER_KEY],
            ],
            'Shadowsocks 2022 in base64: whole and the user\'s half' => [
                'ss://' . base64_encode('2022-blake3-aes-128-gcm:' . self::SERVER_KEY . ':' . self::USER_KEY) . '@de.example.com:443#Ali',
                [self::SERVER_KEY . ':' . self::USER_KEY, self::USER_KEY],
            ],
            'Shadowsocks, the old all-base64 form' => ['ss://' . base64_encode('aes-256-gcm:Legacy-Pass-1@de.example.com:8388') . '#Ali', ['Legacy-Pass-1']],
            'a value given twice is kept once' => ['tuic://pass-word-1:pass-word-1@de.example.com:443', ['pass-word-1:pass-word-1', 'pass-word-1']],
        ];
    }

    /** @param list<string> $credentials */
    #[DataProvider('configs')]
    public function testAConfigCarriesItsCredentials(string $config, array $credentials): void
    {
        $link = ClientLink::find($config);

        self::assertNotNull($link);
        self::assertSame($credentials, $link->credentials);
        self::assertSame([], $link->tokens, 'a config is no subscription link');
    }

    public function testASubscriptionLinkCarriesItsLastTwoPathPartsTheLastFirst(): void
    {
        $link = ClientLink::find('https://sub.example.com:2096/sub/Ab%2BCd%3D%3Dlong/v2ray-json/?format=json#top');

        self::assertNotNull($link);
        self::assertSame(['v2ray-json', 'Ab+Cd==long'], $link->tokens, 'url-decoded, the last first: a format after the token');
        self::assertSame([], $link->credentials);

        self::assertSame(['AbCdEf123456'], ClientLink::find('http://1.2.3.4:2096/sub/AbCdEf123456')?->tokens, 'plain http too; "sub" is too short to be anyone\'s');
        self::assertSame(['AbCdEf123456'], ClientLink::find('https://de.example.com/sub/AbCdEf123456/clash')?->tokens, 'a short format goes, the token stays');
    }

    public function testALongLinkGivesTwoTokensAtMost(): void
    {
        $link = ClientLink::find('https://sub.example.com/aaaaaaaaaa1/bbbbbbbbbb2/cccccccccc3/dddddddddd4/eeeeeeeeee5');

        self::assertSame(['eeeeeeeeee5', 'dddddddddd4'], $link?->tokens, 'the parts before them are never looked for');

        $two = ClientLink::find("https://a.example.com/aaaaaaaaaa1/bbbbbbbbbb2/cccccccccc3\nhttps://b.example.com/dddddddddd4/eeeeeeeeee5/ffffffffff6");
        self::assertSame(['cccccccccc3', 'bbbbbbbbbb2', 'ffffffffff6', 'eeeeeeeeee5'], $two?->tokens, 'two of each link');
    }

    public function testPunctuationASentencePutAfterTheLinkIsNotPartOfIt(): void
    {
        foreach ([
            'a full stop' => 'سرویس من این است: https://de.example.com/sub/AbCdEf123456.',
            'a comma and more' => 'https://de.example.com/sub/AbCdEf123456, ممنون',
            'brackets' => '(https://de.example.com/sub/AbCdEf123456)',
            'guillemets' => '«https://de.example.com/sub/AbCdEf123456»',
            'a question' => 'این درست است؟ https://de.example.com/sub/AbCdEf123456?!',
        ] as $case => $text) {
            self::assertSame(['AbCdEf123456'], ClientLink::find($text)?->tokens, $case);
        }

        self::assertSame([self::ID], ClientLink::find('کانفیگم: vless://' . self::UUID . '@1.2.3.4:443;')?->credentials, 'after a config too');
    }

    public function testEveryLinkOfTheMessageIsReadTogether(): void
    {
        $link = ClientLink::find("از کانال https://t.me/amo_shop_channel گرفتم:\nvless://" . self::UUID . "@1.2.3.4:443\ntrojan://another-password@1.2.3.4:443");

        self::assertNotNull($link);
        self::assertSame(['amo_shop_channel'], $link->tokens, 'an unrelated address is read too…');
        self::assertSame([self::ID, 'another-password'], $link->credentials, '…and never hides the configs after it');

        $many = implode("\n", array_map(static fn(int $i): string => "trojan://password-number-{$i}@1.2.3.4:443", range(1, 6)));
        $read = ClientLink::find($many);
        self::assertNotNull($read);
        self::assertCount(5, $read->credentials, 'five links of one message at most');
    }

    public function testPersianWordsAndPunctuationAfterALinkAreNotPartOfIt(): void
    {
        foreach ([
            'a Persian comma' => 'https://de.example.com/sub/AbCdEf123456، ممنون',
            'a Persian semicolon' => 'https://de.example.com/sub/AbCdEf123456؛',
            'a Persian question mark' => 'درسته https://de.example.com/sub/AbCdEf123456؟',
            'a word written on' => 'https://de.example.com/sub/AbCdEf123456لطفا اضافه کنید',
        ] as $case => $text) {
            self::assertSame(['AbCdEf123456'], ClientLink::find($text)?->tokens, $case);
        }

        self::assertSame([self::ID], ClientLink::find('vless://' . self::UUID . '@1.2.3.4:443?type=tcp#🇩🇪 آلمان، سرور یک')?->credentials, 'a config named in Persian');
    }

    public function testValuesTooShortOrTooLongForAPanelAreDropped(): void
    {
        self::assertNull(ClientLink::find('trojan://short12@de.example.com:443'), 'seven characters');
        self::assertSame(['12345678'], ClientLink::find('trojan://12345678@de.example.com:443')?->credentials, 'eight');
        self::assertNull(ClientLink::find('https://de.example.com/sub/abc'), 'a path of short parts');

        $longest = str_repeat('a', 512);
        self::assertSame([$longest], ClientLink::find("trojan://{$longest}@de.example.com:443")?->credentials, '512');
        self::assertNull(ClientLink::find('trojan://' . $longest . 'a@de.example.com:443'), '513');

        self::assertSame(['short:pass-word-1', 'pass-word-1'], ClientLink::find('tuic://short:pass-word-1@de.example.com:443')?->credentials, 'a short piece goes, the rest stays');
    }

    public function testNothingUsableIsNoLink(): void
    {
        foreach ([
            'words' => 'سلام، سرویسم وصل نمی‌شود',
            'nothing' => '',
            'another scheme' => 'ftp://files.example.com/AbCdEf123456',
            'a site\'s address alone' => 'https://example.com',
            'a config without credentials' => 'vless://de.example.com:443',
            'vmess that is no base64 JSON' => 'vmess://not-base64-at-all!',
            'Shadowsocks without a password' => 'ss://' . base64_encode('no-colon-here') . '@de.example.com:8388',
            'a word ending in a scheme' => 'class://AbCdEf123456@de.example.com',
        ] as $case => $text) {
            self::assertNull(ClientLink::find($text), $case);
        }
    }

    public function testAnIdMatchesInEitherCaseAndAnythingElseExactly(): void
    {
        $config = ClientLink::find('vless://' . self::UUID . '@1.2.3.4:443') ?? self::fail('a link');
        self::assertTrue($config->hasCredential(self::UUID));
        self::assertTrue($config->hasCredential('', 'another', self::ID), 'any of them; empty ones skipped');
        self::assertFalse($config->hasCredential('', ''));
        self::assertFalse($config->hasToken(self::ID), 'a config has no token');

        $subscription = ClientLink::find('https://de.example.com/sub/AbCdEf123456') ?? self::fail('a link');
        self::assertTrue($subscription->hasToken('AbCdEf123456'));
        self::assertFalse($subscription->hasToken('abcdef123456'), 'a subscription id is no uuid: its case counts');
        self::assertFalse($subscription->hasToken(''));
        self::assertFalse($subscription->hasCredential('AbCdEf123456'), 'a subscription link carries no credential');

        $byId = ClientLink::find('https://de.example.com/sub/' . self::UUID) ?? self::fail('a link');
        self::assertSame([self::ID], $byId->tokens);
        self::assertTrue($byId->hasToken(self::UUID), 'a uuid token in either case');
    }
}
