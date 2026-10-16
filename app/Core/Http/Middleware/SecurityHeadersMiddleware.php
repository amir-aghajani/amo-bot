<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Http\RequestOrigin;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Security headers on every answer PHP gives, whatever web server is in front: no sniffing, no framing, no indexing, a
 * sandboxing policy that lets nothing run (PHP answers JSON and files — its one page, an online payment's return page,
 * sets a policy of its own that runs no script either), no caching unless the answer set its own (a picture), and HSTS
 * over HTTPS.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /** Also sent by public/index.php's last-resort answer, which runs without the app. */
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
