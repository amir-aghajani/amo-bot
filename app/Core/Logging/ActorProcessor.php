<?php

declare(strict_types=1);

namespace App\Core\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Every line written for a principal says who it was (`actor` among the line's extras, Acting): what the owner, an
 * agent or one of a shop's admins on its website did — and what failed while they did it — is found by who did it, as
 * a request's lines are by its id (RequestIdProcessor).
 */
final class ActorProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $actor = Acting::current();

        return $actor === null ? $record : $record->with(extra: ['actor' => $actor] + $record->extra);
    }
}
