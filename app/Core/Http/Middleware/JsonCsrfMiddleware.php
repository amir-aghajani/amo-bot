<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Http\Json;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CSRF protection for the session-authenticated APIs: a request that changes something must carry X-Requested-With.
 * A browser cannot add that header cross-origin without a CORS preflight, which the shop never grants a panel's API.
 */
final class JsonCsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly ResponseFactoryInterface $responseFactory) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getMethod(), self::SAFE_METHODS, true)
            || strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest') {
            return $handler->handle($request);
        }

        return Json::error($this->responseFactory->createResponse(), 'درخواست نامعتبر است (هدر X-Requested-With وجود ندارد).', 403);
    }
}
