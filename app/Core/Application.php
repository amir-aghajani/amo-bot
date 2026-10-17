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
 * The entry point: boot() reads config.php into config/'s parts, builds the PHP-DI container (bootstrap/container.php)
 * and boots the database; then http() gives the Slim app (public/index.php) and console() the CLI (bin/console).
 */
final class Application
{
    public const NAME = 'AmoBot';
    public const VERSION = '0.7.0';

    private Config $config;
    private Container $container;

    /** @var SlimApp<Container>|null */
    private ?SlimApp $http = null;
    private ?ConsoleApplication $console = null;

    private function __construct(
        private readonly string $basePath,
        private readonly string $configFile,
    ) {}

    /** @param string|null $configFile config.php; beside the app unless given (the test suite passes its own) */
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

    /** The config.php this process booted with (the container's `config.file`). */
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
     * Files the app writes are owner-only when PHP runs as the account owning the app's folder (PHP-FPM, suEXEC, a
     * shell); under mod_php, PHP is another user and the owner must still read them in the host's file manager.
     */
    private function keepFilesForWhoRunsIt(): void
    {
        Files::ownerOnly(Files::processOwns($this->basePath));
    }

    /**
     * PHP's warnings never go into an HTTP answer (a stray notice breaks the JSON and shows server paths); they go to
     * storage/logs/php-errors.log rather than the host's error_log, which cPanel writes inside the document root.
     * Exceptions keep no call arguments, so no trace carries a password or a token handed down the stack.
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
     * Read config.php into config/'s parts. One that does not parse stops the boot (public/index.php's last resort
     * answers; php-errors.log has the line); a missing one — before the installer — runs on the defaults.
     */
    private function loadConfiguration(): void
    {
        $this->config = Config::fromDirectory($this->configPath(), new ConfigValues(ConfigFile::load($this->configFile)));

        // Every stored time is UTC (the database session too); the shop's zone (LocalTime) applies only when shown.
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

        // The panels' live view follows every committed write, in every process (web, bot, scheduler).
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
