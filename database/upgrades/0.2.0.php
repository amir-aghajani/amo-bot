<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Brings a 0.1.x database to 0.2.0: extra traffic and time, other plan prices, plan buttons, hourly terms, discounts,
 * rewards, and the referral program's gifts. See docs/Database.md for the rules an upgrade keeps.
 *
 * Every step adds one column, table, index or foreign key, and only when the database lacks it. MySQL changes a table
 * outside any transaction, so a run that stopped half-way — or failed on one statement — is simply run again. A table's
 * indexes come before its foreign keys: MySQL gives a foreign key without a usable index an index of its own, which
 * would then stand in for the one schema.php names.
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
    $addIndex = static function (string $table, array $columns, bool $unique = false) use ($schema): void {
        if (!$schema->hasIndex($table, $columns, $unique ? 'unique' : null)) {
            $schema->table($table, static function (Blueprint $blueprint) use ($columns, $unique): void {
                $unique ? $blueprint->unique($columns) : $blueprint->index($columns);
            });
        }
    };

    // Extra traffic and time for a service: a plan's own prices for them (null: the shop's), and the days an order of
    // extra time brings (an order of traffic keeps its bytes in `traffic_bytes`).
    $addColumn('plans', 'extras', static fn(Blueprint $table) => $table->json('extras')->nullable()->after('ip_limit'));
    $addColumn('orders', 'days', static fn(Blueprint $table) => $table->unsignedInteger('days')->nullable()->after('traffic_bytes'));

    // A plan's other prices — a customer's first purchase, agents and «همکار» customer groups — and the price an order
    // was made at.
    $addColumn('plans', 'first_purchase_price', static fn(Blueprint $table) => $table->decimal('first_purchase_price', 14, 2)->nullable()->after('price'));
    $addColumn('plans', 'agent_price', static fn(Blueprint $table) => $table->decimal('agent_price', 14, 2)->nullable()->after('first_purchase_price'));
    $addColumn('customer_groups', 'agent_pricing', static fn(Blueprint $table) => $table->boolean('agent_pricing')->default(false)->after('name'));
    $addColumn('orders', 'pricing', static fn(Blueprint $table) => $table->string('pricing', 16)->default('regular')->after('amount'));

    // A plan's note under the bot's checkout, and its button's colour and row in the bot's plan list.
    $addColumn('plans', 'checkout_note', static fn(Blueprint $table) => $table->text('checkout_note')->nullable()->after('description'));
    $addColumn('plans', 'button_style', static fn(Blueprint $table) => $table->string('button_style', 16)->nullable()->after('extras'));
    $addColumn('plans', 'button_row', static fn(Blueprint $table) => $table->unsignedTinyInteger('button_row')->default(1)->after('button_style'));

    // Discounts: codes, timed sales, loyalty tiers, a customer's own percent, and what an order took off its price.
    $addColumn('users', 'discount_percent', static fn(Blueprint $table) => $table->unsignedTinyInteger('discount_percent')->default(0)->after('credit_limit'));

    $createTable('discount_codes', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->foreignId('bot_id')->default(1);
        $table->string('code', 32);
        $table->string('note', 191)->nullable();
        $table->string('type', 8);
        $table->decimal('value', 14, 2)->default(0);
        $table->decimal('max_discount', 14, 2)->nullable();
        $table->decimal('min_amount', 14, 2)->nullable();
        $table->json('order_types')->nullable();
        $table->json('plan_ids')->nullable();
        $table->json('server_ids')->nullable();
        $table->boolean('first_purchase_only')->default(false);
        $table->timestamp('starts_at')->nullable();
        $table->timestamp('ends_at')->nullable();
        $table->unsignedInteger('max_uses')->nullable();
        $table->unsignedInteger('per_user')->nullable();
        $table->boolean('enabled')->default(true);
        $table->timestamps();
    });
    $addIndex('discount_codes', ['bot_id', 'code'], unique: true);
    $addForeignKey('discount_codes', 'bot_id', 'bots', 'restrict');

    $createTable('sales', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->foreignId('bot_id')->default(1);
        $table->string('name', 64);
        $table->unsignedTinyInteger('percent');
        $table->json('order_types')->nullable();
        $table->json('plan_ids')->nullable();
        $table->timestamp('starts_at')->nullable();
        $table->timestamp('ends_at')->nullable();
        $table->boolean('enabled')->default(true);
        $table->timestamps();
    });
    $addIndex('sales', ['bot_id', 'enabled', 'ends_at']);
    $addForeignKey('sales', 'bot_id', 'bots', 'restrict');

    $createTable('loyalty_tiers', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->foreignId('bot_id')->default(1);
        $table->string('name', 32);
        $table->decimal('min_spent', 14, 2)->default(0);
        $table->unsignedTinyInteger('discount_percent')->default(0);
        $table->unsignedTinyInteger('cashback_percent')->default(0);
        $table->unsignedInteger('sort')->default(0);
        $table->timestamps();
    });
    $addIndex('loyalty_tiers', ['bot_id', 'name'], unique: true);
    $addForeignKey('loyalty_tiers', 'bot_id', 'bots', 'restrict');

    $addColumn('orders', 'discount_amount', static fn(Blueprint $table) => $table->decimal('discount_amount', 14, 2)->default(0)->after('pricing'));
    $addColumn('orders', 'discounts', static fn(Blueprint $table) => $table->json('discounts')->nullable()->after('discount_amount'));
    $addColumn('orders', 'discount_code_id', static fn(Blueprint $table) => $table->foreignId('discount_code_id')->nullable()->after('discounts'));
    $addIndex('orders', ['discount_code_id', 'user_id', 'status']);
    $addForeignKey('orders', 'discount_code_id', 'discount_codes', 'set null');

    // Rewards: cashback, gift codes, the lucky wheel, the nightly draw, and a mass gift to one customer group.
    $createTable('cashback_rules', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->foreignId('bot_id')->default(1);
        $table->unsignedTinyInteger('percent');
        $table->json('order_types')->nullable();
        $table->foreignId('customer_group_id')->nullable();
        $table->foreignId('payment_method_id')->nullable();
        $table->boolean('enabled')->default(true);
        $table->unsignedInteger('sort')->default(0);
        $table->timestamps();
    });
    $addIndex('cashback_rules', ['bot_id', 'enabled']);
    $addForeignKey('cashback_rules', 'bot_id', 'bots', 'restrict');
    $addForeignKey('cashback_rules', 'customer_group_id', 'customer_groups', 'cascade');
    $addForeignKey('cashback_rules', 'payment_method_id', 'payment_methods', 'cascade');

    $createTable('cashbacks', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->foreignId('bot_id')->default(1);
        $table->foreignId('order_id');
        $table->foreignId('user_id');
        $table->unsignedTinyInteger('percent');
        $table->decimal('amount', 14, 2);
        $table->timestamp('reversed_at')->nullable();
        $table->timestamp('created_at')->nullable();
    });
    $addIndex('cashbacks', ['order_id'], unique: true);
    $addIndex('cashbacks', ['user_id', 'bot_id']);
    $addForeignKey('cashbacks', 'bot_id', 'bots', 'restrict');
    $addForeignKey('cashbacks', 'order_id', 'orders', 'cascade');
    $addForeignKey('cashbacks', 'user_id', 'users', 'cascade');

    $createTable('gift_codes', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->foreignId('bot_id')->default(1);
        $table->string('code', 32);
        $table->string('batch', 64)->nullable();
        $table->string('kind', 8);
        $table->decimal('amount', 14, 2)->nullable();
        $table->foreignId('plan_id')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->foreignId('redeemed_by')->nullable();
        $table->timestamp('redeemed_at')->nullable();
        $table->foreignId('order_id')->nullable();
        $table->timestamps();
    });
    $addIndex('gift_codes', ['bot_id', 'code'], unique: true);
    $addIndex('gift_codes', ['bot_id', 'batch']);
    $addForeignKey('gift_codes', 'bot_id', 'bots', 'restrict');
    $addForeignKey('gift_codes', 'plan_id', 'plans', 'set null');
    $addForeignKey('gift_codes', 'redeemed_by', 'users', 'set null');
    $addForeignKey('gift_codes', 'order_id', 'orders', 'set null');

    $createTable('wheel_prizes', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->foreignId('bot_id')->default(1);
        $table->string('label', 64);
        $table->string('kind', 16);
        $table->decimal('amount', 14, 2)->nullable();
        $table->foreignId('plan_id')->nullable();
        $table->unsignedInteger('weight')->default(1);
        $table->boolean('enabled')->default(true);
        $table->unsignedInteger('sort')->default(0);
        $table->timestamps();
    });
    $addIndex('wheel_prizes', ['bot_id', 'enabled']);
    $addForeignKey('wheel_prizes', 'bot_id', 'bots', 'restrict');
    $addForeignKey('wheel_prizes', 'plan_id', 'plans', 'set null');

    $createTable('wheel_spins', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->foreignId('bot_id')->default(1);
        $table->foreignId('user_id');
        $table->foreignId('wheel_prize_id')->nullable();
        $table->string('kind', 16);
        $table->string('label', 64);
        $table->decimal('amount', 14, 2)->nullable();
        $table->string('outcome', 191)->nullable();
        $table->foreignId('order_id')->nullable();
        $table->foreignId('subscription_id')->nullable();
        $table->timestamp('created_at')->nullable();
    });
    $addIndex('wheel_spins', ['user_id', 'created_at']);
    $addIndex('wheel_spins', ['bot_id', 'created_at']);
    $addForeignKey('wheel_spins', 'bot_id', 'bots', 'restrict');
    $addForeignKey('wheel_spins', 'user_id', 'users', 'cascade');
    $addForeignKey('wheel_spins', 'wheel_prize_id', 'wheel_prizes', 'set null');
    $addForeignKey('wheel_spins', 'order_id', 'orders', 'set null');
    $addForeignKey('wheel_spins', 'subscription_id', 'subscriptions', 'set null');

    $createTable('lottery_draws', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->foreignId('bot_id')->default(1);
        $table->date('day');
        $table->unsignedInteger('entries')->default(0);
        $table->string('prize_kind', 16);
        $table->decimal('prize_amount', 14, 2)->nullable();
        $table->foreignId('prize_plan_id')->nullable();
        $table->timestamp('created_at')->nullable();
    });
    $addIndex('lottery_draws', ['bot_id', 'day'], unique: true);
    $addForeignKey('lottery_draws', 'bot_id', 'bots', 'restrict');
    $addForeignKey('lottery_draws', 'prize_plan_id', 'plans', 'set null');

    $createTable('lottery_winners', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->foreignId('lottery_draw_id');
        $table->foreignId('user_id');
        $table->string('outcome', 191)->nullable();
        $table->foreignId('order_id')->nullable();
        $table->foreignId('subscription_id')->nullable();
        $table->timestamp('created_at')->nullable();
    });
    $addIndex('lottery_winners', ['user_id']);
    $addForeignKey('lottery_winners', 'lottery_draw_id', 'lottery_draws', 'cascade');
    $addForeignKey('lottery_winners', 'user_id', 'users', 'cascade');
    $addForeignKey('lottery_winners', 'order_id', 'orders', 'set null');
    $addForeignKey('lottery_winners', 'subscription_id', 'subscriptions', 'set null');

    $addColumn('grants', 'customer_group_id', static fn(Blueprint $table) => $table->foreignId('customer_group_id')->nullable()->after('audience'));
    $addForeignKey('grants', 'customer_group_id', 'customer_groups', 'set null');

    // The referral program's start gifts, its rewards for invites and its alerts about made-up referrals.
    $createTable('referral_bonuses', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->foreignId('bot_id')->default(1);
        $table->foreignId('invitee_id');
        $table->foreignId('referrer_id');
        $table->decimal('referrer_amount', 14, 2)->default(0);
        $table->decimal('invitee_amount', 14, 2)->default(0);
        $table->string('trigger', 16);
        $table->timestamp('created_at')->nullable();
    });
    $addIndex('referral_bonuses', ['invitee_id'], unique: true);
    $addIndex('referral_bonuses', ['referrer_id']);
    $addForeignKey('referral_bonuses', 'bot_id', 'bots', 'restrict');
    $addForeignKey('referral_bonuses', 'invitee_id', 'users', 'cascade');
    $addForeignKey('referral_bonuses', 'referrer_id', 'users', 'cascade');

    $createTable('referral_rewards', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->foreignId('bot_id')->default(1);
        $table->foreignId('referrer_id');
        $table->unsignedInteger('milestone');
        $table->foreignId('order_id')->nullable();
        $table->timestamp('created_at')->nullable();
    });
    $addIndex('referral_rewards', ['referrer_id', 'milestone'], unique: true);
    $addForeignKey('referral_rewards', 'bot_id', 'bots', 'restrict');
    $addForeignKey('referral_rewards', 'referrer_id', 'users', 'cascade');
    $addForeignKey('referral_rewards', 'order_id', 'orders', 'set null');

    $createTable('referral_alerts', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->foreignId('bot_id')->default(1);
        $table->foreignId('referrer_id');
        $table->string('reason', 32);
        $table->unsignedInteger('count')->default(0);
        $table->timestamp('created_at')->nullable();
    });
    $addIndex('referral_alerts', ['referrer_id', 'created_at']);
    $addIndex('referral_alerts', ['bot_id', 'created_at']);
    $addForeignKey('referral_alerts', 'bot_id', 'bots', 'restrict');
    $addForeignKey('referral_alerts', 'referrer_id', 'users', 'cascade');

    // Renewals now earn a commission of their own (`referral.renewal_rate`). A shop that set its rate keeps renewals at
    // that rate, as before, until its owner sets them apart.
    $kept = $db->table('settings')->where('key', 'referral.renewal_rate')->pluck('bot_id')->all();
    foreach ($db->table('settings')->where('key', 'referral.rate')->whereNotIn('bot_id', $kept)->get(['bot_id', 'value']) as $rate) {
        $db->table('settings')->insert(['bot_id' => $rate->bot_id, 'key' => 'referral.renewal_rate', 'value' => $rate->value, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    // Terms in hours (hourly plans): each table's days × 24, then the days' column dropped. While `duration_days` is
    // still there a run counts the hours from it again, so stopping between these steps loses nothing.
    foreach (['plans' => 720, 'subscriptions' => 0] as $table => $default) {
        if (!$schema->hasColumn($table, 'duration_days')) {
            continue;
        }
        $addColumn($table, 'duration_hours', static fn(Blueprint $blueprint) => $blueprint->unsignedInteger('duration_hours')->default($default)->after('duration_days'));
        $db->table($table)->update(['duration_hours' => $db->raw('duration_days * 24')]);
        $schema->table($table, static fn(Blueprint $blueprint) => $blueprint->dropColumn('duration_days'));
    }
};
