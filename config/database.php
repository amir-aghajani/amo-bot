<?php

declare(strict_types=1);

use App\Core\Config\ConfigValues;

/*
 * The database the shop runs on. DB_CONNECTION names its driver (App\Core\Database\Drivers: mysql, sqlite), and the
 * driver makes the connection from its own DB_* settings, with the defaults its fields declare.
 */
return static fn(ConfigValues $settings): array => [
    'driver' => $settings->string('DB_CONNECTION'),
    // Every DB_* setting of config.php as written, as text (a password may well read "null" or "true").
    'connection' => $settings->prefixed('DB_'),
];
