<?php

declare(strict_types=1);

use App\Core\Config\ConfigValues;

return static fn(ConfigValues $settings): array => [
    'name' => $settings->string('APP_NAME'),
    'debug' => $settings->bool('APP_DEBUG'),
    // The address the shop is reached at, its sub-folder included (https://example.com/shop), without a trailing slash:
    // every address the shop hands out — the webhooks, the cron trigger, an agent's login link — is built on it alone.
    'url' => rtrim($settings->string('APP_URL'), '/'),
    // The prefix requests reach PHP under when the web server does not say it (it says it for public_html/shop, and
    // through the root .htaccess); empty = what the front controller's path says.
    'base_path' => $settings->string('APP_BASE_PATH'),
    // 32-byte key, "base64:..." — the web installer writes it.
    'key' => $settings->string('APP_KEY'),
    'timezone' => $settings->string('APP_TIMEZONE'),
    // Seconds an outgoing call may take — Telegram's, an OpenID provider's, the captcha's, Resend's —; a panel's own is
    // its server's setting.
    'http_timeout' => (float) $settings->int('OUTGOING_HTTP_TIMEOUT'),
    // The reverse proxies in front of PHP, whose X-Forwarded-For says who the browser is: addresses or CIDR ranges,
    // apart by commas (empty: REMOTE_ADDR is the browser, as the web server reports it).
    'trusted_proxies' => $settings->string('TRUSTED_PROXIES'),
];
