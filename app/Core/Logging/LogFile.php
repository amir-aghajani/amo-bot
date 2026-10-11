<?php

declare(strict_types=1);

namespace App\Core\Logging;

use App\Core\Support\Files;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * The app's log: a file a day under storage/logs, with the app's file modes (Files). When the file cannot be written
 * (no permission, a full disk) the line goes to PHP's own error log: a failing log never fails the request.
 */
final class LogFile extends RotatingFileHandler
{
    /** The log at `$file`, `$maxFiles` days of it kept (0: every one), from `$level` up. */
    public static function at(string $file, int $maxFiles, Level $level): self
    {
        Files::writableDirectory(dirname($file));

        return new self($file, $maxFiles, $level, filePermission: Files::fileMode());
    }

    protected function write(LogRecord $record): void
    {
        try {
            parent::write($record);
        } catch (\UnexpectedValueException) {
            error_log(rtrim((string) $record->formatted, "\n"));
        }
    }
}
