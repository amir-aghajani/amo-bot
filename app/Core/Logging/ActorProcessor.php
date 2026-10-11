<?php

declare(strict_types=1);

namespace App\Core\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/** Adds `actor` (Acting) to every line a request writes for a signed-in principal, so their actions can be found. */
final class ActorProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $actor = Acting::current();

        return $actor === null ? $record : $record->with(extra: ['actor' => $actor] + $record->extra);
    }
}
