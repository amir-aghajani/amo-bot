<?php

declare(strict_types=1);

namespace App\Core\Config;

/**
 * Every setting config.php may hold — the shop's own configuration, as WordPress keeps its own in wp-config.php —, by
 * section: each with its default (what the shop runs with while the file does not set it; its type is the setting's)
 * and the words the file says about it. config/*.php read them through ConfigValues; ConfigFile writes the file in this
 * order. A driver's own settings are its form's (Core\Drivers), not listed here — a database driver's DB_* follow
 * DB_CONNECTION in the file, a mail driver's MAIL_* end the mail's section.
 */
final class ConfigKeys
{
    /**
     * Section => setting => [its default, what the file says about it (a line break where its comment breaks)].
     *
     * @var array<string, array<string, array{0: string|int|bool, 1: string}>>
     */
    public const SECTIONS = [
        'The shop' => [
            'APP_NAME' => ['AmoBot', 'The shop\'s name, as the panels and the bot say it.'],
            'APP_URL' => ['http://localhost', "The address the shop is reached at, its sub-folder included (https://example.com/shop), with no slash at the end.\nThe webhooks, the cron address and the agents' login links are built on it; Telegram calls a webhook only\nover https."],
            'APP_TIMEZONE' => ['UTC', "The time zone the shop's dates and times are shown in (Asia/Tehran, Europe/Berlin…); what it keeps is UTC\nwhatever this says."],
            'APP_DEBUG' => [false, "A failure's details in the server's answer, for a browser on this very machine only — never anyone else's. Leave\nit off on a live shop."],
        ],
        'Database' => [
            'DB_CONNECTION' => ['mysql', "The database's driver — mysql: MySQL 5.7.8+ or MariaDB 10.3+ —, and under it the DB_* settings it connects with.\nThe installer and the panel's settings write them, once the database answers to them."],
        ],
        'The panel\'s login' => [
            'ADMIN_USERNAME' => ['', 'The owner\'s username for the panel.'],
            'ADMIN_PASSWORD_HASH' => ['', "The password's bcrypt hash — never the password itself. A forgotten password is set again from the sign-in page\n(«رمز را فراموش کرده‌اید؟»)."],
        ],
        'Telegram' => [
            'TELEGRAM_BOT_TOKEN' => ['', 'The main bot\'s token, from @BotFather.'],
            'TELEGRAM_BOT_USERNAME' => ['', 'The main bot\'s @username without the @, filled in from Telegram.'],
            'TELEGRAM_WEBHOOK_SECRET' => ['', 'The secret part of the main bot\'s webhook address, made when its webhook is first registered.'],
            'TELEGRAM_API_URL' => ['https://api.telegram.org', "The Bot API's address: another only for a mirror, or a Bot API server of your own. The bots' tokens go there:\nthe panel's settings ask for the main bot's token again when it moves."],
            'TELEGRAM_POLL_TIMEOUT' => [30, 'Seconds a long poll waits for updates (bot:poll, on a developer\'s machine: a shop on a host runs on webhooks).'],
        ],
        'Mail' => [
            'MAIL_TRANSPORT' => ['none', "How the shop sends email — a website's sign-up codes, password resets and notices: none, or a mail driver —\nsmtp, an account on a mail server (one of the host's Email Accounts in cPanel, or any other); native, the host's\nown mail, as PHP's mail() sends it (php.ini's sendmail_path); or resend, Resend's API (resend.com, the sender's\ndomain verified there). A driver's own MAIL_* settings end this section, as the panel's settings write them."],
            'MAIL_FROM_ADDRESS' => ['', "The address the shop's emails come from (no-reply@example.com) — a mail server sends from its own accounts\nonly. Empty: no email goes out."],
            'MAIL_FROM_NAME' => ['', 'The name they come from; empty: the shop\'s name (an agent\'s shop: its bot\'s).'],
        ],
        'Keys and secrets' => [
            'APP_KEY' => ['', "The key the shop encrypts its secrets with (the panels' passwords, the agents' bots' tokens). Never change it:\nwhat it encrypted could not be read again. Keep a copy of this file with your backups."],
            'CRON_TOKEN' => ['', 'The secret of the /cron/<token> address, for a host whose cron calls an address instead of running a command.'],
            'TRUSTED_PROXIES' => ['', "The reverse proxies in front of PHP (Cloudflare's ranges, a load balancer), addresses or CIDR ranges apart by\ncommas: their X-Forwarded-For says who the browser is. Empty: the web server's REMOTE_ADDR is the browser."],
        ],
        'Advanced' => [
            'APP_BASE_PATH' => ['', 'The folder requests reach PHP under (/shop), only when the web server does not say it; empty: detected.'],
            'OUTGOING_HTTP_TIMEOUT' => [30, 'Seconds an outgoing call may take — Telegram\'s; a panel\'s own is its server\'s setting.'],
            'LOG_LEVEL' => ['info', 'What the log in storage/logs keeps: debug, info, notice, warning or error.'],
            'LOG_FILE' => ['app.log', 'The log\'s name in storage/logs (a file a day).'],
            'LOG_MAX_FILES' => [14, 'How many days of the log are kept.'],
            'SESSION_NAME' => ['amobot_session', 'The panels\' session cookie; another name when several shops share a domain.'],
            'SESSION_LIFETIME' => [120, 'Minutes a panel\'s session lasts while it is not used.'],
            'SESSION_DOMAIN' => ['', 'The cookie\'s domain; empty: the shop\'s own host.'],
            'SESSION_SECURE_COOKIE' => [false, "Over https the cookie is always secure; true forces it behind a proxy that hides the https (Cloudflare's\nFlexible SSL)."],
            'SESSION_SAVE_PATH' => ['', 'The folder the sessions\' files go to; empty: storage/sessions.'],
        ],
    ];

    /** The section a setting it does not list goes in, by how its name starts (a driver's DB_* settings). */
    private const PREFIXES = [
        'APP_' => 'The shop',
        'DB_' => 'Database',
        'ADMIN_' => 'The panel\'s login',
        'TELEGRAM_' => 'Telegram',
        'MAIL_' => 'Mail',
        'LOG_' => 'Advanced',
        'SESSION_' => 'Advanced',
    ];

    /**
     * What the shop runs with while config.php does not set `$key`.
     *
     * @throws \LogicException for a setting nobody declared: a typo in the code that reads it
     */
    public static function default(string $key): string|int|bool
    {
        foreach (self::SECTIONS as $settings) {
            if (isset($settings[$key])) {
                return $settings[$key][0];
            }
        }

        throw new \LogicException("config.php has no setting {$key}: declare it in ConfigKeys::SECTIONS.");
    }

    /** The section `$key` goes in; null for one of no section's (written under «Other settings»). */
    public static function sectionOf(string $key): ?string
    {
        foreach (self::SECTIONS as $section => $settings) {
            if (isset($settings[$key])) {
                return $section;
            }
        }
        foreach (self::PREFIXES as $prefix => $section) {
            if (str_starts_with($key, $prefix)) {
                return $section;
            }
        }

        return null;
    }
}
