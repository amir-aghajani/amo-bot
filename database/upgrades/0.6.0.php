<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Brings a 0.5.0 database to 0.6.0. See docs/Database.md for the rules an upgrade keeps.
 *
 * Every step adds one column, table, index or foreign key, and only when the database lacks it, or fills what it lacks,
 * so a run that stopped half-way is simply run again.
 */
return static function (Builder $schema, Connection $db): void {
    $addColumn = static function (string $table, string $column, Closure $define) use ($schema): void {
        if (!$schema->hasColumn($table, $column)) {
            $schema->table($table, $define);
        }
    };

    // —— Online payment gateways: the invoice a payment is paid on ——
    $addColumn('payments', 'link', static fn(Blueprint $table) => $table->string('link', 1000)->nullable()->after('reference'));
    $addColumn('payments', 'link_expires_at', static fn(Blueprint $table) => $table->timestamp('link_expires_at')->nullable()->after('link'));
    $addColumn('payments', 'invoice', static fn(Blueprint $table) => $table->json('invoice')->nullable()->after('link_expires_at'));
    $addColumn('payments', 'checked_at', static fn(Blueprint $table) => $table->timestamp('checked_at')->nullable()->after('invoice'));
};
