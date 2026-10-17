<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Core\Scheduling\Shops;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Psr\Log\LoggerInterface;

/**
 * The panels' live view (`GET /api/{panel}/changes`): a version number per area of the shop that goes up once a write
 * to one of its tables is committed, by any process. A panel polls the numbers and reloads what the moved areas show.
 *
 * Each write counts for the installation (the owner's panel, every shop's: versions()) and for the shop it was made in
 * (Shops::current() — an agent's panel and a website's admins follow their own shop alone: versions($shop)); one made
 * across every shop, or in forEveryShop(), counts for each. The servers' areas are the installation's: one number.
 *
 * Writes are read off the connection's own query events, so no service has to report one. A write inside a
 * transaction counts at the commit, not at a rollback. The numbers are written outside the transaction (never holding
 * its locks) and never from inside the writing statement's event — the driver's last insert id still belongs to that
 * statement — but just before the next statement, after a commit, or at shutdown. A failed bump is logged, never thrown.
 */
final class ChangeFeed
{
    /**
     * The tables the panels show, by area; any other table moves nothing. Grants and the moves of a server's services
     * are areas of their own because one under way moves its cursor every second.
     */
    public const AREAS = [
        'users' => 'users',
        'wallet_transactions' => 'users',
        'customer_groups' => 'users',
        'customer_group_user' => 'users',
        'account_merges' => 'users',
        'servers' => 'servers',
        'server_inbounds' => 'servers',
        'grants' => 'grants',
        'grant_parts' => 'grants',
        'server_migrations' => 'migrations',
        'migration_failures' => 'migrations',
        'migration_leftovers' => 'migrations',
        'plan_categories' => 'plans',
        'plans' => 'plans',
        'plan_servers' => 'plans',
        'plan_server_inbounds' => 'plans',
        'bot_hidden_servers' => 'plans',
        'payment_methods' => 'payment_methods',
        'payment_cards' => 'payment_methods',
        'orders' => 'orders',
        'payments' => 'payments',
        'referral_commissions' => 'referrals',
        'referral_alerts' => 'referrals',
        'referral_bonuses' => 'referrals',
        'referral_rewards' => 'referrals',
        'agency_levels' => 'agency',
        'agency_requests' => 'agency',
        'bots' => 'agency',
        'traffic_transactions' => 'agency',
        'subscriptions' => 'subscriptions',
        'test_accounts' => 'tests',
        'bot_channels' => 'channels',
        'broadcasts' => 'broadcasts',
        'broadcast_pins' => 'broadcasts',
        'report_chats' => 'reports',
        'report_topics' => 'reports',
        'report_messages' => 'reports',
        'custom_emojis' => 'emoji',
        'tickets' => 'tickets',
        'ticket_messages' => 'tickets',
        'reviews' => 'reviews',
        'gift_codes' => 'rewards',
        'wheel_prizes' => 'rewards',
        'wheel_spins' => 'rewards',
        'loyalty_tiers' => 'rewards',
        'cashback_rules' => 'rewards',
        'lottery_draws' => 'rewards',
        'lottery_winners' => 'rewards',
        'discount_codes' => 'discounts',
        'sales' => 'discounts',
    ];

    /** The areas that are the installation's, not a shop's — the servers and the owner's work on them: one number each. */
    private const INSTALLATION = ['servers', 'grants', 'migrations'];

    /**
     * The area a shop other than the main one follows the main shop's writes to as well: the agency is the main shop's
     * business with its agents (an agent's traffic bought from the main bot, a request).
     */
    private const AGENCY = 'agency';

    /** The shop a write counts for when it is every shop's (`<area>.*`); a shop's own is `<area>.<bot id>`. */
    private const EVERY_SHOP = '*';

    private const TABLE = 'change_versions';

    /** The main bot's shop (Bots\Models\Bot::MAIN, made with the database). */
    private const MAIN_SHOP = 1;

    /** The table a writing statement writes to — quoted with backticks (MySQL) or double quotes (SQLite, PostgreSQL), or bare. */
    private const WRITE = '/^\s*(?:insert(?:\s+or\s+\w+)?(?:\s+ignore)?\s+into|replace\s+into|update(?:\s+ignore)?|delete(?:\s+[`"]?\w+[`"]?)?\s+from|truncate(?:\s+table)?)\s+[`"]?(\w+)[`"]?/i';

    /** @var array<string, true> The numbers (keys()) whose writes are committed and not counted yet */
    private array $committed = [];

    /** @var array<string, true> The numbers written inside the open transaction: counted when it commits, dropped when it rolls back */
    private array $tentative = [];

    /** The transaction level a commit back to counts as the commit: 0, or the tests' own transaction. */
    private int $committedLevel = 0;

    private bool $counting = false;

    /** How many quietly() calls are under way: while any is, a write counts for nothing. */
    private int $quiet = 0;

    /** How many forEveryShop() calls are under way: while any is, a write counts for every shop. */
    private int $everyShop = 0;

    public function __construct(
        private readonly Connection $db,
        private readonly Shops $shops,
        private readonly LoggerInterface $logger,
    ) {}

    /** Follow the connection's writes from now on. */
    public function listen(): void
    {
        $events = $this->db->getEventDispatcher();
        $events?->listen(QueryExecuted::class, $this->executed(...));
        $events?->listen(TransactionCommitted::class, $this->transactionCommitted(...));
        $events?->listen(TransactionRolledBack::class, $this->transactionRolledBack(...));

        $this->db->beforeExecuting(fn() => $this->count());
        register_shutdown_function(fn() => $this->count());
    }

    /**
     * Each area's number: the installation's (every shop's writes — the owner's panel), or what one shop follows (an
     * agent's panel, a website's admins): its own writes and every shop's — the servers' areas the installation's, and
     * the agency's in the main shop too (AGENCY). An area nothing was written to yet is absent (it is 0).
     *
     * @return array<string, int>
     */
    public function versions(?int $shop = null): array
    {
        $this->count();

        $read = [];
        foreach (array_unique(self::AREAS) as $area) {
            $shops = match (true) {
                $shop === null, in_array($area, self::INSTALLATION, true) => [null],
                $area === self::AGENCY => [$shop, self::MAIN_SHOP, self::EVERY_SHOP],
                default => [$shop, self::EVERY_SHOP],
            };
            foreach (array_unique($shops) as $one) {
                $read[self::key($area, $one)] = $area;
            }
        }

        $versions = [];
        foreach ($this->db->table(self::TABLE)->whereIn('area', array_keys($read))->get(['area', 'version']) as $row) {
            $area = $read[(string) $row->area];
            $versions[$area] = ($versions[$area] ?? 0) + (int) $row->version;
        }

        return $versions;
    }

    /**
     * Run `$write` with what it writes counted for every shop — the owner's agency, whose decisions about an agent's
     * shop (their level, traffic, hidden servers) are written from the main one.
     *
     * @template T
     * @param \Closure(): T $write
     * @return T
     */
    public function forEveryShop(\Closure $write): mixed
    {
        $this->everyShop++;
        try {
            return $write();
        } finally {
            $this->everyShop--;
        }
    }

    /**
     * Run `$write` without moving any area — for a write no screen needs to follow (a customer's `last_seen_at`), which
     * would otherwise make every open panel reload. Only what `$write` itself writes goes uncounted.
     *
     * @template T
     * @param \Closure(): T $write
     * @return T
     */
    public function quietly(\Closure $write): mixed
    {
        $this->quiet++;
        try {
            return $write();
        } finally {
            $this->quiet--;
        }
    }

    /**
     * Treat a commit back to `$level` as the commit. The tests run each case inside a transaction rolled back
     * afterwards; for the feed, that transaction is the database.
     */
    public function countAsCommitted(int $level): void
    {
        $this->committedLevel = $level;
        $this->committed = [];
        $this->tentative = [];
    }

    private function executed(QueryExecuted $event): void
    {
        if ($this->quiet > 0 || $event->connection !== $this->db || preg_match(self::WRITE, $event->sql, $match) !== 1) {
            return;
        }

        $area = self::AREAS[$this->unprefixed($match[1])] ?? null;
        if ($area === null) {
            return;
        }

        // The installation's number, and the shop's own — every shop's while no one shop is the writer's.
        $keys = [self::key($area, null) => true];
        if (!in_array($area, self::INSTALLATION, true)) {
            $keys[self::key($area, $this->everyShop > 0 ? self::EVERY_SHOP : $this->shops->current() ?? self::EVERY_SHOP)] = true;
        }

        if ($this->inTransaction()) {
            $this->tentative += $keys;
        } else {
            $this->committed += $keys;
        }
    }

    private function transactionCommitted(TransactionCommitted $event): void
    {
        if ($event->connection === $this->db && !$this->inTransaction()) {
            $this->committed += $this->tentative;
            $this->tentative = [];
            $this->count();
        }
    }

    private function transactionRolledBack(TransactionRolledBack $event): void
    {
        // Only the whole transaction takes its writes back with it. What a rolled-back savepoint wrote is still
        // counted: a number that went up for nothing costs the panel one reload, a missed one a stale screen.
        if ($event->connection === $this->db && !$this->inTransaction()) {
            $this->tentative = [];
        }
    }

    /**
     * Bump the committed areas' numbers in one statement (a sale moves three areas); never inside a transaction, where
     * the bump would wait on its locks.
     */
    private function count(): void
    {
        if ($this->counting || $this->committed === [] || $this->inTransaction()) {
            return;
        }

        $this->counting = true;
        $areas = array_keys($this->committed);
        $this->committed = [];

        try {
            if ($this->db->table(self::TABLE)->whereIn('area', $areas)->increment('version') < count($areas)) {
                // An area's first write makes its row; those that have one already keep the number they were just given.
                $this->db->table(self::TABLE)->insertOrIgnore(array_map(static fn(string $area): array => ['area' => $area, 'version' => 1], $areas));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('The change feed did not count a write to {areas}: {message}', ['areas' => implode(', ', $areas), 'message' => $e->getMessage()]);
        } finally {
            $this->counting = false;
        }
    }

    /** The row an area's number is kept in: `<area>` the installation's, `<area>.<bot id>` a shop's, `<area>.*` every shop's. */
    private static function key(string $area, int|string|null $shop): string
    {
        return $shop === null ? $area : "{$area}.{$shop}";
    }

    private function inTransaction(): bool
    {
        return $this->db->transactionLevel() > $this->committedLevel;
    }

    private function unprefixed(string $table): string
    {
        $prefix = $this->db->getTablePrefix();

        return $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;
    }
}
