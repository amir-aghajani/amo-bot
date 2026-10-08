<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Exceptions\TooLargeException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A request's body as the shop reads it: JSON — what the panels, the installer and Telegram's webhooks send —, held to
 * MAX_BYTES before any of it is decoded. Nothing the shop takes comes near that, so a larger body — said by its
 * Content-Length, or found as it is read (one sent in chunks says none) — is refused with a 413 whoever sent it, before
 * it costs more than that: decoding it whole would hold many times its size in memory, signed in or not. The decoded
 * body is the request's parsed body: an object or a list; anything else — not JSON, a bare value — none. No other kind
 * of body is read: a file upload (multipart) is PHP's, to its own limits and its route's (a QR background's), and a form
 * PHP parsed itself stays as PHP left it. But a POST past PHP's own limit (php.ini's post_max_size) PHP takes nothing of
 * — no field, no file, no byte —, and it would reach its route as a request that sent nothing ("write the message"): one
 * that came so, its Content-Length past that limit, is the same 413.
 */
final class JsonBodyMiddleware implements MiddlewareInterface
{
    /** The largest body read: a bot text, a keyboard, a plan with all its servers or an update of Telegram's, many times over. */
    public const MAX_BYTES = 1_048_576;

    public const TOO_LARGE = 'درخواست بزرگ‌تر از حد مجاز است.';

    /** How much of a body is read at a time. */
    private const CHUNK = 65_536;

    public function __construct(private readonly StreamFactoryInterface $streams) {}

    /** @throws TooLargeException 413: a body over MAX_BYTES, or one PHP took nothing of — past its post_max_size */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
        $length = $request->getHeaderLine('Content-Length');
        $declared = ctype_digit($length) ? (int) $length : null;
        $form = $type === 'multipart/form-data' || $type === 'application/x-www-form-urlencoded';
        $nothingParsed = in_array($request->getParsedBody(), [null, []], true) && $request->getUploadedFiles() === [];
        if ($form && $nothingParsed && self::pastPhpsLimit($request, $declared)) {
            throw new TooLargeException(self::TOO_LARGE);
        }
        if ($type === 'multipart/form-data') {
            return $handler->handle($request);
        }
        if ($declared !== null && $declared > self::MAX_BYTES) {
            throw new TooLargeException(self::TOO_LARGE);
        }
        if ($type !== 'application/json' && !str_ends_with($type, '+json')) {
            return $handler->handle($request);
        }

        $json = self::read($request);
        if ($json === '' && self::pastPhpsLimit($request, $declared)) {
            throw new TooLargeException(self::TOO_LARGE);
        }
        $parsed = json_decode($json, true);

        return $handler->handle($request->withBody($this->streams->createStream($json))->withParsedBody(is_array($parsed) ? $parsed : null));
    }

    /**
     * Whether PHP took nothing of the request's body for its size: a POST whose Content-Length is past post_max_size,
     * which PHP empties before the app sees it (only that header says what was sent). None past a limit of none (0).
     */
    private static function pastPhpsLimit(ServerRequestInterface $request, ?int $declared): bool
    {
        $limit = ini_get('post_max_size');
        $bytes = is_string($limit) && $limit !== '' ? ini_parse_quantity($limit) : 0;

        return $request->getMethod() === 'POST' && $bytes > 0 && $declared !== null && $declared > $bytes;
    }

    /**
     * The body as sent, read no further than one byte past MAX_BYTES.
     *
     * @throws TooLargeException
     */
    private static function read(ServerRequestInterface $request): string
    {
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $json = '';
        while (!$body->eof() && strlen($json) <= self::MAX_BYTES) {
            $chunk = $body->read(self::CHUNK);
            if ($chunk === '') {
                break;
            }
            $json .= $chunk;
        }
        if (strlen($json) > self::MAX_BYTES) {
            throw new TooLargeException(self::TOO_LARGE);
        }

        return $json;
    }
}
