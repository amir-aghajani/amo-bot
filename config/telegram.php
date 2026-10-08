<?php

declare(strict_types=1);

use App\Core\Config\ConfigValues;

return static fn(ConfigValues $settings): array => [
    // Bot token from @BotFather. Edited from the admin settings screen (written to config.php).
    'token' => $settings->string('TELEGRAM_BOT_TOKEN'),
    // The bot's @username; bot:poll / bot:webhook:set fill it in from getMe().
    'username' => $settings->string('TELEGRAM_BOT_USERNAME'),
    // Random string that becomes part of the webhook URL and the X-Telegram-Bot-Api-Secret-Token header.
    'webhook_secret' => $settings->string('TELEGRAM_WEBHOOK_SECRET'),
    // Override when routing through a Bot API mirror / local Bot API server.
    'api_url' => $settings->string('TELEGRAM_API_URL'),
    // Update types the bot subscribes to.
    'allowed_updates' => ['message', 'callback_query', 'my_chat_member'],
    // Long-polling timeout in seconds (bot:poll).
    'poll_timeout' => $settings->int('TELEGRAM_POLL_TIMEOUT'),
];
