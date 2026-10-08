<?php

declare(strict_types=1);

namespace App\Modules\Providers\Exceptions;

/**
 * Anything that goes wrong talking to a panel. Its message is for the log: it may quote the panel and its host, never
 * the panel's address (whose path can be a secret) nor a credential. What a person reads is ProviderErrorPresenter's.
 */
class ProviderException extends \RuntimeException
{
    /**
     * Whether the panel could not be worked with at all — out of reach, refusing the shop's credentials, not answering
     * as its API — rather than answering about the one thing asked. Such a failure is the server's: it is recorded on
     * it, and the shop leaves the panel alone a while (Server::isBackingOff()).
     */
    public function unavailable(): bool
    {
        return false;
    }
}
