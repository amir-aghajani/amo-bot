<?php

declare(strict_types=1);

namespace App\Modules\Providers\Exceptions;

use App\Modules\Providers\Enums\ConnectionFailure;

/**
 * The panel could not be reached at all: `failure` says why (its name, the port, a timeout, its TLS certificate), so
 * the admin is told what to fix; `detail` is what the transport said (cURL's words: the host and the port). The
 * transport's own exception is not kept with it: its message names the request's whole address, whose path may be the
 * panel's secret, and a logged exception prints the ones it was chained to — what the diagnosis needs is `failure` and
 * `detail`, scrubbed of any address.
 */
final class ConnectionException extends ProviderException
{
    public function __construct(
        public readonly ConnectionFailure $failure,
        public readonly string $detail,
    ) {
        parent::__construct("The panel could not be reached ({$failure->value}): {$detail}");
    }

    /** A Guzzle/cURL transport failure, classified — and let go of. */
    public static function fromTransport(\Throwable $e): self
    {
        return new self(ConnectionFailure::of($e->getMessage()), self::tidy($e->getMessage()));
    }

    public function unavailable(): bool
    {
        return true;
    }

    /**
     * Guzzle's message without the link to cURL's manual and without the request's address — the one it ends with
     * ("… for https://host/path"), or any other —: cURL names the host and the port itself, never the path.
     */
    private static function tidy(string $message): string
    {
        $message = preg_replace('~\s*\(see https?://curl\.[^)]*\)~', '', $message) ?? $message;
        $message = preg_replace('~\s+for https?://\S+$~', '', $message) ?? $message;
        $message = preg_replace('~https?://[^\s()<>"\']+~', '[address]', $message) ?? $message;

        return trim($message);
    }
}
