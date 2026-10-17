<?php

declare(strict_types=1);

namespace App\Modules\Providers\Exceptions;

/**
 * Anything that goes wrong talking to a panel. Its message is for the log: it may quote the panel and its host, never
 * the panel's address (whose path can be a secret) nor a credential. What a person reads is ProviderErrorPresenter's.
 */
class ProviderException extends \RuntimeException
{
    /** Set by afterWrite(). */
    private bool $afterWrite = false;

    /**
     * This failure came once the panel had taken the write it followed — the read of what the write left failed —: what
     * a connector throws then, so a caller asks the panel how it stands (mayHaveBeenDone()) rather than taking the write
     * for one not made, and leaving it unrecorded or making it again.
     */
    public function afterWrite(): static
    {
        $this->afterWrite = true;

        return $this;
    }

    /**
     * Whether the panel could not be worked with at all — out of reach, refusing the shop's credentials, not answering as
     * its API — rather than answering about the one thing asked. Such a failure is recorded on the server, which then
     * backs off a while (Server::isBackingOff()).
     */
    public function unavailable(): bool
    {
        return false;
    }

    /**
     * Whether a write that failed so may have been done on the panel all the same, no answer having come: the connection
     * timed out or was cut once the request was on its way (ConnectionException), or something in front of the panel
     * answered for it with an HTTP 5xx page (UnexpectedResponseException) — a proxy that gave up waiting, the panel maybe
     * still at it —; or any failure of the read after a write the panel took (afterWrite()). Otherwise never a refusal in
     * the panel's own words, nor a connection never made: such a write did not happen. A caller asks the panel before
     * doing it again, or counts it as not to be done twice.
     */
    public function mayHaveBeenDone(): bool
    {
        return $this->afterWrite;
    }
}
