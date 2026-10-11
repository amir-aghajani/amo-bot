<?php

declare(strict_types=1);

namespace App\Modules\Providers\Exceptions;

/**
 * The panel understood the call and refused it: `panelMessage` is the reason it gave (a port in use, an unknown client,
 * an invalid setting) and `httpStatus` the status it used. Each connector has its own subclass, so it can tell its own
 * refusals apart.
 */
class PanelApiException extends ProviderException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly string $panelMessage,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    /** The log's message for a refused call: "<panel> <method> <path> failed: <reason>". */
    protected static function callFailed(string $panel, string $method, string $path, int $httpStatus, string $panelMessage): string
    {
        $reason = $panelMessage !== '' ? $panelMessage : "HTTP {$httpStatus}";

        return "{$panel} {$method} {$path} failed: {$reason}";
    }
}
