<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Update;

use App\Modules\Telegram\Handler;

/**
 * One registered route: its handler, whether reaching it is navigation (the flow in progress is left), and whether only
 * the bot's admins reach it.
 */
final readonly class Route
{
    /** @param class-string<Handler> $handler */
    public function __construct(
        public string $handler,
        public bool $navigation = false,
        public bool $adminOnly = false,
    ) {}
}
