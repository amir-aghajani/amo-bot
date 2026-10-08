<?php

declare(strict_types=1);

namespace App\Modules\Bots;

use App\Modules\Bots\Models\Bot;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The owner's sections that are the shop's as a whole, worked where they belong whatever shop the panel shows: every
 * bot's services at once (EVERYWHERE — the servers, their grants, the mass gifts; the container's `shop.everywhere`) or
 * the main bot's shop (MAIN — the agency, whose agents are its customers, and the panel's settings; `shop.main`).
 */
final class ShopScopeMiddleware implements MiddlewareInterface
{
    public const EVERYWHERE = 'everywhere';
    public const MAIN = 'main';

    public function __construct(private readonly string $scope) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $handle = static fn(): ResponseInterface => $handler->handle($request);

        return $this->scope === self::MAIN ? CurrentBot::run(Bot::MAIN, $handle) : CurrentBot::everywhere($handle);
    }
}
