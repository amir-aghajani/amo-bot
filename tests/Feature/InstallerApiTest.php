<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config\ConfigFile;
use App\Core\Database\DatabaseManager;
use App\Core\Database\Drivers\ProbeFailedException;
use App\Core\Installation;
use App\Modules\Auth\Services\AdminAccount;
use App\Modules\Installer\Controllers\InstallerController;
use App\Modules\Installer\Middleware\InstallKeyMiddleware;
use App\Modules\Installer\Services\Installer;
use App\Modules\Installer\Services\InstallKey;
use App\Modules\Telegram\Api\BotToken;
use App\Support\Password;
use App\Support\Requirements;
use Psr\Http\Message\ResponseInterface;
use Tests\Fakes\ProbedDriver;
use Tests\HttpTestCase;

/**
 * The web installer over /api/install, on a fresh shop of the run's own — no config.php, no login, no lock —, opened with the
 * install key the host's files hold, the MySQL driver's probe answered by the test (the tables are the test
 * database's) and Telegram by its fake. Every step says what keeps it from being done — a database that does not
 * answer, a file the server may not write — in the owner's words, and the installation ends only with all of it done.
 */
final class InstallerApiTest extends HttpTestCase
{
    private const TOKEN = '123456789:AAF' . 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    private ProbedDriver $mysql;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installed(false);
        $this->configureAdmin(null);
        // A fresh upload runs on MySQL, the driver config/database.php starts from.
        $this->config(['database.driver' => 'mysql', 'database.connection' => []]);
        $this->mysql = $this->probedDatabase('mysql');
    }

    public function testTheInstallerOpensOnlyWithTheKeyTheHostsFilesHold(): void
    {
        $locked = $this->get('/api/install');
        self::assertSame(403, $locked->getStatusCode());
        self::assertSame([InstallKeyMiddleware::MISSING], $this->decode($locked)['errors']['key']);
        $key = (string) file_get_contents((string) $this->app()->container()->get('install.key'));
        self::assertMatchesRegularExpression('/^[2-9A-Z]{4}(-[2-9A-Z]{4}){3}\n$/', $key, 'made by the first request, for the owner to read off the host');

        $wrong = $this->send('GET', '/api/install', null, [InstallKey::HEADER => 'AAAA-BBBB-CCCC-DDDD']);
        self::assertSame([InstallKeyMiddleware::WRONG], $this->decode($wrong)['errors']['key']);
        self::assertSame(403, $this->send('POST', '/api/install/admin', ['username' => 'boss', 'password' => 'long-enough', 'password_confirmation' => 'long-enough'], ['X-Requested-With' => 'XMLHttpRequest'])->getStatusCode());
        self::assertFalse($this->file()->exists(), 'nothing done without the key');

        self::assertSame(200, $this->send('GET', '/api/install', null, [InstallKey::HEADER => strtolower(trim($key))])->getStatusCode(), 'as typed: case and spaces do not matter');
        self::assertSame(trim($key), $this->service(InstallKey::class)->current(), 'the same key until the installation ends');
    }

    public function testAStorageTheKeyCannotBeWrittenToSaysSo(): void
    {
        $this->swap(InstallKey::class, new InstallKey($this->scratchDir() . '/missing/install-key.txt'), InstallKeyMiddleware::class);

        $response = $this->get('/api/install');

        self::assertSame([503, InstallKeyMiddleware::UNWRITABLE], [$response->getStatusCode(), $this->decode($response)['message']]);
    }

    public function testTheStatusSaysWhatIsLeft(): void
    {
        $status = $this->decode($this->install('GET', '/api/install'));

        self::assertTrue($status['ready'], 'this machine has everything');
        self::assertSame(array_column($this->service(Requirements::class)->check(), null, 'name'), array_column($status['requirements'], null, 'name'), "the host's requirements, each named and worded");
        self::assertSame(['mysql'], array_column($status['database']['drivers'], 'key'), 'the installable drivers');
        self::assertSame(
            ['driver' => 'mysql', 'values' => ['host' => 'localhost', 'port' => 3306, 'database' => 'amobot', 'username' => 'root', 'password' => ['set' => false, 'hint' => ''], 'socket' => '', 'prefix' => ''], 'connected' => false, 'tables' => false, 'error' => null],
            array_diff_key($status['database'], ['drivers' => true]),
            'the fields\' defaults, nothing tried before the database step',
        );
        self::assertSame([], $this->mysql->probed);
        self::assertSame(['configured' => false, 'username' => ''], $status['admin']);
        self::assertFalse($status['telegram']['configured']);
    }

    public function testTheStatusSaysWhyTheDatabaseTheFileNamesDoesNotAnswer(): void
    {
        $this->configFile(['DB_CONNECTION' => 'mysql', 'DB_HOST' => 'db.internal']);
        $this->mysql->answers[] = new ProbeFailedException('سرور دیتابیس جواب نداد.');

        $database = $this->decode($this->install('GET', '/api/install'))['database'];
        self::assertSame([false, false, 'سرور دیتابیس جواب نداد.'], [$database['connected'], $database['tables'], $database['error']]);

        $this->configFile(['DB_CONNECTION' => 'oracle']);
        $database = $this->decode($this->install('GET', '/api/install'))['database'];
        self::assertSame(['mysql', false], [$database['driver'], $database['connected']], 'a database no driver has: the first offered, not connected');
        self::assertStringContainsString('DB_CONNECTION=oracle', (string) $database['error']);
    }

    public function testTheTablesWaitForADatabase(): void
    {
        $refused = $this->install('POST', '/api/install/tables');

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['tables'], array_keys($this->decode($refused)['errors']));
    }

    public function testTablesTheDatabaseRefusesAreSaidInItsOwnWords(): void
    {
        $this->install('POST', '/api/install/database', $this->database());
        // A table is missing, and an index of its name stands in the way of making it.
        $this->db()->getSchemaBuilder()->drop('sequences');
        $this->db()->statement('CREATE INDEX sequences ON settings (key)');
        $logs = $this->logs();

        $refused = $this->install('POST', '/api/install/tables');

        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('already an index named sequences', $this->decode($refused)['errors']['tables'][0], "the database's words: what the owner fixes it by");
        self::assertTrue($logs->hasErrorThatContains('The tables could not be made'));
    }

    public function testAConfigFileTheServerMayNotWriteIsSaid(): void
    {
        // A folder stands where config.php would be written.
        mkdir($this->file()->path());

        $refused = $this->install('POST', '/api/install/database', $this->database());

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['file'], array_keys($this->decode($refused)['errors']));
        self::assertSame([], $this->mysql->probed, 'nothing tried without a file to write it to');
    }

    public function testAPanelLoginTheServerMayNotWriteIsSaidAndNothingIsKept(): void
    {
        // A folder stands where config.php would be written.
        mkdir($this->file()->path());

        $refused = $this->install('POST', '/api/install/admin', ['username' => 'boss', 'password' => 'long-enough', 'password_confirmation' => 'long-enough']);

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['file'], array_keys($this->decode($refused)['errors']));
        self::assertFalse($this->service(AdminAccount::class)->configured(), 'no login in use that the file does not hold');
    }

    public function testTheDatabaseIsWrittenOnlyOnceItAnswersAndItsTablesAreMade(): void
    {
        $this->mysql->answers[] = new ProbeFailedException('نام کاربری یا رمز عبور دیتابیس پذیرفته نشد.');
        $refused = $this->install('POST', '/api/install/database', $this->database());
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('اتصال به دیتابیس برقرار نشد', $this->decode($refused)['message']);
        self::assertSame(['نام کاربری یا رمز عبور دیتابیس پذیرفته نشد.'], $this->decode($refused)['errors']['connection']);
        $env = $this->file();
        self::assertStringStartsWith('base64:', (string) $env->get('APP_KEY'), 'config.php is made, with a key of its own');
        self::assertNotSame('shop_db', $env->get('DB_DATABASE'), 'nothing written that does not work');

        $status = $this->decode($this->install('POST', '/api/install/database', $this->database()));
        self::assertSame(['mysql', 'shop_db', 'shop_user', 'p@ss'], [$env->get('DB_CONNECTION'), $env->get('DB_DATABASE'), $env->get('DB_USERNAME'), $env->get('DB_PASSWORD')]);
        self::assertTrue($status['database']['connected']);
        self::assertSame('shop_db', $status['database']['values']['database']);
        self::assertSame('shop_db', end($this->mysql->probed)['DB_DATABASE'], 'the status asks the database config.php names now');

        $tables = $this->install('POST', '/api/install/tables');
        self::assertSame(200, $tables->getStatusCode());
        self::assertTrue($this->decode($tables)['database']['tables']);

        self::assertSame(403, $this->send('POST', '/api/install/tables', null, [InstallKey::HEADER => $this->service(InstallKey::class)->current()])->getStatusCode(), 'a write needs the header a browser cannot send cross-site');
    }

    public function testAnEmptyDatabasePasswordIsAPasswordToo(): void
    {
        $this->install('POST', '/api/install/database', $this->database());
        $this->install('POST', '/api/install/database', ['password' => ''] + $this->database());

        self::assertSame('', $this->file()->get('DB_PASSWORD'));
    }

    public function testOnlyADriverTheInstallerOffersIsTaken(): void
    {
        $this->install('POST', '/api/install/database', $this->database());
        self::assertSame(['mysql'], array_column($this->decode($this->install('GET', '/api/install'))['database']['drivers'], 'key'), 'a driver that is not installable is not offered');

        $refused = $this->install('POST', '/api/install/database', ['driver' => 'sqlite', 'path' => 'storage/shop.sqlite']);

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['driver'], array_keys($this->decode($refused)['errors']));
        self::assertSame('mysql', $this->file()->get('DB_CONNECTION'));
    }

    public function testThePanelsLoginIsCheckedAndWrittenHashed(): void
    {
        $refused = $this->install('POST', '/api/install/admin', ['username' => 'a b', 'password' => 'short', 'password_confirmation' => 'short']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['username', 'password'], array_keys($this->decode($refused)['errors']));

        $mismatch = $this->install('POST', '/api/install/admin', ['username' => 'boss', 'password' => 'long-enough', 'password_confirmation' => 'something else']);
        self::assertSame(['password_confirmation'], array_keys($this->decode($mismatch)['errors']));

        $beyondBcrypt = str_repeat('x', Password::MAX_BYTES + 1);
        $tooLong = $this->install('POST', '/api/install/admin', ['username' => 'boss', 'password' => $beyondBcrypt, 'password_confirmation' => $beyondBcrypt]);
        self::assertSame(['password'], array_keys($this->decode($tooLong)['errors']), 'what bcrypt would cut is refused, not cut');

        $status = $this->decode($this->install('POST', '/api/install/admin', ['username' => 'boss', 'password' => 'long-enough', 'password_confirmation' => 'long-enough']));
        self::assertSame(['configured' => true, 'username' => 'boss'], $status['admin']);
        self::assertSame('boss', $this->file()->get('ADMIN_USERNAME'));
        self::assertTrue(password_verify('long-enough', (string) $this->file()->get('ADMIN_PASSWORD_HASH')), 'never the plain password');
    }

    public function testTheSiteAndTheBotsTokenAreWrittenOnceTelegramKnowsIt(): void
    {
        $refused = $this->install('POST', '/api/install/site', ['name' => 'فروشگاه امو', 'url' => 'shop.example.com']);
        self::assertSame(['url'], array_keys($this->decode($refused)['errors']));

        $this->telegram()->fail(401, 'Unauthorized');
        $unknown = $this->install('POST', '/api/install/site', ['name' => 'فروشگاه امو', 'url' => 'https://shop.example.com', 'token' => self::TOKEN]);
        self::assertSame([BotToken::REFUSED], $this->decode($unknown)['errors']['token']);
        $env = $this->file();
        self::assertNull($env->get('TELEGRAM_BOT_TOKEN'), 'a token Telegram does not know is not kept');

        $this->telegram()->reply(['id' => 123456789, 'is_bot' => true, 'first_name' => 'Amo', 'username' => 'amo_shop_bot']);
        $status = $this->decode($this->install('POST', '/api/install/site', ['name' => 'فروشگاه امو', 'url' => 'https://shop.example.com/shop/', 'token' => self::TOKEN]));

        self::assertSame(['فروشگاه امو', 'https://shop.example.com/shop', self::TOKEN, 'amo_shop_bot'], [$env->get('APP_NAME'), $env->get('APP_URL'), $env->get('TELEGRAM_BOT_TOKEN'), $env->get('TELEGRAM_BOT_USERNAME')]);
        self::assertSame(['configured' => true, 'username' => 'amo_shop_bot'], $status['telegram']);
        self::assertNull($env->get('APP_DEBUG'), 'the rest left at its defaults');
    }

    public function testFinishingNeedsTheTablesAndTheLoginAndEndsTheInstallerAndItsKey(): void
    {
        $early = $this->install('POST', '/api/install/finish');
        self::assertSame(422, $early->getStatusCode());
        self::assertSame(['tables', 'admin'], array_keys($this->decode($early)['errors']));
        self::assertFalse($this->service(Installation::class)->isInstalled());

        $this->install('POST', '/api/install/database', $this->database());
        $this->install('POST', '/api/install/tables');
        $this->install('POST', '/api/install/admin', ['username' => 'boss', 'password' => 'long-enough', 'password_confirmation' => 'long-enough']);

        self::assertSame(['installed' => true], $this->decode($this->install('POST', '/api/install/finish')));
        self::assertTrue($this->service(Installation::class)->isInstalled());
        self::assertFileDoesNotExist((string) $this->app()->container()->get('install.key'), 'the key opens nothing any more');
        self::assertSame(404, $this->get('/api/install')->getStatusCode(), 'the installer is gone');
        self::assertTrue($this->decode($this->get('/api/app'))['installed']);
    }

    public function testFinishingNeedsWhatTheHostLacks(): void
    {
        $this->install('POST', '/api/install/database', $this->database());
        $this->configureAdmin();
        // A host whose config.php the web server may not write.
        $this->swap(Requirements::class, new Requirements($this->app(), $this->service(DatabaseManager::class), new ConfigFile($this->scratchDir() . '/missing/config.php', $this->scratchDir() . '/config.lock')), Installer::class, InstallerController::class);

        $refused = $this->install('POST', '/api/install/finish');

        self::assertSame(['requirements'], array_keys($this->decode($refused)['errors']));
        self::assertFalse($this->service(Installation::class)->isInstalled());
    }

    public function testALockTheServerMayNotWriteLeavesTheInstallationOpen(): void
    {
        // A folder stands where the lock would be written.
        mkdir((string) $this->app()->container()->get('installation.lock'));
        $this->install('POST', '/api/install/database', $this->database());
        $this->configureAdmin();

        $refused = $this->install('POST', '/api/install/finish');

        self::assertSame(['requirements'], array_keys($this->decode($refused)['errors']));
        self::assertFalse($this->service(Installation::class)->isInstalled());
        self::assertSame(200, $this->install('GET', '/api/install')->getStatusCode(), 'the installer and its key, there to finish with');
    }

    /**
     * An installer request as the installer page makes it: with the key the host's files hold, and the CSRF header.
     *
     * @param array<string, mixed>|null $body
     */
    private function install(string $method, string $path, ?array $body = null): ResponseInterface
    {
        return $this->send($method, $path, $body, ['X-Requested-With' => 'XMLHttpRequest', InstallKey::HEADER => $this->service(InstallKey::class)->current()]);
    }

    /** The run's own config.php, as the installer writes it. */
    private function file(): ConfigFile
    {
        return $this->service(ConfigFile::class);
    }

    /** @return array<string, string> */
    private function database(): array
    {
        return ['driver' => 'mysql', 'host' => 'localhost', 'port' => '3306', 'database' => 'shop_db', 'username' => 'shop_user', 'password' => 'p@ss'];
    }
}
