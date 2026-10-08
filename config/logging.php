<?php

declare(strict_types=1);

use App\Core\Config\ConfigValues;

return static fn(ConfigValues $settings): array => [
    // debug | info | notice | warning | error | critical
    'level' => $settings->string('LOG_LEVEL'),
    // Relative to storage/logs (the test suite writes its own file so dev logs stay readable).
    'file' => $settings->string('LOG_FILE'),
    // How many daily log files to keep.
    'max_files' => $settings->int('LOG_MAX_FILES'),
];
