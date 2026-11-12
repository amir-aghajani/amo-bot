<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Brings a 0.7.x database to 0.8.0: each shop's Telegram mini app, the secret its fingerprint sign-in proves a session
 * by, a plan's label, the premium emoji of a plan's and a category's name, a customer's own name for a service, when
 * support last read a ticket, and a website's sign-in by SMS (its switch, and the index a sign-in finds a customer's
 * mobile by). See docs/Database.md for the rules an upgrade keeps.
 *
 * Every step adds one table, column, index or foreign key, and only when the database lacks it, so a run that stopped
 * half-way is simply run again — MySQL makes a table, then each of its indexes and keys, outside any transaction. A
 * table's indexes come before its foreign keys: MySQL gives a foreign key without a usable index an index of its own.
 */
return static function (Builder $schema, Connection $db): void {
    $addColumn = static function (string $table, string $column, Closure $define) use ($schema): void {
        if (!$schema->hasColumn($table, $column)) {
            $schema->table($table, $define);
        }
    };

    /** @param list<string> $columns */
    $addIndex = static function (string $table, array $columns, bool $unique = false) use ($schema): void {
        if (!$schema->hasIndex($table, $columns, $unique ? 'unique' : null)) {
            $schema->table($table, static fn(Blueprint $blueprint) => $unique ? $blueprint->unique($columns) : $blueprint->index($columns));
        }
    };

    // `$onDelete` as schema.php's restrictOnDelete() writes it; the key gets Laravel's usual name.
    $addForeignKey = static function (string $table, string $column, string $references, string $onDelete) use ($schema): void {
        foreach ($schema->getForeignKeys($table) as $key) {
            if ($key['columns'] === [$column]) {
                return;
            }
        }
        $schema->table($table, static fn(Blueprint $blueprint) => $blueprint->foreign($column)->references('id')->on($references)->onDelete($onDelete));
    };

    if (!$schema->hasTable('mini_apps')) {
        $schema->create('mini_apps', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->unique()->constrained('bots')->restrictOnDelete();
            $table->string('key', 24)->unique();
            $table->boolean('enabled')->default(false);
            $table->string('title', 64)->nullable();
            $table->string('accent_color', 7)->nullable();
            $table->string('logo_path', 100)->nullable();
            $table->string('announcement', 500)->nullable();
            $table->string('announcement_url', 255)->nullable();
            $table->boolean('fullscreen')->default(true);
            $table->boolean('reviews_enabled')->default(false);
            $table->boolean('menu_button')->default(true);
            $table->string('menu_button_text', 32)->nullable();
            $table->timestamps();
        });
    }

    // One mini app a shop (MiniApps::current() counts on it), each under its own key: made here too should a run have
    // stopped once the table was made.
    $addIndex('mini_apps', ['bot_id'], unique: true);
    $addIndex('mini_apps', ['key'], unique: true);
    $addForeignKey('mini_apps', 'bot_id', 'bots', 'restrict');

    $addColumn('customer_sessions', 'biometric_hash', static fn(Blueprint $table) => $table->string('biometric_hash', 64)->nullable()->after('method'));
    $addColumn('plans', 'badge', static fn(Blueprint $table) => $table->string('badge', 16)->nullable()->after('button_row'));
    $addColumn('plans', 'emoji_id', static fn(Blueprint $table) => $table->string('emoji_id', 20)->nullable()->after('name'));
    $addColumn('plan_categories', 'emoji_id', static fn(Blueprint $table) => $table->string('emoji_id', 20)->nullable()->after('name'));
    $addColumn('subscriptions', 'label', static fn(Blueprint $table) => $table->string('label', 32)->nullable()->after('remote_name'));
    $addColumn('tickets', 'support_read_at', static fn(Blueprint $table) => $table->timestamp('support_read_at')->nullable()->after('customer_unread'));

    // Sign-in by SMS: the website's switch, and the customers found by their mobile in their shop.
    $addColumn('websites', 'phone_login', static fn(Blueprint $table) => $table->boolean('phone_login')->default(false)->after('email_signup'));
    $addIndex('users', ['bot_id', 'phone']);
};
