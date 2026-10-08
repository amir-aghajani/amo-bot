<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config\ConfigFile;
use App\Modules\Admin\Api\OwnerAuthController;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Services\AdminAccount;
use App\Modules\Auth\Services\LoginRecovery;
use App\Modules\Auth\Services\RecoveryKey;
use App\Modules\Auth\Services\SignInThrottle;
use Monolog\LogRecord;
use Tests\HttpTestCase;

/**
 * The way back into the owner's panel on a host without a shell — a password forgotten, or no login at all: the sign-in
 * page has the panel write a one-time key to storage/recovery-key.txt, the owner reads it off the host's files (its File
 * Manager) and sets a new login with it — signed in under it, every other session out, the key spent. Nobody without the
 * host's files gets in: a wrong key is a failed sign-in counted per address, a key opens nothing after an hour, and the
 * one there is kept while it opens, so nobody can turn it over while its owner copies it.
 */
final class LoginRecoveryApiTest extends HttpTestCase
{
    private const KEY_URL = '/api/admin/auth/recovery/key';

    private const URL = '/api/admin/auth/recovery';

    private const NEW_PASSWORD = 'a-new-password';

    public function testTheKeyIsWrittenToTheHostsFilesAndKeptWhileItOpens(): void
    {
        $logs = $this->logs();

        $answer = $this->decode($this->postJson(self::KEY_URL));

        $key = $this->key();
        self::assertMatchesRegularExpression('/^[2-9A-Z]{4}(-[2-9A-Z]{4}){3}$/', $key, 'four groups of four, no look-alikes');
        self::assertSame(RecoveryKey::FILE, $answer['file'], 'where the owner finds it');
        self::assertEqualsWithDelta(time() + RecoveryKey::LIFETIME, (int) strtotime($answer['expires_at']), 5);
        self::assertStringNotContainsString($key, (string) json_encode($answer), 'the key itself is the host\'s files\' alone');
        self::assertTrue($logs->hasWarningThatContains('A key to set the panel\'s login again'), 'the owner can read who asked for one');

        $again = $this->decode($this->postJson(self::KEY_URL));

        self::assertSame($key, $this->key(), 'asked again, the same key: nobody turns it over while its owner copies it');
        self::assertSame($answer['expires_at'], $again['expires_at']);
        self::assertCount(1, array_filter($logs->getRecords(), static fn(LogRecord $record): bool => str_contains($record->message, 'A key to set')), 'one key made, one told');
    }

    public function testTheKeySetsANewLoginSignsInUnderItAndEndsEveryOtherSession(): void
    {
        $this->loginAsAdmin();
        $elsewhere = $_SESSION; // another browser, signed in with the login being lost
        $_SESSION = [];
        $logs = $this->logs();
        $this->postJson(self::KEY_URL);

        $response = $this->postJson(self::URL, $this->login(' ' . strtolower($this->key()) . ' ', ['username' => 'boss']));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['name' => 'boss', 'shop' => ['id' => 1, 'name' => 'فروشگاه اصلی', 'username' => null, 'status' => 'active']], $this->decode($response)['session'], 'the key as typed: case and spaces do not matter');
        self::assertSame(200, $this->get('/api/admin/auth/me')->getStatusCode(), 'this browser is signed in under the new login');
        self::assertSame('boss', $this->file()->get('ADMIN_USERNAME'));
        self::assertTrue(password_verify(self::NEW_PASSWORD, (string) $this->file()->get('ADMIN_PASSWORD_HASH')), 'kept in config.php, hashed');
        self::assertFileDoesNotExist($this->keyPath(), 'the key is spent');
        self::assertTrue($logs->hasWarningThatContains('set again with the recovery key'));

        $here = $_SESSION;
        $_SESSION = $elsewhere;
        self::assertSame(401, $this->get('/api/admin/auth/me')->getStatusCode(), 'a session of the old login ends');
        $_SESSION = $here;

        $this->postJson('/api/admin/auth/logout');
        self::assertSame(401, $this->postJson('/api/admin/auth/login', ['username' => self::ADMIN_USERNAME, 'password' => self::ADMIN_PASSWORD])->getStatusCode(), 'the old login opens nothing');
        self::assertSame(200, $this->postJson('/api/admin/auth/login', ['username' => 'boss', 'password' => self::NEW_PASSWORD])->getStatusCode());
    }

    public function testAPanelWithNoLoginAtAllGetsOneThisWay(): void
    {
        $this->configureAdmin(null);
        $refused = $this->postJson('/api/admin/auth/login', ['username' => 'admin', 'password' => 'whatever']);
        self::assertSame([503, SignInRefusedException::NOT_SET_UP], [$refused->getStatusCode(), $this->decode($refused)['message']]);
        self::assertStringContainsString('«رمز را فراموش کرده‌اید؟»', SignInRefusedException::NOT_SET_UP, 'the sign-in page says which way');

        $this->postJson(self::KEY_URL);
        $response = $this->postJson(self::URL, $this->login($this->key(), ['username' => 'owner']));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertTrue($this->service(AdminAccount::class)->configured());
    }

    public function testEveryRefusalOfTheFormComesAtOnceAndAKeyThatWasRightStays(): void
    {
        $this->postJson(self::KEY_URL);

        $refused = $this->postJson(self::URL, ['key' => $this->key(), 'username' => 'a b', 'password' => 'short', 'password_confirmation' => 'short']);

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['username', 'password'], array_keys($this->decode($refused)['errors']), 'the installer\'s one rule');
        self::assertFileExists($this->keyPath(), 'the key stays for the next try');
        self::assertTrue($this->service(AdminAccount::class)->verify(self::ADMIN_USERNAME, self::ADMIN_PASSWORD), 'nothing changed');

        $both = $this->postJson(self::URL, ['key' => 'AAAA-BBBB-CCCC-DDDD', 'username' => 'a b', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);
        self::assertSame(['username', 'key'], array_keys($this->decode($both)['errors']), 'a wrong key among them');
    }

    public function testAKeyIsNeededAndTryingOneMakesNone(): void
    {
        $missing = $this->postJson(self::URL, $this->login(''));
        self::assertSame(['کلید بازیابی را وارد کنید.'], $this->decode($missing)['errors']['key']);

        $made = $this->postJson(self::URL, $this->login('AAAA-BBBB-CCCC-DDDD'));
        self::assertSame([LoginRecovery::WRONG_KEY], $this->decode($made)['errors']['key']);
        self::assertFileDoesNotExist($this->keyPath(), 'no key without asking for one');
        self::assertTrue($this->service(AdminAccount::class)->verify(self::ADMIN_USERNAME, self::ADMIN_PASSWORD));
    }

    public function testAKeyOpensNothingAfterAnHourAndANewOneTakesItsPlace(): void
    {
        $this->postJson(self::KEY_URL);
        $key = $this->key();
        touch($this->keyPath(), time() - RecoveryKey::LIFETIME - 1);

        $late = $this->postJson(self::URL, $this->login($key));

        self::assertSame([LoginRecovery::WRONG_KEY], $this->decode($late)['errors']['key']);
        self::assertTrue($this->service(AdminAccount::class)->verify(self::ADMIN_USERNAME, self::ADMIN_PASSWORD), 'nothing changed');

        $answer = $this->decode($this->postJson(self::KEY_URL));
        self::assertNotSame($key, $this->key(), 'a new one in its place');
        self::assertGreaterThan(time(), (int) strtotime($answer['expires_at']));
    }

    public function testAWrongKeyIsAFailedSignInCountedPerAddressApartFromThePasswords(): void
    {
        $this->postJson(self::KEY_URL);
        for ($i = 0; $i < SignInThrottle::MAX_ATTEMPTS; $i++) {
            $wrong = $this->postJson(self::URL, $this->login('AAAA-BBBB-CCCC-' . str_pad((string) $i, 4, '2')));

            self::assertSame([LoginRecovery::WRONG_KEY], $this->decode($wrong)['errors']['key']);
        }

        $waiting = $this->postJson(self::URL, $this->login($this->key()));

        self::assertSame(429, $waiting->getStatusCode(), 'even the right key waits now');
        self::assertGreaterThan(0, (int) $waiting->getHeaderLine('Retry-After'));
        self::assertTrue($this->service(AdminAccount::class)->verify(self::ADMIN_USERNAME, self::ADMIN_PASSWORD), 'nothing changed');
        self::assertSame(200, $this->postJson('/api/admin/auth/login', ['username' => self::ADMIN_USERNAME, 'password' => self::ADMIN_PASSWORD])->getStatusCode(), 'a guess at the key is no guess at the password');
    }

    public function testAConfigFileTheServerMayNotWriteIsSaidAndTheKeyStays(): void
    {
        $this->postJson(self::KEY_URL);
        // A folder stands where config.php would be written.
        mkdir($this->file()->path());

        $refused = $this->postJson(self::URL, $this->login($this->key()));

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame([ConfigFile::UNWRITABLE], $this->decode($refused)['errors']['file']);
        self::assertFileExists($this->keyPath(), 'there for the next try, once the file can be written');
    }

    public function testAStorageTheKeyCannotBeWrittenToSaysWhatToFix(): void
    {
        $this->swap(RecoveryKey::class, new RecoveryKey($this->scratchDir() . '/missing/recovery-key.txt'), LoginRecovery::class, OwnerAuthController::class);
        $logs = $this->logs();

        $refused = $this->postJson(self::KEY_URL);

        self::assertSame([503, SignInRefusedException::KEY_UNWRITABLE], [$refused->getStatusCode(), $this->decode($refused)['message']]);
        self::assertTrue($logs->hasErrorThatContains('The recovery key could not be written'));
    }

    public function testTheWayBackInIsTheOwnersPanelsAlone(): void
    {
        $this->loginAsAgent($this->agentBot());

        self::assertSame(404, $this->postJson('/api/agent/auth/recovery/key')->getStatusCode());
        self::assertFileDoesNotExist($this->keyPath());
    }

    /**
     * The form as the recovery page sends it — the key, then a login by the installer's rule —, `$fields` over it.
     *
     * @param array<string, string> $fields
     * @return array<string, string>
     */
    private function login(string $key, array $fields = []): array
    {
        return $fields + ['key' => $key, 'username' => self::ADMIN_USERNAME, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD];
    }

    /** The key as the owner reads it off the host's files. */
    private function key(): string
    {
        return trim((string) file_get_contents($this->keyPath()));
    }

    private function keyPath(): string
    {
        return (string) $this->app()->container()->get('recovery.key');
    }

    private function file(): ConfigFile
    {
        return $this->service(ConfigFile::class);
    }
}
