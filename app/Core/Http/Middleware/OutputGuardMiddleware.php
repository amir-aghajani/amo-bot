<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * What PHP prints while a request is handled is never part of the answer: a warning a host prints although the app
 * switched display_errors off (php_admin_flag keeps it on), a library's stray echo. It would come out ahead of the JSON —
 * no longer JSON, and perhaps a path of the server's in it — so it is caught, left out and logged, cut short: the log is
 * for finding where it came from, not a copy of it.
 */
final class OutputGuardMiddleware implements MiddlewareInterface
{
    /** How much of the stray output the log line keeps. */
    private const KEPT = 500;

    public function __construct(private readonly LoggerInterface $logger) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $level = ob_get_level();
        ob_start();
        try {
            return $handler->handle($request);
        } finally {
            // A buffer the request left open is its own too: everything above the level it started at goes.
            $stray = '';
            while (ob_get_level() > $level) {
                $stray = (string) ob_get_clean() . $stray;
            }
            if ($stray !== '') {
                $this->logger->warning('{method} {path} printed output outside its answer; it was left out', [
                    'method' => $request->getMethod(),
                    'path' => $request->getUri()->getPath(),
                    'output' => mb_substr($stray, 0, self::KEPT),
                ]);
            }
        }
    }
}
