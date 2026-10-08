<?php

declare(strict_types=1);

namespace App\Core\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Psr\Log\LoggerInterface;

/**
 * What changed, for the panels' live view (`GET /api/{admin,agent}/changes`): a number per area of the shop that
 * goes up once a write to one of the area's tables is committed, whoever made it — a panel request, the bot, a
 * scheduled task, the report group's buttons. The panel asks for the numbers every few seconds and reloads only
 * what the areas that moved show.
 *
 * The writes are read off the connection's own events (every INSERT, UPDATE and DELETE it runs), so no service
 * has to remember to report one. A write inside a transaction counts once the transaction commits — the number
 * goes up after the commit, outside its locks, so it never holds up a payment — and not at all when it rolls
 * back. The numbers are never written from inside the event of the statement that wrote: the driver's last
 * insert id, which an INSERT's caller reads right after the statement, belongs to that statement. They are
 * written just before the connection's next statement, after a commit, or when the process ends. A number that
 * cannot go up is logged and never fails the write it follows.
 *
 * A write no screen needs to follow at once is made quietly() — a customer's presence, which their every message
 * refreshes — so it does not have every open panel read its lists again.
 */
final class ChangeFeed
{
    /**
     * The tables the panel shows, by area. Any other table — sessions, the bookkeeping, this feed's own — moves nothing. A
     * grant under way moves its cursor every second: an area of its own, so only the screens that follow it read again.
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
        'plan_categories' => 'plans',
        'plans' => 'plans',
        'plan_servers' => 'plans',
        'plan_server_inbounds' => 'plans',
        'payment_methods' => 'payment_methods',
        'orders' => 'orders',
        'payments' => 'payments',
        'referral_commissions' => 'referrals',
        'agency_levels' => 'agency',
        'agency_requests' => 'agency',
        'bots' => 'agency',
        'traffic_transactions' => 'agency',
        'subscriptions' => 'subscriptions',
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
    ];

    private const TABLE = 'change_versions';

    /** The table a writing statement writes to — quoted with backticks (MySQL) or double quotes (SQLite, PostgreSQL), or bare. */
    private const WRITE = '/^\s*(?:insert(?:\s+or\s+\w+)?(?:\s+ignore)?\s+into|replace\s+into|update(?:\s+ignore)?|delete(?:\s+[`"]?\w+[`"]?)?\s+from|truncate(?:\s+table)?)\s+[`"]?(\w+)[`"]?/i';

    /** @var array<string, true> Areas whose writes are committed and not counted yet */
    private array $committed = [];

    /** @var array<string, true> Areas written inside the open transaction: counted when it commits, dropped when it rolls back */
    private array $tentative = [];

    /** The transaction level a commit back to counts as the commit: 0, or the tests' own transaction. */
    private int $committedLevel = 0;

    private bool $counting = false;

    /** How many quietly() calls are under way: while any is, a write counts for nothing. */
    private int $quiet = 0;

    public function __construct(
        private readonly Connection $db,
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

    /** @return array<string, int> Each area's number; an area nothing was written to yet is absent (it is 0). */
    public function versions(): array
    {
        $this->count();

        $versions = [];
        foreach ($this->db->table(self::TABLE)->get(['area', 'version']) as $row) {
            $versions[(string) $row->area] = (int) $row->version;
        }

        return $versions;
    }

    /**
     * Make `$write` without moving any area — for a write no screen needs to follow at once (a customer's presence,
     * `users.last_seen_at`), which would otherwise have every open panel read its lists again. Only what `$write` itself
     * writes goes uncounted: anything written before or after it — in the same transaction too — counts as ever.
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
     * A commit back to `$level` is the commit. The tests run every case inside a transaction of their own that is
     * rolled back afterwards: for the feed, that transaction is the database.
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

        if ($this->inTransaction()) {
            $this->tentative[$area] = true;
        } else {
            $this->committed[$area] = true;
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
     * Write the committed areas' numbers — never inside a transaction, where they would wait on its locks — in one
     * statement for every area a commit moved: each is a write of its own, and a sale moves three.
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
