<?php

declare(strict_types=1);

use App\Core\Config\ConfigValues;

return static fn(ConfigValues $settings): array => [
    'name' => $settings->string('SESSION_NAME'),
    // Minutes of inactivity before the session cookie expires.
    'lifetime' => $settings->int('SESSION_LIFETIME'),
    // Where session files go; empty = storage/sessions.
    'save_path' => $settings->string('SESSION_SAVE_PATH'),
    'cookie' => [
        'path' => '/',
        'domain' => $settings->string('SESSION_DOMAIN'),
        'secure' => $settings->bool('SESSION_SECURE_COOKIE'),
        'httponly' => true,
        'samesite' => 'Lax',
    ],
];
