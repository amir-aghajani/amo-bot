<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/**
 * Core and Support name no module: what they need of one is an interface of theirs the module implements (the
 * scheduler's Shops is the Bots module's BotShops), so the domain can change without the ground under it moving. And a
 * module that another leans on does not lean back: the website's API (Store) calls the customer's account (Accounts),
 * whose sign-ins ask what they need of the website through an interface of their own (Accounts\Contracts\SignInSite,
 * the Store module's Website implementing it). Between the modules, what each names is a map (MODULES): a new
 * dependency is an edit of it, never an accident.
 */
final class LayersTest extends TestCase
{
    /**
     * The modules each module names (App\Modules\<Module>\…), as the tree stands — exactly: a dependency the code takes
     * on is added here, by decision, and one it gives up leaves, so the map says no more than the code does. The doors to
     * the whole shop — the panels' API (Admin), the website's (Store), the bot and its report group (Telegram) — name most
     * of it. Telegram is also where the report group is told what happened (Telegram\Reports\ShopReports) and Telegram is
     * spoken to (Telegram\Api): a module whose news the group hears, or that asks Telegram itself, names it. Beside an edge
     * that is no plain use of the other module's rows or services, why.
     */
    private const MODULES = [
        // A merge offer counts the other account's services (Subscriptions).
        'Accounts' => ['Auth', 'Bots', 'Notifications', 'Subscriptions', 'Telegram', 'Users'],
        'Admin' => ['Accounts', 'Agency', 'Auth', 'Bots', 'Catalog', 'Orders', 'Payments', 'Providers', 'Referrals', 'Reviews', 'Settings', 'Store', 'Subscriptions', 'Support', 'Telegram', 'Users'],
        // An agent's shop opens with a wallet of its own (Payments' built-in method); a plan's traffic, drawn from the agent's pool (Catalog).
        'Agency' => ['Auth', 'Bots', 'Catalog', 'Notifications', 'Orders', 'Payments', 'Settings', 'Subscriptions', 'Telegram', 'Users'],
        'Api' => [],
        // An actor may be a customer — one of the shop's admins —, named by who reads the decision (Users).
        'Auth' => ['Bots', 'Users'],
        // A bot's agent, its traffic and what it sold (Bot::stats()); a token's bot id (Telegram's BotToken).
        'Bots' => ['Agency', 'Orders', 'Subscriptions', 'Telegram', 'Users'],
        // A plan sold or in use is not deleted: its orders and services counted (Orders, Subscriptions).
        'Catalog' => ['Bots', 'Orders', 'Providers', 'Subscriptions'],
        'Installer' => ['Auth', 'Settings', 'Telegram'],
        'Notifications' => ['Bots', 'Orders', 'Payments', 'Referrals', 'Subscriptions', 'Support', 'Telegram', 'Users'],
        'Orders' => ['Agency', 'Auth', 'Bots', 'Catalog', 'Notifications', 'Payments', 'Providers', 'Subscriptions', 'Telegram', 'Users'],
        // A refund takes an agent's traffic back (Agency); money that came in earns a referrer (Referrals).
        'Payments' => ['Agency', 'Auth', 'Bots', 'Notifications', 'Orders', 'Referrals', 'Telegram', 'Users'],
        // A server with services is not deleted (Subscriptions); a panel down or up is the report group's news (Telegram).
        'Providers' => ['Bots', 'Subscriptions', 'Telegram'],
        'Referrals' => ['Bots', 'Payments', 'Settings', 'Users'],
        'Reviews' => ['Auth', 'Bots', 'Telegram', 'Users'],
        'Scheduling' => [],
        // Each bot's own rows (Bots' CurrentBot); the owner's «تنظیمات پنل» checks the main bot's token (Telegram's BotToken),
        // and asks the owner's password (Auth's AdminAccount) before every bot's token goes to another Bot API address.
        'Settings' => ['Auth', 'Bots', 'Telegram'],
        'Store' => ['Accounts', 'Auth', 'Bots', 'Catalog', 'Notifications', 'Orders', 'Payments', 'Providers', 'Referrals', 'Reviews', 'Subscriptions', 'Support', 'Telegram', 'Users'],
        // Traffic given in an agent's shop is the agent's (Agency); «تمدید خودکار» pays from the wallet (Payments).
        'Subscriptions' => ['Agency', 'Auth', 'Bots', 'Catalog', 'Notifications', 'Orders', 'Payments', 'Providers', 'Settings', 'Telegram', 'Users'],
        'Support' => ['Auth', 'Bots', 'Notifications', 'Subscriptions', 'Telegram', 'Users'],
        'Telegram' => ['Agency', 'Auth', 'Bots', 'Catalog', 'Notifications', 'Orders', 'Payments', 'Providers', 'Referrals', 'Reviews', 'Settings', 'Subscriptions', 'Support', 'Users'],
        // The newest release read last is the installation's runtime state: the main bot's settings rows (Bots, Settings).
        'Updates' => ['Bots', 'Settings'],
        // Support signs a customer out or turns their two-factor sign-in off (Accounts); a newcomer's referrer (Referrals);
        // a newcomer reported, a picture fetched from Telegram (Telegram).
        'Users' => ['Accounts', 'Agency', 'Auth', 'Bots', 'Notifications', 'Orders', 'Payments', 'Referrals', 'Settings', 'Subscriptions', 'Telegram'],
    ];

    public function testCoreAndSupportNameNoModule(): void
    {
        $files = 0;
        foreach (['app/Core', 'app/Support'] as $layer) {
            foreach (self::phpFiles($layer) as $path => $source) {
                $files++;
                self::assertStringNotContainsString('App\\Modules\\', $source, $path);
            }
        }
        self::assertGreaterThan(10, $files, 'the layers were read');
    }

    public function testTheAccountsNameNoWebsite(): void
    {
        $files = 0;
        foreach (self::phpFiles('app/Modules/Accounts') as $path => $source) {
            $files++;
            self::assertStringNotContainsString('App\\Modules\\Store\\', $source, $path);
        }
        self::assertGreaterThan(10, $files, 'the module was read');
    }

    public function testEachModuleNamesTheModulesTheMapSaysAndNoOther(): void
    {
        $modules = [];
        foreach (new \DirectoryIterator(dirname(__DIR__, 3) . '/app/Modules') as $folder) {
            if ($folder->isDir() && !$folder->isDot()) {
                $modules[] = $folder->getFilename();
            }
        }
        sort($modules);
        self::assertSame(array_keys(self::MODULES), $modules, 'every module is in the map, in order, and only a module');

        foreach ($modules as $module) {
            $named = [];
            foreach (self::phpFiles("app/Modules/{$module}") as $source) {
                preg_match_all('/App\\\\Modules\\\\(\w+)\\\\/', $source, $matches);
                $named += array_fill_keys($matches[1], true);
            }
            unset($named[$module]);
            $named = array_keys($named);
            sort($named);

            self::assertSame(self::MODULES[$module], $named, "{$module}: a module it names newly is a decision of MODULES; one it no longer names leaves it");
        }
    }

    /** @return iterable<string, string> Every PHP file under the folder, by its path: its source. */
    private static function phpFiles(string $folder): iterable
    {
        $base = dirname(__DIR__, 3);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$base}/{$folder}", \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                yield $file->getPathname() => (string) file_get_contents($file->getPathname());
            }
        }
    }
}
