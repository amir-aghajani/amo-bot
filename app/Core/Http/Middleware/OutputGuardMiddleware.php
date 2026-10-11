<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps whatever PHP prints during a request out of the answer — a warning on a host that forces display_errors on, a
 * stray echo — since it would break the JSON and might leak a server path. It is logged, cut short.
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
