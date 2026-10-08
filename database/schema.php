<?php

declare(strict_types=1);

use App\Modules\Payments\Drivers\Wallet\WalletGateway;
use Illuminate\Database\Schema\Blueprint;

/*
 * The shop's database: every table, in the order they are made (a foreign key only points at a table above it), and
 * the rows a new shop starts with — what a fresh install makes (Core\Database\Schema applies this file). From the first
 * release on, a change here ships with its upgrade, database/upgrades/<version>.php, which brings an installed shop's
 * database to the same shape (Core\Database\Upgrades — the panel's updater runs them); `php bin/console db:rebuild`
 * stays a developer's tool, which makes every table again and carries the rows over.
 */
return [
    'tables' => [
        /*
         * The bots, each with a shop of its own: #1 is the main bot (its token and @username are config.php's, never
         * this row's), the others agents' («نماینده»), whose tokens they handed over from the main bot. A row of a shop — a
         * customer, a plan, an order, a setting — says whose it is with `bot_id` (Bots\CurrentBot); the servers, the
         * grants and the agency's own tables are the shop's as a whole. `user_id` is the agent (a customer of the main
         * bot; no foreign key — users come after bots): their bot runs while they have an agency
         * (`users.agency_level_id`). `token` is encrypted, `telegram_id`/`username`/`title` are what Telegram says the bot
         * is, `problem` what keeps it from running; `webhook_secret` (encrypted too) is in the address of its webhook. The
         * traffic the agent's bot may still sell is the last line of `traffic_transactions`. `panel_epoch` goes up to end
         * the agent's panel sessions; `login_code` is the sha256 of the code of their last panel login link (one use,
         * until `login_code_expires_at`).
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
            $table->timestamps();
        },

        /*
         * The shop's own bookkeeping.
         *
         * `settings` — the settings the admin changes at runtime from a panel (Settings service; groups of them on the
         * settings field engine), each bot's own, a JSON `value` each — plus the bot's runtime state Telegram\BotState
         * keeps there (webhook URL, poll heartbeat). The panel's own configuration lives in config.php, not here.
         *
         * `sequences` — named counters that only go up (Core\Database\Sequence): rows appear on first use, `value` is
         * the last number drawn. First user: the number after "USER_" on panel clients of customers without a
         * Telegram username.
         *
         * `change_versions` — the admin panel's live view (Core\Database\ChangeFeed): one number per area of the shop
         * (payments, orders, …) that goes up whenever a write to one of the area's tables is committed. Rows appear on
         * the first write.
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
         * «نمایندگی»: an agent runs a bot of their own and buys the traffic it sells, at their level's price per GB.
         * `agency_levels` are the admin's (name, `price_per_gb` in Toman, order); a level agents are on cannot go.
         */
        'agency_levels' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name', 32)->unique();
            $table->decimal('price_per_gb', 14, 2)->default(0);
            $table->integer('sort')->default(0);
            $table->timestamps();
        },

        /*
         * Customers — everyone who talked to a bot or signed in on its shop's website (Users\Services\Customers registers
         * them all the same way, whichever door they came through), a customer of that bot's shop (`bot_id`: one person
         * who talks to two bots is two customers), nothing else: the panel's login
         * is config.php's, not a user. The ways a customer signs in are each unique in their shop and each may be
         * missing: `telegram_id` (the Telegram account the bot knows them by — none for one who signed up on the website
         * alone, whom the bot never writes to), `email` (lower case, only ever written once proven — a code sent to it
         * typed back, or Google's `email_verified`), `password_hash` (bcrypt, only with an email) and `google_sub` (their
         * Google account). The email and password sign-in may ask a second step, a code of an authenticator app (RFC 6238):
         * `totp_secret` (encrypted), `totp_recovery_codes` (the keyed hashes of the one-time codes that stand in for the
         * app, encrypted JSON), `totp_enabled_at` (since when it is asked; null: it is not) and `totp_last_step` (the time
         * step of the last code taken: none is taken twice). `role` admin marks the shop's people inside the bot (privileged commands,
         * exempt from its gates). `phone` is the number the customer shared with their own contact card (E.164) —
         * shared is verified; not unique: a customer who recreates their account keeps it. `bot_blocked` is what
         * Telegram last said: the customer blocked the bot (a broadcast skips them). The wallet is the
         * `wallet_transactions` ledger, written only by WalletService: each line's `balance_after` is the balance from
         * then on, the last one the balance now. `referral_code` is the customer's own invite code (made the first time
         * they open «زیرمجموعه‌گیری»), `referred_by` the customer whose link — or code, on the website — brought them:
         * set as they are registered only, never changed. An agent («نماینده», a customer of the main bot) has an `agency_level_id`;
         * `credit_limit` is how far below zero their wallet may go (0 for everyone else).
         *
         * `agency_requests` — a customer asking to become an agent, with their `note`; support approves it (with the
         * level given) or rejects it (with the `reason` the customer reads), `reviewer` saying who.
         *
         * `customer_groups` — the admin's own groups of customers («گروه»: a name, in the admin's order), a customer in
         * any number of them (`customer_group_user`); a broadcast can go to one group.
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
            // A referrer's referrals, counted from the index with the shop's own filter (the referral lists, the program's numbers).
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
            // Who wrote a line by hand — a credit or a debit from a panel, or one of the shop's admins on its website —
            // as Auth\Services\Reviewers keeps them; null for the lines the shop writes itself.
            $table->string('reviewer', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            // A customer's last line — their balance, read off the index alone — and their ledger, newest first (its own
            // name: the columns', after the longest table prefix, would pass MySQL's 64 characters).
            $table->index(['user_id', 'id', 'balance_after'], 'wallet_transactions_balance_index');
        },

        'customer_groups' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->string('name', 32);
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
         * The shop's website (the Store API, docs/Store-API.md): a row per bot that has one — made, switched off, the
         * first time its panel asks. `key` is the public store key the API's address carries (24 lower-case hex
         * characters: it names the shop, it is no secret; a new one ends the old address); `url` the site's address, its
         * origin the first one a browser may call the API from, `origins` the others (a JSON list of
         * `scheme://host[:port]`). Telegram sign-in: `telegram_login` the switch, `telegram_client_id` the Client ID
         * @BotFather shows for the site, `telegram_client_secret` its secret (encrypted; only the redirect flow needs it).
         * Email sign-up: `email_signup` the switch (it takes the installation's mail). Google sign-in: `google_client_id`,
         * the site's OAuth client in Google Cloud. The captcha its forms ask — a sign-up, a sign-in with a password, a
         * password reset, and any of its own (Core\Captcha): `captcha_driver` the driver's key (null: none asked) and
         * `captcha_config` what its form keeps (encrypted JSON: Turnstile's keys, its secret among them). The shop's admins
         * on the website (its admin API, Store\Http\StaffMiddleware): `staff_enabled` whether it lets them in,
         * `staff_strong_sign_in` whether it asks them a strong sign-in (Telegram, Google, or a password with its second
         * step), `staff_grants` what it lets them do beyond the shop's daily work (a JSON list of Store\Enums\StaffGrant).
         *
         * `customer_sessions` — a customer signed in on the website, one row per device: `token_hash` the sha256 of the
         * bearer token (the token itself is shown once, never kept), `device` and `ip` what the customer's session list
         * shows. A session ends after 30 days unused (`last_used_at`, else `created_at`), at `expires_at` (180 days from
         * the sign-in) whatever its use, or when the customer ends it. `authenticated_at` is when the customer last proved
         * a way into the account on it — the sign-in, or one asked again since —: what changes how the account is signed
         * in to asks it within 15 minutes. `method` is how it was signed in (Accounts\Enums\SignInMethod — a stronger way
         * proven again since takes its place; null: a session opened before the shop kept it, counted as a password
         * alone): what the website's admin API asks of a session strong.
         *
         * `auth_challenges` — every short-lived secret of the sign-in flows: an id_token's nonce, an OIDC `state` with the
         * PKCE challenge the site made of its verifier, the redirect address and a nonce (`payload`, encrypted JSON), a
         * code emailed to an address (`subject`, the address — a sign-up's, with the account it makes in `payload`; a
         * password reset's; an email being added to an account, with the password chosen for it), a two-factor sign-in's
         * second step (`user_id` the account it signs in), an authenticator app's secret waiting for its first code (the
         * customer's, one at a time) and a merge ticket (the account that asked, the other one and what to carry over in
         * `payload`). `secret_hash` is the sha256 of the secret the browser holds (a code's keyed with APP_KEY: six digits
         * are no secret to a hash alone); one use each — spending one is a conditional DELETE, so of two at once one wins
         * —, one tried with codes `attempts` times at most.
         *
         * `account_merges` — every merge of two of a shop's customers into one (Accounts\Services\AccountMerger): the
         * account that stayed (`user_id`), the merged one's former number (`merged_user_id` — that row is gone), what it
         * was signed in by and called (`merged`: its Telegram id and handle, its email, whether a Google account signed it
         * in, its names, since when it was a customer), how much of what it owned moved (`moved`: counts), and who merged
         * them (`actor`: the customer themselves, or a panel's principal). The customer's page in the panels lists them.
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
         * What the shop told a customer (Notifications\Services\Notices) — every notice the bot sends them on its own, kept
         * for their website's feed whether it reached them in Telegram, by email or not at all: `type` what it was (a
         * Notifications\Enums\NoticeType), `text` its words as the bot wrote them (Telegram HTML; the caption, when it went
         * as a QR card), `subject_type` + `subject_id` what it is about for the website to link to (one of the customer's
         * orders or services; null for neither), `read_at` when they read it there. Kept 180 days.
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
         * Panels clients are created on — the shop's as a whole, every bot sells on them. Credentials (`password`,
         * `api_token`, `totp_secret`) are stored encrypted; `meta` tunes the connection (verify_tls, timeout,
         * subscription_url). `last_checked_at` / `last_error` are the last contact with the panel and why it failed
         * (a panel that failed is left alone a while). `serves_subscriptions` is what the last check learned about the
         * panel's subscription server (null = never asked) — and a server without one is not sold: the customer gets
         * exactly one subscription link, nothing else.
         *
         * `server_inbounds` mirrors what the panel attaches clients to (refreshed by every check) by the driver's own
         * key — only what the admin picks by, never the protocol settings (they carry every client's secrets):
         * `is_selectable` is the admin opting one in for sale; `enabled` follows the panel (one it no longer lists is
         * kept, switched off: services and plans may point at it); `protocol` and `port` are null for what has neither
         * (a PasarGuard group).
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
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('capacity')->nullable();
            $table->integer('sort')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->boolean('serves_subscriptions')->nullable();
            $table->json('meta')->nullable();
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
         * What is for sale. A plan is duration + traffic + price (0 = unlimited / no expiry), filed under one category
         * or none (the bot asks for the category first when any active category exists; a deleted category leaves its
         * plans uncategorised). `plan_servers` is where a plan is sold — one row per server: `all_inbounds` sells
         * whatever the server marks selectable (resolved at purchase time), otherwise the rows of
         * `plan_server_inbounds`. A purchase picks one server and gets a single client attached to every inbound of
         * that entry. `sort` is the customer-facing order everywhere. Each bot sells its own plans in its own categories
         * (`bot_id`) on the shop's servers.
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
            $table->decimal('price', 14, 2);
            $table->unsignedInteger('duration_days')->default(30);
            $table->decimal('traffic_gb', 10, 2)->default(0);
            $table->unsignedInteger('ip_limit')->default(0);
            $table->boolean('is_active')->default(true);
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
         * A subscription is one client on one panel: `remote_name` is the panel-wide identifier the client is
         * addressed by (named after the customer: "amir_1", "USER_7") and `subscription_url` the one link the customer
         * gets, as the panel served it. The purchase order points at its subscription (`orders.subscription_id`), as a
         * renewal order does. Counters, quota and term mirror the panel, as of `last_synced_at`; `status` is ours
         * (active, expired, disabled, deleted). A change made on the panel holds the row for its length (`lease_token`,
         * until `leased_until`), so two never interleave.
         */
        'subscriptions' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignId('server_id')->constrained('servers')->restrictOnDelete();
            $table->string('remote_name', 191);
            $table->string('subscription_url', 500);
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('traffic_limit_bytes')->default(0);
            $table->unsignedBigInteger('upload_bytes')->default(0);
            $table->unsignedBigInteger('download_bytes')->default(0);
            $table->unsignedInteger('ip_limit')->default(0);
            // The term: duration_days counts from the first connection (0 = never expires); starts_at / expires_at are
            // filled once the panel has started the clock, and stay null until then.
            $table->unsignedInteger('duration_days')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            // When support switched it off (status disabled): the screen says since when.
            $table->timestamp('disabled_at')->nullable();
            // The customer's «تمدید خودکار» switch (its default for new services is the admin's), and when they were
            // last told their wallet could not pay an automatic renewal — once per renewal window.
            $table->boolean('auto_renew')->default(false);
            $table->timestamp('renewal_notified_at')->nullable();
            // When the customer was reminded that the service ends soon / that its traffic runs low — once each while
            // it stays past the admin's threshold; cleared when it is back under it (renewed, more traffic).
            $table->timestamp('expiry_reminded_at')->nullable();
            $table->timestamp('traffic_reminded_at')->nullable();
            // A renewal bought before the paid period ended, with the shop set to reset what is left: that period ends
            // at period_ends_at, and from then on at most next_period_bytes remains (the renewed traffic).
            $table->timestamp('period_ends_at')->nullable();
            $table->unsignedBigInteger('next_period_bytes')->nullable();
            $table->string('lease_token', 32)->nullable();
            $table->timestamp('leased_until')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'remote_name']);
            // A customer's services by state, counted from the index with the shop's own filter (the users screen).
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
        },

        /*
         * Days and traffic the admin gives running services — an outage made good, a gift — with the `reason` the
         * customers read (when `notify`), and, ticked (`include_unstarted`), to those still waiting for their first
         * connection too. A grant is for the services there when it was issued (`upto_subscription_id`), on every
         * server, the agents' only, or one server's (`audience`), and has a part per server it reaches
         * (`grant_parts`): each is worked through on its own, a service at a time past its `last_subscription_id`
         * cursor, by one worker at a time (the one holding `lease_token`, until `leased_until`), so a panel out of reach
         * holds up its own server only (`waiting_reason`); `last_failure` names the latest service a panel refused. A
         * grant runs while one of its parts does — its status and its end are its parts'. One part runs on a server at a
         * time: `running_server_id` is the server while the part runs (computed), and unique.
         */
        'grants' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('days')->default(0);
            $table->unsignedBigInteger('traffic_bytes')->default(0);
            $table->string('reason', 500)->nullable();
            $table->boolean('notify')->default(true);
            $table->boolean('include_unstarted')->default(false);
            $table->string('audience', 8);
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
         * Money changing hands.
         *
         * `payment_methods` — the ways customers can pay, one row per instance of a gateway driver (several
         * card-to-card rows, one per card; later one per merchant account of an online gateway): `config` is the
         * driver's own settings, `sort` the order at checkout. The wallet is a built-in row every shop starts with
         * (below, under `rows`; an agent's shop gets its own when it opens, PaymentMethods::createBuiltins()).
         *
         * `orders` — what the customer asked for: a purchase (`plan_id` + the `server_id` they chose among the plan's
         * servers), a renewal (`subscription_id` — its server is the service's), a wallet top-up, or an agent's traffic
         * (`traffic_bytes`, bought from the main bot for the agent's own). `status` walks pending → paid → processing →
         * fulfilled (or failed), every step a compare-and-swap (Core\Database\Transitions) so a delivery never happens
         * twice; `notes` carries the reason a fulfilment failed or an order was cancelled, in words anyone of the shop may
         * read — a panel that failed it said in a word —, and `diagnosis` the owner's reading of that panel's failure
         * (its address, its answer: ProviderErrorPresenter::describe()), which only the owner is shown. When it was paid
         * is its paying payment's `paid_at`.
         *
         * `request_keys` — every request of the website that ordered, by its key (its Idempotency-Key, one a customer),
         * and the order it came to: the one it made, the open one of the same thing it found, or the one the key had made
         * before (OrderService::open()) — several keys may name one order —, and the way to pay it asked for
         * (`payment_method_id`). The same request made again gets that order back as it stands, never a second one —
         * nor the order paid another way: another way under the key is refused; a key is kept a week (ExpireOrdersTask).
         *
         * `payments` — one attempt to pay an order through a method: the customer is the order's, the driver the
         * method's (a method that has payments is disabled, never deleted). `reference` is the gateway's own id of it.
         * A card-to-card receipt sent in the bot is the Telegram file id (`receipt_file_id`, never copied to disk) and the
         * message the verdicts reply to (`receipt_message_id`); one uploaded from the website is a file of the shop's own
         * (`receipt_path`: its name in storage/uploads/receipts, Payments\Services\Receipts — gone with its order's
         * expiry). Either way its file name as the customer sent it (`receipt_name`), their words with it
         * (`receipt_note`), and when (`receipt_at`). `note` is the word on its latest verdict — the admin's reason
         * for a rejection, their note on a cancellation or a refund, or the gateway's refusal; `reviewer` is who
         * decided (the panel login, a bot admin's @username) — none on a receipt the review window accepted.
         *
         * `referral_commissions` — what a payment earned its customer's referrer: one row per payment at most (`payment_id`
         * unique), with whom it was credited to (`referrer_id` — the customer's `referred_by` when it was earned, kept: two
         * accounts merged since may name another), the rate then in force and the commission credited to the referrer's
         * wallet; `notified_at` is claimed by the one message that tells them.
         *
         * `traffic_transactions` — an agent's prepaid traffic, line by line (Agency\Services\TrafficPool): bought from
         * the main bot (`purchase`, its `order_id`), drawn by a sale or a renewal in their bot (`sale` / `renewal`, the
         * order behind it) or by the traffic the shop gave one of its services (`extension`, with the `reviewer`), given
         * back when that delivery or extension failed (`refund`), or set right by the shop (`adjust`, with the
         * `reviewer`). `bytes` is signed; `balance_after` is what the bot may sell from then on — the last line's, now.
         */
        'payment_methods' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->string('driver', 32);
            $table->string('label', 60);
            $table->json('config');
            $table->boolean('enabled')->default(true);
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->index(['enabled', 'sort']);
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
            $table->unsignedBigInteger('traffic_bytes')->nullable();
            $table->decimal('amount', 14, 2);
            $table->text('notes')->nullable();
            $table->text('diagnosis')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();

            // A customer's orders by status, counted from the index with the shop's own filter (the users screen, their page,
            // the unpaid ones a website's checkout may leave open).
            $table->index(['user_id', 'bot_id', 'status']);
            $table->index('subscription_id');
            // A plan's sales (the plans screen).
            $table->index(['plan_id', 'status']);
            // A shop's orders by status and type (the screen's tabs, its type filter and their counts, the dashboard's
            // attention card) and by day (the dates, the dashboard): with the amount, what a list sold is summed from
            // the index alone.
            $table->index(['bot_id', 'status', 'type', 'amount']);
            $table->index(['bot_id', 'created_at', 'status', 'amount']);
        },

        'request_keys' => static function (Blueprint $table): void {
            $table->foreignId('bot_id')->default(1)->constrained('bots')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('key', 64);
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            // The way to pay the request asked for — null only for a key kept before keys kept it (db:rebuild copies such
            // rows: a week's at most), whose order is answered whatever way is sent. A method is deleted only while
            // nothing was paid with it: a key that never came to a payment goes with it.
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
            $table->string('status', 16)->default('pending');
            $table->decimal('amount', 14, 2);
            $table->string('reference', 191)->nullable();
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

            // What a referrer earned, summed from the index alone with the shop's own filter (their numbers — the bot's, the
            // website's, their page's —, the referrers list; its own name: the columns' would pass MySQL's 64 characters).
            $table->index(['referrer_id', 'bot_id', 'commission'], 'referral_commissions_earned_index');
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
         * Support conversations (Support\Services\Tickets).
         *
         * `tickets` — a customer's question to support («تیکت»), from the shop's website or its bot: its `subject`, the
         * service it is about when there is one (`subscription_id`), and where it stands — `status` open (waiting on
         * support), answered (support wrote last) or closed (`closed_at`) —, when its latest message was written
         * (`last_message_at`, every list's order), whether support wrote since the customer last read it
         * (`customer_unread`), and, once closed, the customer's `rating` (1 to 5) with a `rating_note`.
         *
         * `ticket_messages` — its messages in order: who wrote it (`author`, and who of support — `reviewer`, a panel's
         * principal or a bot admin's @username / tg:<id>), the `body`, at most one picture — sent in Telegram
         * (`attachment_file_id`) or uploaded (`attachment_path`, its name in storage/uploads/tickets — a name of the
         * shop's own, «{bot}-{ticket}-{16 hex}.{ext}»; null again once its ticket has been closed 30 days and the file
         * went) — with its name (`attachment_name`, left when the file goes: the message had a picture), and where it was
         * written (`channel`: web, bot, panel, group).
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
         * Customers' reviews of the shop («نظرات», Reviews\Services\Reviews), written on its website and shown there once
         * support approved them: who wrote it (`user_id`, the customer signed in on the website; null for a guest — or one
         * whose account went), the `name` it is signed with, its `rating` (1 to 5), the writer's words (`body`), a line of
         * where they use the service from (`context`), one of the website's own avatars they picked (`avatar`, the key of
         * the site's own set — never an address), where it stands — `status` pending (waiting on support), approved
         * (shown) or rejected (not) — and who of support decided it last (`reviewer`), when (`decided_at`).
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
         * The bots' own tables, each row a bot's (`bot_id`).
         *
         * `telegram_updates` — the updates a bot took (Telegram's `update_id`), each recorded before it is served, so one
         * Telegram sends again — a webhook answered past its patience, a poller that stopped before confirming its
         * offset — is served once. Telegram holds an update a day at most: a row goes after two
         * (Update\ReceivedUpdates::KEEP_HOURS); a bot's newest is when it last heard from Telegram. `chat_id` is the chat
         * an update counts against — one its chat sent just now (not one Telegram held back): a chat faster than a person
         * is left unanswered while it keeps on (ReceivedUpdates::lately()).
         *
         * `telegram_sessions` — one row per chat with a bot — a customer's private chat, its id their Telegram id: the
         * conversation `state` (a handler's "waiting for…") and `data` (keys starting with `_` are the chat's own facts
         * and survive a menu tap; the rest is the step's scratch).
         *
         * `bot_channels` — channels or groups a customer must be a member of before the bot serves them, as the admin
         * added them by link. The bot has to be an admin there to see who is a member; `bot_is_admin` is what the last
         * check found. The customer is sent to the public `username`'s t.me link, or a private one's `invite_link` —
         * every row has one of the two.
         *
         * `broadcasts` — a message a bot admin (`user_id`) sends to customers from inside the bot (/broadcast): the bot
         * copies — or forwards (`mode`) — the admin's own message (`message_id`, in their private chat with the bot,
         * where the progress and the summary go too) to each customer of the `audience` (all, buyers, non-buyers,
         * inactive, a customer group, agents, one server's — `audience_id` names the group or the server), in batches
         * past the `last_user_id` cursor by one worker at a time (`lease_token` until `leased_until`), with the admin's
         * link `buttons` under a copy and each message pinned when `pin` says so (`broadcast_pins` keeps where, for taking
         * them off later). `status` sending → paused → sending … → done or cancelled; `progress_message_id` is the
         * admin's live progress message. A row of `kind` unpin takes the pins of its `source_id` off again — the message,
         * its mode and audience are its source's, it has none of its own. `content`/`excerpt` say what the message is,
         * for the panel; `reviewer` is the panel login behind an operation started there.
         *
         * `report_topics` — the topics the bot made in its admins' report group (a forum supergroup; which one is
         * `report_chats`', and its topics are forgotten when another is connected): one per subject (`topic`,
         * Reports\Topic), `thread_id` its message_thread_id. A null `thread_id` is a topic being made — or made again
         * after an admin deleted it — by whoever holds the row (`lease_token` until `leased_until`), so two processes
         * never make the same topic twice.
         *
         * `report_messages` — what the shop tells that group, queued: Telegram takes 20 messages a minute in a group, so
         * they go out paced, in order, and wait out a flood limit or an unreachable Telegram instead of being lost; what
         * support must act on — a receipt, a delivery that failed (`priority` 0, Reports\Topic::priority()) — before the
         * rest, and a customer's review (2) after all of it. A message the bot writes in the group itself, outside the
         * queue — a receipt's prompt for a reason — is
         * recorded as one sent (its `ref` what it asks about): a reply to the bot's messages is told by the message it
         * answers, never by their words. `copy_chat_id`/`copy_message_id` is a customer's message to copy with `text` as its caption (a receipt sent in
         * the bot), `photo_path` a picture of the shop's own to send with it as its caption (a receipt or a ticket's
         * picture uploaded from a website or a panel, as `<folder>/<name>` under storage/uploads — Users\Services\
         * CustomerPictures::reference()); `keyboard` the inline buttons it carries (a receipt's «تایید» / «رد», a ticket's
         * «بستن»); `ref` names what a report is about so a later one can reply to it (`reply_ref`: a receipt's verdict
         * under the receipt, a ticket's next message under its last one) and, with `clears_buttons`, take the buttons off
         * it — a receipt decided is not decided again —; `ticket_id` the ticket a report is about, which an admin's reply
         * to it in the group answers, and which keeps it while it is not closed (a week's of the rest are kept). A sender
         * holds a row while it sends it (`lease_token` until `leased_until`); `attempts` counts the senders that went quiet
         * holding it (a crash mid-send); once sent it records the group and `message_id`.
         *
         * `custom_emojis` — the premium emoji an admin showed the bot (/emoji), for the editors' picker: Telegram's
         * `emoji_id`, the plain `emoji` it stands for, `file_id`, a still picture of it for the panel, and how Telegram
         * draws it — `format` (static, animated = a Lottie animation, video = WebM; null until Telegram was asked), the
         * `animation_file_id` that moves, and `repaint` (Telegram paints it in the colour of the text around it).
         */
        'telegram_updates' => static function (Blueprint $table): void {
            $table->foreignId('bot_id')->constrained('bots')->restrictOnDelete();
            $table->unsignedBigInteger('update_id');
            $table->bigInteger('chat_id')->nullable();
            $table->timestamp('received_at')->useCurrent();

            $table->primary(['bot_id', 'update_id']);
            $table->index('received_at');
            // A chat's pace: its updates of the last seconds, counted off the index (its own name: the columns', after the
            // longest table prefix, would pass MySQL's 64 characters).
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
         * `report_chats` — each bot's report group as the shop knows it (Telegram\Reports\ReportGroupState), a row per
         * bot: the group connected (`chat_id`, `title`, `connected_at`), what keeps reports from it (`problem`, a
         * Reports\GroupProblem), until when sending waits (`paused_until`), the connect link's one-time `code`
         * (encrypted, until `code_expires_at`) and the last group offered with it that could not become the report
         * group (`attempt_*`). Runtime state, read afresh by whoever acts on it — not a setting.
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

            // A bot's queue (neither sent nor given up on) in the order it goes — by priority, then as it came: read off
            // the index, however long the queue —, and what it sent in the last minute.
            $table->index(['bot_id', 'sent_at', 'failed_at', 'priority'], 'report_messages_queue_index');
            // What is old enough to go: every report but a ticket's in two ranges under a null ticket (ReportSender::
            // prune(), every minute — the reports kept for a ticket still open never read again), a ticket's by its
            // ticket (pruneTickets(), hourly).
            $table->index(['bot_id', 'ticket_id', 'sent_at', 'failed_at'], 'report_messages_pruning_index');
            $table->index('ref');
            // The report an admin's reply in the group answers, by the message it replied to (a ticket's: Reports\TicketReplies).
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

    // What a new shop starts with — put into a table when it is made, never into one a rebuild carries rows into.
    'rows' => [
        // The main bot: the shop's own (its token is config.php's).
        'bots' => static fn(): array => [
            ['id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ],
        // The wallet: the payment method every shop has (an agent's shop gets its own, PaymentMethods::createBuiltins()).
        'payment_methods' => static fn(): array => [
            ['driver' => WalletGateway::key(), 'label' => WalletGateway::label(), 'config' => '[]', 'enabled' => true, 'sort' => 1, 'created_at' => now(), 'updated_at' => now()],
        ],
    ],
];
