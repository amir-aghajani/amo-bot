<?php

declare(strict_types=1);

namespace Tests\Unit\Providers\ThreeXui;

use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use App\Modules\Providers\Drivers\ThreeXui\ThreeXuiDriver;
use Tests\TestCase;

/**
 * The 3x-ui connector: its card — the versions it needs, the two letters the picker draws, what its panels can do — and
 * its form: the address with its web base path, a token or a username and password with the TOTP secret of a two-factor
 * login, the connection's options; and what an edit keeps of the stored secrets: only what was given for the host the
 * address keeps.
 */
final class ThreeXuiDriverTest extends TestCase
{
    private const TOTP = 'JBSWY3DPEHPK3PXP';

    /** The connection's options, which every save sends. */
    private const OPTIONS = ['verify_tls' => true, 'timeout' => 30, 'subscription_url' => ''];

    private const ADDRESS = 'https://de1.example.com:2053/AbCdEf';

    public function testItsCardSaysWhatThePanelNeedsAndWhatItCanDo(): void
    {
        $card = (new ThreeXuiDriver())->describe()->toArray();

        self::assertSame(['3x-ui', '3X-UI'], [$card['key'], $card['label']]);
        self::assertNotEmpty(array_filter($card['notes'], static fn(string $note): bool => str_contains($note, 'نسخه 3')), 'the versions it needs');
        self::assertEquals((object) ['mark' => '3X', 'vendor' => 'MHSanaei', 'docs_url' => 'https://github.com/MHSanaei/3x-ui', 'inbounds' => true, 'link_rotation' => true], $card['traits']);
        self::assertSame(['base_url', 'auth_mode', 'api_token', 'username', 'password', 'totp_secret', 'verify_tls', 'timeout', 'subscription_url'], array_column($card['fields'], 'name'));
        $fields = array_column($card['fields'], null, 'name');
        self::assertSame(['auth_mode' => ['password']], (array) $fields['totp_secret']['when'], 'the two-factor secret is the password way in\'s');
        self::assertSame(['base_url'], $fields['totp_secret']['bound_to'], 'and goes only where the panel is');
        self::assertSame([true, true, true], [$fields['verify_tls']['advanced'], $fields['timeout']['advanced'], $fields['subscription_url']['advanced']]);
    }

    public function testATokenConnectionIsKeptWithItsOptions(): void
    {
        $values = self::check(['base_url' => ' https://de1.example.com:2053/AbCdEf/ ', 'auth_mode' => 'token', 'api_token' => 'tok', 'verify_tls' => false, 'timeout' => '۴۵', 'subscription_url' => 'https://sub.example.com/sub/']);

        self::assertSame([
            'base_url' => self::ADDRESS,
            'auth_mode' => 'token',
            'api_token' => 'tok',
            'meta.verify_tls' => false,
            'meta.timeout' => 45,
            'meta.subscription_url' => 'https://sub.example.com/sub',
        ], $values, 'the token\'s way in alone: the login\'s fields are not read');
    }

    public function testTheAddressIsThePanelsBaseWithoutItsPages(): void
    {
        foreach (['https://de1.example.com:2053/AbCdEf/panel', 'https://de1.example.com:2053/AbCdEf/panel/inbounds', 'https://de1.example.com:2053/panel/panel', 'https://de1.example.com:2053/login'] as $url) {
            self::assertArrayHasKey('base_url', self::errors(['base_url' => $url, 'auth_mode' => 'token', 'api_token' => 'tok'] + self::OPTIONS), $url);
        }

        // A web base path named «panel» is the panel's base, not its dashboard.
        self::assertSame('https://de1.example.com:2053/panel', self::check(['base_url' => 'https://de1.example.com:2053/panel/', 'auth_mode' => 'token', 'api_token' => 'tok'] + self::OPTIONS)['base_url']);
    }

    public function testATokenIsCopiedWhole(): void
    {
        self::assertSame(['api_token'], array_keys(self::errors(['base_url' => self::ADDRESS, 'auth_mode' => 'token', 'api_token' => 'tok en'] + self::OPTIONS)), 'no space in a token');
        self::assertSame(['api_token'], array_keys(self::errors(['base_url' => self::ADDRESS, 'auth_mode' => 'token'] + self::OPTIONS)), 'and none, none kept');
    }

    public function testAPasswordLoginTakesATotpSecretAsAnAuthenticatorShowsIt(): void
    {
        $values = self::check(self::login(['totp_secret' => 'jbsw y3dp-ehpk 3pxp']));

        self::assertSame(self::TOTP, $values['totp_secret'], 'spaces, dashes, lower case: kept in one form');
        self::assertSame(['admin', 'pw'], [$values['username'], $values['password']]);
        self::assertArrayNotHasKey('api_token', $values, 'the token\'s field is not read');
        self::assertSame('', self::check(self::login())['totp_secret'], 'a panel without a two-factor login has none');

        self::assertSame(['totp_secret'], array_keys(self::errors(self::login(['totp_secret' => '123456']))), 'a one-time code is not the secret');
    }

    public function testAnEditKeepsTheStoredSecretsWhileTheAddressStaysOnItsHost(): void
    {
        $tokened = self::kept(['base_url' => self::ADDRESS, 'auth_mode' => 'token', 'api_token' => 'stored-token']);
        $loggedIn = self::kept(['base_url' => self::ADDRESS, 'auth_mode' => 'password', 'username' => 'admin', 'password' => 'stored-pw', 'totp_secret' => self::TOTP]);

        self::assertSame('stored-token', self::check(['base_url' => 'https://de1.example.com:2053/NewPath', 'auth_mode' => 'token'] + self::OPTIONS, $tokened)['api_token'], 'another web base path is the same panel');

        $values = self::check(self::login(['password' => '']), $loggedIn);
        self::assertSame(['stored-pw', self::TOTP], [$values['password'], $values['totp_secret']]);

        self::assertSame('', self::check(self::login(['clear_totp_secret' => true]), $loggedIn)['totp_secret'], 'a two-factor login switched off on the panel: its secret cleared');
    }

    public function testNoStoredSecretGoesToAnAddressThatMovedToAnotherHost(): void
    {
        $tokened = self::kept(['base_url' => self::ADDRESS, 'auth_mode' => 'token', 'api_token' => 'stored-token']);
        $loggedIn = self::kept(['base_url' => self::ADDRESS, 'auth_mode' => 'password', 'username' => 'admin', 'password' => 'stored-pw', 'totp_secret' => self::TOTP]);

        // Another host, another port, plain http: each is somewhere the secrets were never given for.
        foreach (['https://attacker.example:2053/AbCdEf', 'https://de1.example.com:8443/AbCdEf', 'http://de1.example.com:2053/AbCdEf'] as $url) {
            $errors = self::errors(['base_url' => $url, 'auth_mode' => 'token'] + self::OPTIONS, $tokened);
            self::assertSame(['api_token'], array_keys($errors), $url);
            self::assertStringContainsString('آدرس پنل عوض شده است', $errors['api_token'][0]);

            self::assertSame(['password', 'totp_secret'], array_keys(self::errors(self::login(['base_url' => $url, 'password' => '']), $loggedIn)), $url);
        }

        // Typed again they go there; the two-factor secret of the old address typed again too, or cleared.
        $values = self::check(self::login(['base_url' => 'https://attacker.example:2053/AbCdEf', 'password' => 'typed-again', 'clear_totp_secret' => true]), $loggedIn);
        self::assertSame(['typed-again', ''], [$values['password'], $values['totp_secret']]);
    }

    /**
     * A password login's form, as the screen sends it.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function login(array $overrides = []): array
    {
        return $overrides + ['base_url' => self::ADDRESS, 'auth_mode' => 'password', 'username' => 'admin', 'password' => 'pw'] + self::OPTIONS;
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
        return (new ThreeXuiDriver())->describe()->form->check($input, $kept ?? static fn(): mixed => null);
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
