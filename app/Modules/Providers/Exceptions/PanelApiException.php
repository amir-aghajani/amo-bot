<?php

declare(strict_types=1);

namespace App\Modules\Providers\Exceptions;

/**
 * The panel understood the call and refused it, in its own words: `panelMessage` is the reason it
 * gave (a port in use, an unknown client, an invalid setting) and `httpStatus` the status it used.
 * Drivers subclass this with whatever else they know about the call.
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
}
