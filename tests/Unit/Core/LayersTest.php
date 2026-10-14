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
     * The modules each module names (App\Modules\<Module>\…), exactly as the tree stands: a new dependency is added here
     * on purpose, and one the code gives up is removed, so the map says no more than the code does. The doors to the
     * whole shop — the panels' API (Admin), the website's (Store), the bot and its report group (Telegram) — name most of
     * it. Telegram is also where the report group is told what happened (Telegram\Reports\ShopReports) and Telegram is
     * spoken to (Telegram\Api), so a module whose news the group hears, or that calls Telegram itself, names it. An edge
     * that is more than a plain use of the other module's rows or services says why.
     */
    private const MODULES = [
        // A merge offer counts the other account's services (Subscriptions).
        'Accounts' => ['Auth', 'Bots', 'Notifications', 'Subscriptions', 'Telegram', 'Users'],
        'Admin' => ['Accounts', 'Agency', 'Auth', 'Bots', 'Catalog', 'Discounts', 'Orders', 'Payments', 'Providers', 'Referrals', 'Reviews', 'Rewards', 'Settings', 'Store', 'Subscriptions', 'Support', 'Telegram', 'Users'],
        // An agent's shop opens with a wallet of its own (Payments' built-in method); an order's traffic, drawn from the agent's pool (Orders).
        'Agency' => ['Auth', 'Bots', 'Notifications', 'Orders', 'Payments', 'Settings', 'Subscriptions', 'Telegram', 'Users'],
        // An actor may be a customer — one of the shop's admins —, named by who reads the decision (Users).
        'Auth' => ['Bots', 'Users'],
        // A bot's agent, its traffic and what it sold (Bot::stats()); a token's bot id (Telegram's BotToken).
        'Bots' => ['Agency', 'Orders', 'Subscriptions', 'Telegram', 'Users'],
        // A plan sold or in use is not deleted: its orders and services counted (Orders, Subscriptions); its button in the
        // bot's plan list is drawn in one of the bot's colours (Telegram's KeyboardLayout::STYLES); the custom builder's
        // price list is a bot setting (Settings).
        'Catalog' => ['Bots', 'Orders', 'Providers', 'Settings', 'Subscriptions', 'Telegram'],
        // A customer's loyalty tier is by what they spent: their orders (Orders). A code holds for the plans and the servers
        // named (Catalog, Providers), for a customer who bought nothing (Orders), its uses its orders; its hold is a bot
        // setting (Settings); a customer's own discount is set under whoever set it (Auth's Actor).
        'Discounts' => ['Auth', 'Bots', 'Catalog', 'Orders', 'Providers', 'Settings', 'Users'],
        'Installer' => ['Auth', 'Settings', 'Telegram'],
        // A loyalty tier reached is announced with its name and perks (Discounts' LoyaltyTier); a refund says the cashback it took
        // back (Rewards' Cashback).
        'Notifications' => ['Bots', 'Discounts', 'Orders', 'Payments', 'Referrals', 'Rewards', 'Subscriptions', 'Support', 'Telegram', 'Users'],
        // What a customer pays is their price less the discounts (Discounts), an order made with a code taking one of its uses;
        // a delivered order earns its customer their cashback and the loyalty tier it reached, after its commit (Rewards).
        'Orders' => ['Agency', 'Auth', 'Bots', 'Catalog', 'Discounts', 'Notifications', 'Payments', 'Providers', 'Rewards', 'Subscriptions', 'Telegram', 'Users'],
        // A refund takes an agent's traffic back (Agency), and the cashback the order earned out of what it gives (Rewards);
        // money that came in earns a referrer (Referrals). A checkout may meet a code whose last use went to someone else in
        // the same moment (Discounts' refusal, which it lets through to its door).
        'Payments' => ['Agency', 'Auth', 'Bots', 'Discounts', 'Notifications', 'Orders', 'Referrals', 'Rewards', 'Telegram', 'Users'],
        // A server with services is not deleted (Subscriptions); a panel down or up is the report group's news (Telegram).
        'Providers' => ['Bots', 'Subscriptions', 'Telegram'],
        // A renewal earns at a rate of its own and a referral's first purchase is one of their orders (Orders); the start
        // gift and the plan reward are given as every gift is (Rewards: the plan a Catalog's) and told (Notifications); an
        // alert on referrals that look made up goes to the report group (Telegram), and no admin clears one on themselves
        // (Auth's Actor).
        'Referrals' => ['Auth', 'Bots', 'Catalog', 'Notifications', 'Orders', 'Payments', 'Rewards', 'Settings', 'Telegram', 'Users'],
        'Reviews' => ['Auth', 'Bots', 'Telegram', 'Users'],
        // A prize or a gift is a wallet's line, a service's grant (in an agent's shop out of their pool: Agency) or a
        // plan's service given free — an order the shop's wallet settles (Orders, Payments), sent as any order is
        // (Notifications), worded as the bot words traffic (Telegram's Messages). A gift code, a wheel's slice and spin are
        // each shop's own rows (Bots), the wheel and the codes switched in its bot settings (Settings); a prize a panel
        // failed to give is said in a word (Providers' ProviderErrorPresenter). Cashback adds the customer's loyalty tier's
        // percent, and the nightly draw's entries are what customers spent on (Discounts' Loyalty).
        'Rewards' => ['Agency', 'Auth', 'Bots', 'Catalog', 'Discounts', 'Notifications', 'Orders', 'Payments', 'Providers', 'Settings', 'Subscriptions', 'Telegram', 'Users'],
        // Each bot's own rows (Bots' CurrentBot); the owner's «تنظیمات پنل» checks the main bot's token (Telegram's BotToken),
        // and asks the owner's password (Auth's AdminAccount) before every bot's token goes to another Bot API address.
        'Settings' => ['Auth', 'Bots', 'Telegram'],
        'Store' => ['Accounts', 'Auth', 'Bots', 'Catalog', 'Discounts', 'Notifications', 'Orders', 'Payments', 'Providers', 'Referrals', 'Reviews', 'Rewards', 'Subscriptions', 'Support', 'Telegram', 'Users'],
        // Traffic given in an agent's shop is the agent's (Agency); «تمدید خودکار» pays from the wallet, and a refund
        // takes back what its order gave a service through Payments' RefundedOrders, which RefundedDeliveries
        // implements (Payments).
        'Subscriptions' => ['Agency', 'Auth', 'Bots', 'Catalog', 'Notifications', 'Orders', 'Payments', 'Providers', 'Settings', 'Telegram', 'Users'],
        'Support' => ['Auth', 'Bots', 'Notifications', 'Subscriptions', 'Telegram', 'Users'],
        'Telegram' => ['Agency', 'Auth', 'Bots', 'Catalog', 'Discounts', 'Notifications', 'Orders', 'Payments', 'Providers', 'Referrals', 'Reviews', 'Rewards', 'Settings', 'Subscriptions', 'Support', 'Users'],
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
