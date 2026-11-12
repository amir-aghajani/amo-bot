<?php

declare(strict_types=1);

namespace App\Modules\Backups;

/**
 * What a backup holds, table by table — every table of database/schema.php is one of four kinds, so a table a later
 * version adds cannot be left out unnoticed (BackupTablesTest):
 * - a shop's own (SHOP): its rows carry the shop's `bot_id`;
 * - a shop's by its parent (CHILDREN): a row of a shop's row — a plan's servers, a ticket's messages;
 * - the owner's (OWNER): the installation's servers, its agents' terms and traffic, its mass grants and moves, what it
 *   keeps from an agent's shop — in a backup of the whole installation alone, and never touched by a shop's restore;
 * - what is true only now (TRANSIENT): sign-ins, chats half-way, a queue, an idempotency window, the panels' feed —
 *   never in a backup: restored, an old sign-in would open again.
 *
 * In the order database/schema.php makes them, a parent before its rows: a restore inserts in this order and deletes in
 * the reverse.
 */
final class BackupTables
{
    /**
     * The oldest version whose file a restore takes. A file's rows are cut to today's columns as they go in, and only then
     * do the upgrades since its version run (Restorer::restore()): data an upgrade moves — a column renamed, a value
     * carried into another table — would be lost on the way. An upgrade that moves data raises this to its own version,
     * so a file made before it is refused rather than restored without it (docs/Backups.md).
     */
    public const OLDEST_RESTORABLE = '0.8.0';

    /** A shop's own rows, found by their `bot_id`. */
    public const SHOP = [
        'settings', 'users', 'wallet_transactions', 'wallet_transfers', 'customer_groups', 'websites', 'mini_apps',
        'account_merges', 'notifications', 'plan_categories', 'plans', 'subscriptions', 'test_accounts', 'payment_methods',
        'payment_cards', 'discount_codes', 'sales', 'loyalty_tiers', 'cashback_rules', 'wheel_prizes', 'orders', 'gift_codes',
        'wheel_spins', 'cashbacks', 'lottery_draws', 'payments', 'referral_commissions', 'referral_bonuses', 'referral_rewards',
        'referral_alerts', 'tickets', 'reviews', 'bot_channels', 'broadcasts', 'report_chats', 'report_topics', 'custom_emojis',
    ];

    /**
     * A shop's rows found by their parent's, each as [its column naming the parent, the parent's table] — the parent a
     * SHOP table, or another child.
     *
     * @var array<string, array{string, string}>
     */
    public const CHILDREN = [
        'customer_group_user' => ['customer_group_id', 'customer_groups'],
        'plan_servers' => ['plan_id', 'plans'],
        'plan_server_inbounds' => ['plan_server_id', 'plan_servers'],
        'lottery_winners' => ['lottery_draw_id', 'lottery_draws'],
        'ticket_messages' => ['ticket_id', 'tickets'],
        'broadcast_pins' => ['broadcast_id', 'broadcasts'],
    ];

    /** The owner's: the whole installation's backup holds them; a shop's backup and restore never do. */
    public const OWNER = [
        'bots', 'sequences', 'agency_levels', 'agency_requests', 'servers', 'server_inbounds', 'bot_hidden_servers', 'grants',
        'grant_parts', 'server_migrations', 'migration_failures', 'migration_leftovers', 'traffic_transactions',
    ];

    /**
     * Never in a backup; a restore empties them — every row for the whole installation, the shop's (by its `bot_id`) for
     * a shop: a sign-in, a chat half-way, a queued report.
     */
    public const TRANSIENT = [
        'change_versions', 'customer_sessions', 'auth_challenges', 'request_keys', 'telegram_updates', 'telegram_sessions',
        'report_messages',
    ];

    /** Of the transient ones, what a restore keeps as it is: the panels' feed counts on, Telegram's updates stay answered once. */
    public const KEPT_ON_RESTORE = ['change_versions', 'telegram_updates'];

    /**
     * Columns of a shop's rows that are the owner's to set, kept as they are now by a shop's restore (a plan the owner
     * keeps from sale stays kept).
     *
     * @var array<string, list<string>>
     */
    public const OWNERS_COLUMNS = ['plans' => ['hidden_by_owner']];

    /**
     * Columns of a shop's rows only the owner reads — why a delivery failed in full, the panel's address with it
     * (OrderDirectory::notes()) —, written empty in a shop's backup, which its agent downloads and gets in their report
     * group; a shop's restore keeps their values now.
     *
     * @var array<string, list<string>>
     */
    public const OWNER_ONLY_COLUMNS = ['orders' => ['diagnosis']];

    /**
     * Columns of a shop's rows naming who decided — the owner's login among them —, written in a shop's backup as anyone
     * but the owner reads them (Reviewers::shared(): «پشتیبانی» for the owner); a shop's restore keeps the owner's as
     * they are now.
     *
     * @var array<string, list<string>>
     */
    public const REVIEWER_COLUMNS = [
        'wallet_transactions' => ['reviewer'], 'orders' => ['created_by'], 'payments' => ['reviewer'], 'ticket_messages' => ['reviewer'],
        'reviews' => ['reviewer'], 'broadcasts' => ['reviewer'],
    ];

    /**
     * The owner's rows that name a shop's row, each as [table, column, the shop's table]: after a shop's restore, one
     * naming a row the restore left out names none (its foreign key's own rule, ON DELETE SET NULL).
     *
     * @var list<array{string, string, string}>
     */
    public const OWNERS_REFERENCES = [
        ['traffic_transactions', 'order_id', 'orders'],
        ['migration_failures', 'subscription_id', 'subscriptions'],
        ['migration_leftovers', 'subscription_id', 'subscriptions'],
        ['grants', 'customer_group_id', 'customer_groups'],
    ];

    /**
     * A shop's rows that name the owner's — a server, an inbound, an agency level — each as [table, column, the owner's
     * table, what a restore does when that row is gone now]: `refuse` (a service on a server that is gone: restored, it
     * would be a service on no server), `drop` (a plan's entry on it), `null` (an order's, a test's).
     *
     * @var list<array{string, string, string, 'refuse'|'drop'|'null'}>
     */
    public const SHOP_REFERENCES = [
        ['users', 'agency_level_id', 'agency_levels', 'null'],
        ['plan_servers', 'server_id', 'servers', 'drop'],
        ['plan_server_inbounds', 'server_inbound_id', 'server_inbounds', 'drop'],
        ['subscriptions', 'server_id', 'servers', 'refuse'],
        ['test_accounts', 'server_id', 'servers', 'null'],
        ['orders', 'server_id', 'servers', 'null'],
    ];

    /**
     * The tables a backup of `$scope` holds, in the schema's order.
     *
     * @param list<string> $schema Every table, in database/schema.php's order
     * @return list<string>
     */
    public static function of(BackupScope $scope, array $schema): array
    {
        $held = $scope->isShop() ? [...self::SHOP, ...array_keys(self::CHILDREN)] : [...self::SHOP, ...array_keys(self::CHILDREN), ...self::OWNER];

        return array_values(array_filter($schema, static fn(string $table): bool => in_array($table, $held, true)));
    }
}
