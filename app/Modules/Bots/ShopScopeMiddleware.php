<?php

declare(strict_types=1);

namespace App\Modules\Bots;

use App\Modules\Bots\Models\Bot;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs the owner's installation-wide sections where they belong, whatever shop the panel shows: across every bot's rows
 * (EVERYWHERE — servers, grants, mass gifts; the container's `shop.everywhere`) or in the main bot's shop (MAIN — the
 * agency, whose agents are its customers, and the panel's settings; `shop.main`).
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
