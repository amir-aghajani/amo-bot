<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config\ConfigFile;
use App\Core\Config\Repository as Config;
use App\Core\Database\Drivers\ProbeFailedException;
use App\Core\Database\Drivers\ProbeResult;
use App\Modules\Auth\Services\AdminAccount;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Settings\Exceptions\UnknownGroupException;
use App\Modules\Settings\Services\ConfigSettings;
use App\Modules\Telegram\Api\BotToken;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Fakes\ProbedDriver;
use Tests\HttpTestCase;

/**
 * The owner's settings screen edits config.php — the run's own here, the database drivers' probes answered by the test
 * (SQLite offered as a second driver to switch to) and Telegram by its fake.
 */
final class AdminConfigSettingsApiTest extends HttpTestCase
{
    private const TOKEN = '123456789:AAF' . 'yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy';

    private ConfigFile $file;

    private ProbedDriver $mysql;

    private ProbedDriver $sqlite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = $this->configFile([
            'APP_NAME' => 'AmoBot',
            'APP_URL' => 'http://localhost',
            'APP_KEY' => 'base64:x',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'amobot',
            'DB_USERNAME' => 'root',
            'DB_PASSWORD' => 'old pass',
            'TELEGRAM_BOT_TOKEN' => '',
            'LOG_LEVEL' => 'info',
        ]);
        $this->mysql = $this->probedDatabase('mysql');
        $this->sqlite = $this->probedDatabase('sqlite', installable: true);

        $this->loginAsAdmin();
    }

    public function testScreenStateNeverExposesSecrets(): void
    {
        $data = $this->decode($this->get('/api/admin/settings/config'));

        self::assertSame(['path' => 'config.php', 'exists' => true, 'writable' => true], $data['file']);
        self::assertSame(['name' => 'AmoBot', 'url' => 'http://localhost'], array_intersect_key($data['groups']['app'], ['name' => 0, 'url' => 0]));
        $config = $this->service(Config::class);
        self::assertSame($config->get('app.timezone'), $data['groups']['app']['timezone'], 'a setting missing from the file shows what the app runs with (config/*.php)');
        self::assertSame((int) $config->get('telegram.poll_timeout'), $data['groups']['telegram']['poll_timeout']);
        self::assertSame($config->get('session.cookie.secure'), $data['groups']['advanced']['session_secure_cookie']);
        self::assertSame($config->get('app.debug'), $data['groups']['app']['debug']);
        self::assertSame($config->get('shop.cron_token') !== '', $data['groups']['advanced']['cron_token']['set'], 'a secret the file lacks is still "set" when the app has one');
        self::assertSame(['set' => true, 'hint' => '••••••••'], $data['groups']['database']['values']['password']);
        self::assertSame(['set' => false, 'hint' => ''], $data['groups']['telegram']['token']);
        self::assertContains(['id' => 'Asia/Tehran', 'label' => '(UTC+03:30) وقت ایران'], $data['meta']['timezones']);
        self::assertLessThan(200, count($data['meta']['timezones']), 'Windows-style groups, not every IANA id');
        self::assertSame('http://localhost/webhooks/telegram/…', $data['meta']['webhook_url']);
        self::assertStringNotContainsString('old pass', (string) json_encode($data));
    }

    public function testASettingWrittenByHandReadsAsWhatItMeans(): void
    {
        $this->configFile(['TELEGRAM_POLL_TIMEOUT' => '25', 'SESSION_SECURE_COOKIE' => 'true', 'APP_DEBUG' => 'false']);

        $groups = $this->decode($this->get('/api/admin/settings/config'))['groups'];

        self::assertSame(25, $groups['telegram']['poll_timeout'], 'a number written as text is a number');
        self::assertTrue($groups['advanced']['session_secure_cookie'], 'and a switch written as a word a switch');
        self::assertFalse($groups['app']['debug']);
    }

    public function testAppGroupIsValidatedAndWritten(): void
    {
        $response = $this->unchecked()->putJson('/api/admin/settings/config/app', ['name' => '', 'url' => 'shop.example.com', 'debug' => 'maybe', 'timezone' => 'Mars/Olympus']);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['name', 'url', 'debug', 'timezone'], array_keys($this->decode($response)['errors']));

        // The shop's address goes into every address it hands out: nothing in it but where the shop is.
        foreach (['https://admin:pw@shop.example.com', 'https://shop.example.com/?ref=1', 'https://shop.example.com/#top'] as $stray) {
            $refused = $this->putJson('/api/admin/settings/config/app', ['name' => 'AmoBot', 'url' => $stray, 'debug' => false, 'timezone' => 'UTC']);
            self::assertSame([422, ['url']], [$refused->getStatusCode(), array_keys($this->decode($refused)['errors'])], $stray);
        }

        $response = $this->putJson('/api/admin/settings/config/app', ['name' => ' فروشگاه #1 "امو" \'$x\' ', 'url' => 'https://shop.example.com/', 'debug' => true, 'timezone' => 'Asia/Tehran']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        self::assertSame('فروشگاه #1 "امو" \'$x\'', $this->file->get('APP_NAME'), 'trimmed, and read back from the PHP file as typed — its quotes, a # and a $ are text');
        self::assertSame('https://shop.example.com', $this->file->get('APP_URL'), 'trailing slash dropped');
        self::assertTrue($this->file->get('APP_DEBUG'), 'a switch is kept as one');
        self::assertSame('Asia/Tehran', $this->file->get('APP_TIMEZONE'));
        self::assertSame('base64:x', $this->file->get('APP_KEY'), 'untouched settings stay');
        self::assertTrue($this->decode($response)['groups']['app']['debug']);
    }

    public function testTheDatabaseGroupIsTheDriversFieldsWithTheDriversOnOffer(): void
    {
        $database = $this->decode($this->get('/api/admin/settings/config'))['groups']['database'];

        self::assertSame('mysql', $database['driver']);
        self::assertSame(['mysql', 'sqlite'], array_column($database['drivers'], 'key'), 'every installable driver, in registration order');
        self::assertSame(['host', 'port', 'database', 'username', 'password', 'socket', 'prefix'], array_column($database['drivers'][0]['fields'], 'name'));
        self::assertSame(
            ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'amobot', 'username' => 'root', 'password' => ['set' => true, 'hint' => '••••••••'], 'socket' => '', 'prefix' => ''],
            $database['values'],
            'what the file says, else the field\'s default',
        );
    }

    public function testTheDriverConfigPhpNamesIsOfferedThoughTheInstallerOffersItNot(): void
    {
        $this->sqlite->installable = null;
        $offered = fn(): array => array_column($this->decode($this->get('/api/admin/settings/config'))['groups']['database']['drivers'], 'key');

        self::assertSame(['mysql'], $offered(), 'SQLite is no driver the installer offers');

        $this->file->set('DB_CONNECTION', 'sqlite');
        self::assertSame(['mysql', 'sqlite'], $offered(), 'but the one config.php names is, in the order they are registered');
    }

    public function testDatabaseGroupIsOnlyWrittenWhenTheConnectionWorks(): void
    {
        $this->mysql->answers[] = new ProbeFailedException('نام کاربری یا رمز عبور دیتابیس پذیرفته نشد.');

        $response = $this->putJson('/api/admin/settings/config/database', ['driver' => 'mysql', 'host' => 'db.internal', 'port' => '3307', 'database' => 'shop', 'username' => 'shop', 'password' => 'new-secret']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['نام کاربری یا رمز عبور دیتابیس پذیرفته نشد.'], $this->decode($response)['errors']['connection']);
        self::assertSame('127.0.0.1', $this->file->get('DB_HOST'), 'nothing written');
        self::assertSame('new-secret', $this->mysql->probed[0]['DB_PASSWORD'], 'the probe tried what was typed');

        $response = $this->putJson('/api/admin/settings/config/database', ['driver' => 'mysql', 'host' => 'db.internal', 'port' => '۳۳۰۷', 'database' => 'shop', 'username' => 'shop', 'password' => 'new-secret']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('db.internal', $this->file->get('DB_HOST'));
        self::assertSame(3307, $this->file->get('DB_PORT'), 'Persian digits are a port too');
        self::assertSame('new-secret', $this->file->get('DB_PASSWORD'));
        self::assertSame('mysql', $this->file->get('DB_CONNECTION'));
        self::assertSame('db.internal', $this->decode($response)['groups']['database']['values']['host']);

        $response = $this->putJson('/api/admin/settings/config/database', ['driver' => 'mysql', 'host' => 'db.internal', 'port' => '۳۳۰۷', 'database' => 'shop2', 'username' => 'shop']);

        self::assertSame(200, $response->getStatusCode(), 'the same address, typed in Persian digits again');
        self::assertSame(['shop2', 'new-secret'], [$this->file->get('DB_DATABASE'), $this->file->get('DB_PASSWORD')], 'an omitted secret keeps its value while the address stays');
    }

    public function testTheDriversFieldsAreCheckedBeforeAnythingIsTried(): void
    {
        $response = $this->putJson('/api/admin/settings/config/database', ['driver' => 'mysql', 'host' => 'bad host', 'port' => '70000', 'database' => 'shop db', 'username' => '', 'socket' => 'relative.sock', 'prefix' => 'amo-']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['host', 'port', 'database', 'username', 'socket', 'prefix'], array_keys($this->decode($response)['errors']));
        self::assertSame([], $this->mysql->probed);
    }

    public function testSecretsCanBeReplacedOrCleared(): void
    {
        $this->putJson('/api/admin/settings/config/database', ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'amobot', 'username' => 'root', 'clear_password' => true]);
        self::assertSame('', $this->file->get('DB_PASSWORD'));

        $this->putJson('/api/admin/settings/config/database', ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'amobot', 'username' => 'root', 'password' => 'p@ss \'#1\' $x \\']);
        self::assertSame('p@ss \'#1\' $x \\', $this->file->get('DB_PASSWORD'), 'whatever it holds, kept as typed');
    }

    public function testDatabaseTestDoesNotWrite(): void
    {
        $this->mysql->answers[] = new ProbeResult('MySQL 8.0.36', 3);

        $data = $this->decode($this->postJson('/api/admin/settings/config/database/test', ['driver' => 'mysql', 'host' => 'other', 'port' => '3306', 'database' => 'x', 'username' => 'y', 'password' => 'z']));

        self::assertSame(['version' => 'MySQL 8.0.36', 'tables' => 3], $data['database']);
        self::assertSame('127.0.0.1', $this->file->get('DB_HOST'));
    }

    public function testSwitchingTheDriverWritesItsOwnSettingsOnceItsDatabaseAnswers(): void
    {
        $this->sqlite->answers[] = new ProbeFailedException('پوشه وجود ندارد.');
        $refused = $this->putJson('/api/admin/settings/config/database', ['driver' => 'sqlite', 'path' => 'storage/missing/shop.sqlite']);
        self::assertSame(['پوشه وجود ندارد.'], $this->decode($refused)['errors']['connection']);
        self::assertSame(['mysql', 'amobot'], [$this->file->get('DB_CONNECTION'), $this->file->get('DB_DATABASE')], 'nothing written');

        $data = $this->decode($this->putJson('/api/admin/settings/config/database', ['driver' => 'sqlite', 'path' => 'storage/shop.sqlite']));

        self::assertSame(['sqlite', 'storage/shop.sqlite'], [$this->file->get('DB_CONNECTION'), $this->file->get('DB_DATABASE')]);
        self::assertSame('127.0.0.1', $this->file->get('DB_HOST'), "the other driver's settings stay for a switch back");
        self::assertSame(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => 'storage/shop.sqlite'], $this->sqlite->probed[1]);
        self::assertSame([], $this->mysql->probed);
        self::assertSame(['driver' => 'sqlite', 'values' => ['path' => 'storage/shop.sqlite']], array_diff_key($data['groups']['database'], ['drivers' => true]));
    }

    public function testADriverSwitchedToStartsWithNothingStored(): void
    {
        $this->file->set('DB_CONNECTION', 'sqlite');

        $response = $this->putJson('/api/admin/settings/config/database', ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'amobot', 'username' => 'root']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['mysql', ''], [$this->file->get('DB_CONNECTION'), $this->file->get('DB_PASSWORD')], 'a password the screen never showed is not kept');
    }

    public function testTheRunningDriversFieldsTheFileDoesNotNameShowWhatTheShopRunsWith(): void
    {
        $this->configFile(['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'amobot']);
        $this->config(['database.driver' => 'mysql', 'database.connection' => ['DB_HOST' => 'db.internal', 'DB_DATABASE' => 'other']]);

        $values = $this->decode($this->get('/api/admin/settings/config'))['groups']['database']['values'];

        self::assertSame(['db.internal', 'amobot', 3306], [$values['host'], $values['database'], $values['port']], "the file's setting first, then what the shop runs with, then the field's default");
    }

    public function testAFileNamingADatabaseNoDriverHasShowsTheFirstOffered(): void
    {
        $this->configFile(['DB_CONNECTION' => 'oracle']);

        self::assertSame('mysql', $this->decode($this->get('/api/admin/settings/config'))['groups']['database']['driver']);
    }

    public function testADriverWhosePhpExtensionIsMissingIsRefusedBeforeItsDatabaseIsTried(): void
    {
        $this->mysql->extensions = ['amobot_missing'];

        $response = $this->putJson('/api/admin/settings/config/database', ['driver' => 'mysql', 'host' => 'db.internal', 'port' => '3306', 'database' => 'shop', 'username' => 'shop', 'password' => 'its own']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([ProbeFailedException::missingExtension('amobot_missing')->getMessage()], $this->decode($response)['errors']['connection']);
        self::assertSame([], $this->mysql->probed);
        self::assertSame('127.0.0.1', $this->file->get('DB_HOST'), 'nothing written');
    }

    public function testOnlyADriverTheScreenOffersCanBePicked(): void
    {
        $response = $this->unchecked()->putJson('/api/admin/settings/config/database', ['driver' => 'oracle', 'host' => 'db']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['driver'], array_keys($this->decode($response)['errors']));
        self::assertSame('mysql', $this->file->get('DB_CONNECTION'));
    }

    public function testTelegramTokenIsCheckedAgainstGetMe(): void
    {
        $this->telegram()->fail(401, 'Unauthorized');
        $refused = $this->postJson('/api/admin/settings/config/telegram/test', ['token' => self::TOKEN]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame([BotToken::REFUSED], $this->decode($refused)['errors']['token']);

        $this->telegram()->reply(['id' => 123456789, 'is_bot' => true, 'first_name' => 'Amo Shop', 'username' => 'amo_shop_bot']);
        $data = $this->decode($this->postJson('/api/admin/settings/config/telegram/test', ['token' => self::TOKEN]));
        self::assertSame(['id' => 123456789, 'username' => 'amo_shop_bot', 'name' => 'Amo Shop'], $data['bot']);
        self::assertSame([self::TOKEN, self::TOKEN], [$this->telegram()->tokenOf(0), $this->telegram()->tokenOf(1)], 'asked with the token typed');

        $malformed = $this->postJson('/api/admin/settings/config/telegram/test', ['token' => 'not-a-token']);
        self::assertSame([BotToken::MALFORMED], $this->decode($malformed)['errors']['token']);
        self::assertCount(2, $this->telegram()->calls(), 'a token that does not look like one is refused before Telegram is asked');

        $none = $this->postJson('/api/admin/settings/config/telegram/test', []);
        self::assertSame(['token'], array_keys($this->decode($none)['errors']), 'nothing typed and nothing kept');
    }

    public function testTelegramGroupStoresTokenAndUsername(): void
    {
        $response = $this->putJson('/api/admin/settings/config/telegram', ['token' => self::TOKEN, 'username' => '@Amo_Shop_Bot', 'api_url' => 'https://api.telegram.org/', 'poll_timeout' => '۲۵', 'webhook_secret' => '']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(self::TOKEN, $this->file->get('TELEGRAM_BOT_TOKEN'));
        self::assertSame('Amo_Shop_Bot', $this->file->get('TELEGRAM_BOT_USERNAME'), 'leading @ dropped');
        self::assertSame('https://api.telegram.org', $this->file->get('TELEGRAM_API_URL'));
        self::assertSame(25, $this->file->get('TELEGRAM_POLL_TIMEOUT'), 'Persian digits are a number too, kept as one');
        self::assertSame(['set' => true, 'hint' => '123456789:••••••••yyyy'], $this->decode($response)['groups']['telegram']['token']);
        self::assertSame(25, $this->decode($response)['groups']['telegram']['poll_timeout']);
    }

    public function testTheBotsTokenGoesNowhereButTheBotApiAddressItWasSavedWith(): void
    {
        $this->file->setMany(['TELEGRAM_BOT_TOKEN' => self::TOKEN, 'TELEGRAM_API_URL' => 'https://api.telegram.org']);
        $telegram = ['username' => 'amo_shop_bot', 'poll_timeout' => '30'];

        foreach (['https://evil.example', 'http://api.telegram.org', 'https://api.telegram.org:8443'] as $elsewhere) {
            $moved = $this->putJson('/api/admin/settings/config/telegram', $telegram + ['api_url' => $elsewhere, 'token' => '', 'current_password' => self::ADMIN_PASSWORD]);
            self::assertSame([422, ['token' => [ConfigSettings::TOKEN_MOVED]]], [$moved->getStatusCode(), $this->decode($moved)['errors']], "{$elsewhere}: another host, a scheme in the clear, another port");
        }
        self::assertSame(['https://api.telegram.org', self::TOKEN], [$this->file->get('TELEGRAM_API_URL'), $this->file->get('TELEGRAM_BOT_TOKEN')], 'nothing written');

        // Asked about now, the kept token goes to the address saved — never to the one only tried.
        $this->telegram()->reply(['id' => 123456789, 'is_bot' => true, 'first_name' => 'Amo Shop', 'username' => 'amo_shop_bot']);
        self::assertSame(200, $this->postJson('/api/admin/settings/config/telegram/test', [])->getStatusCode());
        self::assertSame(['api.telegram.org', self::TOKEN], [$this->telegram()->history[0]['request']->getUri()->getHost(), $this->telegram()->tokenOf(0)]);

        self::assertSame(200, $this->putJson('/api/admin/settings/config/telegram', $telegram + ['api_url' => 'https://api.telegram.org/', 'token' => ''])->getStatusCode(), 'the same address, however it is written');
        self::assertSame(200, $this->putJson('/api/admin/settings/config/telegram', $telegram + ['api_url' => 'https://botapi.example', 'token' => self::TOKEN, 'current_password' => self::ADMIN_PASSWORD])->getStatusCode(), 'typed again for the new address');
        self::assertSame(['https://botapi.example', self::TOKEN], [$this->file->get('TELEGRAM_API_URL'), $this->file->get('TELEGRAM_BOT_TOKEN')]);
        self::assertSame(200, $this->putJson('/api/admin/settings/config/telegram', $telegram + ['api_url' => 'https://api.telegram.org', 'clear_token' => true, 'current_password' => self::ADMIN_PASSWORD])->getStatusCode(), 'or cleared');
        self::assertSame('', $this->file->get('TELEGRAM_BOT_TOKEN'));
    }

    /**
     * Moving the Bot API's address to another origin sends every bot's token there — agents' too, which no screen shows —:
     * the save asks the owner's current password, a wrong one a failed sign-in counted with the login's; the same origin,
     * and every other group, ask none.
     */
    public function testMovingTheBotApiAddressAsksTheOwnersCurrentPassword(): void
    {
        $this->file->setMany(['TELEGRAM_BOT_TOKEN' => '', 'TELEGRAM_API_URL' => 'https://api.telegram.org']);
        $moving = ['username' => '', 'poll_timeout' => '30', 'api_url' => 'https://botapi.example'];

        $none = $this->putJson('/api/admin/settings/config/telegram', ['poll_timeout' => '0'] + $moving);
        self::assertSame([422, ['poll_timeout', 'current_password']], [$none->getStatusCode(), array_keys($this->decode($none)['errors'])], 'with the form\'s other refusals, at once');
        self::assertSame(['current_password' => ['رمز عبور فعلی را وارد کنید.']], array_intersect_key($this->decode($none)['errors'], ['current_password' => true]));

        $throttle = $this->service(SignInThrottle::class);
        $here = (new ServerRequestFactory())->createServerRequest('PUT', 'http://localhost/');
        for ($failed = 1; $failed < SignInThrottle::MAX_ATTEMPTS; $failed++) {
            $throttle->failed(AdminAccount::WAY, $here, self::ADMIN_USERNAME);
        }
        $wrong = $this->putJson('/api/admin/settings/config/telegram', $moving + ['current_password' => 'not it']);
        self::assertSame([422, ['current_password' => [AdminAccount::WRONG_PASSWORD]]], [$wrong->getStatusCode(), $this->decode($wrong)['errors']]);
        $spent = $this->putJson('/api/admin/settings/config/telegram', $moving + ['current_password' => self::ADMIN_PASSWORD]);
        self::assertSame(429, $spent->getStatusCode(), "counted with the login's failures: the address waits");
        self::assertSame('https://api.telegram.org', $this->file->get('TELEGRAM_API_URL'), 'nothing written');

        $throttle->passed(AdminAccount::WAY, $here);
        self::assertSame(200, $this->putJson('/api/admin/settings/config/telegram', $moving + ['current_password' => self::ADMIN_PASSWORD])->getStatusCode());
        self::assertSame('https://botapi.example', $this->file->get('TELEGRAM_API_URL'));
        self::assertSame(200, $this->putJson('/api/admin/settings/config/telegram', ['api_url' => 'https://botapi.example/v2'] + $moving)->getStatusCode(), 'another path of the same origin asks none');
        self::assertSame(200, $this->putJson('/api/admin/settings/config/app', ['name' => 'AmoBot', 'url' => 'https://shop.example', 'debug' => false, 'timezone' => 'UTC'])->getStatusCode(), 'nor does another group');
    }

    public function testTheWebhookSecretIsCheckedLikeTheOtherSecrets(): void
    {
        $telegram = ['token' => '', 'username' => '', 'api_url' => 'https://api.telegram.org', 'poll_timeout' => '30'];

        $response = $this->putJson('/api/admin/settings/config/telegram', $telegram + ['webhook_secret' => 'has spaces & symbols']);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['webhook_secret'], array_keys($this->decode($response)['errors']));

        $short = $this->putJson('/api/admin/settings/config/telegram', $telegram + ['webhook_secret' => 'short_one']);
        self::assertSame(422, $short->getStatusCode(), 'a guessable secret is no lock');
        self::assertStringContainsString('16', $this->decode($short)['errors']['webhook_secret'][0]);

        self::assertSame(200, $this->putJson('/api/admin/settings/config/telegram', $telegram + ['webhook_secret' => 'ok_secret-1-long-enough'])->getStatusCode());
        self::assertSame('ok_secret-1-long-enough', $this->file->get('TELEGRAM_WEBHOOK_SECRET'));

        self::assertSame(200, $this->putJson('/api/admin/settings/config/telegram', $telegram)->getStatusCode());
        self::assertSame('ok_secret-1-long-enough', $this->file->get('TELEGRAM_WEBHOOK_SECRET'), 'omitted: kept');

        self::assertSame(200, $this->putJson('/api/admin/settings/config/telegram', $telegram + ['clear_webhook_secret' => true])->getStatusCode());
        self::assertSame('', $this->file->get('TELEGRAM_WEBHOOK_SECRET'));
    }

    public function testAdvancedGroupIsValidatedFieldByField(): void
    {
        $response = $this->putJson('/api/admin/settings/config/advanced', ['log_level' => 'verbose', 'session_lifetime' => '4', 'session_secure_cookie' => false, 'http_timeout' => '121', 'cron_token' => 'short']);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['log_level', 'session_lifetime', 'http_timeout', 'cron_token'], array_keys($this->decode($response)['errors']));
        self::assertSame(['تایم‌اوت درخواست‌های خروجی باید عددی بین 5 تا 120 ثانیه باشد.'], $this->decode($response)['errors']['http_timeout']);
        self::assertSame('info', $this->file->get('LOG_LEVEL'), 'nothing written');

        $response = $this->putJson('/api/admin/settings/config/advanced', ['log_level' => 'debug', 'session_lifetime' => '43200', 'session_secure_cookie' => true, 'http_timeout' => '۵', 'cron_token' => 'abcdefghijklmnop']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['debug', 43200, true, 5, 'abcdefghijklmnop'], [$this->file->get('LOG_LEVEL'), $this->file->get('SESSION_LIFETIME'), $this->file->get('SESSION_SECURE_COOKIE'), $this->file->get('OUTGOING_HTTP_TIMEOUT'), $this->file->get('CRON_TOKEN')]);
    }

    public function testAGroupTheScreenDoesNotHaveIsNoneToSave(): void
    {
        self::assertSame(404, $this->unchecked()->putJson('/api/admin/settings/config/nope', [])->getStatusCode());

        $this->expectException(UnknownGroupException::class);
        $this->service(ConfigSettings::class)->update('nope', [], (new ServerRequestFactory())->createServerRequest('PUT', 'http://localhost/'));
    }

    public function testTheCronTokenIsMaskedOnTheScreenAndWholeOnlyWhenAskedFor(): void
    {
        $this->config(['app.url' => 'https://shop.example.com/store', 'app.base_path' => '/store']);
        self::assertSame(['url' => null], $this->decode($this->get('/api/admin/settings/config/cron-url')));

        $this->file->set('CRON_TOKEN', 'abcdefghijklmnop');
        $screen = $this->decode($this->get('/api/admin/settings/config'));

        self::assertSame(['set' => true, 'hint' => '••••••mnop'], $screen['groups']['advanced']['cron_token']);
        self::assertSame('https://shop.example.com/store/cron/…', $screen['meta']['cron_url']);
        self::assertStringNotContainsString('abcdefghijklmnop', (string) json_encode($screen), 'the whole token is never on the screen');
        self::assertSame(['url' => 'https://shop.example.com/store/cron/abcdefghijklmnop'], $this->decode($this->get('/api/admin/settings/config/cron-url')), 'on the address the shop is reached at — its sub-folder once, never doubled by the request prefix');
    }

    public function testUnwritableFileIsReportedNotSwallowed(): void
    {
        // A folder stands where config.php is written.
        $path = $this->file->path();
        unlink($path);
        mkdir($path);
        $logs = $this->logs();

        $response = $this->putJson('/api/admin/settings/config/advanced', ['log_level' => 'debug', 'session_lifetime' => '60', 'session_secure_cookie' => false, 'http_timeout' => '30']);

        self::assertSame(422, $response->getStatusCode());
        $error = $this->decode($response);
        self::assertSame([ConfigFile::UNWRITABLE], $error['errors']['file']);
        self::assertSame($error['errors']['file'][0], $error['message'], 'in the admin\'s words, never the server\'s path');
        self::assertTrue($logs->hasErrorThatContains('could not be written'), 'the path is the log\'s');
    }
}
