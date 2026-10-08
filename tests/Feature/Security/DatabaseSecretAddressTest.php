<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Core\Config\ConfigFile;
use Tests\Fakes\ProbedDriver;
use Tests\HttpTestCase;

/**
 * The database password the settings screen keeps is never sent to another server: moved to another host, port or
 * socket — saved or only tried —, a blank password is asked for again instead of the stored one going there (the
 * panels' connections keep the same rule). A database that may have no password says so by clearing it.
 */
final class DatabaseSecretAddressTest extends HttpTestCase
{
    private ProbedDriver $mysql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
        $this->configFile(['APP_KEY' => 'base64:x', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'amobot', 'DB_USERNAME' => 'root', 'DB_PASSWORD' => 'kept secret']);
        $this->mysql = $this->probedDatabase('mysql');
    }

    public function testAMovedAddressAsksForThePasswordInsteadOfSendingTheStoredOne(): void
    {
        $moves = [
            'host' => ['host' => 'db.elsewhere.example', 'port' => '3306'],
            'port' => ['host' => '127.0.0.1', 'port' => '3307'],
            'socket' => ['host' => '127.0.0.1', 'port' => '3306', 'socket' => '/tmp/other.sock'],
        ];
        foreach ($moves as $what => $address) {
            foreach (['/api/admin/settings/config/database/test' => 'postJson', '/api/admin/settings/config/database' => 'putJson'] as $path => $method) {
                $response = $this->{$method}($path, $address + ['driver' => 'mysql', 'database' => 'amobot', 'username' => 'root']);

                self::assertSame(422, $response->getStatusCode(), "{$what} moved: {$path}");
                self::assertSame(['آدرس دیتابیس عوض شده است؛ رمز عبور را دوباره وارد کنید.'], $this->decode($response)['errors']['password']);
            }
        }

        self::assertSame([], $this->mysql->probed, 'the new address was never tried, so the stored password went nowhere');
        self::assertSame('kept secret', $this->service(ConfigFile::class)->get('DB_PASSWORD'));
    }

    public function testAPasswordTypedForTheNewAddressOrClearedIsWhatGoesThere(): void
    {
        $typed = $this->postJson('/api/admin/settings/config/database/test', ['driver' => 'mysql', 'host' => 'db.elsewhere.example', 'port' => '3306', 'database' => 'amobot', 'username' => 'root', 'password' => 'its own']);
        self::assertSame(200, $typed->getStatusCode());

        $cleared = $this->postJson('/api/admin/settings/config/database/test', ['driver' => 'mysql', 'host' => 'db.elsewhere.example', 'port' => '3306', 'database' => 'amobot', 'username' => 'root', 'clear_password' => true]);
        self::assertSame(200, $cleared->getStatusCode(), 'a database without a password');

        self::assertSame(['its own', ''], array_column($this->mysql->probed, 'DB_PASSWORD'));
    }

    public function testTheSameAddressKeepsTheStoredPasswordWhateverElseChanges(): void
    {
        $response = $this->postJson('/api/admin/settings/config/database/test', ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '۳۳۰۶', 'database' => 'other', 'username' => 'shop']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('kept secret', $this->mysql->probed[0]['DB_PASSWORD'], 'another database or user on the same server');
    }
}
