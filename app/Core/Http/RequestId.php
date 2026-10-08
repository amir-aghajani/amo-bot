<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * The id of the request this process is answering: made afresh for each one (RequestIdMiddleware), said in the answer's
 * `X-Request-Id` header and in every error answer (`request_id`, Json::error()), and written on every log line the
 * request leaves (RequestIdProcessor) — the panel shows it on a failure of the server's own («کد پیگیری»), and the owner
 * finds the request in the log by it. It holds for the rest of the request, past the app's answer: a line written at
 * shutdown, or the front controller's last answer when something outside the app's error handling threw, carries it too.
 * The command line answers no request, so its lines carry none.
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

    /** A new id: 16 hex characters — 64 random bits, enough to tell any request of a log from the others. */
    public static function generate(): string
    {
        return bin2hex(random_bytes(8));
    }

    /** The request `$id` is answered from now on. */
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
