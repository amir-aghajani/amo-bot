<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Http\Json;
use App\Modules\Bots\CurrentBot;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Guards one panel's API (`panel.admin` for /api/admin, `panel.agent` for /api/agent): a 401 without a signed-in
 * principal — asked before any shop is looked at — else the request runs in the principal's shop (CurrentBot), read by
 * them (CurrentPrincipal), with the principal on the request (Principal::of()).
 */
final class PanelAuthMiddleware implements MiddlewareInterface
{
    public const SIGNED_OUT = 'برای دسترسی باید وارد شوید.';

    public function __construct(
        private readonly PanelAuth $auth,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $principal = $this->auth->principal($request);
        if ($principal === null) {
            return Json::error($this->responseFactory->createResponse(), self::SIGNED_OUT, 401);
        }

        return CurrentBot::run($principal->shop, static fn(): ResponseInterface => CurrentPrincipal::run(
            $principal,
            static fn(): ResponseInterface => $handler->handle($request->withAttribute(Principal::ATTRIBUTE, $principal)),
        ));
    }
}
