<?php

declare(strict_types=1);

namespace App\Modules\Store\Http;

use App\Core\Http\ErrorHandler;
use App\Core\Http\Json;
use App\Modules\Store\Models\Website;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * What only a website has — its ways in, its captcha, its admins' API: through the mini app's key these addresses are
 * the router's own 404, as if they were not there.
 */
final class WebsiteOnlyMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ResponseFactoryInterface $responses) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!StoreMiddleware::door($request) instanceof Website) {
            return Json::error($this->responses->createResponse(), ErrorHandler::MESSAGES[404], 404);
        }

        return $handler->handle($request);
    }
}
