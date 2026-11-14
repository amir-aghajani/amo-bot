<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\Marzban;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use App\Core\Security\Encrypter;
use App\Modules\Providers\Drivers\Marzban\MarzbanDriver;
use App\Modules\Providers\Drivers\Marzban\MarzbanTokens;
use App\Modules\Providers\Enums\AuthMode;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Providers\Support\PanelCredentials;
use Tests\TestCase;

/**
 * The Marzban connector's card and form: the panel's root address, an admin's username and password — the one way in,
 * its API has no keys —, the shared options; every refusal at once; and a stored password kept while left blank, as
 * long as the address stays on its host.
 */
final class MarzbanDriverTest extends TestCase
{
    /** The connection's options, which every save sends. */
    private const OPTIONS = ['verify_tls' => true, 'timeout' => 30, 'subscription_url' => '', 'proxy_enabled' => false];

    public function testItsCardSaysWhatThePanelNeeds(): void
    {
        $card = self::driver()->describe()->toArray();

        self::assertSame(['marzban', 'Marzban'], [$card['key'], $card['label']]);
        self::assertNotEmpty(array_filter($card['notes'], static fn(string $note): bool => str_contains($note, '0.8')), 'the versions it needs');
        self::assertNotEmpty(array_filter($card['notes'], static fn(string $note): bool => str_contains($note, 'sudo')), 'whose users a non-sudo admin sees');
        self::assertEquals((object) ['mark' => 'MZ', 'vendor' => 'Gozargah', 'docs_url' => 'https://github.com/Gozargah/Marzban', 'inbounds' => true, 'link_rotation' => true], $card['traits']);
        self::assertSame(['base_url', 'auth_mode', 'username', 'password', 'verify_tls', 'timeout', 'subscription_url', 'proxy_enabled', 'proxy_host', 'proxy_port', 'proxy_username', 'proxy_password'], array_column($card['fields'], 'name'), 'no API token: Marzban has none');
        $mode = array_column($card['fields'], null, 'name')['auth_mode'];
        self::assertSame(['password' => 'نام کاربری و رمز'], array_column($mode['options'], 'label', 'value'), 'the password way, alone');
        self::assertSame('password', $mode['default']);
    }

    public function testAUsernameIsThreeTo32LettersDigitsAndUnderscores(): void
    {
        $names = self::driver()->capabilities()->names;

        self::assertSame([true, true, true], [$names->allows('ali'), $names->allows('ali__reza_1'), $names->allows(str_repeat('a', 32))]);
        self::assertSame([false, false, false, false], [$names->allows('al'), $names->allows(str_repeat('a', 33)), $names->allows('ali-reza'), $names->allows('علی_1')]);
        self::assertSame(['alireza1', '007', 'amir_1'], [$names->fit('ali.reza-1'), $names->fit('7'), $names->fit('amir_1')], 'the shop\'s own names made to fit');
        self::assertFalse(self::driver()->capabilities()->ipLimit, 'no per-user IP limit on Marzban');
    }

    public function testAnAdminsUsernameAndPasswordAreKeptWithTheOptions(): void
    {
        $values = self::check(['base_url' => ' https://panel.example.com:8000/ ', 'auth_mode' => 'password', 'username' => ' admin ', 'password' => 'S3cret pass', 'verify_tls' => false, 'timeout' => '۴۵', 'subscription_url' => 'https://sub.example.com/sub/', 'proxy_enabled' => false]);

        self::assertSame([
            'base_url' => 'https://panel.example.com:8000',
            'auth_mode' => 'password',
            'username' => 'admin',
            'password' => 'S3cret pass',
            'meta.verify_tls' => false,
            'meta.timeout' => 45,
            'meta.subscription_url' => 'https://sub.example.com/sub',
            'proxy_enabled' => false,
        ], $values);
    }

    public function testEveryRefusedFieldIsReportedAtOnce(): void
    {
        $errors = self::errors(['base_url' => 'panel.example.com', 'auth_mode' => 'password', 'username' => '', 'verify_tls' => true, 'timeout' => 1, 'subscription_url' => 'sub.example.com', 'proxy_enabled' => false]);

        self::assertEqualsCanonicalizing(['base_url', 'username', 'password', 'timeout', 'subscription_url'], array_keys($errors));

        // An API token is no way into Marzban: neither the way nor a token is taken.
        self::assertSame(['auth_mode'], array_keys(self::errors(['base_url' => 'https://mz.example', 'auth_mode' => 'token', 'api_token' => 'eyJhbGciOiJIUzI1NiJ9.e30.sig'] + self::OPTIONS)));
        self::assertArrayNotHasKey('api_token', self::check(['base_url' => 'https://mz.example', 'auth_mode' => 'password', 'username' => 'admin', 'password' => 'pw', 'api_token' => 'tok'] + self::OPTIONS));
    }

    public function testTheAddressIsThePanelsRootWithNeitherTheDashboardNorTheApiNorCredentials(): void
    {
        foreach (['https://mz.example:8000/dashboard/', 'https://mz.example:8000/dashboard/#/', 'https://mz.example/api', 'https://mz.example/docs', 'https://admin:secret@mz.example:8000'] as $url) {
            self::assertArrayHasKey('base_url', self::errors(['base_url' => $url, 'auth_mode' => 'password', 'username' => 'admin', 'password' => 'pw'] + self::OPTIONS), $url);
        }

        // A reverse proxy may put the whole panel under a path of its own.
        self::assertSame('https://example.com/mz', self::check(['base_url' => 'https://example.com/mz', 'auth_mode' => 'password', 'username' => 'admin', 'password' => 'pw'] + self::OPTIONS)['base_url']);
    }

    public function testEditingKeepsTheStoredPasswordWhileTheAddressStaysOnItsHost(): void
    {
        $admin = self::kept(['base_url' => 'https://mz.example', 'auth_mode' => 'password', 'username' => 'admin', 'password' => 'stored']);

        self::assertSame('stored', self::check(['base_url' => 'https://mz.example', 'auth_mode' => 'password', 'username' => 'admin', 'password' => ''] + self::OPTIONS, $admin)['password']);
        // The same host under another path is still the panel the password was given for.
        self::assertSame('stored', self::check(['base_url' => 'https://MZ.example:443/panel', 'auth_mode' => 'password', 'username' => 'admin'] + self::OPTIONS, $admin)['password']);
    }

    public function testAPasswordIsNeverSentToAnAddressThatMovedToAnotherHostNorAnotherWay(): void
    {
        $admin = self::kept(['base_url' => 'https://mz.example', 'auth_mode' => 'password', 'username' => 'admin', 'password' => 'stored']);

        foreach (['https://evil.example', 'https://mz.example:8443', 'http://mz.example'] as $url) {
            $errors = self::errors(['base_url' => $url, 'auth_mode' => 'password', 'username' => 'admin'] + self::OPTIONS, $admin);
            self::assertSame(['password'], array_keys($errors), $url);
            self::assertSame(PanelConnection::MOVED . 'رمز عبور را دوباره وارد کنید.', $errors['password'][0]);
        }

        $proxied = ['proxy_enabled' => true, 'proxy_host' => 'proxy.example.com', 'proxy_port' => 1080];
        self::assertSame(['password'], array_keys(self::errors($proxied + ['base_url' => 'https://mz.example', 'auth_mode' => 'password', 'username' => 'admin'] + self::OPTIONS, $admin)));
        self::assertSame(['password'], array_keys(self::errors(['verify_tls' => false, 'base_url' => 'https://mz.example', 'auth_mode' => 'password', 'username' => 'admin'] + self::OPTIONS, $admin)));
    }

    public function testAStoredServerSignsInWithItsUsernameAndPassword(): void
    {
        $this->app(); // registers the encrypter the Server casts need

        $credentials = PanelCredentials::of(new Server(['driver' => 'marzban', 'base_url' => 'https://mz.example', 'username' => 'admin', 'password' => 'pw']));

        self::assertSame([AuthMode::Password, 'admin', 'pw'], [$credentials->mode, $credentials->username, $credentials->password]);
        self::assertTrue($credentials->canLogin());
    }

    /** The connector, for its card and form alone: no token is ever kept. */
    private static function driver(): MarzbanDriver
    {
        return new MarzbanDriver(new MarzbanTokens(new Encrypter(''), ''));
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
        return self::driver()->describe()->form->check($input, $kept ?? static fn(): mixed => null);
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
