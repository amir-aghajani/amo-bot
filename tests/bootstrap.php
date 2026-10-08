<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// The app's clock is UTC (Application sets it at boot); a test that runs before anything boots it reads the same
// clock, whatever php.ini says.
date_default_timezone_set('UTC');
