<?php

declare(strict_types=1);

namespace App\Core;

use App\Console\Kernel as ConsoleKernel;
use App\Core\Config\ConfigFile;
use App\Core\Config\ConfigValues;
use App\Core\Config\Repository as Config;
use App\Core\Database\Casts\Encrypted;
use App\Core\Database\ChangeFeed;
use App\Core\Database\DatabaseManager;
use App\Core\Http\HttpKernel;
use App\Core\Security\Encrypter;
use App\Core\Support\Files;
use App\Support\LocalTime;
use DI\Container;
use DI\ContainerBuilder;
use Psr\Log\LoggerInterface;
use Slim\App as SlimApp;
use Symfony\Component\Console\Application as ConsoleApplication;

/**
 * The single entry point that wires everything together.
 *
 * Application::boot() reads config.php into config/'s parts, builds the DI container and boots Eloquent.
 * From there ->http() gives the Slim app (public/index.php) and ->console() the CLI (bin/console).
 */
final class Application
{
    public const NAME = 'AmoBot';
    public const VERSION = '0.1.1';

    private Config $config;
    private Container $container;

    /** @var SlimApp<Container>|null */
    private ?SlimApp $http = null;
    private ?ConsoleApplication $console = null;

    private function __construct(
        private readonly string $basePath,
        private readonly string $configFile,
    ) {}

    /** @param string|null $configFile The shop's config.php: beside the app unless said (the test suite's own) */
    public static function boot(string $basePath, ?string $configFile = null): self
    {
        $basePath = rtrim($basePath, '/\\');
        $app = new self($basePath, $configFile ?? $basePath . '/config.php');
        $app->keepFilesForWhoRunsIt();
        $app->keepErrorsOutOfAnswers();
        $app->loadConfiguration();
        $app->buildContainer();
        $app->bootDatabase();

        return $app;
    }

    /** @return SlimApp<Container> */
    public function http(): SlimApp
    {
        return $this->http ??= HttpKernel::create($this);
    }

    public function console(): ConsoleApplication
    {
        return $this->console ??= ConsoleKernel::create($this);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function isDebug(): bool
    {
        return (bool) $this->config->get('app.debug', false);
    }

    public function basePath(string $path = ''): string
    {
        return $this->join($this->basePath, $path);
    }

    /** The shop's config.php, the one this process booted with (the container's `config.file`). */
    public function configFile(): string
    {
        return $this->configFile;
    }

    public function configPath(string $path = ''): string
    {
        return $this->join($this->basePath . '/config', $path);
    }

    public function storagePath(string $path = ''): string
    {
        return $this->join($this->basePath . '/storage', $path);
    }

    public function databasePath(string $path = ''): string
    {
        return $this->join($this->basePath . '/database', $path);
    }

    /**
     * What the files the app makes may be read by (Core\Support\Files): its owner's alone while PHP runs as the account
     * that owns the app's folder — PHP-FPM, suEXEC, LSAPI, a shell —, the account's to read where PHP runs as another
     * user (mod_php), whose owner opens the install key, the recovery key and config.php in the host's file manager.
     */
    private function keepFilesForWhoRunsIt(): void
    {
        Files::ownerOnly(Files::processOwns($this->basePath));
    }

    /**
     * PHP's own warnings never go into an HTTP answer — the API speaks JSON, and a stray notice would break it and show
     * the server's paths, or a line of a broken config.php (many shared hosts ship display_errors on) — from before
     * anything can fail, so a boot that fails is a bare 500 too (public/index.php says so from its first line, before
     * the app is loaded). They go to storage/logs instead of the host's default error_log, which cPanel writes beside the
     * script — inside the document root. And an exception keeps no argument of the calls that led to it: a trace — in
     * the log, or the debug block of an answer — never carries a password, a token or a customer's data handed down the
     * stack.
     */
    private function keepErrorsOutOfAnswers(): void
    {
        ini_set('log_errors', '1');
        ini_set('error_log', $this->storagePath('logs/php-errors.log'));
        ini_set('zend.exception_ignore_args', '1');
        if (!$this->runningInConsole()) {
            ini_set('display_errors', '0');
        }
    }

    /**
     * config.php's settings, made into config/'s parts. A config.php that does not parse stops the boot (the front
     * controller's last resort answers, and its error is in storage/logs/php-errors.log, its line with it); one that is
     * not there — a fresh upload, before the installer — runs on the defaults.
     */
    private function loadConfiguration(): void
    {
        $this->config = Config::fromDirectory($this->configPath(), new ConfigValues(ConfigFile::load($this->configFile)));

        // Every time the app keeps is UTC; the shop's zone is how times read (LocalTime) — the database speaks UTC too.
        date_default_timezone_set('UTC');
        LocalTime::use((string) $this->config->get('app.timezone', 'UTC'));
        mb_internal_encoding('UTF-8');

        error_reporting($this->isDebug() ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        if ($this->runningInConsole() && $this->isDebug()) {
            ini_set('display_errors', '1');
        }
    }

    private function buildContainer(): void
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->useAttributes(false);
        $builder->addDefinitions([
            self::class => $this,
            Config::class => $this->config,
        ]);
        $builder->addDefinitions($this->basePath . '/bootstrap/container.php');

        $this->container = $builder->build();
    }

    private function bootDatabase(): void
    {
        $this->container->get(DatabaseManager::class)->boot();

        // The admin panel's live view follows every committed write, in every process (web, bot, scheduler).
        $this->container->get(ChangeFeed::class)->listen();

        Encrypted::use($this->container->get(Encrypter::class), $this->container->get(LoggerInterface::class));
    }

    private function runningInConsole(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }

    private function join(string $base, string $path): string
    {
        return $path === '' ? $base : $base . '/' . ltrim($path, '/\\');
    }
}
