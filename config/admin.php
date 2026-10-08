<?php

declare(strict_types=1);

use App\Core\Config\ConfigValues;

/*
 * The panel's one login (Auth\Services\AdminAccount): the owner's username and their password's bcrypt hash, kept in
 * config.php by the web installer, the owner's own change from the panel and its recovery.
 */
return static fn(ConfigValues $settings): array => [
    'username' => $settings->string('ADMIN_USERNAME'),
    'password' => $settings->string('ADMIN_PASSWORD_HASH'),
];
