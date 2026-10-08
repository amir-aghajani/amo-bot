<?php

declare(strict_types=1);

namespace App\Core\Logging;

use App\Core\Support\Files;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * The app's log: a file a day under storage/logs (RotatingFileHandler), its folder and each file made as the app's own
 * files are (Core\Support\Files: its owner's alone, or the account's to read where PHP runs as another user) — and, when
 * that file cannot be written (the folder not the web server's to write, a full disk), PHP's own error log takes the line
 * instead: a log that cannot be kept never takes the request, the update or the run it tells about down with it.
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
