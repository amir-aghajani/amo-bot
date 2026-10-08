<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\PasarGuard;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use App\Modules\Providers\Drivers\PasarGuard\PasarGuardDriver;
use Tests\TestCase;

/**
 * The PasarGuard connector: its card and its form — the panel's root address, an API key in its whole `pg_key_…` shape
 * or an admin's username and password, the connection's options —, every refusal at once, and secrets kept while left
 * blank, as long as the address stays on its host.
 */
final class PasarGuardDriverTest extends TestCase
{
    private const KEY = 'pg_key_6f1c2a3b-4d5e-4f60-8a1b-2c3d4e5f6a7b';

    /** The connection's options, which every save sends. */
    private const OPTIONS = ['verify_tls' => true, 'timeout' => 30, 'subscription_url' => ''];

    public function testItsCardSaysWhatThePanelNeeds(): void
    {
        $card = (new PasarGuardDriver())->describe()->toArray();

        self::assertSame(['pasarguard', 'PasarGuard'], [$card['key'], $card['label']]);
        self::assertNotEmpty(array_filter($card['notes'], static fn(string $note): bool => str_contains($note, 'API Keys')));
        self::assertNotEmpty(array_filter($card['notes'], static fn(string $note): bool => str_contains($note, '3.1')), 'the versions it needs');
        self::assertEquals((object) ['mark' => 'PG', 'vendor' => 'PasarGuard', 'docs_url' => 'https://github.com/PasarGuard/panel', 'inbounds' => true, 'link_rotation' => true], $card['traits']);
        self::assertSame(['base_url', 'auth_mode', 'api_token', 'username', 'password', 'verify_tls', 'timeout', 'subscription_url'], array_column($card['fields'], 'name'), 'no two-factor secret of its own');
        self::assertSame(['token' => 'کلید API', 'password' => 'نام کاربری و رمز'], array_column(array_column($card['fields'], null, 'name')['auth_mode']['options'], 'label', 'value'));
    }

    public function testAnApiKeyConnectionIsKeptWithTheOptions(): void
    {
        $values = self::check(['base_url' => ' https://panel.example.com:8000/ ', 'auth_mode' => 'token', 'api_token' => self::KEY, 'verify_tls' => false, 'timeout' => '۴۵', 'subscription_url' => 'https://sub.example.com/sub/']);

        self::assertSame([
            'base_url' => 'https://panel.example.com:8000',
            'auth_mode' => 'token',
            'api_token' => self::KEY,
            'meta.verify_tls' => false,
            'meta.timeout' => 45,
            'meta.subscription_url' => 'https://sub.example.com/sub',
        ], $values);
    }

    public function testEveryRefusedFieldIsReportedAtOnce(): void
    {
        $errors = self::errors(['base_url' => 'panel.example.com', 'auth_mode' => 'token', 'api_token' => 'pg_key_6f1c…6a7b', 'verify_tls' => true, 'timeout' => 1, 'subscription_url' => 'sub.example.com']);

        self::assertEqualsCanonicalizing(['base_url', 'api_token', 'timeout', 'subscription_url'], array_keys($errors));
        self::assertStringContainsString('pg_key_', $errors['api_token'][0], 'the cut-short key the list shows is not the key');

        self::assertSame(['base_url', 'api_token'], array_keys(self::errors(['base_url' => '', 'auth_mode' => 'token'] + self::OPTIONS)));
        self::assertSame(['auth_mode'], array_keys(self::errors(['base_url' => 'https://pg.example', 'auth_mode' => 'cookie', 'api_token' => self::KEY] + self::OPTIONS)), 'no way in: none of its fields is asked');
    }

    public function testTheAddressIsThePanelsRootWithNeitherTheDashboardNorTheApiNorCredentials(): void
    {
        foreach (['https://pg.example:8000/dashboard/', 'https://pg.example:8000/dashboard/#/users', 'https://pg.example/api', 'https://admin:secret@pg.example:8000'] as $url) {
            self::assertArrayHasKey('base_url', self::errors(['base_url' => $url, 'auth_mode' => 'token', 'api_token' => self::KEY] + self::OPTIONS), $url);
        }

        // A reverse proxy may put the whole panel under a path of its own.
        self::assertSame('https://example.com/pg', self::check(['base_url' => 'https://example.com/pg', 'auth_mode' => 'token', 'api_token' => self::KEY] + self::OPTIONS)['base_url']);
    }

    public function testAnAdminsUsernameAndPasswordInstead(): void
    {
        self::assertSame(['username', 'password'], array_keys(self::errors(['base_url' => 'https://pg.example', 'auth_mode' => 'password'] + self::OPTIONS)));

        $values = self::check(['base_url' => 'https://pg.example', 'auth_mode' => 'password', 'username' => ' admin ', 'password' => 'pw', 'api_token' => self::KEY] + self::OPTIONS);

        self::assertSame(['admin', 'pw'], [$values['username'], $values['password']]);
        self::assertArrayNotHasKey('api_token', $values, 'one way in at a time: the key\'s field is not read');
    }

    public function testEditingKeepsTheStoredSecretsWhileTheAddressStaysOnItsHost(): void
    {
        $keyed = self::kept(['base_url' => 'https://pg.example', 'auth_mode' => 'token', 'api_token' => self::KEY]);
        $admin = self::kept(['base_url' => 'https://pg.example', 'auth_mode' => 'password', 'username' => 'admin', 'password' => 'stored']);

        self::assertSame(self::KEY, self::check(['base_url' => 'https://pg.example', 'auth_mode' => 'token'] + self::OPTIONS, $keyed)['api_token'], 'a blank key keeps the stored one');
        self::assertSame('stored', self::check(['base_url' => 'https://pg.example', 'auth_mode' => 'password', 'username' => 'admin', 'password' => ''] + self::OPTIONS, $admin)['password']);

        // The same host under another path is still the panel the secret was given for; a new key goes anywhere.
        self::assertSame(self::KEY, self::check(['base_url' => 'https://PG.example:443/panel', 'auth_mode' => 'token'] + self::OPTIONS, $keyed)['api_token']);
        $other = 'pg_key_00000000-0000-4000-8000-000000000000';
        self::assertSame($other, self::check(['base_url' => 'https://evil.example', 'auth_mode' => 'token', 'api_token' => $other] + self::OPTIONS, $keyed)['api_token']);
    }

    public function testASecretIsNeverSentToAnAddressThatMovedToAnotherHost(): void
    {
        $keyed = self::kept(['base_url' => 'https://pg.example', 'auth_mode' => 'token', 'api_token' => self::KEY]);
        $admin = self::kept(['base_url' => 'https://pg.example', 'auth_mode' => 'password', 'username' => 'admin', 'password' => 'stored']);

        // Another host, another port, plain http: the key was given for the old address, and is typed again.
        foreach (['https://evil.example', 'https://pg.example:8443', 'http://pg.example'] as $url) {
            $errors = self::errors(['base_url' => $url, 'auth_mode' => 'token'] + self::OPTIONS, $keyed);
            self::assertSame(['api_token'], array_keys($errors), $url);
            self::assertStringContainsString('آدرس پنل عوض شده است', $errors['api_token'][0]);
        }
        self::assertSame(['password'], array_keys(self::errors(['base_url' => 'https://evil.example', 'auth_mode' => 'password', 'username' => 'admin'] + self::OPTIONS, $admin)));
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
        return (new PasarGuardDriver())->describe()->form->check($input, $kept ?? static fn(): mixed => null);
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
