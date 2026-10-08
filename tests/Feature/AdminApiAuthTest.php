<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\ErrorHandler;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Services\AdminAccount;
use App\Modules\Auth\Services\SignInThrottle;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\HttpTestCase;

/**
 * The owner's panel's one login, from config/admin.php: signing in, staying signed in, being signed out when the
 * credentials change, a password kept in plain text keeping the panel closed, and the failures counted — per address,
 * and per account from every address together — by a throttle that lets nobody try while it cannot count. (Every
 * route of the panel needing its session and the CSRF header is RouteGuardsTest's.)
 */
final class AdminApiAuthTest extends HttpTestCase
{
    /** The owner's way in, as the throttle counts it (OwnerAuthController). */
    private const WAY = 'login';

    public function testEmptyFieldsAreValidationErrors(): void
    {
        $response = $this->postJson('/api/admin/auth/login', ['username' => '', 'password' => '']);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('username', $this->decode($response)['errors']);
        self::assertArrayHasKey('password', $this->decode($response)['errors']);
    }

    public function testWrongCredentialsAre401(): void
    {
        $wrongPassword = $this->postJson('/api/admin/auth/login', ['username' => self::ADMIN_USERNAME, 'password' => 'wrong']);
        self::assertSame([401, SignInRefusedException::WRONG_CREDENTIALS], [$wrongPassword->getStatusCode(), $this->decode($wrongPassword)['message']]);
        self::assertSame(401, $this->postJson('/api/admin/auth/login', ['username' => 'someone', 'password' => self::ADMIN_PASSWORD])->getStatusCode());
        self::assertSame(401, $this->get('/api/admin/auth/me')->getStatusCode());
    }

    public function testAnAddressThatKeepsFailingWaits(): void
    {
        for ($i = 0; $i < SignInThrottle::MAX_ATTEMPTS; $i++) {
            self::assertSame(401, $this->postJson('/api/admin/auth/login', ['username' => self::ADMIN_USERNAME, 'password' => 'wrong'])->getStatusCode());
        }

        // Even the right password waits now: the window is not over.
        $response = $this->postJson('/api/admin/auth/login', ['username' => self::ADMIN_USERNAME, 'password' => self::ADMIN_PASSWORD]);

        self::assertSame(429, $response->getStatusCode());
        $wait = (int) $response->getHeaderLine('Retry-After');
        self::assertGreaterThan(0, $wait);
        self::assertLessThanOrEqual(SignInThrottle::DECAY_SECONDS, $wait, 'the window opened with the first failure');
        self::assertSame(SignInThrottle::refused($wait)->getMessage(), $this->decode($response)['message'], 'the wait in words too');
        self::assertSame(401, $this->get('/api/admin/auth/me')->getStatusCode());
    }

    public function testSigningInClearsTheFailuresBeforeIt(): void
    {
        for ($i = 1; $i < SignInThrottle::MAX_ATTEMPTS; $i++) {
            $this->postJson('/api/admin/auth/login', ['username' => self::ADMIN_USERNAME, 'password' => 'wrong']);
        }
        self::assertSame(200, $this->postJson('/api/admin/auth/login', ['username' => self::ADMIN_USERNAME, 'password' => self::ADMIN_PASSWORD])->getStatusCode());
        $this->postJson('/api/admin/auth/logout');

        for ($i = 1; $i < SignInThrottle::MAX_ATTEMPTS; $i++) {
            self::assertSame(401, $this->postJson('/api/admin/auth/login', ['username' => self::ADMIN_USERNAME, 'password' => 'wrong'])->getStatusCode());
        }
    }

    public function testACustomerRecordIsNotALogin(): void
    {
        $this->customer(['username' => 'root']);

        self::assertSame(401, $this->postJson('/api/admin/auth/login', ['username' => 'root', 'password' => 'anything'])->getStatusCode());
    }

    public function testWithoutAConfiguredLoginNobodyGetsIn(): void
    {
        $this->configureAdmin(null);

        $response = $this->postJson('/api/admin/auth/login', ['username' => 'admin', 'password' => 'whatever']);

        self::assertSame([503, SignInRefusedException::NOT_SET_UP], [$response->getStatusCode(), $this->decode($response)['message']], 'the recovery sets one');
        self::assertFalse($this->service(AdminAccount::class)->configured());
    }

    public function testAPlainPasswordInTheConfigKeepsThePanelClosedAndTheLogSaysWhy(): void
    {
        $logs = $this->logs();
        $this->config(['admin.password' => 'plain-secret']);

        $refused = $this->postJson('/api/admin/auth/login', ['username' => self::ADMIN_USERNAME, 'password' => 'plain-secret']);

        self::assertSame(503, $refused->getStatusCode(), 'the right words do not open it: only a hash is a password');
        self::assertSame(SignInRefusedException::NOT_SET_UP, $this->decode($refused)['message']);
        self::assertFalse($this->service(AdminAccount::class)->configured());
        self::assertTrue($logs->hasWarningThatContains('is no password hash'), 'whoever edited the file learns why');
        self::assertTrue($logs->hasWarningThatContains((string) $this->app()->container()->get('config.file')), 'and which file it is');

        // Its hash kept in its place (what the recovery or the installer writes), the panel opens.
        $this->configureAdmin(self::ADMIN_USERNAME, 'plain-secret');
        self::assertSame(200, $this->postJson('/api/admin/auth/login', ['username' => self::ADMIN_USERNAME, 'password' => 'plain-secret'])->getStatusCode());
    }

    public function testAPasswordGuessedAtFromManyAddressesMakesTheAccountWaitAndASignInThatWorksDoesNotResetIt(): void
    {
        // Someone guessing at the owner's password from address after address, one try each — the account's limit
        // short by one, however the name was typed.
        $throttle = $this->service(SignInThrottle::class);
        for ($i = 1; $i < SignInThrottle::MAX_ACCOUNT_ATTEMPTS; $i++) {
            $throttle->failed(self::WAY, self::from("10.0.{$i}.1"), ' ' . strtoupper(self::ADMIN_USERNAME) . ' ');
        }

        // The owner signs in meanwhile, which closes their address's window — not the account's.
        self::assertSame(200, $this->postJson('/api/admin/auth/login', ['username' => self::ADMIN_USERNAME, 'password' => self::ADMIN_PASSWORD])->getStatusCode());
        $this->postJson('/api/admin/auth/logout');
        self::assertSame(401, $this->postJson('/api/admin/auth/login', ['username' => self::ADMIN_USERNAME, 'password' => 'guess'])->getStatusCode(), "the guesser's last try");

        $waiting = $this->postJson('/api/admin/auth/login', ['username' => self::ADMIN_USERNAME, 'password' => self::ADMIN_PASSWORD]);
        self::assertSame(429, $waiting->getStatusCode(), 'the account waits, its own owner too, from any address');
        self::assertGreaterThan(0, (int) $waiting->getHeaderLine('Retry-After'));
        self::assertSame(401, $this->postJson('/api/admin/auth/login', ['username' => 'someone-else', 'password' => 'guess'])->getStatusCode(), 'another name is not held up, nor this address');
        self::assertSame(0, $throttle->wait(self::WAY, self::from('192.0.2.7')), 'a fresh address may try');
    }

    public function testAThrottleThatCannotKeepCountLetsNobodySignInAndTheLogSaysWhere(): void
    {
        // A file stands where the throttle keeps its count, so it cannot write there.
        $folder = (string) $this->app()->container()->get('throttle.path');
        file_put_contents($folder, '');
        $logs = $this->logs();

        $owner = $this->postJson('/api/admin/auth/login', ['username' => self::ADMIN_USERNAME, 'password' => self::ADMIN_PASSWORD]);
        $agent = $this->postJson('/api/agent/auth/link', ['code' => 'any-code']);

        foreach ([$owner, $agent] as $refused) {
            self::assertSame([500, ErrorHandler::MESSAGES[500]], [$refused->getStatusCode(), $this->decode($refused)['message']], 'the right password and any link alike');
        }
        self::assertSame(401, $this->get('/api/admin/auth/me')->getStatusCode(), 'nobody signed in');
        self::assertTrue($logs->hasErrorThatContains($folder), 'the operator reads which folder to fix');
    }

    public function testLoginMeDashboardLogoutFlow(): void
    {
        $login = $this->postJson('/api/admin/auth/login', ['username' => ' ' . self::ADMIN_USERNAME . ' ', 'password' => self::ADMIN_PASSWORD]);
        self::assertSame(200, $login->getStatusCode(), (string) $login->getBody());
        self::assertSame(['name' => self::ADMIN_USERNAME, 'shop' => ['id' => 1, 'name' => 'فروشگاه اصلی', 'username' => null, 'status' => 'active']], $this->decode($login)['session']);
        self::assertSame([['id' => 1, 'name' => 'فروشگاه اصلی', 'username' => null, 'status' => 'active']], $this->decode($this->get('/api/admin/shops'))['shops'], 'the shops the owner may open: the main one, no agents yet');

        $me = $this->get('/api/admin/auth/me');
        self::assertSame(200, $me->getStatusCode());
        self::assertSame(self::ADMIN_USERNAME, $this->decode($me)['session']['name']);

        $dashboard = $this->get('/api/admin/dashboard');
        self::assertSame(200, $dashboard->getStatusCode());
        self::assertSame(0, $this->decode($dashboard)['kpis']['users_total'], 'the login is not a user');

        self::assertSame(204, $this->postJson('/api/admin/auth/logout')->getStatusCode());
        self::assertSame(401, $this->get('/api/admin/auth/me')->getStatusCode());
    }

    public function testChangingTheCredentialsEndsTheSession(): void
    {
        $this->loginAsAdmin();
        self::assertSame(200, $this->get('/api/admin/auth/me')->getStatusCode());

        $this->configureAdmin(self::ADMIN_USERNAME, 'a-new-password');

        self::assertSame(401, $this->get('/api/admin/auth/me')->getStatusCode(), 'a session opened under the old password is over');
        self::assertSame(200, $this->postJson('/api/admin/auth/login', ['username' => self::ADMIN_USERNAME, 'password' => 'a-new-password'])->getStatusCode());
    }

    /** A sign-in from that address. */
    private static function from(string $address): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/admin/auth/login', ['REMOTE_ADDR' => $address]);
    }
}
