<?php

declare(strict_types=1);

use App\Modules\Payments\Drivers\Outside\OutsideGateway;
use App\Modules\Payments\Drivers\Wallet\WalletGateway;
use Illuminate\Database\Schema\Blueprint;

/*
 * Every table, in the order they are made (a foreign key only points at a table above it), and the rows a new shop
 * starts with — what a fresh install makes (Core\Database\Schema). A change here ships with the same change in
 * database/upgrades/<version>.php for installed shops; `php bin/console db:rebuild` follows it on a developer's
 * database. See docs/Database.md.
 */
return [
    'tables' => [
        /*
         * The bots, each with a shop of its own: #1 is the main bot (its token and @username live in config.php), the
         * others agents' («نماینده»). A shop's rows carry `bot_id` (Bots\CurrentBot); servers, grants and the agency's
         * tables belong to the installation. `user_id` is the agent (a main-bot customer; no foreign key, since users
         * come later), whose bot runs while they have an agency level. `token` and `webhook_secret` are encrypted;
         * `telegram_id`, `username` and `title` are what Telegram reports; `problem` is what keeps the bot from running.
         * `panel_epoch` goes up to end the agent's panel sessions; `login_code` is the sha256 of their last one-time
         * panel link, valid until `login_code_expires_at`.
         *
         * An agent's terms of selling (Agency\Services\AgencyStanding): their agency ends at `agency_expires_at` (null:
         * never), and they buy at least `monthly_minimum` Toman of traffic each Jalali month (null: no minimum) —
         * `minimum_checked_month` is the last month («1405-07») judged against it, or the one it was set in, which is not
         * judged; `expiry_warned_at` and `minimum_warned_month` keep each warning to once. While `selling_suspended_at`
         * is set the shop sells nothing new — `suspension_reason` says why: expired, minimum or manual —, its bot and its
         * existing services running on. `bot_hidden_servers` (below the servers) are the servers the owner keeps from the
         * agent's shop.
         */
        'bots' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->nullable()->unique();
            $table->text('token')->nullable();
            $table->unsignedBigInteger('telegram_id')->nullable()->unique();
            $table->string('username', 64)->nullable();
            $table->string('title', 128)->nullable();
            $table->string('problem', 255)->nullable();
            $table->text('webhook_secret')->nullable();
            $table->unsignedInteger('panel_epoch')->default(0);
            $table->string('login_code', 64)->nullable();
            $table->timestamp('login_code_expires_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('agency_expires_at')->nullable();
            $table->timestamp('expiry_warned_at')->nullable();
            $table->decimal('monthly_minimum', 14, 2)->nullable();
            $table->string('minimum_checked_month', 7)->nullable();
            $table->string('minimum_warned_month', 7)->nullable();
            $table->timestamp('selling_suspended_at')->nullable();
            $table->string('suspension_reason', 16)->nullable();
            $table->timestamps();
        },

        /*
         * Bookkeeping.
         *
         * `settings` — runtime settings a panel changes, per bot, a JSON `value` each (Settings\Services\Settings), plus
         * the bot's runtime state (Telegram\BotState). The installation's configuration is config.php, not this.
         *
         * `sequences` — named counters that only go up (Core\Database\Sequence), e.g. the number in "USER_7".
         *
         * `change_versions` — the panels' live view (Core\Database\ChangeFeed): a version per area of the shop, bumped
         * when a write to one of its tables commits.
         */
        'settings' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->string('key', 191);
            $table->longText('value')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['bot_id', 'key']);
        },

        'sequences' => static function (Blueprint $table): void {
            $table->string('name', 64)->primary();
            $table->unsignedBigInteger('value')->default(0);
        },

        'change_versions' => static function (Blueprint $table): void {
            $table->string('area', 32)->primary();
            $table->unsignedBigInteger('version')->default(0);
        },

        /*
         * «نمایندگی»: an agent runs a bot of their own and buys the traffic it sells at their level's `price_per_gb`.
         * A level agents are on cannot be deleted.
         */
        'agency_levels' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name', 32)->unique();
            $table->decimal('price_per_gb', 14, 2)->default(0);
            $table->integer('sort')->default(0);
            $table->timestamps();
        },

        /*
         * Customers of a bot's shop — whoever talked to the bot or signed in on its website (one person in two shops is
         * two customers). The owner's panel login is config.php's, not a user. The ways in are each unique in the shop
         * and each optional: `telegram_id` (none for a website-only customer, whom the bot never writes to), `email`
         * (lower case, stored only once proven), `password_hash` (bcrypt, only with an email) and `google_sub`.
         * Two-factor sign-in (RFC 6238): `totp_secret` (encrypted), `totp_recovery_codes` (keyed hashes, encrypted JSON),
         * `totp_enabled_at` (null: off) and `totp_last_step` (so no code is taken twice).
         *
         * `role` admin makes the customer one of the shop's admins (the bot's privileged commands, the website's admin
         * API). `phone` is a verified number (their own contact card, E.164). `bot_blocked` is Telegram's last word on
         * whether they blocked the bot. The balance is the last line of `wallet_transactions`. `referral_code` is their
         * own invite code; `referred_by` whoever brought them, set once at registration. An agent has an
         * `agency_level_id`, and `credit_limit` is how far below zero their wallet may go.
         *
         * `agency_requests` — a customer asking to become an agent, approved (with a level) or rejected (with a `reason`).
         *
         * `wallet_transfers` — «انتقال موجودی»: money a customer sent another of the shop from their wallet, with the two
         * ledger lines it wrote (`debit_id`, `credit_id`) and the website request's Idempotency-Key (`request_key`).
         *
         * `customer_groups` — the admin's groups of customers («گروه»), members in `customer_group_user`.
         */
        'users' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->unsignedBigInteger('telegram_id')->nullable();
            $table->string('email', 191)->nullable();
            $table->string('password_hash', 255)->nullable();
            $table->string('google_sub', 191)->nullable();
            $table->text('totp_secret')->nullable();
            $table->text('totp_recovery_codes')->nullable();
            $table->timestamp('totp_enabled_at')->nullable();
            $table->unsignedInteger('totp_last_step')->nullable();
            $table->string('username', 64)->nullable();
            $table->string('first_name', 128)->nullable();
            $table->string('last_name', 128)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('status', 16)->default('active');
            $table->string('role', 16)->default('customer');
            $table->boolean('bot_blocked')->default(false);
            $table->foreignId('agency_level_id')->nullable()->constrained('agency_levels')->restrictOnDelete();
            $table->decimal('credit_limit', 14, 2)->default(0);
            // A percent off whatever the customer buys, theirs for good (Discounts: «تخفیف اختصاصی»); 0 none.
            $table->unsignedTinyInteger('discount_percent')->default(0);
            $table->string('referral_code', 16)->nullable()->unique();
            $table->foreignId('referred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // A sign-in finds its customer by one of these, in their shop; several customers without one share its null.
            $table->unique(['bot_id', 'telegram_id']);
            $table->unique(['bot_id', 'email']);
            $table->unique(['bot_id', 'google_sub']);
            $table->index(['bot_id', 'status']);
            // A shop's customers newest first (the users screen), and the dashboard's newcomers by day.
            $table->index(['bot_id', 'created_at']);
            // The users screen in the order they were last seen, read off the index instead of sorting the shop.
            $table->index(['bot_id', 'last_seen_at']);
            // A referrer's referrals, counted from the index with the shop's `bot_id` filter (the referral lists, the
            // program's numbers).
            $table->index(['referred_by', 'bot_id']);
        },

        'agency_requests' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('note', 500)->nullable();
            $table->foreignId('level_id')->nullable()->constrained('agency_levels')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->string('reviewer', 64)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['user_id', 'id']);
        },

        'wallet_transactions' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 8);
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_after', 14, 2);
            $table->string('description')->nullable();
            // Who wrote a line by hand (a panel, a website admin), as Auth\Services\Reviewers keeps them; null for the
            // shop's own lines.
            $table->string('reviewer', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            // A customer's last line (their balance, off the index alone) and their ledger newest first. Named: the
            // default name would pass MySQL's 64 characters after the longest prefix.
            $table->index(['user_id', 'id', 'balance_after'], 'wallet_transactions_balance_index');
        },

        'wallet_transfers' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            // The sender's line and the recipient's: a panel shows each line's other customer.
            $table->foreignId('debit_id')->unique()->constrained('wallet_transactions')->cascadeOnDelete();
            $table->foreignId('credit_id')->unique()->constrained('wallet_transactions')->cascadeOnDelete();
            // Null from the bot. The same key from the same sender is answered by this transfer.
            $table->string('request_key', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            // What a sender sent today (their daily cap), and a sender's key answered once.
            $table->index(['sender_id', 'created_at']);
            $table->unique(['sender_id', 'request_key']);
            $table->index('recipient_id');
        },

        'customer_groups' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->string('name', 32);
            // Its customers pay plans' agent prices («همکار»), as the agents do.
            $table->boolean('agent_pricing')->default(false);
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->unique(['bot_id', 'name']);
        },

        'customer_group_user' => static function (Blueprint $table): void {
            $table->foreignId('customer_group_id')->constrained('customer_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->primary(['customer_group_id', 'user_id']);
            $table->index('user_id');
        },

        /*
         * A shop's website (the Store API): one row per bot, made switched off the first time a panel asks. `key` is the
         * public store key in the API's address (a new one ends the old address); `url` and `origins` are the origins a
         * browser may call from. Sign-in: Telegram (`telegram_login`, `telegram_client_id`, `telegram_client_secret`
         * encrypted, needed for the redirect flow only), email sign-up (`email_signup`), Google (`google_client_id`). The
         * captcha its forms ask: `captcha_driver` (null: none) and `captcha_config` (encrypted JSON). Its admins
         * (Store\Http\StaffMiddleware): `staff_enabled`, `staff_strong_sign_in`, `staff_grants` (Store\Enums\StaffGrant).
         *
         * `customer_sessions` — a website sign-in per device: `token_hash` (the bearer token's sha256; the token is shown
         * once), `device` and `ip` for the session list. Ends 30 days unused, or at `expires_at` (180 days after the
         * sign-in). `authenticated_at` is when a way in was last proven on it (changing how the account signs in asks
         * this within 15 minutes); `method` how it was signed in (Accounts\Enums\SignInMethod; null counts as a password
         * alone).
         *
         * `auth_challenges` — the sign-in flows' short-lived secrets: nonces, OIDC states (with the PKCE challenge and
         * redirect address in `payload`, encrypted JSON), emailed codes (`subject` the address), a two-factor second
         * step, an authenticator secret awaiting its first code, a merge ticket. `secret_hash` is the secret's sha256
         * (keyed with APP_KEY for a code: six digits are no secret to a plain hash). Each is spent once by a conditional
         * DELETE, and one tried with codes at most `attempts` times.
         *
         * `account_merges` — every merge of two customers (Accounts\Services\AccountMerger): the account that stayed, the
         * merged one's former id and what identified it (`merged`), counts of what moved (`moved`), and the `actor`.
         */
        'websites' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->unique()->constrained('bots')->restrictOnDelete();
            $table->string('key', 24)->unique();
            $table->boolean('enabled')->default(false);
            $table->string('url', 255)->nullable();
            $table->json('origins')->nullable();
            $table->boolean('telegram_login')->default(false);
            $table->string('telegram_client_id', 32)->nullable();
            $table->text('telegram_client_secret')->nullable();
            $table->boolean('email_signup')->default(false);
            $table->string('google_client_id', 191)->nullable();
            $table->string('captcha_driver', 32)->nullable();
            $table->text('captcha_config')->nullable();
            $table->boolean('reviews_enabled')->default(false);
            $table->boolean('staff_enabled')->default(false);
            $table->boolean('staff_strong_sign_in')->default(true);
            $table->json('staff_grants')->nullable();
            $table->timestamps();
        },

        'customer_sessions' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('device', 128)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('method', 16)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('authenticated_at')->nullable();
            // Always set; a TIMESTAMP that may not be null needs a default in strict MySQL — one written without it has ended.
            $table->timestamp('expires_at')->useCurrent();
            $table->timestamp('created_at')->nullable();

            // A customer's sessions, newest first (their session list).
            $table->index(['user_id', 'id']);
        },

        'auth_challenges' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->string('purpose', 16);
            $table->string('subject', 191)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('secret_hash', 64);
            $table->text('payload')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            // Always set; a default only because strict MySQL wants one — a challenge written without it has expired.
            $table->timestamp('expires_at')->useCurrent();
            $table->timestamp('created_at')->nullable();

            // A secret the browser brings back, found in its shop (its own name: the columns', after the longest table
            // prefix, would reach MySQL's 64 characters).
            $table->index(['bot_id', 'purpose', 'secret_hash'], 'auth_challenges_secret_index');
            // A code emailed to an address, found by what it is for and the address (and the one it replaces).
            $table->index(['bot_id', 'purpose', 'subject']);
            // What has expired, for the hourly housekeeping.
            $table->index('expires_at');
        },

        'account_merges' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('merged_user_id');
            $table->json('merged');
            $table->json('moved');
            $table->string('actor', 64);
            $table->timestamp('created_at')->nullable();

            // A customer's merges, newest first (their page in the panels).
            $table->index(['user_id', 'id']);
        },

        /*
         * Every notice the shop sent a customer (Notifications\Services\Notices), kept for their website's feed however
         * it reached them: `type` (NoticeType), `text` (Telegram HTML), `subject_type` + `subject_id` (the order, service
         * or ticket to link to), `read_at`. Kept 180 days.
         */
        'notifications' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 32);
            $table->text('text');
            $table->string('subject_type', 16)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();

            // A customer's feed, newest first, a page at a time (GET /notifications).
            $table->index(['user_id', 'id']);
            // What a customer has not read: counted off the index alone (GET /me, the feed's meta), the feed's `unread`
            // page newest first (the id rides at its end), and the rows that marking them read writes.
            $table->index(['user_id', 'read_at']);
            // What aged out, for the hourly housekeeping (every shop's at once).
            $table->index('created_at');
        },

        /*
         * The VPN panels services are made on — the installation's, every bot sells on them. `password`, `api_token` and
         * `totp_secret` are encrypted; `meta` holds the connector's other settings. `last_checked_at` / `last_error` are
         * the last contact and why it failed (a failed panel is left alone a while). `serves_subscriptions` is what the
         * last check learned (null: never asked); a server serving no subscription links is not sold.
         *
         * `proxy_*` are the SOCKS5 proxy the shop reaches the panel through, when `proxy_enabled` (a panel the shop's host
         * cannot reach directly): its host, port and optional login; `proxy_password` is encrypted.
         *
         * `test_*` are the free test accounts («اکانت تست») the panel gives: whether it gives them, and what each gets —
         * traffic, hours, and whether the hours count from the first connection (`test_on_hold`) or from the moment the
         * test is made.
         *
         * `server_inbounds` mirrors what the panel attaches clients to, by the connector's own `remote_key` — never the
         * protocol settings, which carry every client's secrets. `is_selectable` is the admin offering it for sale;
         * `enabled` follows the panel (one it no longer lists is kept, switched off).
         */
        'servers' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name', 100);
            $table->string('driver', 32);
            $table->string('base_url');
            $table->string('username', 128)->nullable();
            $table->text('password')->nullable();
            $table->text('api_token')->nullable();
            $table->text('totp_secret')->nullable();
            $table->boolean('proxy_enabled')->default(false);
            $table->string('proxy_host')->nullable();
            $table->unsignedSmallInteger('proxy_port')->nullable();
            $table->string('proxy_username')->nullable();
            $table->text('proxy_password')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('capacity')->nullable();
            $table->integer('sort')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->boolean('serves_subscriptions')->nullable();
            $table->json('meta')->nullable();
            $table->boolean('test_enabled')->default(false);
            // 500 MB.
            $table->unsignedBigInteger('test_traffic_bytes')->default(524288000);
            $table->unsignedInteger('test_duration_hours')->default(24);
            $table->boolean('test_on_hold')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort']);
        },

        'server_inbounds' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->string('remote_key', 191);
            $table->string('tag', 128);
            $table->string('protocol', 32)->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('remark')->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('is_selectable')->default(false);
            $table->string('network', 32)->nullable();
            $table->string('security', 32)->nullable();
            $table->unsignedInteger('client_count')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'remote_key']);
        },

        /*
         * What is for sale, each bot its own. A plan is a term, traffic and a price (0 traffic or term: unlimited), in a
         * category or none. `plan_servers` is where it is sold, one row per server: `all_inbounds` sells every inbound
         * the server marks selectable, else the rows of `plan_server_inbounds`. A purchase picks one server and gets one
         * client on all of that entry's inbounds. `sort` is the customer-facing order.
         */
        'plan_categories' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->string('name', 64);
            $table->boolean('is_active')->default(true);
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->unique(['bot_id', 'name']);
            $table->index(['is_active', 'sort']);
        },

        'plans' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('plan_categories')->nullOnDelete();
            $table->string('name', 128);
            $table->text('description')->nullable();
            // A note under its checkout («یادداشت پیش‌فاکتور»), a purchase's and a renewal's; null for none.
            $table->text('checkout_note')->nullable();
            $table->decimal('price', 14, 2);
            // The price of a customer's first purchase in the shop («قیمت خرید اول»), below the price; null for none.
            $table->decimal('first_purchase_price', 14, 2)->nullable();
            // The price for agents and «همکار» customer groups («قیمت نماینده»), below the price; null for none.
            $table->decimal('agent_price', 14, 2)->nullable();
            // The term in hours from the first connection (a daily plan's days × 24); 0 for no limit.
            $table->unsignedInteger('duration_hours')->default(720);
            $table->decimal('traffic_gb', 10, 2)->default(0);
            $table->unsignedInteger('ip_limit')->default(0);
            // The plan's own prices for extra traffic and time (ExtrasSettings' form, by field); null for the shop's.
            $table->json('extras')->nullable();
            // Its button in the bot's plan list: Telegram's colour (null: the client's) and how many share its row (1–3).
            $table->string('button_style', 16)->nullable();
            $table->unsignedTinyInteger('button_row')->default(1);
            $table->boolean('is_active')->default(true);
            // An agent's plan the owner keeps from sale («پنهان‌شده توسط مدیر»), whatever its own switch says.
            $table->boolean('hidden_by_owner')->default(false);
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort']);
        },

        'plan_servers' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->boolean('all_inbounds')->default(false);
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->unique(['plan_id', 'server_id']);
        },

        'plan_server_inbounds' => static function (Blueprint $table): void {
            $table->foreignId('plan_server_id')->constrained('plan_servers')->cascadeOnDelete();
            $table->foreignId('server_inbound_id')->constrained('server_inbounds')->cascadeOnDelete();

            $table->primary(['plan_server_id', 'server_inbound_id']);
        },

        /*
         * The servers the owner keeps from an agent's shop: its plans are not sold on them, its free tests not given there
         * and its services not moved there; the services it already has on them run on (Bots\Models\Bot::hidesServer()).
         */
        'bot_hidden_servers' => static function (Blueprint $table): void {
            $table->foreignId('bot_id')->constrained('bots')->cascadeOnDelete();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();

            $table->primary(['bot_id', 'server_id']);
        },

        /*
         * A customer's service: one client on one panel. `remote_name` is the client's panel-wide name ("amir_1",
         * "USER_7") and `subscription_url` the one link the customer gets. Counters, quota and term mirror the panel as
         * of `last_synced_at`; `status` is the shop's own. A change made on the panel holds the row with a lease, so two
         * never interleave. `is_test` marks a free test account: no plan, never renewed, removed once it ends while its
         * shop cleans up. `ended_at` is when it ended — its deadline, or when the shop found its traffic used up or its
         * client gone from the panel; null while it runs —, what the automatic removal of ended services counts from.
         *
         * `test_accounts` — the free tests given, each shop's: what the quota counts, so a test removed from its panel
         * still counts. A row is claimed (`issued_at` null) before the panel is asked, issued once the service is made,
         * and deleted again when that failed. `subscription_id` is the service while it lasts. The terms are kept as
         * they were given.
         */
        'subscriptions' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->boolean('is_test')->default(false);
            // When the customer added it to their account by sending its link: a client on the panel the shop did not
            // sell (ServiceClaims). It has no plan until a renewal gives it one.
            $table->timestamp('claimed_at')->nullable();
            $table->foreignId('server_id')->constrained('servers')->restrictOnDelete();
            $table->string('remote_name', 191);
            $table->string('subscription_url', 500);
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('traffic_limit_bytes')->default(0);
            $table->unsignedBigInteger('upload_bytes')->default(0);
            $table->unsignedBigInteger('download_bytes')->default(0);
            $table->unsignedInteger('ip_limit')->default(0);
            // The term in hours from the first connection (0: never ends); starts_at / expires_at stay null until the
            // panel starts the clock.
            $table->unsignedInteger('duration_hours')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            // When support switched it off.
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            // The customer's «تمدید خودکار» switch, and when they were last told their wallet could not pay it (once per
            // renewal window).
            $table->boolean('auto_renew')->default(false);
            $table->timestamp('renewal_notified_at')->nullable();
            // When the customer was reminded of the end / of low traffic: once while past the threshold, cleared when
            // back under it.
            $table->timestamp('expiry_reminded_at')->nullable();
            $table->timestamp('traffic_reminded_at')->nullable();
            // A renewal queued behind the period in use (bought before that period ended): at period_ends_at at most
            // next_period_bytes remains (unused traffic not carried; null: nothing capped) and the device limit becomes
            // next_period_ip_limit (null: as it is).
            $table->timestamp('period_ends_at')->nullable();
            $table->unsignedBigInteger('next_period_bytes')->nullable();
            $table->unsignedInteger('next_period_ip_limit')->nullable();
            // When the device limit last went back to a package's (a renewal's, at once or when its queued period
            // began): extra devices bought before then are no longer in it.
            $table->timestamp('ip_limit_reset_at')->nullable();
            $table->string('lease_token', 32)->nullable();
            $table->timestamp('leased_until')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'remote_name']);
            // A customer's services by state, counted from the index with the shop's `bot_id` filter (users screen).
            $table->index(['user_id', 'bot_id', 'status']);
            // A shop's services by state and deadline: the screen's tabs and counts, ending soon, automatic renewal, reminders.
            $table->index(['bot_id', 'status', 'expires_at']);
            // The same across every shop: the sync's servers, the mass gift's audience.
            $table->index(['status', 'expires_at']);
            // A server's services by state: its room, its page and the servers list, a grant's audience.
            $table->index(['server_id', 'status']);
            // A plan's services by state (the plans screen), and the renewed periods due to begin.
            $table->index(['plan_id', 'status']);
            $table->index(['bot_id', 'period_ends_at']);
            // A shop's test accounts by state: the clean-up's and the screen's.
            $table->index(['bot_id', 'is_test', 'status']);
        },

        'test_accounts' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->unsignedBigInteger('traffic_bytes');
            $table->unsignedInteger('duration_hours');
            $table->boolean('on_hold');
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            // A customer's tests in a window (their quota); the shop's in a day (its cap) and newest first (the list).
            $table->index(['user_id', 'bot_id', 'created_at']);
            $table->index(['bot_id', 'created_at']);
            // A service's test, let go when the service is deleted (ProvisioningService).
            $table->index('subscription_id');
        },

        /*
         * Days and traffic given to running services (an outage made good, a gift), with the `reason` customers read
         * when `notify`. A grant covers the services that existed when it was issued (`upto_subscription_id`) in its
         * `audience` and has a part per server (`grant_parts`), each worked through past its cursor by whoever holds its
         * lease, so a panel out of reach holds up only its own server. A grant's status is its parts'. One part runs
         * per server at a time: `running_server_id` (generated) is unique.
         */
        'grants' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('days')->default(0);
            $table->unsignedBigInteger('traffic_bytes')->default(0);
            $table->string('reason', 500)->nullable();
            $table->boolean('notify')->default(true);
            $table->boolean('include_unstarted')->default(false);
            $table->string('audience', 8);
            // The customer group of a mass gift to one group (`audience` group).
            $table->foreignId('customer_group_id')->nullable()->constrained('customer_groups')->nullOnDelete();
            $table->unsignedBigInteger('upto_subscription_id')->default(0);
            $table->string('reviewer', 191)->nullable();
            $table->timestamp('created_at')->nullable();
        },

        'grant_parts' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('grant_id')->constrained('grants')->cascadeOnDelete();
            // No cascade: MySQL refuses one on a column a stored generated column is computed from (running_server_id).
            // A server deleted takes its parts with it in ServerService::delete().
            $table->foreignId('server_id')->constrained('servers')->restrictOnDelete();
            $table->string('status', 16);
            $table->unsignedBigInteger('last_subscription_id')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('granted')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('waiting_reason', 500)->nullable();
            $table->string('last_failure', 500)->nullable();
            $table->string('lease_token', 32)->nullable();
            $table->timestamp('leased_until')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('running_server_id')->nullable()->storedAs("case when status = 'running' then server_id end");

            $table->unique(['grant_id', 'server_id']);
            $table->unique('running_server_id');
            // A server's parts, newest first (its page), and whether one runs there; the parts under way (the task).
            $table->index(['server_id', 'status']);
            $table->index('status');
        },

        /*
         * A server's services moved to another in bulk («انتقال سرویس‌ها»): `mode` migrate («مهاجرت») while the source's
         * panel answers, rescue («نجات») when it does not. A job covers every shop's services on the source when it was
         * started (`upto_subscription_id`) — the active ones, those switched off by support with `include_disabled`, only
         * `plan_ids`' when given — and is worked through past its cursor by whoever holds its lease, as a grant's part
         * is. One open job (queued, running, paused) per source: `open_source_id` (generated) is unique; MySQL cannot
         * cascade on the column it is computed from, so ServerService::delete() removes a server's jobs itself.
         *
         * `migration_failures` — each service a job could not move, and why. `migration_leftovers` — each client a move
         * left on a panel (a rescue's on the source's, one a panel did not say it deleted or made), a job's or a single
         * move's (no `migration_id`), removed once that panel answers again (`removed_at`); it outlives its job, which a
         * deleted server takes with it.
         */
        'server_migrations' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('source_server_id')->constrained('servers')->restrictOnDelete();
            $table->foreignId('target_server_id')->constrained('servers')->restrictOnDelete();
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

            $table->unique('open_source_id');
            // A server's jobs, newest first (its page); whether a server is the target of one open; the jobs under way (the task).
            $table->index(['source_server_id', 'status']);
            $table->index(['target_server_id', 'status']);
            $table->index('status');
        },

        'migration_failures' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('migration_id')->constrained('server_migrations')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('remote_name', 191);
            $table->string('reason', 500);
            $table->timestamp('created_at')->nullable();

            $table->index('migration_id');
            $table->index('subscription_id');
        },

        'migration_leftovers' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('migration_id')->nullable()->constrained('server_migrations')->nullOnDelete();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('remote_name', 191);
            $table->string('last_error', 500)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('removed_at')->nullable();

            // A server's clients still to remove (its page, the task); a job's.
            $table->index(['server_id', 'removed_at']);
            $table->index('migration_id');
            $table->index('subscription_id');
        },

        /*
         * Money.
         *
         * `payment_methods` — one row per instance of a gateway driver: `config` is the driver's settings, `sort` the
         * checkout order, `min_amount` and `max_amount` the amounts it is offered for (null: no bound). The wallet is a
         * built-in row of every shop.
         *
         * `payment_cards` — the cards a card-to-card method shows, one picked at random per payment among the enabled ones
         * whose `daily_cap` (Toman a day on the shop's clock; null: none) leaves room; `bank` is the owner's word for it.
         *
         * `orders` — a purchase (`plan_id` and the chosen `server_id`), a renewal or an extra (`subscription_id`), a
         * wallet top-up, or an agent's traffic (`traffic_bytes`). `status` moves pending → paid → processing → fulfilled
         * (or failed), every step a compare-and-swap so nothing is delivered twice. `notes` is why it failed or was
         * cancelled, in words anyone may read; `diagnosis` the panel's failure in full, shown to the owner alone.
         * `created_by` and `support_note` mark one support made for its customer by hand, paid at once: free, from the
         * wallet, or by the outside row — money support took outside the shop, another built-in row of every shop.
         *
         * `request_keys` — the website's ordering requests by Idempotency-Key and the order each came to, with the way to
         * pay it asked for: the same request again gets that order back, never a second one. Kept a week.
         *
         * `payments` — one attempt to pay an order through a method (a method with payments is never deleted); a card
         * transfer's `card_id` is the card it was shown, the same every time it is shown (a card with payments is never
         * deleted either). A receipt sent in the bot is Telegram's file (`receipt_file_id`, `receipt_message_id`); one
         * uploaded on the website is a file under storage/uploads/receipts (`receipt_path`). `note` is the latest
         * verdict's word; `reviewer` who decided (none when the review window accepted it).
         *
         * `referral_commissions` — what a payment earned the referrer, once per payment; `referrer_id` is kept as it was
         * when earned, and `notified_at` is claimed by the one message that tells them.
         *
         * `traffic_transactions` — an agent's prepaid traffic ledger (Agency\Services\TrafficPool): purchases, sales and
         * renewals drawn, extensions, refunds and adjustments. `bytes` is signed; `balance_after` is what the bot may still
         * sell.
         */
        'payment_methods' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->string('driver', 32);
            $table->string('label', 60);
            $table->json('config');
            $table->decimal('min_amount', 14, 2)->nullable();
            $table->decimal('max_amount', 14, 2)->nullable();
            $table->boolean('enabled')->default(true);
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->index(['enabled', 'sort']);
        },

        'payment_cards' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->cascadeOnDelete();
            $table->string('number', 16);
            $table->string('holder', 100);
            $table->string('bank', 60)->nullable();
            $table->boolean('enabled')->default(true);
            $table->integer('sort')->default(0);
            $table->decimal('daily_cap', 14, 2)->nullable();
            $table->timestamps();

            // A method's cards in their order (its form, the pick).
            $table->index(['payment_method_id', 'sort']);
        },

        /*
         * Discounts. `discount_codes` — a code typed at checkout: `type` percent, fixed or free and its `value` (a percent
         * capped at `max_discount`, or Toman), limited by `min_amount`, `order_types`, `plan_ids`, `server_ids` (null:
         * any), `first_purchase_only`, its window and its uses (`max_uses`, `per_user`). A use is an order made with it
         * that is not cancelled, so an unpaid order gives its use back by itself. `sales` — a timed percent off plans
         * («فروش ویژه»). `loyalty_tiers` — tiers by what a customer spent («سطح وفاداری»), each a discount and extra
         * cashback. A customer's own discount is `users.discount_percent`.
         */
        'discount_codes' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
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

            // A code a customer types, found in their shop (its case aside: kept in upper case).
            $table->unique(['bot_id', 'code']);
        },

        'sales' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->string('name', 64);
            $table->unsignedTinyInteger('percent');
            $table->json('order_types')->nullable();
            $table->json('plan_ids')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            // The sales a shop runs now, read with every price shown.
            $table->index(['bot_id', 'enabled', 'ends_at']);
        },

        'loyalty_tiers' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->string('name', 32);
            $table->decimal('min_spent', 14, 2)->default(0);
            $table->unsignedTinyInteger('discount_percent')->default(0);
            $table->unsignedTinyInteger('cashback_percent')->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['bot_id', 'name']);
        },

        /*
         * Cashback rules («کش‌بک»): a percent of what an order paid, credited once it is delivered, per kind of order
         * (null: purchases and renewals), customer group and way to pay (null: any).
         */
        'cashback_rules' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->unsignedTinyInteger('percent');
            $table->json('order_types')->nullable();
            $table->foreignId('customer_group_id')->nullable()->constrained('customer_groups')->cascadeOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['bot_id', 'enabled']);
        },

        /*
         * The lucky wheel's prizes («گردونه شانس»): a `kind` (nothing, wallet, traffic, days, a free plan), its `amount`,
         * and a `weight`, its share of the wheel.
         */
        'wheel_prizes' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->string('label', 64);
            $table->string('kind', 16);
            $table->decimal('amount', 14, 2)->nullable();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->unsignedInteger('weight')->default(1);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['bot_id', 'enabled']);
        },

        'orders' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('status', 16)->default('pending');
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignId('server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            // What an order brings: traffic (an agent's, a service's extra, or a built service's) and days (a service's extra
            // time, or a built service's term), and devices (extra devices, or a built service's limit).
            $table->unsignedBigInteger('traffic_bytes')->nullable();
            $table->unsignedInteger('days')->nullable();
            $table->unsignedInteger('devices')->nullable();
            // A purchase's client name the buyer asked for (typed, or drawn at random); null for the shop's pattern.
            $table->string('client_name', 64)->nullable();
            // How a renewal adds its package (Subscriptions\Enums\RenewalMethod); null for any other order.
            $table->string('renewal_method', 16)->nullable();
            // What a renewal gave its service — the term and traffic it added as delivered (signed: a reset that replaced
            // more than it gave gave less than nothing), its plan when it had none (one added by its link), a term when it
            // never ended, a quota when it was unlimited —, what its refund takes back; the term (in hours, and the
            // deadline; null: waiting for the first connection) and the traffic left that a package without them
            // replaced, which its refund puts back; and what it started afresh (its term, its traffic count, its device
            // limit; extra days for a service whose time ran out start its term afresh too), which an earlier order's
            // refund no longer takes, and hands on to it. Its device limit too: the one it set at once, or queued behind
            // the period in use (ip_limit_queued), and what that replaced (the service's limit, or the limit queued
            // before; null: none; set at once, when the limit had last gone back to a package's), which its refund puts
            // back.
            $table->integer('given_hours')->nullable();
            $table->bigInteger('given_bytes')->nullable();
            $table->boolean('gave_plan')->default(false);
            $table->boolean('gave_term')->default(false);
            $table->boolean('gave_quota')->default(false);
            $table->unsignedInteger('term_was')->nullable();
            $table->timestamp('expires_was')->nullable();
            $table->unsignedBigInteger('quota_was')->nullable();
            $table->boolean('reset_term')->default(false);
            $table->boolean('reset_traffic')->default(false);
            $table->boolean('reset_ip_limit')->default(false);
            $table->unsignedInteger('given_ip_limit')->nullable();
            $table->unsignedInteger('ip_limit_was')->nullable();
            $table->boolean('ip_limit_queued')->default(false);
            $table->timestamp('ip_limit_reset_was')->nullable();
            $table->decimal('amount', 14, 2);
            // Which of the plan's prices it was made at (Orders\Enums\OrderPricing).
            $table->string('pricing', 16)->default('regular');
            // What came off its price (Orders\DTO\Quote): the total, the lines (source, words, Toman), and the code used.
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->json('discounts')->nullable();
            $table->foreignId('discount_code_id')->nullable()->constrained('discount_codes')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->text('diagnosis')->nullable();
            // An order support made for its customer by hand (Orders\Services\ManualOrders): who made it — the name a
            // decision keeps —, and their note; both null for an order the customer made.
            $table->string('created_by', 191)->nullable();
            $table->text('support_note')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            // When a refund took back what a renewal or an extra gave its service: once.
            $table->timestamp('taken_back_at')->nullable();
            $table->timestamps();

            // A customer's orders by status, counted from the index with the shop's `bot_id` filter (the users screen,
            // their page, the unpaid ones a website's checkout may leave open).
            $table->index(['user_id', 'bot_id', 'status']);
            $table->index('subscription_id');
            // A plan's sales (the plans screen).
            $table->index(['plan_id', 'status']);
            // A shop's orders by status and type (the screen's tabs, its type filter and their counts, the dashboard's
            // attention card) and by day (the dates, the dashboard): with the amount, what a list sold is summed from
            // the index alone.
            $table->index(['bot_id', 'status', 'type', 'amount']);
            $table->index(['bot_id', 'created_at', 'status', 'amount']);
            // A code's uses — in all, and a customer's —, counted from the index (Discounts\Services\DiscountCodes).
            $table->index(['discount_code_id', 'user_id', 'status']);
        },

        /*
         * Rewards given. `gift_codes` — wallet credit or a free plan («کد هدیه»), made in batches, redeemed once.
         * `wheel_spins` — each spin: who, the prize as it was, the outcome. `cashbacks` — an order's cashback, once.
         * `lottery_draws` — a night's draw among buyers, and `lottery_winners`.
         */
        'gift_codes' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('batch', 64)->nullable();
            $table->string('kind', 8);
            $table->decimal('amount', 14, 2)->nullable();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('redeemed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('redeemed_at')->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamps();

            $table->unique(['bot_id', 'code']);
            // A batch's codes, the newest first (the panel's list).
            $table->index(['bot_id', 'batch']);
        },

        'wheel_spins' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('wheel_prize_id')->nullable()->constrained('wheel_prizes')->nullOnDelete();
            $table->string('kind', 16);
            $table->string('label', 64);
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('outcome', 191)->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            // A customer's last spin (the wheel's cooldown) and a shop's spins newest first (the panel's list).
            $table->index(['user_id', 'created_at']);
            $table->index(['bot_id', 'created_at']);
        },

        'cashbacks' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('percent');
            $table->decimal('amount', 14, 2);
            $table->timestamp('reversed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'bot_id']);
        },

        'lottery_draws' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            // The shop's day it drew (its zone's), once a day.
            $table->date('day');
            $table->unsignedInteger('entries')->default(0);
            $table->string('prize_kind', 16);
            $table->decimal('prize_amount', 14, 2)->nullable();
            $table->foreignId('prize_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['bot_id', 'day']);
        },

        'lottery_winners' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('lottery_draw_id')->constrained('lottery_draws')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('outcome', 191)->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('user_id');
        },

        'request_keys' => static function (Blueprint $table): void {
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('key', 64);
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            // The way to pay the request asked for; null only on keys kept before this column (any way is then accepted).
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            // The order a customer's request came to by its key (OrderService::keyed()) — one order a key.
            $table->primary(['user_id', 'key']);
            // The keys a week old, forgotten (ExpireOrdersTask).
            $table->index('created_at');
        },

        'payments' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->foreignId('card_id')->nullable()->constrained('payment_cards')->restrictOnDelete();
            $table->string('status', 16)->default('pending');
            $table->decimal('amount', 14, 2);
            $table->string('reference', 191)->nullable();
            // An online gateway's invoice (GatewayKind::Online): the page the customer pays on, until when it takes money
            // there, the gateway's own details of it, and when the shop last asked the gateway about it.
            $table->string('link', 1000)->nullable();
            $table->timestamp('link_expires_at')->nullable();
            $table->json('invoice')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->string('receipt_file_id', 255)->nullable();
            $table->string('receipt_path', 255)->nullable();
            $table->string('receipt_name', 255)->nullable();
            $table->string('receipt_note', 1024)->nullable();
            $table->integer('receipt_message_id')->nullable();
            $table->timestamp('receipt_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('reviewer', 64)->nullable();
            $table->timestamps();

            // A shop's payments by status — the screen's tabs, the receipts waiting for review — and its takings by day (the
            // dashboard's revenue: the method, the amount and the order — whether a refund was a top-up's — read from the
            // index alone, no row read; its own name, the columns' would pass MySQL's 64 characters); its payments by the
            // day they were made (the screen's dates, with what the list took in); a method's payments, counted from the index.
            $table->index(['bot_id', 'status', 'paid_at', 'payment_method_id', 'amount', 'order_id'], 'payments_takings_index');
            $table->index(['bot_id', 'created_at', 'status', 'amount']);
            $table->index(['payment_method_id', 'bot_id']);
            // What a card took in today (its daily cap), summed from the index alone; a card's payments, counted from it —
            // both read without the shop's `bot_id` filter (PaymentCards): a card's payments are its shop's.
            $table->index(['card_id', 'created_at', 'status', 'amount']);
        },

        'referral_commissions' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->foreignId('payment_id')->unique()->constrained('payments')->cascadeOnDelete();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rate');
            $table->decimal('commission', 14, 2);
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            // What a referrer earned, summed from the index alone with the shop's `bot_id` filter (their numbers — the
            // bot's, the website's, their page's —, the referrers list; its own name: the columns' would pass MySQL's 64
            // characters).
            $table->index(['referrer_id', 'bot_id', 'commission'], 'referral_commissions_earned_index');
        },

        /*
         * The referral program beyond commissions. `referral_bonuses` — the start gift («هدیه شروع») to the referrer and
         * the newcomer, once per newcomer, on joining or on the first purchase (`trigger`). `referral_rewards` — a free plan
         * each time a referrer's buying referrals reach a `milestone`. `referral_alerts` — referrals that look made up
         * («زیرمجموعه فیک»): why, and how many.
         */
        'referral_bonuses' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->foreignId('invitee_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('referrer_amount', 14, 2)->default(0);
            $table->decimal('invitee_amount', 14, 2)->default(0);
            $table->string('trigger', 16);
            $table->timestamp('created_at')->nullable();

            $table->index('referrer_id');
        },

        'referral_rewards' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('milestone');
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['referrer_id', 'milestone']);
        },

        'referral_alerts' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 32);
            $table->unsignedInteger('count')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index(['referrer_id', 'created_at']);
            $table->index(['bot_id', 'created_at']);
        },

        'traffic_transactions' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->constrained('bots')->restrictOnDelete();
            $table->string('type', 16);
            $table->bigInteger('bytes');
            $table->bigInteger('balance_after');
            $table->string('description', 255)->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('reviewer', 191)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['bot_id', 'id']);
            $table->index('order_id');
        },

        /*
         * Support tickets (Support\Services\Tickets). `tickets` — a customer's question («تیکت») from the website or the
         * bot, maybe about a service: `status` open (waiting on support), answered or closed; `last_message_at` orders every
         * list; `customer_unread` says support wrote since they last read; a closed one may carry a `rating`.
         *
         * `ticket_messages` — the conversation: `author`, support's `reviewer`, the `body`, at most one picture (Telegram's
         * `attachment_file_id`, or an upload's `attachment_path` under storage/uploads/tickets, cleared 30 days after the
         * ticket closes while `attachment_name` stays), and the `channel` it was written in.
         */
        'tickets' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('subject', 120);
            $table->string('status', 16)->default('open');
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->boolean('customer_unread')->default(false);
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('rating_note', 500)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            // A customer's tickets, the latest activity first, a page at a time (the website's list).
            $table->index(['user_id', 'last_message_at']);
            // A shop's tickets by status, the latest activity first — the panels' tabs —, and the open ones counted off
            // the index alone (the queue: the sidebar, the dashboard, the list's own count).
            $table->index(['bot_id', 'status', 'last_message_at']);
            // The tickets about a service, let go of as it is deleted (the foreign key's).
            $table->index('subscription_id');
        },

        'ticket_messages' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->string('author', 16);
            $table->string('reviewer', 191)->nullable();
            $table->text('body');
            $table->string('attachment_file_id', 255)->nullable();
            $table->string('attachment_path', 100)->nullable();
            $table->string('attachment_name', 255)->nullable();
            $table->string('channel', 8);
            $table->timestamp('created_at')->nullable();

            // A ticket's conversation in order (its page), and its messages counted from the index (the panels' list).
            $table->index(['ticket_id', 'id']);
            // The uploaded pictures the shop keeps still — few: a customer's are a budget's, and they go a while after
            // their ticket closed — read from the index alone for the housekeeping that lets them go
            // (TicketAttachments::prune()), never every message of every ticket closed.
            $table->index('attachment_path');
        },

        /*
         * Customers' reviews of the shop («نظرات»), written on its website and shown once approved: the writer (null for a
         * guest), the `name` signed, a `rating` of 1–5, the `body`, a `context` line, an `avatar` key from the site's own
         * set (never an address), `status` pending, approved or rejected, and who decided it, when.
         */
        'reviews' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 64);
            $table->unsignedTinyInteger('rating');
            $table->string('body', 600);
            $table->string('context', 100)->nullable();
            $table->string('avatar', 32)->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('reviewer', 191)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            // A shop's reviews by status, the newest first — the website's approved ones and the panels' tabs, a page at a
            // time —, counted, and their ratings averaged, from the index alone (the website's summary, the queue's
            // pending count, each list's own).
            $table->index(['bot_id', 'status', 'id', 'rating']);
            // A customer's reviews, carried to the account that stays by a merge and let go of as theirs goes (the
            // foreign key's).
            $table->index('user_id');
        },

        /*
         * Each bot's own Telegram state.
         *
         * `telegram_updates` — every update a bot took, recorded before it is served so one Telegram sends again is served
         * once; kept two days (ReceivedUpdates::KEEP_HOURS). `chat_id` is the chat it counts against, so a chat faster than
         * a person is left unanswered while it keeps on.
         *
         * `telegram_sessions` — one row per chat: the conversation `state` and `data` (keys starting with `_` are the
         * chat's own facts and outlive a flow; the rest is the step's scratch).
         *
         * `bot_channels` — the channels a customer must join first; the bot must be an admin there (`bot_is_admin`, the
         * last check's finding). Each row has a public `username` or a private `invite_link`.
         *
         * `broadcasts` — a bot admin's /broadcast: their own message copied or forwarded to an `audience`, in batches past
         * the `last_user_id` cursor under a lease, with link `buttons` and optional pins (`broadcast_pins` keeps where).
         * `progress_message_id` is the admin's live progress message. A row of `kind` unpin takes its `source_id`'s pins
         * off again; it has no message of its own.
         *
         * `report_topics` — the topics the bot made in its report group, one per `topic`; a null `thread_id` is one being
         * made by whoever holds the lease, so two processes never make it twice.
         *
         * `report_messages` — the report group's queue (Telegram takes 20 a minute in a group), sent by `priority` then in
         * order, waiting out a flood limit instead of being lost. A receipt sent in the bot is copied
         * (`copy_chat_id`/`copy_message_id`), a picture the shop keeps sent from `photo_path`. `ref` / `reply_ref` /
         * `clears_buttons` thread a report under an earlier one and take its buttons off; `ticket_id` keeps a ticket's
         * reports while it is open. A message the bot writes in the group outside the queue (a rejection's prompt) is
         * recorded as one sent, so a reply is matched by the message it answers, never by its text.
         *
         * `custom_emojis` — the premium emoji an admin showed the bot (/emoji), for the editors' picker: the plain `emoji`
         * it stands for, a still picture (`file_id`), and how Telegram draws it (`format`, `animation_file_id`,
         * `repaint`).
         */
        'telegram_updates' => static function (Blueprint $table): void {
            $table->foreignId('bot_id')->constrained('bots')->restrictOnDelete();
            $table->unsignedBigInteger('update_id');
            $table->bigInteger('chat_id')->nullable();
            $table->timestamp('received_at')->useCurrent();

            $table->primary(['bot_id', 'update_id']);
            $table->index('received_at');
            // A chat's pace, counted off the index (named: the default name would pass MySQL's 64 characters).
            $table->index(['bot_id', 'chat_id', 'received_at'], 'telegram_updates_pace_index');
        },

        'telegram_sessions' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->bigInteger('chat_id');
            $table->string('state', 128)->nullable();
            $table->json('data')->nullable();

            $table->unique(['bot_id', 'chat_id']);
        },

        'bot_channels' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->bigInteger('chat_id');
            $table->string('type', 16);
            $table->string('title', 128);
            $table->string('username', 64)->nullable();
            $table->string('invite_link', 255)->nullable();
            $table->boolean('bot_is_admin')->default(true);
            $table->timestamp('checked_at')->nullable();
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->unique(['bot_id', 'chat_id']);
        },

        'broadcasts' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->string('kind', 8)->default('message');
            $table->foreignId('source_id')->nullable()->constrained('broadcasts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reviewer', 191)->nullable();
            $table->integer('message_id')->nullable();
            $table->string('mode', 8)->nullable();
            $table->string('audience', 16)->nullable();
            $table->unsignedBigInteger('audience_id')->nullable();
            $table->boolean('pin')->default(false);
            $table->json('buttons')->nullable();
            $table->string('content', 16)->nullable();
            $table->string('excerpt', 255)->nullable();
            $table->string('status', 16)->default('sending');
            $table->integer('total')->default(0);
            $table->integer('sent')->default(0);
            $table->integer('blocked')->default(0);
            $table->integer('failed')->default(0);
            $table->unsignedBigInteger('last_user_id')->default(0);
            $table->integer('progress_message_id')->nullable();
            $table->string('lease_token', 32)->nullable();
            $table->timestamp('leased_until')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('status');
        },

        'broadcast_pins' => static function (Blueprint $table): void {
            $table->foreignId('broadcast_id')->constrained('broadcasts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->integer('message_id');

            $table->primary(['broadcast_id', 'user_id']);
        },

        /*
         * `report_chats` — each bot's report group (Telegram\Reports\ReportGroupState): the group connected, what keeps
         * reports from it (`problem`), until when sending waits (`paused_until`), the connect link's one-time `code`
         * (encrypted) and the last group that could not be connected (`attempt_*`). Runtime state, read afresh.
         */
        'report_chats' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->unique()->constrained('bots')->restrictOnDelete();
            $table->bigInteger('chat_id')->nullable();
            $table->string('title', 128)->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->string('problem', 32)->nullable();
            $table->timestamp('paused_until')->nullable();
            $table->text('code')->nullable();
            $table->timestamp('code_expires_at')->nullable();
            $table->string('attempt_title', 128)->nullable();
            $table->string('attempt_problem', 32)->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamps();
        },

        'report_topics' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->string('topic', 32);
            $table->integer('thread_id')->nullable();
            $table->string('lease_token', 32)->nullable();
            $table->timestamp('leased_until')->nullable();
            $table->timestamps();

            $table->unique(['bot_id', 'topic']);
        },

        'report_messages' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->string('topic', 32);
            $table->unsignedTinyInteger('priority')->default(1);
            $table->text('text');
            $table->bigInteger('copy_chat_id')->nullable();
            $table->integer('copy_message_id')->nullable();
            $table->string('photo_path', 255)->nullable();
            $table->json('keyboard')->nullable();
            $table->string('ref', 64)->nullable();
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->string('reply_ref', 64)->nullable();
            $table->boolean('clears_buttons')->default(false);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('lease_token', 32)->nullable();
            $table->timestamp('leased_until')->nullable();
            $table->bigInteger('chat_id')->nullable();
            $table->integer('message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamps();

            // A bot's queue in sending order, and what it sent in the last minute, read off the index.
            $table->index(['bot_id', 'sent_at', 'failed_at', 'priority'], 'report_messages_queue_index');
            // Pruning: reports of no ticket under a null `ticket_id` (ReportSender::prune()), a ticket's by its ticket
            // (pruneTickets()), so the reports kept for open tickets are never read again.
            $table->index(['bot_id', 'ticket_id', 'sent_at', 'failed_at'], 'report_messages_pruning_index');
            $table->index('ref');
            // The report an admin's reply answers, by the message it replied to (Reports\TicketReplies).
            $table->index(['chat_id', 'message_id']);
        },

        'custom_emojis' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->string('emoji_id', 32);
            $table->string('emoji', 32);
            $table->string('file_id', 255)->nullable();
            $table->string('format', 8)->nullable();
            $table->string('animation_file_id', 255)->nullable();
            $table->boolean('repaint')->default(false);
            $table->timestamps();

            $table->unique(['bot_id', 'emoji_id']);
        },
    ],

    // The rows a new shop starts with, put into a table when it is made (never by a rebuild).
    'rows' => [
        // The main bot (its token lives in config.php).
        'bots' => static fn(): array => [
            ['id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ],
        // The wallet and the outside row (money support took outside the shop): the payment methods every shop has (an
        // agent's shop gets its own as it opens).
        'payment_methods' => static fn(): array => [
            ['driver' => WalletGateway::key(), 'label' => WalletGateway::label(), 'config' => '[]', 'enabled' => true, 'sort' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['driver' => OutsideGateway::key(), 'label' => OutsideGateway::label(), 'config' => '[]', 'enabled' => true, 'sort' => 2, 'created_at' => now(), 'updated_at' => now()],
        ],
    ],
];
