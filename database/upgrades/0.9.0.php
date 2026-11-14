<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Brings a 0.8.x database to 0.9.0: «انتقال از ربات دیگر» — an import of another bot's data, its staged copy, what it
 * brought (so a later import of the same bot does not bring it twice) and what it could not —, and «تایید خودکار واریز»:
 * the bank's deposit SMS a phone app forwards (`deposits`). See docs/Database.md for the rules an upgrade keeps.
 *
 * Every step adds one table, index, foreign key or column, only when the database lacks it, so a run that stopped
 * half-way is simply run again: each table is made as schema.php makes it, then each of its indexes and keys is added
 * again if MySQL had not got to it (as 0.8.0.php does for `mini_apps`). And two columns of `users`, each added only
 * while it is missing: when an import learned a customer had bought in the bot the shop came from
 * (`bought_elsewhere_at`) — no newcomer here —, and whether support excused them from the phone rule (`phone_exempt`).
 */
return static function (Builder $schema, Connection $db): void {
    $createTable = static function (string $table, Closure $columns) use ($schema): void {
        if (!$schema->hasTable($table)) {
            $schema->create($table, $columns);
        }
    };

    $addColumn = static function (string $table, string $column, Closure $define) use ($schema): void {
        if (!$schema->hasColumn($table, $column)) {
            $schema->table($table, $define);
        }
    };

    /**
     * @param list<string> $columns
     * @param string|null $name As schema.php names it; null for Laravel's usual name
     */
    $addIndex = static function (string $table, array $columns, bool $unique = false, ?string $name = null) use ($schema): void {
        if (!$schema->hasIndex($table, $columns, $unique ? 'unique' : null)) {
            $schema->table($table, static fn(Blueprint $blueprint) => $unique ? $blueprint->unique($columns, $name) : $blueprint->index($columns, $name));
        }
    };

    // `$onDelete` as schema.php's restrictOnDelete(), cascadeOnDelete() and nullOnDelete() write it; the key gets
    // Laravel's usual name.
    $addForeignKey = static function (string $table, string $column, string $references, string $onDelete) use ($schema): void {
        foreach ($schema->getForeignKeys($table) as $key) {
            if ($key['columns'] === [$column]) {
                return;
            }
        }
        $schema->table($table, static fn(Blueprint $blueprint) => $blueprint->foreign($column)->references('id')->on($references)->onDelete($onDelete));
    };

    $createTable('imports', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
        $table->string('migrator', 32);
        $table->string('source', 16);
        $table->string('phase', 16);
        $table->string('file_name', 191)->nullable();
        $table->json('reading')->nullable();
        $table->text('connection')->nullable();
        $table->json('schema')->nullable();
        $table->string('version', 32)->nullable();
        $table->json('survey')->nullable();
        $table->text('panels')->nullable();
        $table->json('choices')->nullable();
        $table->string('stage', 24)->nullable();
        $table->unsignedBigInteger('cursor')->default(0);
        $table->json('counts')->nullable();
        $table->text('error')->nullable();
        $table->string('reviewer', 64)->nullable();
        $table->timestamp('started_at')->nullable();
        $table->timestamp('finished_at')->nullable();
        $table->timestamps();

        $table->index('phase');
    });

    $createTable('import_rows', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->foreignId('import_id')->constrained('imports')->cascadeOnDelete();
        $table->string('table_name', 64);
        $table->longText('data');

        $table->index(['import_id', 'table_name', 'id']);
    });

    $createTable('import_links', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
        $table->string('migrator', 32);
        $table->string('kind', 24);
        $table->string('source_key', 191);
        $table->unsignedBigInteger('target_id')->nullable();
        $table->foreignId('import_id')->nullable()->constrained('imports')->nullOnDelete();
        $table->timestamp('created_at')->nullable();

        $table->unique(['bot_id', 'migrator', 'kind', 'source_key'], 'import_links_source_unique');
    });

    $createTable('import_problems', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->foreignId('import_id')->constrained('imports')->cascadeOnDelete();
        $table->string('kind', 24);
        $table->string('source_key', 191);
        $table->string('reason', 32);
        $table->string('detail', 500)->nullable();
        $table->timestamp('created_at')->nullable();

        $table->index(['import_id', 'reason']);
        $table->index(['import_id', 'kind']);
    });

    // MySQL makes a table, then each of its indexes and keys, as statements of their own: a run that stopped between them
    // is completed here — every index, then every foreign key, each added only while it is missing (indexes first: a
    // foreign key without a usable index gets one of its own).
    $addIndex('imports', ['phase']);
    $addIndex('import_rows', ['import_id', 'table_name', 'id']);
    $addIndex('import_links', ['bot_id', 'migrator', 'kind', 'source_key'], unique: true, name: 'import_links_source_unique');
    $addIndex('import_problems', ['import_id', 'reason']);
    $addIndex('import_problems', ['import_id', 'kind']);
    $addForeignKey('imports', 'bot_id', 'bots', 'restrict');
    $addForeignKey('import_rows', 'import_id', 'imports', 'cascade');
    $addForeignKey('import_links', 'bot_id', 'bots', 'restrict');
    $addForeignKey('import_links', 'import_id', 'imports', 'set null');
    $addForeignKey('import_problems', 'import_id', 'imports', 'cascade');

    // A customer who bought in the bot the shop came from is no newcomer here.
    $addColumn('users', 'bought_elsewhere_at', static fn(Blueprint $table) => $table->timestamp('bought_elsewhere_at')->nullable()->after('discount_percent'));

    // A customer support excused from the phone rule: never asked for their number.
    $addColumn('users', 'phone_exempt', static fn(Blueprint $table) => $table->boolean('phone_exempt')->default(false)->after('bought_elsewhere_at'));

    // The bank's deposit SMS a phone app forwards (Payments\Deposits), and what came of each.
    $createTable('deposits', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
        $table->string('source', 16);
        $table->string('external_id', 100);
        $table->string('status', 16);
        $table->unsignedBigInteger('amount_rial')->nullable();
        $table->string('bank', 60)->nullable();
        $table->string('sender', 60)->nullable();
        $table->text('body')->nullable();
        $table->string('note', 500)->nullable();
        $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
        $table->timestamp('received_at')->nullable();
        $table->timestamps();
        $table->unique(['bot_id', 'source', 'external_id']);
        $table->index(['bot_id', 'status', 'created_at']);
        $table->index(['bot_id', 'created_at']);
    });
    $addIndex('deposits', ['bot_id', 'source', 'external_id'], unique: true);
    $addIndex('deposits', ['bot_id', 'status', 'created_at']);
    $addIndex('deposits', ['bot_id', 'created_at']);
    $addForeignKey('deposits', 'bot_id', 'bots', 'restrict');
    $addForeignKey('deposits', 'payment_id', 'payments', 'set null');
};
