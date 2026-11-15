<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\Rebecca;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use App\Modules\Providers\Drivers\Rebecca\RebeccaDriver;
use App\Modules\Providers\Drivers\Rebecca\RebeccaProvider;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\PanelHttp;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use Tests\Support\FakePanel;
use Tests\TestCase;

/**
 * The Rebecca connector's card and form: the panel's address (the dashboard's, without /dashboard), a whole `rk_…` API key
 * as the one way in, the shared options; its services sold as inbounds, links it can renew, the device limit its nodes
 * hold; and the provider it builds on a server's address and key.
 */
final class RebeccaDriverTest extends TestCase
{
    /** `rk_` and 43 base64url characters, as Rebecca mints an API key (internal/app/admin, «حساب من ← کلیدهای API»). */
    private const KEY = 'rk_q8Xv2kL0bN7rT4yZ1aS6dF3gH9jK5mP2wE4tY6uI8o_';

    /** The connection's options, which every save sends. */
    private const OPTIONS = ['verify_tls' => true, 'timeout' => 30, 'subscription_url' => '', 'proxy_enabled' => false];

    /** Arabic-script diacritics (tanwin, harakat, the hamza above): none in any Persian line (docs/Conventions.md). */
    private const DIACRITICS = '/[\x{064B}-\x{065F}\x{0670}]/u';

    public function testItsCardSaysWhatThePanelNeeds(): void
    {
        $card = (new RebeccaDriver())->describe()->toArray();

        self::assertSame(['rebecca', 'Rebecca'], [$card['key'], $card['label']]);
        self::assertStringContainsString('سرویس', $card['description'], 'its services are what a plan picks');
        self::assertNotEmpty(array_filter($card['notes'], static fn(string $note): bool => str_contains($note, '1.0')), 'the versions it needs');
        self::assertNotEmpty(array_filter($card['notes'], static fn(string $note): bool => str_contains($note, '«حساب من ← کلیدهای API»')), 'where the key is made');
        self::assertNotEmpty(array_filter($card['notes'], static fn(string $note): bool => str_contains($note, 'full access') && str_contains($note, 'محدودیت IP')), 'what another admin must be allowed');
        self::assertNotEmpty(array_filter($card['notes'], static fn(string $note): bool => str_contains($note, 'یک سرویس')), 'one service a plan');
        self::assertNotEmpty(array_filter($card['notes'], static fn(string $note): bool => str_contains($note, 'VLESS') && str_contains($note, 'VMess')), 'the configs a link claim reads');
        self::assertEquals((object) ['mark' => 'RB', 'vendor' => 'rebeccapanel', 'docs_url' => 'https://github.com/rebeccapanel/Rebecca', 'inbounds' => true, 'link_rotation' => true], $card['traits']);
        self::assertSame(['base_url', 'auth_mode', 'api_token', 'verify_tls', 'timeout', 'subscription_url', 'proxy_enabled', 'proxy_host', 'proxy_port', 'proxy_username', 'proxy_password'], array_column($card['fields'], 'name'), 'no username or password: bots come in with a key');
        self::assertSame(['token' => 'کلید API'], array_column(array_column($card['fields'], null, 'name')['auth_mode']['options'], 'label', 'value'), 'one way in, named');
        self::assertSame('https://panel.example.com:8000', array_column($card['fields'], null, 'name')['base_url']['placeholder']);
    }

    /** Plain Persian everywhere, and a path in a right-to-left line kept in its isolates, so «/dashboard» never reads «dashboard/». */
    public function testItsWordsHaveNoDiacriticsAndItsPathsAreIsolated(): void
    {
        $words = self::strings((new RebeccaDriver())->describe()->toArray());
        $words[] = self::errors(['base_url' => 'https://panel.example.com/dashboard', 'auth_mode' => 'token', 'api_token' => self::KEY] + self::OPTIONS)['base_url'][0];
        $words[] = self::errors(['base_url' => 'https://panel.example.com', 'auth_mode' => 'token', 'api_token' => 'rk_short'] + self::OPTIONS)['api_token'][0];

        foreach ($words as $text) {
            self::assertDoesNotMatchRegularExpression(self::DIACRITICS, $text, $text);
            if (preg_match('/\p{Arabic}/u', $text) === 1) {
                self::assertDoesNotMatchRegularExpression('~(?<!\x{2068})/(dashboard|api|docs)\b~u', $text, "a path outside its isolates: {$text}");
            }
        }

        $fields = array_column((new RebeccaDriver())->describe()->toArray()['fields'], null, 'name');
        self::assertStringContainsString("\u{2068}/dashboard\u{2069}", (string) $fields['base_url']['hint']);
        self::assertStringContainsString("\u{2068}/dashboard\u{2069}", $words[count($words) - 2], 'the refusal of a page names it isolated');
    }

    public function testItSellsServicesAsInboundsRenewsLinksAndHoldsADeviceLimit(): void
    {
        $capabilities = (new RebeccaDriver())->capabilities();

        self::assertSame([true, true, true], [$capabilities->inbounds, $capabilities->linkRotation, $capabilities->ipLimit]);
    }

    /** Rebecca's own rule (internal/app/user/validation.go): 3 to 32 of `a-zA-Z0-9._@-`, separators side by side allowed. */
    public function testANameIsThreeTo32LettersDigitsAndTheSeparatorsThePanelTakes(): void
    {
        $names = (new RebeccaDriver())->capabilities()->names;

        self::assertSame([true, true, true, true], [$names->allows('abc'), $names->allows('Ali.reza@x-1_2'), $names->allows('ali__1'), $names->allows(str_repeat('a', 32))]);
        self::assertSame([false, false, false, false, false], [$names->allows('ab'), $names->allows(str_repeat('a', 33)), $names->allows('ali reza'), $names->allows('علی_1'), $names->allows('ali+1')]);
        self::assertSame(['alireza', '007', str_repeat('a', 32)], [$names->fit('ali reza'), $names->fit('7'), $names->fit(str_repeat('a', 40))], 'the shop\'s own names made to fit');
    }

    public function testAnApiKeyConnectionIsKeptWithTheOptions(): void
    {
        $values = self::check(['base_url' => ' https://panel.example.com:8000/ ', 'auth_mode' => 'token', 'api_token' => self::KEY, 'verify_tls' => false, 'timeout' => '۴۵', 'subscription_url' => 'https://sub.example.com/sub/', 'proxy_enabled' => false]);

        self::assertSame([
            'base_url' => 'https://panel.example.com:8000',
            'auth_mode' => 'token',
            'api_token' => self::KEY,
            'meta.verify_tls' => false,
            'meta.timeout' => 45,
            'meta.subscription_url' => 'https://sub.example.com/sub',
            'proxy_enabled' => false,
        ], $values);
    }

    /** The key Rebecca shows once: `rk_` and 32 random bytes in base64url — anything shorter, longer or of another panel is no key. */
    public function testTheKeyIsAWholeRebeccaKey(): void
    {
        foreach (['rk_' . str_repeat('A', 43), 'rk_' . str_repeat('-', 43), self::KEY] as $key) {
            self::assertSame($key, self::check(['base_url' => 'https://rb.example', 'auth_mode' => 'token', 'api_token' => $key] + self::OPTIONS)['api_token'], $key);
        }

        foreach ([
            'cut short' => 'rk_q8Xv2kL0bN7rT4yZ',
            'one short' => 'rk_' . str_repeat('A', 42),
            'one long' => 'rk_' . str_repeat('A', 44),
            'base64 with padding' => 'rk_' . str_repeat('A', 42) . '=',
            'base64, not url-safe' => 'rk_' . str_repeat('A', 42) . '+',
            'no prefix' => str_repeat('A', 46),
            'HMPanel\'s' => 'hmp_q8Xv2kL0bN7rT4yZ1aS6dF3gH9jK5mP2',
            'PasarGuard\'s' => 'pg_key_6f1c2a3b-4d5e-4f60-8a1b-2c3d4e5f6a7b',
            'a Marzban JWT' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJhZG1pbiJ9.c2lnbmF0dXJl',
        ] as $case => $key) {
            $errors = self::errors(['base_url' => 'https://rb.example', 'auth_mode' => 'token', 'api_token' => $key] + self::OPTIONS);
            self::assertSame(['api_token'], array_keys($errors), $case);
            self::assertStringContainsString('rk_', $errors['api_token'][0], $case);
        }

        self::assertSame(['base_url', 'api_token'], array_keys(self::errors(['base_url' => '', 'auth_mode' => 'token'] + self::OPTIONS)), 'both said at once');
    }

    public function testThereIsNoPasswordWayIn(): void
    {
        self::assertSame(['auth_mode'], array_keys(self::errors(['base_url' => 'https://rb.example', 'auth_mode' => 'password', 'username' => 'admin', 'password' => 'pw'] + self::OPTIONS)));

        $values = self::check(['base_url' => 'https://rb.example', 'auth_mode' => 'token', 'api_token' => self::KEY, 'username' => 'admin', 'password' => 'pw'] + self::OPTIONS);
        self::assertArrayNotHasKey('username', $values, 'no field of its own to keep them in');
        self::assertArrayNotHasKey('password', $values);
    }

    public function testTheAddressIsThePanelsOwnWithoutItsDashboardApiOrDocs(): void
    {
        foreach (['https://rb.example/dashboard', 'https://rb.example/dashboard/', 'https://rb.example/Dashboard/#/users', 'https://rb.example/api', 'https://rb.example/api/v2/services', 'https://rb.example/docs', 'https://rb.example/docs/', 'https://rb.example:8000/panel/dashboard', 'https://admin:secret@rb.example', 'rb.example'] as $url) {
            self::assertArrayHasKey('base_url', self::errors(['base_url' => $url, 'auth_mode' => 'token', 'api_token' => self::KEY] + self::OPTIONS), $url);
        }

        // A reverse proxy may put the whole panel under a path of its own.
        foreach (['https://example.com/rb' => 'https://example.com/rb', 'http://1.2.3.4:8000/' => 'http://1.2.3.4:8000', 'https://rb.example/dashboards' => 'https://rb.example/dashboards'] as $url => $kept) {
            self::assertSame($kept, self::check(['base_url' => $url, 'auth_mode' => 'token', 'api_token' => self::KEY] + self::OPTIONS)['base_url'], $url);
        }
    }

    public function testEditingKeepsTheStoredKeyOnlyWhileTheAddressStaysOnItsHost(): void
    {
        $keyed = self::kept(['base_url' => 'https://rb.example:8000', 'auth_mode' => 'token', 'api_token' => self::KEY]);

        self::assertSame(self::KEY, self::check(['base_url' => 'https://rb.example:8000', 'auth_mode' => 'token'] + self::OPTIONS, $keyed)['api_token'], 'a blank key keeps the stored one');
        self::assertSame(self::KEY, self::check(['base_url' => 'https://RB.example:8000/rb', 'auth_mode' => 'token'] + self::OPTIONS, $keyed)['api_token'], 'the same host under another path is still the panel');

        foreach (['https://evil.example:8000', 'https://rb.example:8443', 'http://rb.example:8000'] as $url) {
            $errors = self::errors(['base_url' => $url, 'auth_mode' => 'token'] + self::OPTIONS, $keyed);
            self::assertSame(['api_token'], array_keys($errors), $url);
            self::assertSame(PanelConnection::MOVED . 'کلید API را دوباره وارد کنید.', $errors['api_token'][0]);
        }

        $other = 'rk_' . str_repeat('B', 43);
        self::assertSame($other, self::check(['base_url' => 'https://evil.example', 'auth_mode' => 'token', 'api_token' => $other] + self::OPTIONS, $keyed)['api_token'], 'a new key goes anywhere');
    }

    /** connect(): a RebeccaProvider on the server's address — a reverse proxy's path kept —, key and transfer options. */
    public function testConnectBuildsTheProviderOnTheServersAddressKeyAndOptions(): void
    {
        $this->app(); // registers the encrypter the Server casts need
        $panel = new FakePanel();
        $panel->raw(new Response(200, ['Content-Type' => 'application/json'], '{"users":[],"link_templates":{},"total":0,"status_breakdown":{}}'));
        $server = new Server(['name' => 'RB', 'driver' => 'rebecca', 'base_url' => 'https://example.com/rb', 'api_token' => self::KEY, 'meta' => ['verify_tls' => false, 'timeout' => 9]]);

        $provider = (new RebeccaDriver())->connect($server, new PanelHttp($panel->client(), new NullLogger()));
        $provider->testConnection();

        self::assertInstanceOf(RebeccaProvider::class, $provider);
        self::assertSame(['GET /rb/api/users'], $panel->calls());
        self::assertSame('example.com', $panel->request(0)->getUri()->getHost());
        self::assertSame('Bearer ' . self::KEY, $panel->request(0)->getHeaderLine('Authorization'));
        self::assertSame([false, 9.0], [$panel->options(0)['verify'], $panel->options(0)['timeout']]);
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
        return (new RebeccaDriver())->describe()->form->check($input, $kept ?? static fn(): mixed => null);
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
