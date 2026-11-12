<?php

declare(strict_types=1);

namespace App\Modules\Store\Http;

use App\Core\Http\ErrorHandler;
use App\Core\Http\Json;
use App\Modules\Store\Models\MiniApp;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * What only the mini app has — signing in by Telegram's launch data, its logo, the secret it keeps behind the device's
 * fingerprint or face: through a website's key these addresses are the router's own 404, as if they were not there.
 */
final class MiniAppOnlyMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ResponseFactoryInterface $responses) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!StoreMiddleware::door($request) instanceof MiniApp) {
            return Json::error($this->responses->createResponse(), ErrorHandler::MESSAGES[404], 404);
        }

        return $handler->handle($request);
    }
}
