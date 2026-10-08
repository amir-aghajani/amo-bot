<?php

declare(strict_types=1);

namespace App\Core\Logging;

use App\Core\Http\RequestId;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Every line a request writes says which request it was (`request_id` among the line's extras, RequestId): the id the
 * panel shows on a failure finds them all — a failure's line, and what led up to it.
 */
final class RequestIdProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $id = RequestId::current();

        return $id === null ? $record : $record->with(extra: ['request_id' => $id] + $record->extra);
    }
}
