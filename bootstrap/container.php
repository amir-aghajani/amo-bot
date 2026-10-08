<?php

declare(strict_types=1);

/*
 * PHP-DI definitions. Anything not listed here is autowired by type-hint.
 * Keep this file for services that need configuration or a factory.
 */

use App\Console\Commands\BotPollCommand;
use App\Core\Application;
use App\Core\Captcha\Drivers\Altcha;
use App\Core\Captcha\Drivers\Turnstile;
use App\Core\Captcha\Verifier;
use App\Core\Config\ConfigFile;
use App\Core\Config\Repository as Config;
use App\Core\Database\DatabaseManager;
use App\Core\Database\Drivers\MySqlDriver;
use App\Core\Database\Drivers\SqliteDriver;
use App\Core\Database\Schema;
use App\Core\Drivers\Registry;
use App\Core\Http\BasePath;
use App\Core\Http\CorsPolicy;
use App\Core\Http\Middleware\InstalledMiddleware;
use App\Core\Http\RequestOrigin;
use App\Core\Http\RouteCache;
use App\Core\Installation;
use App\Core\Logging\LoggerFactory;
use App\Core\Mail\Drivers\Native;
use App\Core\Mail\Drivers\Resend;
use App\Core\Mail\Drivers\Smtp;
use App\Core\Mail\Mailer;
use App\Core\Mail\MailTransport;
use App\Core\Scheduling\BackgroundRun;
use App\Core\Scheduling\Budget;
use App\Core\Scheduling\Scheduler;
use App\Core\Scheduling\Shops;
use App\Core\Security\Encrypter;
use App\Core\Security\RateLimiter;
use App\Core\Session\Session;
use App\Core\Support\FileCache;
use App\Core\Support\Sleeper;
use App\Core\Support\SystemSleeper;
use App\Modules\Accounts\Oidc\Jwks;
use App\Modules\Auth\PanelAuthMiddleware;
use App\Modules\Auth\Services\AgentAuth;
use App\Modules\Auth\Services\OwnerAuth;
use App\Modules\Auth\Services\RecoveryKey;
use App\Modules\Bots\Scheduling\BotShops;
use App\Modules\Bots\ShopScopeMiddleware;
use App\Modules\Installer\Services\InstallKey;
use App\Modules\Payments\Drivers\Manual\ManualDriver;
use App\Modules\Payments\Drivers\Wallet\WalletDriver;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Providers\Drivers\PasarGuard\PasarGuardDriver;
use App\Modules\Providers\Drivers\ThreeXui\ThreeXuiDriver;
use App\Modules\Providers\ProviderRegistry;
use App\Modules\Providers\Support\PanelHttp;
use App\Modules\Referrals\Services\ReferralSettings;
use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Settings\Services\DatabaseSettings;
use App\Modules\Settings\Services\MailSettings;
use App\Modules\Settings\Services\Settings;
use App\Modules\Store\Http\WebsiteCorsPolicy;
use App\Modules\Subscriptions\Services\ReminderSettings;
use App\Modules\Subscriptions\Services\RenewalSettings;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\BotSettings;
use App\Modules\Telegram\Emoji\PremiumEmojiStatus;
use App\Modules\Telegram\Polling\LongPolls;
use App\Modules\Telegram\Polling\Poller;
use App\Modules\Telegram\Qr\QrBackground;
use App\Modules\Telegram\Reports\ReportSettings;
use App\Modules\Telegram\Update\Dispatcher;
use App\Modules\Updates\AppFolders;
use App\Modules\Updates\Maintenance;
use App\Modules\Updates\ReleaseKey;
use App\Modules\Updates\Updater;
use App\Modules\Updates\Workspace;
use App\Modules\Users\Enums\PictureFolder;
use App\Modules\Users\Services\CustomerPictures;
use App\Modules\Users\Services\WalletSettings;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;
use Symfony\Component\Mailer\Transport\TransportInterface;

use function DI\autowire;
use function DI\factory;
use function DI\get;

/**
 * A client for outgoing calls over `$transport`: the configured timeout — a host that does not answer at all is given up
 * on early, one that answers slowly gets the whole of it — and failures left to the caller to read.
 */
$outgoing = static fn(Config $config, HandlerStack $transport): GuzzleClient => new GuzzleClient([
    'handler' => $transport,
    'timeout' => (float) $config->get('app.http_timeout', 30),
    'connect_timeout' => min(10.0, (float) $config->get('app.http_timeout', 30)),
    'http_errors' => false,
    'headers' => ['User-Agent' => Application::NAME . '/' . Application::VERSION],
]);

return [
    // --- PSR-7 factories -------------------------------------------------------------------
    ResponseFactoryInterface::class => autowire(ResponseFactory::class),
    StreamFactoryInterface::class => autowire(StreamFactory::class),

    // --- HTTP --------------------------------------------------------------------------------
    // The URL prefix requests arrive under ("/shop", "" at the root), which Slim strips before routing.
    'http.base_path' => factory(fn(Config $config) => BasePath::detect(
        (string) $config->get('app.base_path', ''),
        (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
        PHP_SAPI,
    )),
    // The proxies whose forwarding headers say who the browser is (TRUSTED_PROXIES).
    RequestOrigin::class => factory(fn(Config $config) => RequestOrigin::trusting((string) $config->get('app.trusted_proxies', ''))),
    // The installer's routes, open only until the shop is installed (the shop's routes: InstalledMiddleware itself).
    'installer.open' => autowire(InstalledMiddleware::class)->constructorParameter('installer', true),
    // Each panel's API behind its own session: the owner's (/api/admin) and the agents' (/api/agent).
    'panel.admin' => autowire(PanelAuthMiddleware::class)->constructorParameter('auth', get(OwnerAuth::class)),
    'panel.agent' => autowire(PanelAuthMiddleware::class)->constructorParameter('auth', get(AgentAuth::class)),
    // The owner's sections that are the shop's as a whole: across every bot's services, or in the main bot's shop.
    'shop.everywhere' => autowire(ShopScopeMiddleware::class)->constructorParameter('scope', ShopScopeMiddleware::EVERYWHERE),
    'shop.main' => autowire(ShopScopeMiddleware::class)->constructorParameter('scope', ShopScopeMiddleware::MAIN),
    // The router's table, kept between requests (RouteCache).
    'routes.cache' => factory(fn(Application $app) => $app->storagePath('cache/routes')),
    RouteCache::class => autowire()->constructorParameter('directory', get('routes.cache')),
    // Which pages on other origins may call the API from a browser: the shops' websites, each its own (CorsMiddleware).
    CorsPolicy::class => autowire(WebsiteCorsPolicy::class)->constructorParameter('basePath', get('http.base_path')),

    // --- Infrastructure --------------------------------------------------------------------
    LoggerInterface::class => factory(LoggerFactory::class),

    // Outgoing HTTP — the panels, the checks — and, apart, the Bot API's (and bot:poll's long polls', below): each sends
    // through a transport (a handler stack) of its own held here, the one place a test puts its fake (FakePanel,
    // FakeTelegram) — so nothing built around a client is built again.
    'http.transport' => factory(static fn(): HandlerStack => HandlerStack::create()),
    'telegram.transport' => factory(static fn(): HandlerStack => HandlerStack::create()),
    ClientInterface::class => factory(static fn(Config $config, ContainerInterface $c): GuzzleClient => $outgoing($config, $c->get('http.transport'))),

    // The databases the shop can run on, by what DB_CONNECTION holds (Core\Database\Drivers): register additional drivers here.
    'database.drivers' => factory(static fn(ContainerInterface $c): Registry => new Registry([$c->get(MySqlDriver::class), $c->get(SqliteDriver::class)])),
    SqliteDriver::class => factory(fn(Application $app) => new SqliteDriver($app->basePath())),
    DatabaseManager::class => autowire()->constructorParameter('drivers', get('database.drivers')),
    DatabaseSettings::class => autowire()->constructorParameter('drivers', get('database.drivers')),

    Connection::class => factory(fn(Capsule $capsule) => $capsule->getConnection()),
    ConnectionInterface::class => get(Connection::class),

    Encrypter::class => factory(fn(Config $config) => Encrypter::fromKey((string) $config->get('app.key', ''))),

    // Session files go to storage/sessions unless config.php names another folder: every host keeps and cleans them alike.
    Session::class => factory(function (Config $config, Application $app) {
        $settings = (array) $config->get('session', []);
        if ((string) ($settings['save_path'] ?? '') === '') {
            $settings['save_path'] = $app->storagePath('sessions');
        }

        return new Session($settings);
    }),

    // Sign-in attempts, counted in files (shared hosting has no cache server).
    'throttle.path' => factory(fn(Application $app) => $app->storagePath('cache/throttle')),
    RateLimiter::class => autowire()->constructorParameter('directory', get('throttle.path')),
    // What another service hands over and would hand over again unchanged — a picture Telegram keeps — kept a short while.
    'files.cache' => factory(fn(Application $app) => $app->storagePath('cache/files')),
    FileCache::class => autowire()->constructorParameter('directory', get('files.cache')),
    // The OpenID providers' signing keys a website's sign-in is checked with, kept a while rather than read every time.
    'jwks.path' => factory(fn(Application $app) => $app->storagePath('cache/jwks')),
    Jwks::class => autowire()->constructorParameter('directory', get('jwks.path')),
    // The captchas a website may ask of its forms (Core\Captcha\Drivers): register additional drivers here.
    'captcha.drivers' => factory(static fn(ContainerInterface $c): Registry => new Registry([$c->get(Turnstile::class), $c->get(Altcha::class)])),
    Verifier::class => autowire()->constructorParameter('drivers', get('captcha.drivers')),

    // The ways the shop's email goes out, by what MAIL_TRANSPORT holds (Core\Mail\Drivers): register additional drivers here.
    'mail.drivers' => factory(static fn(ContainerInterface $c): Registry => new Registry([$c->get(Smtp::class), $c->get(Native::class), $c->get(Resend::class)])),
    // How it goes out now (config.php's MAIL_*; null: none) — the one place a test puts its fake (RecordingMailTransport),
    // so the Mailer built around it is never built again.
    'mail.transport' => factory(static fn(Config $config, ContainerInterface $c, LoggerInterface $logger): ?TransportInterface => MailTransport::fromConfig($config, $c->get('mail.drivers'), $logger)),
    Mailer::class => autowire()->constructorParameter('transport', get('mail.transport'))->constructorParameter('drivers', get('mail.drivers')),
    MailSettings::class => autowire()->constructorParameter('drivers', get('mail.drivers')),

    Sleeper::class => autowire(SystemSleeper::class),

    // The lock the installer writes once the shop is set up.
    'installation.lock' => factory(fn(Application $app) => $app->storagePath('installed.lock')),
    Installation::class => autowire()->constructorParameter('lockPath', get('installation.lock')),
    // The web installer's one-time key, read off the host's files by whoever installs the shop.
    'install.key' => factory(fn(Application $app) => $app->storagePath('install-key.txt')),
    InstallKey::class => autowire()->constructorParameter('path', get('install.key')),
    // The key that sets the panel's login again when it is lost (LoginRecovery), read off the host's files the same way.
    'recovery.key' => factory(fn(Application $app) => $app->storagePath('recovery-key.txt')),
    RecoveryKey::class => autowire()->constructorParameter('path', get('recovery.key')),

    // The shop's tables, on the database DB_CONNECTION names: a fresh install's as database/schema.php has them, an
    // installed shop's brought along by the upgrades every release that changes them ships (Upgrades).
    Schema::class => factory(fn(Connection $db, DatabaseManager $database, Application $app) => new Schema($db, $database->driver(), require $app->databasePath('schema.php'))),

    // The owner's one-press update (Modules\Updates): its folder, the flag that holds the shop while an update installs
    // (public/index.php reads it there), the key releases are signed with, and the app's own folder it swaps.
    'updates.path' => factory(fn(Application $app) => $app->storagePath('updates')),
    'updates.flag' => factory(fn(Application $app) => $app->basePath(Maintenance::FLAG)),
    'updates.app' => factory(fn(Application $app) => $app->basePath()),
    'release.key' => factory(fn(Application $app) => $app->basePath('resources/release-key.pub')),
    // The scheduler's own lock (Core\Scheduling\Scheduler): an install holds it, so no scheduled run starts meanwhile.
    'schedule.lock' => factory(fn(ContainerInterface $c) => $c->get('schedule.state') . '.lock'),
    Workspace::class => autowire()->constructorParameter('folder', get('updates.path')),
    Maintenance::class => autowire()->constructorParameter('flag', get('updates.flag')),
    ReleaseKey::class => autowire()->constructorParameter('path', get('release.key')),
    AppFolders::class => factory(fn(ContainerInterface $c, Workspace $workspace) => new AppFolders($c->get('updates.app'), $workspace->previous())),
    Updater::class => autowire()
        ->constructorParameter('app', get('updates.app'))
        ->constructorParameter('schedulerLock', get('schedule.lock'))
        ->constructorParameter('routes', get('routes.cache')),

    // Where the scheduler remembers when each task last ran; the shops it goes round are the bots'.
    'schedule.state' => factory(fn(Application $app) => $app->storagePath('cache/schedule.json')),
    Shops::class => autowire(BotShops::class),
    Scheduler::class => factory(function (Application $app, ContainerInterface $c, Shops $shops, Budget $budget, LoggerInterface $logger) {
        $scheduler = new Scheduler($c, $shops, $budget, $logger, $c->get('schedule.state'));
        $register = require $app->basePath('bootstrap/schedule.php');
        $register($scheduler);

        return $scheduler;
    }),

    // --- Domain registries: register additional drivers here ---------------------------------
    // The panel connectors, by what servers.driver holds (Providers\Drivers) — and, on them, the client of each
    // server's panel, kept a while (ProviderRegistry).
    'panel.drivers' => factory(static fn(ContainerInterface $c): Registry => new Registry([$c->get(ThreeXuiDriver::class), $c->get(PasarGuardDriver::class)])),
    ProviderRegistry::class => factory(static function (PanelHttp $http, ContainerInterface $c): ProviderRegistry {
        $registry = new ProviderRegistry($http);
        foreach ($c->get('panel.drivers')->all() as $driver) {
            $registry->register($driver);
        }

        return $registry;
    }),

    // The ways the shop takes money, by what payment_methods.driver holds (Payments\Drivers).
    'payment.drivers' => factory(static fn(ContainerInterface $c): Registry => new Registry([$c->get(WalletDriver::class), $c->get(ManualDriver::class)])),
    GatewayRegistry::class => autowire()->constructorParameter('drivers', get('payment.drivers')),
    // The pictures the shop is sent, each kind in a folder of its own (Users\Enums\PictureFolder): the receipts customers
    // upload from the shops' websites, the support tickets' — one sent in the bot stays Telegram's file.
    'receipts.path' => factory(fn(Application $app) => $app->storagePath('uploads/receipts')),
    'tickets.path' => factory(fn(Application $app) => $app->storagePath('uploads/tickets')),
    CustomerPictures::class => factory(static fn(BotApi $api, LoggerInterface $logger, RateLimiter $limiter, FileCache $fetched, ContainerInterface $c): CustomerPictures => new CustomerPictures($api, [
        PictureFolder::Receipts->value => $c->get('receipts.path'),
        PictureFolder::Tickets->value => $c->get('tickets.path'),
    ], $logger, CustomerPictures::ROOM, $limiter, $fetched)),

    // The bot settings screen's groups, each module's own declaration: register another module's here.
    BotSettingsScreen::class => factory(fn(Settings $settings, ContainerInterface $c) => new BotSettingsScreen($settings, [
        $c->get(BotSettings::class),
        $c->get(WalletSettings::class),
        $c->get(RenewalSettings::class),
        $c->get(ReminderSettings::class),
        $c->get(ReferralSettings::class),
        $c->get(ReportSettings::class),
    ])),

    // The shop's own configuration: config.php beside the app, as WordPress keeps wp-config.php — written by the
    // installer, the owner's settings screen and their login, one writer at a time (its lock).
    'config.file' => factory(fn(Application $app) => $app->configFile()),
    'config.lock' => factory(fn(Application $app) => $app->storagePath('cache/config.lock')),
    ConfigFile::class => autowire()->constructorParameter('path', get('config.file'))->constructorParameter('lock', get('config.lock')),

    // --- Telegram ----------------------------------------------------------------------------
    BotApi::class => factory(static fn(Config $config, Sleeper $sleeper, PremiumEmojiStatus $premiumEmoji, ContainerInterface $c): BotApi => BotApi::fromConfig(
        $outgoing($config, $c->get('telegram.transport')),
        $config,
        $sleeper,
        $premiumEmoji,
    )),

    // The QR backgrounds the admins upload, each bot's its own.
    'qr.backgrounds' => factory(fn(Application $app) => $app->storagePath('uploads/qr')),
    QrBackground::class => autowire()->constructorParameter('dir', get('qr.backgrounds')),

    Dispatcher::class => factory(function (ContainerInterface $c, Application $app) {
        $dispatcher = $c->get('telegram.dispatcher.base');
        $register = require $app->basePath('routes/bot.php');
        $register($dispatcher);

        return $dispatcher;
    }),
    'telegram.dispatcher.base' => autowire(Dispatcher::class),

    // bot:poll's long polls, over a curl multi handle of their own (LongPolls), and the file only one poller holds.
    'telegram.polling.curl' => factory(static fn(): CurlMultiHandler => new CurlMultiHandler(['select_timeout' => 1])),
    'telegram.polling' => factory(static fn(ContainerInterface $c): HandlerStack => HandlerStack::create($c->get('telegram.polling.curl'))),
    LongPolls::class => autowire()->constructorParameter('transport', get('telegram.polling'))->constructorParameter('curl', get('telegram.polling.curl')),
    'poller.lock' => factory(fn(Application $app) => $app->storagePath('cache/bot-poll.lock')),
    BotPollCommand::class => autowire()->constructorParameter('lock', get('poller.lock')),
    // The poller's scheduler runs, each in a process of its own (`schedule:run`); none where PHP starts no process.
    'schedule.background' => factory(fn(Application $app) => BackgroundRun::available() ? new BackgroundRun([PHP_BINARY, $app->basePath('bin/console'), 'schedule:run']) : null),
    Poller::class => autowire()->constructorParameter('background', get('schedule.background')),
];
