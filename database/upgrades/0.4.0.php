<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Brings a 0.3.0 database to 0.4.0: when a customer added a service to their account by sending its link (a panel
 * client the shop did not sell), and whether a renewal gave such a service its plan (its refund takes the plan back);
 * what an order brings in devices (extra devices, a built service's limit), the client name a buyer asked for, how a
 * renewal adds its package, the device limit a renewal sets — at once, or queued behind the period in use — with what
 * it replaced, and what else a renewal's refund and an earlier order's need of it (what a renewal gave, signed now); and
 * the cashback rules for every kind of order now for extra devices too. See docs/Database.md for the rules an upgrade
 * keeps.
 *
 * Every step adds one column, and only when the database lacks it, changes one only while it is as 0.3.0 made it, or
 * fills what it lacks, so a run that stopped half-way is simply run again.
 */
return static function (Builder $schema, Connection $db): void {
    $addColumn = static function (string $table, string $column, Closure $define) use ($schema): void {
        if (!$schema->hasColumn($table, $column)) {
            $schema->table($table, $define);
        }
    };

    $addColumn('subscriptions', 'claimed_at', static fn(Blueprint $table) => $table->timestamp('claimed_at')->nullable()->after('is_test'));
    $addColumn('orders', 'devices', static fn(Blueprint $table) => $table->unsignedInteger('devices')->nullable()->after('days'));
    $addColumn('orders', 'client_name', static fn(Blueprint $table) => $table->string('client_name', 64)->nullable()->after('devices'));
    $addColumn('orders', 'renewal_method', static fn(Blueprint $table) => $table->string('renewal_method', 16)->nullable()->after('client_name'));
    // Every renewal so far extended its service — so one still awaiting its payment is found again by the same checkout.
    $db->table('orders')->where('type', 'renewal')->whereNull('renewal_method')->update(['renewal_method' => 'extend']);
    $addColumn('orders', 'gave_plan', static fn(Blueprint $table) => $table->boolean('gave_plan')->default(false)->after('given_bytes'));
    // A renewal's device limit: set at once, or queued behind the period in use; its refund puts back what it replaced.
    $addColumn('subscriptions', 'next_period_ip_limit', static fn(Blueprint $table) => $table->unsignedInteger('next_period_ip_limit')->nullable()->after('next_period_bytes'));
    $addColumn('subscriptions', 'ip_limit_reset_at', static fn(Blueprint $table) => $table->timestamp('ip_limit_reset_at')->nullable()->after('next_period_ip_limit'));
    $addColumn('orders', 'given_ip_limit', static fn(Blueprint $table) => $table->unsignedInteger('given_ip_limit')->nullable()->after('gave_plan'));
    $addColumn('orders', 'ip_limit_was', static fn(Blueprint $table) => $table->unsignedInteger('ip_limit_was')->nullable()->after('given_ip_limit'));
    $addColumn('orders', 'ip_limit_queued', static fn(Blueprint $table) => $table->boolean('ip_limit_queued')->default(false)->after('ip_limit_was'));
    // What a renewal gave where the service had none, what it started afresh, and when the limit it set at once had last
    // gone back to a package's: the refunds of it and of the orders before it.
    $addColumn('orders', 'gave_term', static fn(Blueprint $table) => $table->boolean('gave_term')->default(false)->after('gave_plan'));
    $addColumn('orders', 'gave_quota', static fn(Blueprint $table) => $table->boolean('gave_quota')->default(false)->after('gave_term'));
    $addColumn('orders', 'reset_term', static fn(Blueprint $table) => $table->boolean('reset_term')->default(false)->after('gave_quota'));
    $addColumn('orders', 'reset_traffic', static fn(Blueprint $table) => $table->boolean('reset_traffic')->default(false)->after('reset_term'));
    $addColumn('orders', 'reset_ip_limit', static fn(Blueprint $table) => $table->boolean('reset_ip_limit')->default(false)->after('reset_traffic'));
    $addColumn('orders', 'ip_limit_reset_was', static fn(Blueprint $table) => $table->timestamp('ip_limit_reset_was')->nullable()->after('ip_limit_queued'));
    // The term and the traffic left a renewal onto a package without them replaced: its refund puts them back.
    $addColumn('orders', 'term_was', static fn(Blueprint $table) => $table->unsignedInteger('term_was')->nullable()->after('gave_quota'));
    $addColumn('orders', 'expires_was', static fn(Blueprint $table) => $table->timestamp('expires_was')->nullable()->after('term_was'));
    $addColumn('orders', 'quota_was', static fn(Blueprint $table) => $table->unsignedBigInteger('quota_was')->nullable()->after('expires_was'));

    // What a renewal gave is signed now: a reset that replaced more than it gives gave less than nothing, which its refund
    // gives back. MySQL and MariaDB change the column once — while it is still unsigned, so a second run changes nothing;
    // SQLite keeps no sign, and has nothing to change.
    $signed = static function (string $table, string $column, Closure $define) use ($schema): void {
        foreach ($schema->getColumns($table) as $kept) {
            if ($kept['name'] === $column && str_contains(strtolower((string) $kept['type']), 'unsigned')) {
                $schema->table($table, $define);
            }
        }
    };
    $signed('orders', 'given_hours', static fn(Blueprint $table) => $table->integer('given_hours')->nullable()->change());
    $signed('orders', 'given_bytes', static fn(Blueprint $table) => $table->bigInteger('given_bytes')->nullable()->change());

    // A cashback rule saved for every kind of order 0.3.0 sold is for every kind still: extra devices are one now. A rule
    // already for them, or for fewer kinds, stays as it is.
    $before = ['purchase', 'renewal', 'extra_traffic', 'extra_time'];
    foreach ($db->table('cashback_rules')->whereNotNull('order_types')->get(['id', 'order_types']) as $rule) {
        $kinds = json_decode((string) $rule->order_types, true);
        if (is_array($kinds) && count($kinds) === count($before) && array_diff($before, $kinds) === []) {
            $db->table('cashback_rules')->where('id', $rule->id)->update(['order_types' => json_encode([...$before, 'extra_devices'])]);
        }
    }
};
