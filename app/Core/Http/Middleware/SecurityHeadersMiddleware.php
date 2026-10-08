<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Http\RequestOrigin;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The headers every answer PHP gives carries, whatever serves it (Apache's .htaccess sets some of them, but nginx and
 * `php -S` read no .htaccess): no type sniffing, never framed, no referrer to other sites, not indexed. PHP answers only
 * JSON and the bytes the panels show (a receipt, a picture) — the panels' pages are static files the web server hands
 * out itself — so every answer also gets a policy that lets nothing run: opened on its own in a tab, whatever it holds
 * cannot script the panels' origin. What PHP answers is the shop's private data, never kept by a browser or proxy cache
 * (an answer that says how it may be cached — a picture — keeps its own word). Over HTTPS, HSTS.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /** Every answer's — the front controller's last resort, which answers without the app, sends them too. */
    public const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'same-origin',
        'X-Robots-Tag' => 'noindex, nofollow',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; sandbox",
        'Cache-Control' => 'no-store',
    ];

    public function __construct(private readonly RequestOrigin $origin) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        foreach (self::HEADERS as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        // The browser keeps to HTTPS on this host from now on (not its subdomains — those are the owner's other sites).
        if ($this->origin->isHttps($request) && !$response->hasHeader('Strict-Transport-Security')) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
