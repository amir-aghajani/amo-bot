<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;

/** A handler that answers its tap with a toast, then throws: how the dispatcher tells of a failure the button cannot. */
final class AnsweredBoomHandler implements Handler
{
    public function handle(Context $ctx): void
    {
        $ctx->answer('working on it');

        throw new \RuntimeException('boom after the answer');
    }
}
