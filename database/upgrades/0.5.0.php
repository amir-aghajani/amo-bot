<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Brings a 0.4.0 database to 0.5.0. See docs/Database.md for the rules an upgrade keeps.
 *
 * Every step adds one column, table, index or foreign key, and only when the database lacks it, or fills what it lacks,
 * so a run that stopped half-way is simply run again. A table's indexes come before its foreign keys: MySQL gives a
 * foreign key without a usable index an index of its own, which would then stand in for the one schema.php names.
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

    // —— A SOCKS5 proxy per server ——
    $addColumn('servers', 'proxy_enabled', static fn(Blueprint $table) => $table->boolean('proxy_enabled')->default(false)->after('totp_secret'));
    $addColumn('servers', 'proxy_host', static fn(Blueprint $table) => $table->string('proxy_host')->nullable()->after('proxy_enabled'));
    $addColumn('servers', 'proxy_port', static fn(Blueprint $table) => $table->unsignedSmallInteger('proxy_port')->nullable()->after('proxy_host'));
    $addColumn('servers', 'proxy_username', static fn(Blueprint $table) => $table->string('proxy_username')->nullable()->after('proxy_port'));
    $addColumn('servers', 'proxy_password', static fn(Blueprint $table) => $table->text('proxy_password')->nullable()->after('proxy_username'));

    // —— Rescue and migration of a server's services ——
    $createTable('server_migrations', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->foreignId('source_server_id');
        $table->foreignId('target_server_id');
        $table->string('mode', 8);
        $table->boolean('include_disabled')->default(false);
        $table->json('plan_ids')->nullable();
        $table->boolean('notify')->default(true);
        $table->string('status', 16);
        $table->unsignedBigInteger('upto_subscription_id')->default(0);
        $table->unsignedBigInteger('last_subscription_id')->default(0);
        $table->unsignedInteger('total')->default(0);
        $table->unsignedInteger('moved')->default(0);
        $table->unsignedInteger('failed')->default(0);
        $table->unsignedInteger('skipped')->default(0);
        $table->string('waiting_reason', 500)->nullable();
        $table->string('reason', 500)->nullable();
        $table->string('last_failure', 500)->nullable();
        $table->string('reviewer', 191)->nullable();
        $table->string('lease_token', 32)->nullable();
        $table->timestamp('leased_until')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('started_at')->nullable();
        $table->timestamp('finished_at')->nullable();
        $table->unsignedBigInteger('open_source_id')->nullable()->storedAs("case when status in ('queued', 'running', 'paused') then source_server_id end");
    });
    if (!$schema->hasIndex('server_migrations', ['open_source_id'], 'unique')) {
        $schema->table('server_migrations', static fn(Blueprint $table) => $table->unique('open_source_id'));
    }
    $addIndex('server_migrations', ['source_server_id', 'status']);
    $addIndex('server_migrations', ['target_server_id', 'status']);
    $addIndex('server_migrations', ['status']);
    $addForeignKey('server_migrations', 'source_server_id', 'servers', 'restrict');
    $addForeignKey('server_migrations', 'target_server_id', 'servers', 'restrict');

    $createTable('migration_failures', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->foreignId('migration_id');
        $table->foreignId('subscription_id')->nullable();
        $table->string('remote_name', 191);
        $table->string('reason', 500);
        $table->timestamp('created_at')->nullable();
    });
    $addIndex('migration_failures', ['migration_id']);
    $addIndex('migration_failures', ['subscription_id']);
    $addForeignKey('migration_failures', 'migration_id', 'server_migrations', 'cascade');
    $addForeignKey('migration_failures', 'subscription_id', 'subscriptions', 'set null');

    $createTable('migration_leftovers', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->foreignId('migration_id')->nullable();
        $table->foreignId('server_id');
        $table->foreignId('subscription_id')->nullable();
        $table->string('remote_name', 191);
        $table->string('last_error', 500)->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('removed_at')->nullable();
    });
    $addIndex('migration_leftovers', ['server_id', 'removed_at']);
    $addIndex('migration_leftovers', ['migration_id']);
    $addIndex('migration_leftovers', ['subscription_id']);
    $addForeignKey('migration_leftovers', 'migration_id', 'server_migrations', 'set null');
    $addForeignKey('migration_leftovers', 'server_id', 'servers', 'cascade');
    $addForeignKey('migration_leftovers', 'subscription_id', 'subscriptions', 'set null');

    // —— Wallet transfers between customers ——
    $createTable('wallet_transfers', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->foreignId('bot_id')->default(1);
        $table->foreignId('sender_id');
        $table->foreignId('recipient_id');
        $table->decimal('amount', 14, 2);
        $table->foreignId('debit_id');
        $table->foreignId('credit_id');
        $table->string('request_key', 64)->nullable();
        $table->timestamp('created_at')->nullable();
    });
    foreach ([['debit_id'], ['credit_id'], ['sender_id', 'request_key']] as $unique) {
        if (!$schema->hasIndex('wallet_transfers', $unique, 'unique')) {
            $schema->table('wallet_transfers', static fn(Blueprint $table) => $table->unique($unique));
        }
    }
    $addIndex('wallet_transfers', ['sender_id', 'created_at']);
    $addIndex('wallet_transfers', ['recipient_id']);
    $addForeignKey('wallet_transfers', 'bot_id', 'bots', 'restrict');
    $addForeignKey('wallet_transfers', 'sender_id', 'users', 'cascade');
    $addForeignKey('wallet_transfers', 'recipient_id', 'users', 'cascade');
    $addForeignKey('wallet_transfers', 'debit_id', 'wallet_transactions', 'cascade');
    $addForeignKey('wallet_transfers', 'credit_id', 'wallet_transactions', 'cascade');

    // —— Payment methods: limits, cards and their rotation ——
    $addColumn('payment_methods', 'min_amount', static fn(Blueprint $table) => $table->decimal('min_amount', 14, 2)->nullable()->after('config'));
    $addColumn('payment_methods', 'max_amount', static fn(Blueprint $table) => $table->decimal('max_amount', 14, 2)->nullable()->after('min_amount'));
    $createTable('payment_cards', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->foreignId('bot_id')->default(1);
        $table->foreignId('payment_method_id');
        $table->string('number', 16);
        $table->string('holder', 100);
        $table->string('bank', 60)->nullable();
        $table->boolean('enabled')->default(true);
        $table->integer('sort')->default(0);
        $table->decimal('daily_cap', 14, 2)->nullable();
        $table->timestamps();
    });
    $addIndex('payment_cards', ['payment_method_id', 'sort']);
    $addForeignKey('payment_cards', 'bot_id', 'bots', 'restrict');
    $addForeignKey('payment_cards', 'payment_method_id', 'payment_methods', 'cascade');
    $addColumn('payments', 'card_id', static fn(Blueprint $table) => $table->foreignId('card_id')->nullable()->after('payment_method_id'));
    $addIndex('payments', ['card_id', 'created_at', 'status', 'amount']);
    $addForeignKey('payments', 'card_id', 'payment_cards', 'restrict');

    // A card-to-card method kept its one card in its settings: the card becomes the method's first row, and every payment
    // made with the method was shown it. The number is read as its form read a kept one — Persian digits, spaces and
    // dashes allowed —, and one it would read as none (not 16 digits passing the Luhn check) makes no row: the method is
    // then offered to nobody until a card is added to it. A method that has a card row already (a run that stopped
    // half-way) keeps it.
    $luhn = static function (string $digits): bool {
        $sum = 0;
        foreach (array_reverse(str_split($digits)) as $i => $char) {
            $n = (int) $char * ($i % 2 === 1 ? 2 : 1);
            $sum += $n > 9 ? $n - 9 : $n;
        }

        return $sum % 10 === 0;
    };
    $latin = [];
    foreach (['۰۱۲۳۴۵۶۷۸۹', '٠١٢٣٤٥٦٧٨٩'] as $script) {
        foreach (mb_str_split($script) as $digit => $char) {
            $latin[$char] = (string) $digit;
        }
    }
    foreach ($db->table('payment_methods')->where('driver', 'manual')->orderBy('id')->get(['id', 'bot_id', 'config']) as $method) {
        $config = json_decode((string) $method->config, true);
        $config = is_array($config) ? $config : [];
        $card = $db->table('payment_cards')->where('payment_method_id', $method->id)->orderBy('sort')->orderBy('id')->value('id');
        $number = is_string($config['card_number'] ?? null) ? (string) preg_replace('/\D+/', '', strtr($config['card_number'], $latin)) : '';
        if ($card === null && preg_match('/^\d{16}$/', $number) === 1 && $luhn($number)) {
            $holder = is_string($config['card_holder'] ?? null) ? mb_substr(trim($config['card_holder']), 0, 100) : '';
            $now = gmdate('Y-m-d H:i:s');
            $card = $db->table('payment_cards')->insertGetId([
                'bot_id' => $method->bot_id, 'payment_method_id' => $method->id, 'number' => $number, 'holder' => $holder,
                'enabled' => true, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        if ($card !== null) {
            $db->table('payments')->where('payment_method_id', $method->id)->whereNull('card_id')->update(['card_id' => $card]);
        }
    }

    // —— Card-to-card payments and receipts ——

    // —— Orders made by support ——
    // Who made an order by hand for its customer, and their note.
    $addColumn('orders', 'created_by', static fn(Blueprint $table) => $table->string('created_by', 191)->nullable()->after('diagnosis'));
    $addColumn('orders', 'support_note', static fn(Blueprint $table) => $table->text('support_note')->nullable()->after('created_by'));
    // The outside row — money support took outside the shop — is built into every shop, as the wallet is: each shop
    // without one gets it, after its other methods.
    foreach ($db->table('bots')->orderBy('id')->pluck('id') as $bot) {
        if (!$db->table('payment_methods')->where('bot_id', $bot)->where('driver', 'outside')->exists()) {
            $now = gmdate('Y-m-d H:i:s');
            $db->table('payment_methods')->insert([
                'bot_id' => $bot, 'driver' => 'outside', 'label' => 'خارج از فروشگاه', 'config' => '[]', 'enabled' => true,
                'sort' => (int) $db->table('payment_methods')->where('bot_id', $bot)->max('sort') + 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    // —— Agencies: hidden servers and plans, expiry, monthly minimum ——
    $addColumn('bots', 'agency_expires_at', static fn(Blueprint $table) => $table->timestamp('agency_expires_at')->nullable()->after('connected_at'));
    $addColumn('bots', 'expiry_warned_at', static fn(Blueprint $table) => $table->timestamp('expiry_warned_at')->nullable()->after('agency_expires_at'));
    $addColumn('bots', 'monthly_minimum', static fn(Blueprint $table) => $table->decimal('monthly_minimum', 14, 2)->nullable()->after('expiry_warned_at'));
    $addColumn('bots', 'minimum_checked_month', static fn(Blueprint $table) => $table->string('minimum_checked_month', 7)->nullable()->after('monthly_minimum'));
    $addColumn('bots', 'minimum_warned_month', static fn(Blueprint $table) => $table->string('minimum_warned_month', 7)->nullable()->after('minimum_checked_month'));
    $addColumn('bots', 'selling_suspended_at', static fn(Blueprint $table) => $table->timestamp('selling_suspended_at')->nullable()->after('minimum_warned_month'));
    $addColumn('bots', 'suspension_reason', static fn(Blueprint $table) => $table->string('suspension_reason', 16)->nullable()->after('selling_suspended_at'));
    $addColumn('plans', 'hidden_by_owner', static fn(Blueprint $table) => $table->boolean('hidden_by_owner')->default(false)->after('is_active'));
    $createTable('bot_hidden_servers', static function (Blueprint $table): void {
        $table->foreignId('bot_id');
        $table->foreignId('server_id');

        $table->primary(['bot_id', 'server_id']);
    });
    $addForeignKey('bot_hidden_servers', 'bot_id', 'bots', 'cascade');
    $addForeignKey('bot_hidden_servers', 'server_id', 'servers', 'cascade');

    // —— Last of all: the card settings 0.4.0 reads ——
    // A card-to-card method's card is its first card row now, so the copy in its settings goes — only once every other
    // step is done: an upgrade that stops before this puts 0.4.0 back on a database whose methods still show their card.
    foreach ($db->table('payment_methods')->where('driver', 'manual')->orderBy('id')->get(['id', 'config']) as $method) {
        $config = json_decode((string) $method->config, true);
        if (is_array($config) && (array_key_exists('card_number', $config) || array_key_exists('card_holder', $config))) {
            unset($config['card_number'], $config['card_holder']);
            $db->table('payment_methods')->where('id', $method->id)->update(['config' => json_encode((object) $config)]);
        }
    }
};
