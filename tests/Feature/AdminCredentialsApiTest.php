<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config\ConfigFile;
use App\Core\Config\Repository as Config;
use App\Modules\Auth\Services\AdminAccount;
use App\Modules\Auth\Services\SignInThrottle;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\HttpTestCase;

/**
 * The owner changes the panel's login from their panel (PUT /api/admin/auth/credentials): the current password first —
 * a wrong one is a failed sign-in, counted with the login's, so this is no way around the throttle —, then the
 * installer's one rule for the new login, every refusal at once; written to config.php, the change signs every
 * other browser out and keeps this one signed in, in the shop it has open. A file the server may not write is said, and
 * changes nothing.
 */
final class AdminCredentialsApiTest extends HttpTestCase
{
    private const URL = '/api/admin/auth/credentials';

    private const NEW_PASSWORD = ' a new passphrase ';

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
    }

    public function testTheNewPasswordOpensThePanelAndOtherBrowsersAreSignedOut(): void
    {
        $elsewhere = $_SESSION; // another browser, signed in with the same login

        $changed = $this->putJson(self::URL, $this->change(['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]));

        self::assertSame(200, $changed->getStatusCode(), (string) $changed->getBody());
        self::assertSame(['name' => self::ADMIN_USERNAME, 'shop' => ['id' => 1, 'name' => 'فروشگاه اصلی', 'username' => null, 'status' => 'active']], $this->decode($changed)['session']);
        self::assertSame(200, $this->get('/api/admin/auth/me')->getStatusCode(), 'this browser stays signed in');
        self::assertSame(self::ADMIN_USERNAME, $this->file()->get('ADMIN_USERNAME'));
        self::assertTrue(password_verify(self::NEW_PASSWORD, (string) $this->file()->get('ADMIN_PASSWORD_HASH')), 'written hashed, as typed');

        $here = $_SESSION;
        $_SESSION = $elsewhere;
        self::assertSame(401, $this->get('/api/admin/auth/me')->getStatusCode(), 'every other browser is signed out');
        $_SESSION = $here;

        $this->postJson('/api/admin/auth/logout');
        self::assertSame(401, $this->signIn(self::ADMIN_USERNAME, self::ADMIN_PASSWORD), 'the old password opens nothing');
        self::assertSame(401, $this->signIn(self::ADMIN_USERNAME, trim(self::NEW_PASSWORD)), 'a password is what was typed, spaces and all');
        self::assertSame(200, $this->signIn(self::ADMIN_USERNAME, self::NEW_PASSWORD));
    }

    public function testABlankPasswordChangesTheUsernameAloneInTheShopThatIsOpen(): void
    {
        $bot = $this->agentBot();
        $this->openShop($bot);
        $hash = (string) $this->service(Config::class)->get('admin.password');

        $changed = $this->putJson(self::URL, $this->change(['username' => ' boss ']));

        self::assertSame(200, $changed->getStatusCode(), (string) $changed->getBody());
        self::assertSame(['boss', $bot->id], [$this->decode($changed)['session']['name'], $this->decode($changed)['session']['shop']['id']]);
        self::assertSame(['boss', $hash], [$this->file()->get('ADMIN_USERNAME'), $this->file()->get('ADMIN_PASSWORD_HASH')], 'the password kept as it was');

        $this->postJson('/api/admin/auth/logout');
        self::assertSame(401, $this->signIn(self::ADMIN_USERNAME, self::ADMIN_PASSWORD));
        self::assertSame(200, $this->signIn('boss', self::ADMIN_PASSWORD));
    }

    public function testEveryRefusalOfTheFormComesAtOnceAndChangesNothing(): void
    {
        $refused = $this->putJson(self::URL, ['current_password' => '', 'username' => 'a b', 'password' => 'short', 'password_confirmation' => 'short']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertEqualsCanonicalizing(['current_password', 'username', 'password'], array_keys($this->decode($refused)['errors']));

        $mismatch = $this->putJson(self::URL, $this->change(['password' => 'long-enough', 'password_confirmation' => 'something else']));
        self::assertSame(['password_confirmation'], array_keys($this->decode($mismatch)['errors']));

        $confirmationAlone = $this->putJson(self::URL, $this->change(['password_confirmation' => 'long-enough']));
        self::assertSame(['password'], array_keys($this->decode($confirmationAlone)['errors']), 'a confirmation is of a password');

        $nothing = $this->putJson(self::URL, $this->change());
        self::assertSame(['password'], array_keys($this->decode($nothing)['errors']), 'a change that changes nothing');

        self::assertFalse($this->file()->exists(), 'nothing written');
        self::assertTrue($this->service(AdminAccount::class)->verify(self::ADMIN_USERNAME, self::ADMIN_PASSWORD));
    }

    public function testAWrongCurrentPasswordIsAFailedSignInCountedWithTheLogins(): void
    {
        $change = $this->change(['password' => 'long-enough', 'password_confirmation' => 'long-enough']);

        // A blank one is not a guess.
        for ($i = 0; $i < SignInThrottle::MAX_ATTEMPTS; $i++) {
            self::assertSame(['current_password'], array_keys($this->decode($this->putJson(self::URL, ['current_password' => ''] + $change))['errors']));
        }
        for ($i = 0; $i < SignInThrottle::MAX_ATTEMPTS; $i++) {
            $wrong = $this->putJson(self::URL, ['current_password' => 'guess-' . $i] + $change);

            self::assertSame(422, $wrong->getStatusCode());
            self::assertSame([AdminAccount::WRONG_PASSWORD], $this->decode($wrong)['errors']['current_password']);
        }

        // Even the right password waits now, here and at the login: one count.
        $waiting = $this->putJson(self::URL, $change);
        self::assertSame(429, $waiting->getStatusCode());
        self::assertGreaterThan(0, (int) $waiting->getHeaderLine('Retry-After'));
        self::assertSame(SignInThrottle::refused((int) $waiting->getHeaderLine('Retry-After'))->getMessage(), $this->decode($waiting)['message']);
        self::assertSame(429, $this->postJson('/api/admin/auth/login', ['username' => self::ADMIN_USERNAME, 'password' => self::ADMIN_PASSWORD])->getStatusCode());
        self::assertSame(200, $this->get('/api/admin/auth/me')->getStatusCode(), 'the session itself goes on');
        self::assertTrue($this->service(AdminAccount::class)->verify(self::ADMIN_USERNAME, self::ADMIN_PASSWORD), 'nothing changed');
    }

    public function testTheChangeWaitsWhileTheLoginWaits(): void
    {
        // Someone guessing at the login from this address…
        for ($i = 0; $i < SignInThrottle::MAX_ATTEMPTS; $i++) {
            $this->postJson('/api/admin/auth/login', ['username' => self::ADMIN_USERNAME, 'password' => 'guess-' . $i]);
        }

        $waiting = $this->putJson(self::URL, $this->change(['username' => 'boss']));

        self::assertSame(429, $waiting->getStatusCode());
        self::assertGreaterThan(0, (int) $waiting->getHeaderLine('Retry-After'));
        self::assertSame(self::ADMIN_USERNAME, $this->service(AdminAccount::class)->username());
    }

    public function testGuessesFromManyAddressesMakeTheAccountWaitHereToo(): void
    {
        $throttle = $this->service(SignInThrottle::class);
        for ($i = 1; $i < SignInThrottle::MAX_ACCOUNT_ATTEMPTS; $i++) {
            $throttle->failed(AdminAccount::WAY, self::from("10.0.{$i}.1"), self::ADMIN_USERNAME);
        }

        self::assertSame(422, $this->putJson(self::URL, $this->change(['current_password' => 'guess', 'username' => 'boss']))->getStatusCode(), 'the last guess the account takes');

        self::assertSame(429, $this->putJson(self::URL, $this->change(['username' => 'boss']))->getStatusCode());
        self::assertGreaterThan(0, $throttle->wait(AdminAccount::WAY, self::from('192.0.2.7'), self::ADMIN_USERNAME), 'the account waits from any address');
    }

    public function testAFileTheServerMayNotWriteIsSaidAndNothingChanges(): void
    {
        // A folder stands where config.php would be written.
        mkdir($this->file()->path());
        $logs = $this->logs();

        $refused = $this->putJson(self::URL, $this->change(['username' => 'boss', 'password' => 'long-enough', 'password_confirmation' => 'long-enough']));

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame([ConfigFile::UNWRITABLE], $this->decode($refused)['errors']['file'], 'what to fix, in the owner\'s words');
        self::assertTrue($logs->hasErrorThatContains('could not be written'));
        self::assertSame(['name' => self::ADMIN_USERNAME, 'shop' => ['id' => 1, 'name' => 'فروشگاه اصلی', 'username' => null, 'status' => 'active']], $this->decode($this->get('/api/admin/auth/me'))['session']);
        self::assertTrue($this->service(AdminAccount::class)->verify(self::ADMIN_USERNAME, self::ADMIN_PASSWORD), 'the login in use is still the one the file holds');
    }

    public function testAnAgentsPanelHasNoSuchThing(): void
    {
        $this->loginAsAgent($this->agentBot());

        self::assertSame(404, $this->putJson('/api/agent/auth/credentials', $this->change(['username' => 'boss']))->getStatusCode());
    }

    /**
     * The form as the panel sends it: the right current password, the login as it is, no new password — `$fields` over it.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function change(array $fields = []): array
    {
        return $fields + ['current_password' => self::ADMIN_PASSWORD, 'username' => self::ADMIN_USERNAME, 'password' => '', 'password_confirmation' => ''];
    }

    private function signIn(string $username, string $password): int
    {
        return $this->postJson('/api/admin/auth/login', ['username' => $username, 'password' => $password])->getStatusCode();
    }

    /** The run's own config.php, where the login is kept. */
    private function file(): ConfigFile
    {
        return $this->service(ConfigFile::class);
    }

    /** A sign-in from that address. */
    private static function from(string $address): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/admin/auth/login', ['REMOTE_ADDR' => $address]);
    }
}
