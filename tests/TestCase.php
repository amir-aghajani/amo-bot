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
use Tests\Support\FakeGoogleLogin;
use Tests\Support\FakePanel;
use Tests\Support\FakeTelegram;
use Tests\Support\FakeTelegramLogin;
use Tests\Support\FakeTurnstile;

/**
 * One booted application per test process, kept off the machine it runs on — and each test hands it the doubles it
 * needs by swapping a leaf, never what was built around one: a fake transport under the outgoing HTTP client and under
 * the Bot API (panelHttp(), telegramLogin(), googleLogin(), turnstile(), telegram()), the tests' own mail server under
 * the Mailer (mail()), a handler on the logger (logs()), the run's own files (configFile()), a probed database driver
 * (probedDatabase()), configuration for the test (config()). Everything is put back in tearDown.
 */
abstract class TestCase extends BaseTestCase
{
    /** Where the app keeps the files it writes, by container entry: in the run's own folder (runDir()), never storage/. */
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
     * The configuration every test process boots with — its own config.php, never this machine's: an in-memory SQLite
     * database, a fixed APP_KEY, UTC, no bot token; every setting that changes behaviour, pinned.
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

    private ?FakePanel $panelHttp = null;

    private ?FakeTelegramLogin $telegramLogin = null;

    private ?FakeGoogleLogin $googleLogin = null;

    private ?FakeTurnstile $turnstile = null;

    private ?TestHandler $logs = null;

    private bool $fakePanel = false;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        // Whose shop the code works in, and which request it answers, are the process's: the next test starts with none.
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
        if ($this->panelHttp !== null || $this->telegramLogin !== null || $this->googleLogin !== null || $this->turnstile !== null) {
            self::transport('http.transport')->setHandler(self::offline(...));
            $this->panelHttp = null;
            $this->telegramLogin = null;
            $this->googleLogin = null;
            $this->turnstile = null;
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

        // The panel drivers are one registry per process, which keeps a driver per server id: every test's servers are
        // its own (their ids come round again), and a test's fake panel goes with it — or it would show up in the
        // driver catalogue of whatever test runs next.
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
     * One booted application per test process (its configuration pinned, CONFIG: an in-memory SQLite database, a fixed
     * APP_KEY, UTC, no bot token, no panel login), off this machine: the config.php it boots with is the run's own and
     * gone once read — a test starts with none, as a fresh upload does —, the files it writes (FILES — the install lock
     * and key, the recovery key, the config.php the settings edit, the scheduler's state, the sign-in throttle, the
     * OpenID providers' keys, the QR backgrounds, the receipts uploaded from a website, the support tickets' pictures, the
     * poller's lock) go to the run's own folder — the router's table to
     * one beside it, kept for the whole run —, its outgoing HTTP — the panels', the OpenID providers', the Bot API's, the
     * poller's — reaches no network (a test hands it a fake), and nothing sleeps.
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
            // The shop's own connection is booted already, on the real driver.
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
     * Put a test double into the container for the rest of this test, rebuilding the entries that
     * hold the real one (each from its bootstrap/container.php definition, so a factory-built one is
     * built the same way again). Everything is restored in tearDown — dependents first, so the entry
     * itself is restored last and never briefly wired to a stale one. For what the helpers above
     * cover, a leaf is swapped instead and nothing needs listing.
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
     * A panel for this test: `Tests\Fakes\FakePanelDriver` becomes driver "fake" (give a Server
     * `driver => 'fake'`), its panels `Tests\Fakes\FakeProvider`s, their log emptied; the driver is forgotten again in
     * tearDown. Asked again in the same test, it changes nothing — what the test told the panels so far stands.
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
     * The fake Telegram for this test: the transport the Bot API — and bot:poll's long polls — send through from the
     * first time it is asked for, the main bot's token FakeTelegram::TOKEN — so handlers, notifiers, tasks and the
     * poller all talk to it.
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
     * A picture as its header describes it — width, height and type —, or null for bytes that are none: what an upload
     * the shop kept is, whether GD wrote it again (Users\Services\CustomerPictures::keep()) or not.
     *
     * @return array{int, int, string}|null
     */
    protected static function imageOf(string $bytes): ?array
    {
        $size = $bytes === '' ? false : getimagesizefromstring($bytes);

        return $size === false ? null : [$size[0], $size[1], $size['mime']];
    }

    /**
     * What the bot says for a text the admin has not reworded — the catalog's wording, with `$values`
     * filled in when the test cares about a text that has variables.
     *
     * @param array<string, scalar> $values
     */
    protected static function text(BotText $text, array $values = []): string
    {
        return Messages::fill($text->spec()->default, $values);
    }

    /**
     * Where the app's outgoing HTTP goes for this test — a 3x-ui panel being probed, the settings
     * screen's getMe() — the transport of the container's client from the first time it is asked for.
     */
    protected function panelHttp(): FakePanel
    {
        if ($this->panelHttp === null) {
            $this->panelHttp = new FakePanel();
            self::transport('http.transport')->setHandler($this->panelHttp);
        }

        return $this->panelHttp;
    }

    /**
     * Telegram's sign-in service for this test (oauth.telegram.org: its keys, its code exchange), the transport of the
     * container's outgoing client from the first time it is asked for — what a website's Telegram sign-in reaches.
     */
    protected function telegramLogin(): FakeTelegramLogin
    {
        if ($this->telegramLogin === null) {
            $this->telegramLogin = new FakeTelegramLogin();
            self::transport('http.transport')->setHandler($this->telegramLogin);
        }

        return $this->telegramLogin;
    }

    /**
     * Google's sign-in service for this test (its published keys), the transport of the container's outgoing client from
     * the first time it is asked for — what a website's Google sign-in reaches.
     */
    protected function googleLogin(): FakeGoogleLogin
    {
        if ($this->googleLogin === null) {
            $this->googleLogin = new FakeGoogleLogin();
            self::transport('http.transport')->setHandler($this->googleLogin);
        }

        return $this->googleLogin;
    }

    /**
     * Cloudflare Turnstile for this test (its siteverify), the transport of the container's outgoing client from the first
     * time it is asked for — what a website's captcha is checked with.
     */
    protected function turnstile(): FakeTurnstile
    {
        if ($this->turnstile === null) {
            $this->turnstile = new FakeTurnstile();
            self::transport('http.transport')->setHandler($this->turnstile);
        }

        return $this->turnstile;
    }

    /**
     * The shop's email set up for this test — config.php's MAIL_FROM_ADDRESS (MAIL_FROM), the tests' mail server the
     * transport whatever MAIL_TRANSPORT says —: what it sent. Without it, no email can go (Mailer::ready()).
     */
    protected function mail(): RecordingMailTransport
    {
        $this->config(['mail.from_address' => self::MAIL_FROM]);

        return self::mailServer();
    }

    /**
     * Configuration for this test (`telegram.webhook_secret`, `shop.cron_token`…), as it was again afterwards.
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
     * The config.php the app reads and writes (the run's own, never this machine's), holding `$settings` and nothing else
     * for this test.
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
     * The database driver `$key` with its probe answered by the test — the installer's and the settings screen's
     * connection checks — and offered by the installer or not, whatever the driver says (null keeps it).
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
        $dir = self::runDir() . '/' . bin2hex(random_bytes(6));
        mkdir($dir);

        return $dir;
    }

    /** What the app keeps a short while (Core\Support\FileCache — a picture fetched from Telegram), as it is once its time is over. */
    protected function fileCacheExpired(): void
    {
        foreach (glob($this->app()->container()->get('files.cache') . '/*') ?: [] as $file) {
            touch($file, time() - FileCache::KEEP_SECONDS - 1);
        }
    }

    /**
     * `$work` run as at the end of a scheduler's turn (Core\Scheduling\Budget): a task that works through a queue — the
     * sync, the grants, the renewals — stops before its next item. Outside it the work has a turn's time of its own again.
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

    /** What the network is for the app in a test: not there — a call no fake answers fails at once, as an unreachable host does. */
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
