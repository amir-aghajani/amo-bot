<?php

declare(strict_types=1);

namespace App\Modules\Providers\Exceptions;

/**
 * The panel understood the call and refused it: `panelMessage` is the reason it gave (a port in use, an unknown client,
 * an invalid setting) and `httpStatus` the status it used; `hint`, when the driver has one, is its remedy in Persian (a
 * setting on that panel), appended to the generic explanation the owner reads. Each connector has its own subclass, so
 * it can tell its own refusals apart.
 */
class PanelApiException extends ProviderException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly string $panelMessage,
        public readonly string $hint = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    /**
     * Whether the panel refused to show a client because another admin holds it — on a panel where the shop's admin sees
     * only its own users —, so its name is taken all the same (ProvisioningService::taken()). Any 403 by default; a
     * connector whose panel also says 403 for a permission its admin lacks tells its own words apart.
     */
    public function hidesAnothersClient(): bool
    {
        return $this->httpStatus === 403;
    }

    /** The log's message for a refused call: "<panel> <method> <path> failed: <reason>". */
    protected static function callFailed(string $panel, string $method, string $path, int $httpStatus, string $panelMessage): string
    {
        $reason = $panelMessage !== '' ? $panelMessage : "HTTP {$httpStatus}";

        return "{$panel} {$method} {$path} failed: {$reason}";
    }
}
