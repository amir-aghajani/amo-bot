<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\HmPanel;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use App\Modules\Providers\Drivers\HmPanel\HmPanelDriver;
use App\Modules\Providers\Support\PanelConnection;
use Tests\TestCase;

/**
 * The HMPanel connector's card and form: the panel's root address, a whole `hmp_…` API key as the one way in, the shared
 * options; every refusal at once; and the stored key kept while left blank, as long as the address stays on its host
 * and the way there stays the same.
 */
final class HmPanelDriverTest extends TestCase
{
    private const KEY = 'hmp_q8Xv2kL0bN7rT4yZ1aS6dF3gH9jK5mP2';

    /** The connection's options, which every save sends. */
    private const OPTIONS = ['verify_tls' => true, 'timeout' => 30, 'subscription_url' => '', 'proxy_enabled' => false];

    public function testItsCardSaysWhatThePanelNeeds(): void
    {
        $card = (new HmPanelDriver())->describe()->toArray();

        self::assertSame(['hmpanel', 'HMPanel (HMray)'], [$card['key'], $card['label']]);
        self::assertNotEmpty(array_filter($card['notes'], static fn(string $note): bool => str_contains($note, '2.5')), 'the versions it needs');
        self::assertNotEmpty(array_filter($card['notes'], static fn(string $note): bool => str_contains($note, '«تنظیمات ← API»') && str_contains($note, '*')), 'where the key is made, and with which scopes');
        self::assertNotEmpty(array_filter($card['notes'], static fn(string $note): bool => str_contains($note, '3X-UI')), 'only the 3x-ui panels beneath it');
        self::assertEquals((object) ['mark' => 'HM', 'vendor' => 'neoauroraproject', 'docs_url' => 'https://github.com/neoauroraproject/hmpanel/blob/main/docs/admin-api.md', 'inbounds' => true, 'link_rotation' => false], $card['traits']);
        self::assertSame(['base_url', 'auth_mode', 'api_token', 'verify_tls', 'timeout', 'subscription_url', 'proxy_enabled', 'proxy_host', 'proxy_port', 'proxy_username', 'proxy_password'], array_column($card['fields'], 'name'), 'no username or password: its API takes its key alone');
        self::assertSame(['token' => 'کلید API'], array_column(array_column($card['fields'], null, 'name')['auth_mode']['options'], 'label', 'value'), 'one way in, named');
    }

    public function testItSellsInboundsWithAnIpLimitButRenewsNoLink(): void
    {
        $capabilities = (new HmPanelDriver())->capabilities();

        self::assertSame([true, true, false], [$capabilities->inbounds, $capabilities->ipLimit, $capabilities->linkRotation]);
    }

    public function testANameIsTwoTo64LettersDigitsAndTheSeparatorsThePanelTakes(): void
    {
        $names = (new HmPanelDriver())->capabilities()->names;

        self::assertSame([true, true, true, true], [$names->allows('ab'), $names->allows('ali.reza+1@x-1_2'), $names->allows('ali__1'), $names->allows(str_repeat('a', 64))]);
        self::assertSame([false, false, false, false], [$names->allows('a'), $names->allows(str_repeat('a', 65)), $names->allows('ali reza'), $names->allows('علی_1')]);
        self::assertSame(['alireza', '07', 'ali__1'], [$names->fit('ali reza'), $names->fit('7'), $names->fit('ali__1')], 'the shop\'s own names made to fit: two separators may stand side by side');
    }

    public function testAnApiKeyConnectionIsKeptWithTheOptions(): void
    {
        $values = self::check(['base_url' => ' https://panel.example.com/ ', 'auth_mode' => 'token', 'api_token' => self::KEY, 'verify_tls' => false, 'timeout' => '۴۵', 'subscription_url' => 'https://sub.example.com/s/', 'proxy_enabled' => false]);

        self::assertSame([
            'base_url' => 'https://panel.example.com',
            'auth_mode' => 'token',
            'api_token' => self::KEY,
            'meta.verify_tls' => false,
            'meta.timeout' => 45,
            'meta.subscription_url' => 'https://sub.example.com/s',
            'proxy_enabled' => false,
        ], $values);
    }

    public function testEveryRefusedFieldIsReportedAtOnce(): void
    {
        $errors = self::errors(['base_url' => 'panel.example.com', 'auth_mode' => 'token', 'api_token' => 'hmp_q8Xv2kL0', 'verify_tls' => true, 'timeout' => 1, 'subscription_url' => 'sub.example.com', 'proxy_enabled' => false]);

        self::assertEqualsCanonicalizing(['base_url', 'api_token', 'timeout', 'subscription_url'], array_keys($errors));
        self::assertStringContainsString('hmp_', $errors['api_token'][0], 'the cut-short prefix the list shows is not the key');

        self::assertSame(['base_url', 'api_token'], array_keys(self::errors(['base_url' => '', 'auth_mode' => 'token'] + self::OPTIONS)));
        self::assertSame(['api_token'], array_keys(self::errors(['base_url' => 'https://hm.example', 'auth_mode' => 'token', 'api_token' => 'pg_key_6f1c2a3b-4d5e-4f60-8a1b-2c3d4e5f6a7b'] + self::OPTIONS)), 'another panel\'s key');
    }

    public function testThereIsNoPasswordWayIn(): void
    {
        $errors = self::errors(['base_url' => 'https://hm.example', 'auth_mode' => 'password', 'username' => 'admin', 'password' => 'pw'] + self::OPTIONS);

        self::assertSame(['auth_mode'], array_keys($errors));

        $values = self::check(['base_url' => 'https://hm.example', 'auth_mode' => 'token', 'api_token' => self::KEY, 'username' => 'admin', 'password' => 'pw'] + self::OPTIONS);
        self::assertArrayNotHasKey('username', $values, 'no field of its own to keep them in');
        self::assertArrayNotHasKey('password', $values);
    }

    public function testTheAddressIsThePanelsRootWithNoneOfItsPagesNorCredentials(): void
    {
        foreach (['https://hm.example/api', 'https://hm.example/api/v1', 'https://hm.example/api-keys', 'https://hm.example/dashboard', 'https://hm.example/clients/', 'https://hm.example/login', 'https://hm.example/settings', 'https://hm.example/s/9f8e7d6c5b', 'https://admin:secret@hm.example'] as $url) {
            self::assertArrayHasKey('base_url', self::errors(['base_url' => $url, 'auth_mode' => 'token', 'api_token' => self::KEY] + self::OPTIONS), $url);
        }

        // A reverse proxy may put the whole panel under a path of its own.
        self::assertSame('https://example.com/hm', self::check(['base_url' => 'https://example.com/hm', 'auth_mode' => 'token', 'api_token' => self::KEY] + self::OPTIONS)['base_url']);
        self::assertSame('https://hm.example:2053', self::check(['base_url' => 'https://hm.example:2053', 'auth_mode' => 'token', 'api_token' => self::KEY] + self::OPTIONS)['base_url']);
    }

    public function testEditingKeepsTheStoredKeyWhileTheAddressStaysOnItsHost(): void
    {
        $keyed = self::kept(['base_url' => 'https://hm.example', 'auth_mode' => 'token', 'api_token' => self::KEY]);

        self::assertSame(self::KEY, self::check(['base_url' => 'https://hm.example', 'auth_mode' => 'token'] + self::OPTIONS, $keyed)['api_token'], 'a blank key keeps the stored one');
        self::assertSame(self::KEY, self::check(['base_url' => 'https://HM.example:443/panel', 'auth_mode' => 'token'] + self::OPTIONS, $keyed)['api_token'], 'the same host under another path is still the panel');

        $other = 'hmp_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
        self::assertSame($other, self::check(['base_url' => 'https://evil.example', 'auth_mode' => 'token', 'api_token' => $other] + self::OPTIONS, $keyed)['api_token'], 'a new key goes anywhere');
    }

    public function testTheKeyIsNeverSentToAnAddressThatMovedNorAnotherWayThere(): void
    {
        $keyed = self::kept(['base_url' => 'https://hm.example', 'auth_mode' => 'token', 'api_token' => self::KEY]);

        foreach (['https://evil.example', 'https://hm.example:8443', 'http://hm.example'] as $url) {
            $errors = self::errors(['base_url' => $url, 'auth_mode' => 'token'] + self::OPTIONS, $keyed);
            self::assertSame(['api_token'], array_keys($errors), $url);
            self::assertSame(PanelConnection::MOVED . 'کلید API را دوباره وارد کنید.', $errors['api_token'][0]);
        }

        $proxied = ['proxy_enabled' => true, 'proxy_host' => 'proxy.example.com', 'proxy_port' => 1080];
        self::assertSame(['api_token'], array_keys(self::errors($proxied + ['base_url' => 'https://hm.example', 'auth_mode' => 'token'] + self::OPTIONS, $keyed)), 'a proxy switched on');
        self::assertSame(['api_token'], array_keys(self::errors(['verify_tls' => false, 'base_url' => 'https://hm.example', 'auth_mode' => 'token'] + self::OPTIONS, $keyed)), 'the TLS certificate no longer checked');
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
        return (new HmPanelDriver())->describe()->form->check($input, $kept ?? static fn(): mixed => null);
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
