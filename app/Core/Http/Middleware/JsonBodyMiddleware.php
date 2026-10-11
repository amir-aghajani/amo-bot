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
 * Reads a JSON body, no further than MAX_BYTES: a larger one (by its Content-Length, or as it is read — a chunked body
 * declares none) is a 413 before it is decoded, signed in or not. The parsed body is an object or a list, else null.
 * Other bodies are not read (a multipart upload is PHP's). A POST past php.ini's post_max_size arrives emptied by PHP
 * and would look like a request that sent nothing, so it is the same 413.
 */
final class JsonBodyMiddleware implements MiddlewareInterface
{
    /** The largest body read: far more than anything the shop takes (a keyboard, a plan with its servers, an update). */
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

    /** Whether PHP emptied the body for its size: a POST whose Content-Length is past post_max_size (0: no limit). */
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
