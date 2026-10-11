<?php

declare(strict_types=1);

namespace App\Core\Logging;

use Monolog\Formatter\LineFormatter;
use Monolog\LogRecord;

/**
 * Monolog's line formatter with every secret redacted (Redact). A record stays one entry: its continuation lines (a
 * trace, a customer's multi-line text) are indented so they can never pass for a record of their own, and control codes
 * a viewer acts on (terminal escapes, other line separators, bidi overrides) are written as `?`.
 */
final class RedactingLineFormatter extends LineFormatter
{
    /** How a record's continuation lines begin; a record begins with its time in brackets. */
    public const CONTINUATION = '    ';

    /**
     * C0 controls but the tab and the line breaks, DEL, and — as UTF-8 — the line and paragraph separators and the bidi
     * embeddings, overrides and isolates.
     */
    private const CONTROLS = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\xE2\x80[\xA8-\xAE]|\xE2\x81[\xA6-\xA9]/';

    public function format(LogRecord $record): string
    {
        $line = (string) preg_replace(self::CONTROLS, '?', Redact::text(parent::format($record)));

        return (string) preg_replace('/\r\n?|\n/', "\n" . self::CONTINUATION, rtrim($line, "\r\n")) . "\n";
    }
}
