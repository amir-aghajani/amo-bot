<?php

declare(strict_types=1);

namespace App\Core\Logging;

use Monolog\Formatter\LineFormatter;
use Monolog\LogRecord;

/**
 * Monolog's line, secrets taken out (Redact) — the last word on every log line, whatever wrote it: a message, its
 * context, an exception's message and every previous exception's and trace the formatter prints.
 *
 * One record is one entry, whatever it holds: the lines after its first — a trace, a value with line breaks in it,
 * a customer's text — are indented under it, so nothing written into a record can begin a line that reads as a record of
 * its own; and no control code a viewer acts on (a terminal's escape sequence, a line break of another kind, a bidi
 * override that would reorder what is shown) is written as it is, but as a `?`.
 */
final class RedactingLineFormatter extends LineFormatter
{
    /** How the lines after a record's first begin: never like a record, which begins with its time in brackets. */
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
