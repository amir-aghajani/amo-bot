<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Modules\Telegram\Update\GroupHandler;
use App\Modules\Telegram\Update\Update;

/** A report group's button that always throws: how the dispatcher answers a broken group handler. */
final class GroupBoomHandler implements GroupHandler
{
    public function handle(Update $update): void
    {
        throw new \RuntimeException('group boom');
    }
}
