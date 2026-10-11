<?php

declare(strict_types=1);

namespace App\Core\Logging;

use App\Core\Http\RequestId;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/** Adds `request_id` (RequestId) to every line a request writes, so the id a panel shows on a failure finds them. */
final class RequestIdProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $id = RequestId::current();

        return $id === null ? $record : $record->with(extra: ['request_id' => $id] + $record->extra);
    }
}
