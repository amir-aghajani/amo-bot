<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * The id of the request being answered: made by RequestIdMiddleware, sent in `X-Request-Id` and every error body, and
 * written on every log line (RequestIdProcessor). The panels show it on a server failure («کد پیگیری») so the owner can
 * find the log lines. It lasts to the end of the process, so shutdown lines carry it too; the CLI has none.
 */
final class RequestId
{
    public const HEADER = 'X-Request-Id';

    private static ?string $current = null;

    /** The id of the request being answered; null outside one. */
    public static function current(): ?string
    {
        return self::$current;
    }

    /** A new id: 16 hex characters (64 random bits). */
    public static function generate(): string
    {
        return bin2hex(random_bytes(8));
    }

    /** Start answering the request `$id`. */
    public static function begin(string $id): void
    {
        self::$current = $id;
    }

    /** No request is being answered — between two tests. */
    public static function reset(): void
    {
        self::$current = null;
    }
}
