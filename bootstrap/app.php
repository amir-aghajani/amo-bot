<?php

declare(strict_types=1);

use App\Core\Application;

require dirname(__DIR__) . '/vendor/autoload.php';

return Application::boot(dirname(__DIR__));
