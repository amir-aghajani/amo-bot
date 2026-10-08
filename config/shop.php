<?php

declare(strict_types=1);

use App\Core\Config\ConfigValues;

return static fn(ConfigValues $settings): array => [
    // Secret for the /cron/{token} web trigger (hosts without a real cron).
    'cron_token' => $settings->string('CRON_TOKEN'),
];
