<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;

/** A handler that always throws: how the dispatcher answers a broken screen. */
final class BoomHandler implements Handler
{
    public function handle(Context $ctx): void
    {
        throw new \RuntimeException('boom');
    }
}
