<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Application;
use App\Core\Config\ConfigFile;
use App\Core\Config\Repository as Config;
use App\Core\Database\Drivers\DatabaseDriver;
use App\Core\Drivers\Registry;
use App\Core\Http\RequestId;
use App\Core\Scheduling\Budget;
use App\Core\Support\FileCache;
use App\Core\Support\Files;
use App\Core\Support\Sleeper;
use App\Modules\Bots\CurrentBot;
use App\Modules\Providers\ProviderRegistry;
use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Support\PhpVersion;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Tests\Fakes\FakePanelDriver;
use Tests\Fakes\FakeProvider;
use Tests\Fakes\NullSleeper;
use Tests\Fakes\ProbedDriver;
use Tests\Fakes\RecordingMailTransport;
use Tests\Support\FakeAban;
use Tests\Support\FakeAqayePardakht;
use Tests\Support\FakeAtlasPay;
use Tests\Support\FakeCubePay;
use Tests\Support\FakeGoogleLogin;
use Tests\Support\FakePanel;
use Tests\Support\FakePlisio;
use Tests\Support\FakeTelegram;
use Tests\Support\FakeTelegramLogin;
use Tests\Support\FakeTurnstile;
use Tests\Support\FakeVariza;
use Tests\Support\FakeZarinpal;

/**
 * The base of every test: one booted application per process, kept off this machine (its own config.php, files and
 * no network). A test gets its doubles by swapping a leaf, never what was built around it: a fake transport under the
 * HTTP clients (panelHttp(), telegramLogin(), googleLogin(), turnstile(), cubePay(), zarinpal(), atlasPay(), aban(),
 * plisio(), aqayePardakht(), variza() — outgoingFake() for any other —, telegram()), the mail server (mail()), a log
 * handler (logs()), config.php (configFile()), a database probe (probedDatabase()), configuration (config()).
 * Everything is put back in tearDown.
 */
abstract class TestCase extends BaseTestCase
{
    /** The files the app writes, by container entry: in the run's own folder, never storage/. */
    protected const FILES = [
        'installation.lock' => 'installed.lock',
        'install.key' => 'install-key.txt',
        'recovery.key' => 'recovery-key.txt',
        'config.file' => 'config.php',
        'config.lock' => 'config.lock',
        'schedule.state' => 'schedule.json',
        'throttle.path' => 'throttle',
        'files.cache' => 'files',
        'jwks.path' => 'jwks',
        'qr.backgrounds' => 'qr',
        'receipts.path' => 'receipts',
        'tickets.path' => 'tickets',
        'poller.lock' => 'bot-poll.lock',
    ];

    /**
     * The config.php every test process boots with (never this machine's): in-memory SQLite, a fixed APP_KEY, UTC, no
     * bot token, no panel login — every setting that changes behaviour pinned.
     */
    private const CONFIG = [
        'APP_DEBUG' => true,
        'APP_URL' => 'http://localhost',
        'APP_BASE_PATH' => '',
        'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'APP_TIMEZONE' => 'UTC',
        'OUTGOING_HTTP_TIMEOUT' => 1,
        'TRUSTED_PROXIES' => '',
        // The SQLite driver's database, in memory: made fresh for every test process.
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
        // No token: a call that reaches the real Bot API instead of the fake fails at once.
        'TELEGRAM_BOT_TOKEN' => '',
        'TELEGRAM_BOT_USERNAME' => '',
        'TELEGRAM_WEBHOOK_SECRET' => '',
        'CRON_TOKEN' => '',
        // No panel login until a test sets one.
        'ADMIN_USERNAME' => '',
        'ADMIN_PASSWORD_HASH' => '',
        'LOG_LEVEL' => 'debug',
        'LOG_FILE' => 'tests.log',
    ];

    /** The PHP every test plays the host as (PhpVersion::setTestHost()): one without known holes, whatever runs the suite. */
    public const HOST_PHP = '8.4.27';

    /** Who the shop's emails come from once a test sets its mail up (mail()). */
    protected const MAIL_FROM = 'shop@example.com';

    /** The transports the app reaches Telegram through: the Bot API's, and bot:poll's long polls. */
    private const TELEGRAM = ['telegram.transport', 'telegram.polling'];

    /** Every transport the app sends outgoing HTTP through: the panels' and checks' client, and Telegram's. */
    private const TRANSPORTS = ['http.transport', ...self::TELEGRAM];

    private static ?Application $app = null;

    /** The run's own folder: made at boot, emptied after every test, gone when the process ends. */
    private static ?string $runDir = null;

    /** @var array<string, mixed>|null bootstrap/container.php's definitions: how a swapped entry is rebuilt and put back. */
    private static ?array $definitions = null;

    /** @var list<string> Container entries this test replaced, in the order to restore them. */
    private array $swapped = [];

    /** @var array<string, mixed> Configuration this test set, with what to put back */
    private array $configured = [];

    /** @var list<ProbedDriver> The drivers whose probes this test answered (probedDatabase()), to put back */
    private array $probed = [];

    private ?FakeTelegram $telegram = null;

    /** @var array<class-string, object> The fakes this test put under the outgoing client (outgoingFake()), by class */
    private array $outgoing = [];

    private ?TestHandler $logs = null;

    private bool $fakePanel = false;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        PhpVersion::setTestHost(self::HOST_PHP);
        // The current shop and request id are process-wide: the next test starts with neither.
        CurrentBot::reset();
        RequestId::reset();

        foreach ($this->swapped as $id) {
            $this->app()->container()->set($id, $this->definition($id));
        }
        $this->swapped = [];

        if ($this->telegram !== null) {
            foreach (self::TELEGRAM as $transport) {
                self::transport($transport)->setHandler(self::offline(...));
            }
            $this->telegram = null;
        }
        if ($this->outgoing !== []) {
            self::transport('http.transport')->setHandler(self::offline(...));
            $this->outgoing = [];
        }
        if (self::$app !== null) {
            self::mailServer()->reset();
        }
        if ($this->logs !== null) {
            $logger = $this->logger();
            $logger->setHandlers(array_values(array_filter($logger->getHandlers(), fn(object $handler): bool => $handler !== $this->logs)));
            $this->logs = null;
        }
        foreach ($this->probed as $driver) {
            $driver->reset();
        }
        $this->probed = [];
        foreach ($this->configured as $key => $value) {
            $this->service(Config::class)->set($key, $value);
        }
        $this->configured = [];
        self::empty(self::runDir());

        // The panel registry is one per process and keeps a client per server id, and ids come round again: forget them,
        // and unregister a test's fake panel driver, or it would show in the next test's driver catalogue.
        if (self::$app !== null) {
            $registry = $this->service(ProviderRegistry::class);
            $this->fakePanel ? $registry->unregister(FakeProvider::driver()) : $registry->flush();
        }
        if ($this->fakePanel) {
            FakeProvider::reset();
            $this->fakePanel = false;
        }

        parent::tearDown();
    }

    /**
     * The process's application, booted the first time: on CONFIG (its config.php deleted once read, so a test starts
     * with none, as a fresh upload does), every file it writes (FILES) in the run's own folder, the router's table in one
     * beside it kept for the whole run, no network for any outgoing call until a test hands it a fake, and no sleeping.
     */
    protected function app(): Application
    {
        if (self::$app === null) {
            $config = new ConfigFile(self::runDir() . '/' . self::FILES['config.file'], self::runDir() . '/' . self::FILES['config.lock']);
            $config->setMany(self::CONFIG);
            self::$app = Application::boot(dirname(__DIR__), $config->path());
            self::empty(self::runDir());
            // A relation read row by row in a list (an N+1) fails the test; live it is only logged (DatabaseManager).
            Model::handleLazyLoadingViolationUsing(null);
            Model::preventAccessingMissingAttributes();
            $container = self::$app->container();
            foreach (self::FILES as $entry => $file) {
                $container->set($entry, self::runDir() . '/' . $file);
            }
            // Every request of every test reads the router's table as a live one does (RouteCache), from a folder no
            // test empties: a file deleted under a request that had just found it would have FastRoute write it anew.
            $container->set('routes.cache', self::runDir() . '-routes');
            $container->set(Sleeper::class, new NullSleeper());
            // The shop's email goes to the tests' own mail server, whatever config.php's MAIL_* say (mail() sets up the rest).
            $container->set('mail.transport', new RecordingMailTransport());
            // Every database driver as a test may answer its probes (probedDatabase()) — the real one's until it does.
            // The app's own connection is booted already, on the real driver.
            $container->set('database.drivers', new Registry(array_map(
                static fn(DatabaseDriver $driver): ProbedDriver => new ProbedDriver($driver),
                $container->get('database.drivers')->all(),
            )));
            // The poller's scheduler runs here, in the test's own process and database (a process of its own would boot
            // a database of its own); BackgroundRunTest covers the other way.
            $container->set('schedule.background', null);
            foreach (self::TRANSPORTS as $transport) {
                self::transport($transport)->setHandler(self::offline(...));
            }
        }

        return self::$app;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    protected function service(string $id): object
    {
        return $this->app()->container()->get($id);
    }

    /**
     * Put a double in the container for this test, rebuilding the entries that hold the real one (from their
     * bootstrap/container.php definitions). Restored in tearDown, dependents first. The helpers above swap a leaf
     * instead and need no such list.
     *
     * @param string ...$rebuild Container entries whose singleton holds `$id`
     */
    protected function swap(string $id, object $instance, string ...$rebuild): void
    {
        $container = $this->app()->container();
        $container->set($id, $instance);
        foreach ($rebuild as $dependent) {
            $container->set($dependent, $this->definition($dependent));
        }

        $this->swapped = array_values(array_unique([...$rebuild, ...$this->swapped, $id]));
    }

    /** How bootstrap/container.php defines an entry: its factory or wiring there, autowiring otherwise. */
    private function definition(string $id): mixed
    {
        self::$definitions ??= require $this->app()->basePath('bootstrap/container.php');

        return self::$definitions[$id] ?? \DI\autowire($id);
    }

    /** Deliver services as text: the QR card (which needs GD) is covered by its own suite. */
    protected function withoutQr(): void
    {
        $this->service(Settings::class)->set('bot.qr_enabled', false);
    }

    /**
     * Register the fake connector "fake" (FakePanelDriver, its clients FakeProvider) for this test, its state emptied;
     * unregistered in tearDown. Asked again in the same test, it changes nothing.
     */
    protected function fakePanel(): void
    {
        if ($this->fakePanel) {
            return;
        }
        FakeProvider::reset();
        $this->service(ProviderRegistry::class)->register(new FakePanelDriver());
        $this->fakePanel = true;
    }

    /**
     * The fake Telegram for this test: the transport of the Bot API and of bot:poll's long polls, with the main bot's
     * token FakeTelegram::TOKEN.
     */
    protected function telegram(): FakeTelegram
    {
        if ($this->telegram === null) {
            $this->telegram = new FakeTelegram();
            foreach (self::TELEGRAM as $transport) {
                self::transport($transport)->setHandler($this->telegram);
            }
            $this->config(['telegram.token' => FakeTelegram::TOKEN]);
        }

        return $this->telegram;
    }

    /**
     * A picture's width, height and type off its header; null for bytes that are no picture.
     *
     * @return array{int, int, string}|null
     */
    protected static function imageOf(string $bytes): ?array
    {
        $size = $bytes === '' ? false : getimagesizefromstring($bytes);

        return $size === false ? null : [$size[0], $size[1], $size['mime']];
    }

    /**
     * A bot text's default wording, with `$values` filled in.
     *
     * @param array<string, scalar> $values
     */
    protected static function text(BotText $text, array $values = []): string
    {
        return Messages::fill($text->spec()->default, $values);
    }

    /** The fake panel (any outgoing HTTP: a panel, Resend) as the outgoing client's transport for this test. */
    protected function panelHttp(): FakePanel
    {
        return $this->outgoingFake(FakePanel::class);
    }

    /** Telegram's sign-in service (oauth.telegram.org) as the outgoing client's transport for this test. */
    protected function telegramLogin(): FakeTelegramLogin
    {
        return $this->outgoingFake(FakeTelegramLogin::class);
    }

    /** Google's sign-in service (its published keys) as the outgoing client's transport for this test. */
    protected function googleLogin(): FakeGoogleLogin
    {
        return $this->outgoingFake(FakeGoogleLogin::class);
    }

    /** Cloudflare Turnstile's siteverify as the outgoing client's transport for this test. */
    protected function turnstile(): FakeTurnstile
    {
        return $this->outgoingFake(FakeTurnstile::class);
    }

    /** CubePay's API as the outgoing client's transport for this test. */
    protected function cubePay(): FakeCubePay
    {
        return $this->outgoingFake(FakeCubePay::class);
    }

    /** Zarinpal's API (live and sandbox) as the outgoing client's transport for this test. */
    protected function zarinpal(): FakeZarinpal
    {
        return $this->outgoingFake(FakeZarinpal::class);
    }

    /** AtlasPay's API as the outgoing client's transport for this test. */
    protected function atlasPay(): FakeAtlasPay
    {
        return $this->outgoingFake(FakeAtlasPay::class);
    }

    /** AbanGateway's API as the outgoing client's transport for this test. */
    protected function aban(): FakeAban
    {
        return $this->outgoingFake(FakeAban::class);
    }

    /** Plisio's API as the outgoing client's transport for this test. */
    protected function plisio(): FakePlisio
    {
        return $this->outgoingFake(FakePlisio::class);
    }

    /** AqayePardakht's API (live and sandbox) as the outgoing client's transport for this test. */
    protected function aqayePardakht(): FakeAqayePardakht
    {
        return $this->outgoingFake(FakeAqayePardakht::class);
    }

    /** Variza's API as the outgoing client's transport for this test; its webhooks are the test's to send. */
    protected function variza(): FakeVariza
    {
        return $this->outgoingFake(FakeVariza::class);
    }

    /**
     * The fake `$class` (a Guzzle handler: FakePanel, FakeGitHub, a gateway's) as the outgoing client's transport for this
     * test, made — and put under the client — the first time the test asks for it; offline again in tearDown.
     *
     * @template T of callable-object
     * @param class-string<T> $class
     * @return T
     */
    protected function outgoingFake(string $class): object
    {
        $fake = $this->outgoing[$class] ?? null;
        if (!$fake instanceof $class) {
            $fake = new $class();
            $this->outgoing[$class] = $fake;
            self::transport('http.transport')->setHandler($fake);
        }

        return $fake;
    }

    /**
     * Set the shop's email up for this test (a sender address; the transport is always the tests' mail server) and
     * return the server. Without it no email can go.
     */
    protected function mail(): RecordingMailTransport
    {
        $this->config(['mail.from_address' => self::MAIL_FROM]);

        return self::mailServer();
    }

    /**
     * Set configuration for this test (`telegram.webhook_secret`…); put back in tearDown.
     *
     * @param array<string, mixed> $values
     */
    protected function config(array $values): void
    {
        $config = $this->service(Config::class);
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $this->configured)) {
                $this->configured[$key] = $config->get($key);
            }
            $config->set($key, $value);
        }
    }

    /** What the app logs during this test, on top of its log file: a handler to ask (hasWarningThatContains()…). */
    protected function logs(): TestHandler
    {
        if ($this->logs === null) {
            $this->logs = new TestHandler();
            $this->logger()->pushHandler($this->logs);
        }

        return $this->logs;
    }

    /**
     * The config.php the app reads and writes (the run's own), holding `$settings` alone.
     *
     * @param array<string, string|int|bool> $settings
     */
    protected function configFile(array $settings): ConfigFile
    {
        $file = $this->service(ConfigFile::class);
        Files::delete($file->path());
        $file->setMany($settings);

        return $file;
    }

    /**
     * The database driver `$key` with its probe answered by the test (the installer's and the settings' checks), and
     * offered by the installer or not whatever the driver says (null keeps it).
     */
    protected function probedDatabase(string $key, ?bool $installable = null): ProbedDriver
    {
        $probed = $this->app()->container()->get('database.drivers')->get($key);
        assert($probed instanceof ProbedDriver);
        $probed->installable = $installable;
        $this->probed[] = $probed;

        return $probed->answer();
    }

    /** A folder of this test's own, gone with everything in it once the test is over. */
    protected function scratchDir(): string
    {
        // Boot first: the boot empties the run's folder once, and would take a folder made before it.
        $this->app();
        $dir = self::runDir() . '/' . bin2hex(random_bytes(6));
        mkdir($dir);

        return $dir;
    }

    /** Age everything in FileCache past its time. */
    protected function fileCacheExpired(): void
    {
        foreach (glob($this->app()->container()->get('files.cache') . '/*') ?: [] as $file) {
            touch($file, time() - FileCache::KEEP_SECONDS - 1);
        }
    }

    /**
     * Run `$work` as at the end of a scheduler's turn (Budget): a task working through a queue stops before its next item.
     *
     * @param \Closure(): mixed $work
     */
    protected function withTheTurnOver(\Closure $work): void
    {
        $budget = $this->service(Budget::class);
        $budget->turn(0.0);
        try {
            $work();
        } finally {
            $budget->turn(null);
        }
    }

    private function logger(): Logger
    {
        $logger = $this->service(LoggerInterface::class);
        assert($logger instanceof Logger);

        return $logger;
    }

    /** The tests' mail server: the container's `mail.transport` from the boot on. */
    private static function mailServer(): RecordingMailTransport
    {
        $server = self::$app?->container()->get('mail.transport');
        assert($server instanceof RecordingMailTransport);

        return $server;
    }

    /** @return HandlerStack<callable(RequestInterface, array<array-key, mixed>): PromiseInterface> */
    private static function transport(string $id): HandlerStack
    {
        $transport = self::$app?->container()->get($id);
        assert($transport instanceof HandlerStack);

        return $transport;
    }

    /** No network in a test: a call no fake answers fails at once, as to an unreachable host. */
    private static function offline(RequestInterface $request): PromiseInterface
    {
        return new RejectedPromise(new ConnectException("No network in the tests: {$request->getMethod()} {$request->getUri()} (a test hands the app a fake: panelHttp(), telegram()).", $request));
    }

    private static function runDir(): string
    {
        if (self::$runDir === null) {
            self::$runDir = sys_get_temp_dir() . '/amobot-tests-' . getmypid() . '-' . bin2hex(random_bytes(3));
            mkdir(self::$runDir);
            register_shutdown_function(static function (string $dir): void {
                foreach ([$dir, $dir . '-routes'] as $folder) {
                    if (is_dir($folder)) {
                        self::empty($folder);
                        @rmdir($folder);
                    }
                }
            }, self::$runDir);
        }

        return self::$runDir;
    }

    /** Everything in a folder — files, folders and what they hold — deleted; the folder stays. */
    private static function empty(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
    }
}
