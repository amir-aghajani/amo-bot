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
 * Guards one panel's API — the owner's (/api/admin, the container's `panel.admin`) or the agents' (/api/agent,
 * `panel.agent`): a request without a principal signed in to that panel is a 401 JSON; one with is worked in the shop
 * it is the principal's in (the owner's: the shop the request names; an agent's: their bot's — CurrentBot), read by them
 * (CurrentPrincipal), and carries them (Principal::of()), whose name a decision is recorded under. Who is signed in is
 * asked first: a request of nobody's is told that, whatever shop it names.
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
