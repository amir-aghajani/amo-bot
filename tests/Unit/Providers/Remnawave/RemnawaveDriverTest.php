<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\Remnawave;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use App\Modules\Providers\Drivers\Remnawave\RemnawaveDriver;
use App\Modules\Providers\Drivers\Remnawave\RemnawaveProvider;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\PanelHttp;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use Tests\Support\FakePanel;
use Tests\TestCase;

/**
 * The Remnawave connector's card and form: the panel's address (its domain, without a page's path), a whole HS256 API
 * token as the one way in, an auth portal's key — optional, kept in `servers.password`, bound to the address like the
 * token —, the shared options; its internal squads sold as inbounds, links it can renew, no IP limit; and the provider it
 * builds on a server's address, token and gate key.
 */
final class RemnawaveDriverTest extends TestCase
{
    /** A made-up API token shaped as Remnawave mints one (Remnawave Settings → API Tokens): an HS256 JWT. */
    private const TOKEN = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1dWlkIjoiNWIwYzdlMmEtOTFkNC00YzNmLThlNmEtMmY0YjlkMWM3YTMwIiwidXNlcm5hbWUiOm51bGwsInJvbGUiOiJBUEkiLCJpYXQiOjE3NjAwMDAwMDAsImV4cCI6MTc5MTUzNjAwMH0.a0AfkgXIXEKU9nW9aNb6MgPc0Eo7xjqCdzBkUlGGLhI';

    /** Another made-up one, of another token. */
    private const OTHER_TOKEN = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1dWlkIjoiYzFkMmUzZjQtYTViNi00YzdkLThlOWYtMGExYjJjM2Q0ZTVmIiwidXNlcm5hbWUiOm51bGwsInJvbGUiOiJBUEkiLCJpYXQiOjE3NjAwMDAwMDAsImV4cCI6MTc5MTUzNjAwMH0.MZ985n4d3z1NRrmtTkueEhptffC6m3FLNRh8PcrEOmM';

    /** A made-up TinyAuth gate key: `Basic` and base64 of a login. */
    private const GATE = 'Basic YW1vYm90OnRlc3QtZ2F0ZQ==';

    /** The connection's options, which every save sends. */
    private const OPTIONS = ['verify_tls' => true, 'timeout' => 30, 'subscription_url' => '', 'proxy_enabled' => false];

    /** Arabic-script diacritics (tanwin, harakat, the hamza above): none in any Persian line (docs/Conventions.md). */
    private const DIACRITICS = '/[\x{064B}-\x{065F}\x{0670}]/u';

    public function testItsCardSaysWhatThePanelNeeds(): void
    {
        $card = (new RemnawaveDriver())->describe()->toArray();
        $notes = static fn(string ...$words): array => array_filter($card['notes'], static fn(string $note): bool => array_filter($words, static fn(string $word): bool => !str_contains($note, $word)) === []);

        self::assertSame(['remnawave', 'Remnawave'], [$card['key'], $card['label']]);
        self::assertStringContainsString('اسکواد', $card['description'], 'its internal squads are what a plan picks');
        self::assertNotEmpty($notes('2.1'), 'the versions it needs');
        self::assertNotEmpty($notes("«\u{2068}Remnawave Settings\u{2069} ← \u{2068}API Tokens\u{2069}»", 'انقضا'), 'where the token is made, and that it expires');
        self::assertNotEmpty($notes('ریورس‌پراکسی', "\u{2068}http\u{2069}", 'شبکه خصوصی'), 'reached through its HTTPS proxy, or its own port over http from its own network alone');
        self::assertNotEmpty($notes("\u{2068}users:*\u{2069}", "\u{2068}internal-squads:read\u{2069}", "\u{2068}system:read\u{2069}", 'همه پنل'), 'the scopes it needs first; * opens the whole panel');
        self::assertNotEmpty($notes('«کلید دروازه»', "\u{2068}TinyAuth\u{2069}"), 'a portal in front');
        self::assertNotEmpty($notes('اسکوادهای داخلی'), 'its squads as the inbounds');
        self::assertNotEmpty($notes('لحظه فروش'), 'no term waiting for the first connection');
        self::assertNotEmpty($notes("\u{2068}HWID\u{2069}", "\u{2068}HWID Device Limit\u{2069}", 'صفر'), 'the devices are its HWID limit, held while the panel\'s HWID limit is on');
        self::assertEquals((object) ['mark' => 'RW', 'vendor' => 'remnawave', 'docs_url' => 'https://github.com/remnawave/panel', 'inbounds' => true, 'link_rotation' => true], $card['traits']);
        self::assertSame(['base_url', 'auth_mode', 'api_token', 'gate_key', 'verify_tls', 'timeout', 'subscription_url', 'proxy_enabled', 'proxy_host', 'proxy_port', 'proxy_username', 'proxy_password'], array_column($card['fields'], 'name'), 'no username or password: the token is the one way in');

        $fields = array_column($card['fields'], null, 'name');
        self::assertSame(['token' => "توکن \u{2068}API\u{2069}"], array_column($fields['auth_mode']['options'], 'label', 'value'), 'one way in, named');
        self::assertSame('https://panel.example.com', $fields['base_url']['placeholder']);
        self::assertSame([true, false, true], [$fields['gate_key']['secret'], $fields['gate_key']['required'], $fields['gate_key']['advanced']], 'an optional secret, under the advanced settings');
        self::assertSame(['base_url', 'proxy_host', 'proxy_port', 'verify_tls'], $fields['gate_key']['bound_to'], 'bound like the token');
        self::assertSame($fields['api_token']['bound_to'], $fields['gate_key']['bound_to']);
        self::assertStringContainsString("\u{2068}X-Api-Key\u{2069}", (string) $fields['gate_key']['hint']);
        $tokenHint = (string) $fields['api_token']['hint'];
        self::assertStringContainsString("\u{2068}users:*\u{2069}، \u{2068}internal-squads:read\u{2069} و \u{2068}system:read\u{2069}", $tokenHint, 'the scopes it needs');
        self::assertLessThan(strpos($tokenHint, 'دسترسی *'), strpos($tokenHint, "\u{2068}users:*\u{2069}"), 'those first; * opens the whole panel');
        self::assertStringContainsString('همه پنل', $tokenHint);
    }

    /** Plain Persian everywhere, and a path in a right-to-left line kept in its isolates, so «/api» never reads «api/». */
    public function testItsWordsHaveNoDiacriticsAndItsPathsAreIsolated(): void
    {
        $words = self::strings((new RemnawaveDriver())->describe()->toArray());
        $words[] = self::errors(['base_url' => 'https://panel.example.com/dashboard', 'auth_mode' => 'token', 'api_token' => self::TOKEN] + self::OPTIONS)['base_url'][0];
        $words[] = self::errors(['base_url' => 'https://panel.example.com', 'auth_mode' => 'token', 'api_token' => 'eyJhbGciOiJIUzI1NiJ9'] + self::OPTIONS)['api_token'][0];
        $words[] = self::errors(['base_url' => 'https://panel.example.com', 'auth_mode' => 'token', 'api_token' => self::TOKEN, 'gate_key' => 'کلید'] + self::OPTIONS)['gate_key'][0];

        foreach ($words as $text) {
            self::assertDoesNotMatchRegularExpression(self::DIACRITICS, $text, $text);
            if (preg_match('/\p{Arabic}/u', $text) === 1) {
                self::assertDoesNotMatchRegularExpression('~(?<!\x{2068})/(dashboard|api|auth)\b~u', $text, "a path outside its isolates: {$text}");
                self::assertDoesNotMatchRegularExpression('~(?<!\x{2068})\b(Remnawave|API Tokens|X-Api-Key|TinyAuth)\b(?!\x{2069})~u', $text, "a Latin name outside its isolates: {$text}");
            }
        }

        $refusal = $words[count($words) - 3];
        self::assertStringContainsString("\u{2068}/dashboard\u{2069}", $refusal, 'the refusal of a page names it isolated');
    }

    public function testItSellsSquadsAsInboundsRenewsLinksAndHoldsDevicesByItsHwidLimit(): void
    {
        $capabilities = (new RemnawaveDriver())->capabilities();

        self::assertSame([true, true, true], [$capabilities->inbounds, $capabilities->linkRotation, $capabilities->ipLimit], 'its HWID limit is the plan\'s devices: extra devices are sold');
    }

    /** Remnawave's own rule (create-user.command.ts): 3 to 36 of `a-zA-Z0-9_-`, matched as written. */
    public function testANameIsThreeTo36LettersDigitsUnderscoresAndDashes(): void
    {
        $names = (new RemnawaveDriver())->capabilities()->names;

        self::assertSame([true, true, true, true], [$names->allows('abc'), $names->allows('Ali_reza-1'), $names->allows('ali__1'), $names->allows(str_repeat('a', 36))]);
        self::assertSame([false, false, false, false, false, false], [$names->allows('ab'), $names->allows(str_repeat('a', 37)), $names->allows('ali reza'), $names->allows('علی_1'), $names->allows('ali.reza'), $names->allows('ali@x')]);
        self::assertSame(['alireza', '007', str_repeat('a', 36)], [$names->fit('ali reza'), $names->fit('7'), $names->fit(str_repeat('a', 40))], 'the shop\'s own names made to fit');
    }

    public function testATokenConnectionIsKeptWithTheOptionsAndTheGateKeyInThePasswordColumn(): void
    {
        $values = self::check(['base_url' => ' https://panel.example.com/ ', 'auth_mode' => 'token', 'api_token' => self::TOKEN, 'gate_key' => ' ' . self::GATE . ' ', 'verify_tls' => false, 'timeout' => '۴۵', 'subscription_url' => 'https://sub.example.com/', 'proxy_enabled' => false]);

        self::assertSame([
            'base_url' => 'https://panel.example.com',
            'auth_mode' => 'token',
            'api_token' => self::TOKEN,
            'password' => self::GATE,
            'meta.verify_tls' => false,
            'meta.timeout' => 45,
            'meta.subscription_url' => 'https://sub.example.com',
            'proxy_enabled' => false,
        ], $values);

        $without = self::check(['base_url' => 'https://panel.example.com', 'auth_mode' => 'token', 'api_token' => self::TOKEN] + self::OPTIONS);
        self::assertSame('', $without['password'] ?? '', 'no gate, no key: a panel without a portal');
    }

    /** The token Remnawave shows once: an HS256 JWT — two base64url JSON parts (`eyJ…`) and a 43-character signature. */
    public function testTheTokenIsAWholeRemnawaveApiToken(): void
    {
        foreach ([self::TOKEN, self::OTHER_TOKEN, 'eyJa.eyJb.' . str_repeat('-', 43)] as $token) {
            self::assertSame($token, self::check(['base_url' => 'https://rw.example', 'auth_mode' => 'token', 'api_token' => $token] + self::OPTIONS)['api_token'], $token);
        }

        [$header, $claims, $signature] = explode('.', self::TOKEN);
        foreach ([
            'cut short' => substr(self::TOKEN, 0, -1),
            'one long' => self::TOKEN . 'A',
            'no signature' => "{$header}.{$claims}",
            'an empty signature' => "{$header}.{$claims}.",
            'padded base64' => "{$header}.{$claims}." . substr($signature, 0, 42) . '=',
            'base64, not url-safe' => "{$header}.{$claims}." . substr($signature, 0, 42) . '+',
            'claims that are no JSON' => "{$header}.QUJD.{$signature}",
            'a Bearer prefix' => 'Bearer ' . self::TOKEN,
            'a Rebecca key' => 'rk_q8Xv2kL0bN7rT4yZ1aS6dF3gH9jK5mP2wE4tY6uI8o_',
            'PasarGuard\'s' => 'pg_key_6f1c2a3b-4d5e-4f60-8a1b-2c3d4e5f6a7b',
            'a Marzban JWT' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJhZG1pbiJ9.c2lnbmF0dXJl',
        ] as $case => $token) {
            $errors = self::errors(['base_url' => 'https://rw.example', 'auth_mode' => 'token', 'api_token' => $token] + self::OPTIONS);
            self::assertSame(['api_token'], array_keys($errors), $case);
            self::assertStringContainsString("\u{2068}eyJ\u{2069}", $errors['api_token'][0], $case);
        }

        self::assertSame(['base_url', 'api_token'], array_keys(self::errors(['base_url' => '', 'auth_mode' => 'token'] + self::OPTIONS)), 'both said at once');
    }

    public function testThereIsNoPasswordWayIn(): void
    {
        self::assertSame(['auth_mode'], array_keys(self::errors(['base_url' => 'https://rw.example', 'auth_mode' => 'password', 'username' => 'admin', 'password' => 'pw'] + self::OPTIONS)));

        $values = self::check(['base_url' => 'https://rw.example', 'auth_mode' => 'token', 'api_token' => self::TOKEN, 'username' => 'admin', 'password' => 'pw'] + self::OPTIONS);
        self::assertArrayNotHasKey('username', $values, 'no field of its own to keep them in');
        self::assertSame('', $values['password'] ?? '', 'an admin\'s password is not taken for the gate key');
    }

    /** A portal's key as it is sent: printable ASCII, words one space apart (TinyAuth's «Basic …»), at most 512. */
    public function testTheGateKeyIsWhatAPortalTakes(): void
    {
        foreach (['k3y-From_Caddy.Portal', self::GATE, str_repeat('a', 512), '!~#$%&*'] as $key) {
            self::assertSame($key, self::check(['base_url' => 'https://rw.example', 'auth_mode' => 'token', 'api_token' => self::TOKEN, 'gate_key' => $key] + self::OPTIONS)['password'], $key);
        }

        foreach ([
            'too long' => str_repeat('a', 513),
            'two spaces' => 'Basic  YW1vYm90',
            'a tab' => "Basic\tYW1vYm90",
            'a line break' => "Basic YW1v\nYm90",
            'Persian' => 'رمزپورتال۱۲',
            'a letter outside ASCII' => 'clé-du-portail',
        ] as $case => $key) {
            $errors = self::errors(['base_url' => 'https://rw.example', 'auth_mode' => 'token', 'api_token' => self::TOKEN, 'gate_key' => $key] + self::OPTIONS);
            self::assertSame(['gate_key'], array_keys($errors), $case);
            self::assertStringContainsString('کلید دروازه', $errors['gate_key'][0], $case);
            self::assertStringNotContainsString($key, $errors['gate_key'][0], 'its refusal repeats none of it');
        }
    }

    public function testTheAddressIsThePanelsOwnWithoutItsPages(): void
    {
        foreach (['https://rw.example/api', 'https://rw.example/api/users', 'https://rw.example/auth/login', 'https://rw.example/dashboard', 'https://rw.example/Dashboard/', 'https://example.com/rw/dashboard', 'https://admin:secret@rw.example', 'rw.example', 'https://rw.example/?x=1'] as $url) {
            self::assertArrayHasKey('base_url', self::errors(['base_url' => $url, 'auth_mode' => 'token', 'api_token' => self::TOKEN] + self::OPTIONS), $url);
        }

        // A reverse proxy may put the whole panel under a path of its own; the backend's own port is reached over http.
        foreach (['https://example.com/rw' => 'https://example.com/rw', 'http://203.0.113.7:3000/' => 'http://203.0.113.7:3000', 'https://rw.example/apis' => 'https://rw.example/apis'] as $url => $kept) {
            self::assertSame($kept, self::check(['base_url' => $url, 'auth_mode' => 'token', 'api_token' => self::TOKEN] + self::OPTIONS)['base_url'], $url);
        }
    }

    /** The token and the gate key are kept while the address stays on its host — and both asked again once it moves. */
    public function testEditingKeepsTheStoredSecretsOnlyWhileTheAddressStaysOnItsHost(): void
    {
        $kept = self::kept(['base_url' => 'https://rw.example', 'auth_mode' => 'token', 'api_token' => self::TOKEN, 'password' => self::GATE]);

        $values = self::check(['base_url' => 'https://RW.example/panel', 'auth_mode' => 'token'] + self::OPTIONS, $kept);
        self::assertSame([self::TOKEN, self::GATE], [$values['api_token'], $values['password']], 'blank keeps the stored ones; another path on the host is the same panel');

        foreach (['https://evil.example', 'https://rw.example:8443', 'http://rw.example'] as $url) {
            $errors = self::errors(['base_url' => $url, 'auth_mode' => 'token'] + self::OPTIONS, $kept);
            self::assertSame(['api_token', 'gate_key'], array_keys($errors), $url);
            self::assertSame(PanelConnection::MOVED . "توکن \u{2068}API\u{2069} را دوباره وارد کنید.", $errors['api_token'][0]);
            self::assertSame(PanelConnection::MOVED . 'کلید دروازه را دوباره وارد کنید.', $errors['gate_key'][0]);
        }

        // A new token goes anywhere; a gate key left behind on the old host is cleared rather than sent along.
        $values = self::check(['base_url' => 'https://evil.example', 'auth_mode' => 'token', 'api_token' => self::OTHER_TOKEN, 'clear_gate_key' => true] + self::OPTIONS, $kept);
        self::assertSame([self::OTHER_TOKEN, ''], [$values['api_token'], $values['password']]);

        // Cleared while the address stays: a panel whose portal was taken away.
        self::assertSame('', self::check(['base_url' => 'https://rw.example', 'auth_mode' => 'token', 'clear_gate_key' => '1'] + self::OPTIONS, $kept)['password']);
    }

    /** connect(): a RemnawaveProvider on the server's address — a reverse proxy's path kept —, token, gate key and transfer options. */
    public function testConnectBuildsTheProviderOnTheServersAddressTokenGateKeyAndOptions(): void
    {
        $this->app(); // registers the encrypter the Server casts need
        $panel = new FakePanel();
        $panel->raw(new Response(200, ['Content-Type' => 'application/json'], '{"response":{"users":[],"total":0}}'));
        $server = new Server(['name' => 'RW', 'driver' => 'remnawave', 'base_url' => 'https://example.com/rw', 'api_token' => self::TOKEN, 'password' => self::GATE, 'meta' => ['verify_tls' => false, 'timeout' => 9]]);

        $provider = (new RemnawaveDriver())->connect($server, new PanelHttp($panel->client(), new NullLogger()));
        $provider->testConnection();

        self::assertInstanceOf(RemnawaveProvider::class, $provider);
        self::assertSame(['GET /rw/api/users'], $panel->calls());
        self::assertSame('start=0&size=1', $panel->request(0)->getUri()->getQuery(), 'one user read: the token and its users scope');
        self::assertSame('example.com', $panel->request(0)->getUri()->getHost());
        self::assertSame('Bearer ' . self::TOKEN, $panel->request(0)->getHeaderLine('Authorization'));
        self::assertSame(self::GATE, $panel->request(0)->getHeaderLine('X-Api-Key'));
        self::assertFalse($panel->request(0)->hasHeader('X-Forwarded-For'), 'over https its proxy says so');
        self::assertSame([false, 9.0], [$panel->options(0)['verify'], $panel->options(0)['timeout']]);

        // The backend's own port over http on its private network: what the proxy would have added, and no gate key for a
        // server without one; across the internet, nothing of the kind.
        foreach (['http://10.0.0.5:3000' => ['127.0.0.1', 'https'], 'http://203.0.113.7:3000' => ['', '']] as $address => $forwarded) {
            $panel = new FakePanel();
            $panel->raw(new Response(200, ['Content-Type' => 'application/json'], '{"response":{"users":[],"total":0}}'));
            $server = new Server(['name' => 'RW', 'driver' => 'remnawave', 'base_url' => $address, 'api_token' => self::TOKEN, 'meta' => []]);
            (new RemnawaveDriver())->connect($server, new PanelHttp($panel->client(), new NullLogger()))->testConnection();

            self::assertSame([...$forwarded, false], [$panel->request(0)->getHeaderLine('X-Forwarded-For'), $panel->request(0)->getHeaderLine('X-Forwarded-Proto'), $panel->request(0)->hasHeader('X-Api-Key')], $address);
        }
    }

    /**
     * Every string a card holds, at any depth.
     *
     * @param array<mixed> $value
     * @return list<string>
     */
    private static function strings(array $value): array
    {
        $strings = [];
        array_walk_recursive($value, static function (mixed $item) use (&$strings): void {
            if (is_string($item)) {
                $strings[] = $item;
            }
        });

        return $strings;
    }

    /**
     * What a server keeps, by the form's keys.
     *
     * @param array<string, mixed> $stored
     * @return \Closure(Field<mixed>): mixed
     */
    private static function kept(array $stored): \Closure
    {
        return static fn(Field $field): mixed => $stored[$field->key] ?? null;
    }

    /**
     * The connector's form, checked: the values to keep by key.
     *
     * @param array<string, mixed> $input
     * @param (\Closure(Field<mixed>): mixed)|null $kept
     * @return array<string, mixed>
     */
    private static function check(array $input, ?\Closure $kept = null): array
    {
        return (new RemnawaveDriver())->describe()->form->check($input, $kept ?? static fn(): mixed => null);
    }

    /**
     * @param array<string, mixed> $input
     * @param (\Closure(Field<mixed>): mixed)|null $kept
     * @return array<string, list<string>>
     */
    private static function errors(array $input, ?\Closure $kept = null): array
    {
        try {
            self::check($input, $kept);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        self::fail('expected a ValidationException');
    }
}
