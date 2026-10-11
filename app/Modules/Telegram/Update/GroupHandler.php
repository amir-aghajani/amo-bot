<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Update;

/** Handles an update from a group (the report group): no user, session or gates — the handler checks who pressed. */
interface GroupHandler
{
    public function handle(Update $update): void;
}
