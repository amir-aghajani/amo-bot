<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Brings a 0.6.0 database to 0.7.0. See docs/Database.md for the rules an upgrade keeps.
 *
 * Every step adds one column or index, and only when the database lacks it, so a run that stopped half-way is simply
 * run again.
 */
return static function (Builder $schema, Connection $db): void {
    $addColumn = static function (string $table, string $column, Closure $define) use ($schema): void {
        if (!$schema->hasColumn($table, $column)) {
            $schema->table($table, $define);
        }
    };

    /** @param list<string> $columns */
    $addUnique = static function (string $table, array $columns) use ($schema): void {
        if (!$schema->hasIndex($table, $columns, 'unique')) {
            $schema->table($table, static fn(Blueprint $blueprint) => $blueprint->unique($columns));
        }
    };

    // —— Support's changes by hand under an Idempotency-Key: a wallet's credit or debit, an agent's traffic adjustment,
    // a ticket's answer — the same request again answered by the line or the message it wrote. Every row there was
    // written without one. ——
    $addColumn('wallet_transactions', 'request_key', static fn(Blueprint $table) => $table->string('request_key', 64)->nullable()->after('reviewer'));
    $addUnique('wallet_transactions', ['user_id', 'request_key']);
    $addColumn('traffic_transactions', 'request_key', static fn(Blueprint $table) => $table->string('request_key', 64)->nullable()->after('reviewer'));
    $addUnique('traffic_transactions', ['bot_id', 'request_key']);
    $addColumn('ticket_messages', 'request_key', static fn(Blueprint $table) => $table->string('request_key', 64)->nullable()->after('channel'));
    $addUnique('ticket_messages', ['ticket_id', 'request_key']);
};
