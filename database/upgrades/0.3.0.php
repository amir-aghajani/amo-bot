<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Brings a 0.2.0 database to 0.3.0: free test accounts («اکانت تست») — each panel's terms, the mark on a test's service
 * and the tests given —, when each service ended, which the automatic removal of ended services counts from, and what
 * a renewal gave and when a refund took it back. See docs/Database.md for the rules an upgrade keeps.
 *
 * Every step adds one column, table, index or foreign key, and only when the database lacks it, so a run that stopped
 * half-way is simply run again. A table's indexes come before its foreign keys: MySQL gives a foreign key without a
 * usable index an index of its own, which would then stand in for the one schema.php names.
 */
return static function (Builder $schema, Connection $db): void {
    $addColumn = static function (string $table, string $column, Closure $define) use ($schema): void {
        if (!$schema->hasColumn($table, $column)) {
            $schema->table($table, $define);
        }
    };

    $createTable = static function (string $table, Closure $columns) use ($schema): void {
        if (!$schema->hasTable($table)) {
            $schema->create($table, $columns);
        }
    };

    // `$onDelete`: restrict, cascade or set null — what schema.php's restrictOnDelete(), cascadeOnDelete() and
    // nullOnDelete() write. The key gets Laravel's usual name, `<table>_<column>_foreign`.
    $addForeignKey = static function (string $table, string $column, string $references, string $onDelete) use ($schema): void {
        foreach ($schema->getForeignKeys($table) as $key) {
            if ($key['columns'] === [$column]) {
                return;
            }
        }
        $schema->table($table, static function (Blueprint $blueprint) use ($column, $references, $onDelete): void {
            $blueprint->foreign($column)->references('id')->on($references)->onDelete($onDelete);
        });
    };

    /** @param list<string> $columns */
    $addIndex = static function (string $table, array $columns) use ($schema): void {
        if (!$schema->hasIndex($table, $columns)) {
            $schema->table($table, static fn(Blueprint $blueprint) => $blueprint->index($columns));
        }
    };

    // Each panel's test accounts: whether it gives them, and what each gets (500 MB and 24 hours from the first
    // connection until the owner sets otherwise; off until then).
    $addColumn('servers', 'test_enabled', static fn(Blueprint $table) => $table->boolean('test_enabled')->default(false)->after('meta'));
    $addColumn('servers', 'test_traffic_bytes', static fn(Blueprint $table) => $table->unsignedBigInteger('test_traffic_bytes')->default(524288000)->after('test_enabled'));
    $addColumn('servers', 'test_duration_hours', static fn(Blueprint $table) => $table->unsignedInteger('test_duration_hours')->default(24)->after('test_traffic_bytes'));
    $addColumn('servers', 'test_on_hold', static fn(Blueprint $table) => $table->boolean('test_on_hold')->default(true)->after('test_duration_hours'));

    // A test's service, and the tests given — what each customer's quota counts.
    $addColumn('subscriptions', 'is_test', static fn(Blueprint $table) => $table->boolean('is_test')->default(false)->after('plan_id'));
    $addIndex('subscriptions', ['bot_id', 'is_test', 'status']);

    $createTable('test_accounts', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->foreignId('bot_id')->default(1);
        $table->foreignId('user_id');
        $table->foreignId('server_id')->nullable();
        $table->foreignId('subscription_id')->nullable();
        $table->unsignedBigInteger('traffic_bytes');
        $table->unsignedInteger('duration_hours');
        $table->boolean('on_hold');
        $table->timestamp('issued_at')->nullable();
        $table->timestamps();
    });
    $addIndex('test_accounts', ['user_id', 'bot_id', 'created_at']);
    $addIndex('test_accounts', ['bot_id', 'created_at']);
    $addIndex('test_accounts', ['subscription_id']);
    $addForeignKey('test_accounts', 'bot_id', 'bots', 'restrict');
    $addForeignKey('test_accounts', 'user_id', 'users', 'cascade');
    $addForeignKey('test_accounts', 'server_id', 'servers', 'set null');
    $addForeignKey('test_accounts', 'subscription_id', 'subscriptions', 'set null');

    // What a renewal gave its service, and when a refund took back what an order gave.
    $addColumn('orders', 'given_hours', static fn(Blueprint $table) => $table->unsignedInteger('given_hours')->nullable()->after('days'));
    $addColumn('orders', 'given_bytes', static fn(Blueprint $table) => $table->unsignedBigInteger('given_bytes')->nullable()->after('given_hours'));
    $addColumn('orders', 'taken_back_at', static fn(Blueprint $table) => $table->timestamp('taken_back_at')->nullable()->after('fulfilled_at'));

    // When each service ended. The services that have ended already and have none are given theirs: the deadline they
    // passed, else when the shop last read them from their panel — so their removal counts its hours from then.
    $addColumn('subscriptions', 'ended_at', static fn(Blueprint $table) => $table->timestamp('ended_at')->nullable()->after('disabled_at'));
    $now = gmdate('Y-m-d H:i:s');
    $db->table('subscriptions')->whereIn('status', ['expired', 'deleted'])->whereNull('ended_at')->update(['ended_at' => $db->raw(
        "CASE WHEN expires_at IS NOT NULL AND expires_at <= '{$now}' THEN expires_at ELSE COALESCE(last_synced_at, updated_at, '{$now}') END",
    )]);
};
