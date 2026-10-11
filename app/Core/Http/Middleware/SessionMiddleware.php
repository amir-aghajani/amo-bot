<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Http\RequestOrigin;
use App\Core\Session\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Opens the session for the panels' APIs. A read (GET, HEAD) releases it at once — it has read who is signed in — so a
 * page's reads run in parallel instead of queueing on the session file's lock; a write keeps it to the end. Nothing
 * else PHP answers opens a session.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    private const READS = ['GET', 'HEAD'];

    public function __construct(
        private readonly Session $session,
        private readonly RequestOrigin $origin,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->session->start($this->origin->isHttps($request));
        if (in_array($request->getMethod(), self::READS, true)) {
            $this->session->release();
        }

        try {
            return $handler->handle($request);
        } finally {
            $this->session->end();
        }
    }
}
