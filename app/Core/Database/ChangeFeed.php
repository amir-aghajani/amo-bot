<?php

declare(strict_types=1);

namespace App\Core\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Psr\Log\LoggerInterface;

/**
 * The panels' live view (`GET /api/{panel}/changes`): a version number per area of the shop that goes up once a write
 * to one of its tables is committed, by any process. A panel polls the numbers and reloads what the moved areas show.
 *
 * Writes are read off the connection's own query events, so no service has to report one. A write inside a
 * transaction counts at the commit, not at a rollback. The numbers are written outside the transaction (never holding
 * its locks) and never from inside the writing statement's event — the driver's last insert id still belongs to that
 * statement — but just before the next statement, after a commit, or at shutdown. A failed bump is logged, never thrown.
 */
final class ChangeFeed
{
    /**
     * The tables the panels show, by area; any other table moves nothing. Grants are an area of their own because a
     * running grant moves its cursor every second.
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
        'referral_alerts' => 'referrals',
        'referral_bonuses' => 'referrals',
        'referral_rewards' => 'referrals',
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
