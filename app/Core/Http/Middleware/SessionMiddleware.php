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
 * Starts the session for the panels' API (routes/api.php puts it on /api/admin and /api/agent), and lets go of it when
 * the request is over (Session::end()) — a read (GET, HEAD) at once: it changes nothing of the session, which has all
 * it needs from then on (who is signed in — the shop is each request's own, never the session's), so a page's reads
 * run alongside each other instead of one after the other on the session's lock. Signing in or out, changing the
 * login, ending an agent's other sessions — the writes — keep it to the end. Everything else PHP answers — the
 * webhooks, the cron address, the health check, the installer — is stateless and never opens one.
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
