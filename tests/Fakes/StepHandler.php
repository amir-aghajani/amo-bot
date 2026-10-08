<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;

/** A step of a flow that answers whatever reaches it with SAYS: how a test sees the step, not the menu, took a message. */
final class StepHandler implements Handler
{
    public const SAYS = 'the step took it';

    public function handle(Context $ctx): void
    {
        $ctx->reply(self::SAYS);
    }
}
