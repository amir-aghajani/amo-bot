<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Door;

use App\Core\Exceptions\DomainRuleException;
use App\Core\Http\Json;
use App\Modules\Auth\CurrentPrincipal;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Auth\Principal;
use App\Modules\Auth\StaffGrant;
use App\Modules\Bots\CurrentBot;
use App\Modules\Telegram\Admin\AdminSeat;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Routing\RouteContext;

/**
 * The door of the shop's management from its bot's private chat (routes/api.php, PATH): the panels' very routes, under
 * `/owner`, `/agent` and `/staff`. A request gets in only with the seat the bot put on it in-process (SEAT, a request
 * attribute — no client can send one): from outside, every path here is a 404, as if there were none. Then, asked in
 * this order:
 * 1. the seat's kind is the door's (an admin never takes the owner's);
 * 2. an operation that needs a grant (StaffGrant::ARGUMENT on its route) is granted to an admin («مدیران ربات»);
 * and the request runs in the seat's shop, as its principal — every change logged with them on the line. A grant a
 * service asks of the operation itself (a customer put in a «همکار» group) is refused in the bot's words.
 */
final class BotAdminDoor implements MiddlewareInterface
{
    /** Where the door is, under the app's base path. */
    public const PATH = '/api/bot-admin';

    /** The request attribute the bot puts the seat under (PanelBridge). */
    public const SEAT = 'bot_admin_seat';

    public const NOT_GRANTED = 'مالک فروشگاه این کار را به مدیران ربات نسپرده است.';
    public const AGENT_GROUP_NOT_GRANTED = 'مالک فروشگاه گذاشتن مشتری در گروه «همکار» را به مدیران ربات نسپرده است.';

    /** The methods that change nothing, left out of the log. */
    private const READS = ['GET', 'HEAD'];

    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $seat = $request->getAttribute(self::SEAT);
        $route = RouteContext::fromRequest($request)->getRoute();
        if (!$seat instanceof AdminSeat || $route === null || !str_starts_with($route->getPattern(), self::PATH . '/' . $seat->kind->value . '/')) {
            return Json::error($this->responses->createResponse(), 'صفحه مورد نظر پیدا نشد.', 404);
        }
        $named = $route->getArgument(StaffGrant::ARGUMENT);
        $grant = $named === null ? null : StaffGrant::tryFrom($named) ?? throw new \LogicException("The route names no grant there is: {$named}.");
        if ($grant !== null && !$seat->holds($grant)) {
            return Json::error($this->responses->createResponse(), self::NOT_GRANTED, 403);
        }

        $principal = $seat->principal;

        return CurrentBot::run($principal->shop, fn(): ResponseInterface => CurrentPrincipal::run($principal, function () use ($request, $handler, $principal, $seat): ResponseInterface {
            try {
                $response = $handler->handle($request->withAttribute(Principal::ATTRIBUTE, $principal));
            } catch (ActorRefusedException $refused) {
                if ($refused->grant === null) {
                    $this->changed($request, $seat, $refused->status());

                    throw $refused;
                }
                // A grant a service asks (putting a customer in a «همکار» group): said in the bot's words, not the website's.
                $this->changed($request, $seat, 403);

                return Json::error($this->responses->createResponse(), $refused->grant === StaffGrant::Catalog ? self::AGENT_GROUP_NOT_GRANTED : self::NOT_GRANTED, 403);
            } catch (\Throwable $e) {
                // The status the error handler answers it with: a refusal is no failure.
                $this->changed($request, $seat, match (true) {
                    $e instanceof DomainRuleException => $e->status(),
                    $e instanceof ModelNotFoundException => 404,
                    $e instanceof HttpException => $e->getCode(),
                    default => null,
                });

                throw $e;
            }
            $this->changed($request, $seat, $response->getStatusCode());

            return $response;
        }));
    }

    /** Log a change asked from the bot and its status (null: it failed past an answer); reads are not logged. */
    private function changed(ServerRequestInterface $request, AdminSeat $seat, ?int $status): void
    {
        if (!in_array($request->getMethod(), self::READS, true)) {
            $this->logger->info('A shop {kind} in the bot asked {method} {path}: {status}', [
                'kind' => $seat->kind->value,
                'method' => $request->getMethod(),
                'path' => substr($request->getUri()->getPath(), strpos($request->getUri()->getPath(), self::PATH) ?: 0),
                'status' => $status ?? 'failed',
                'actor' => $seat->principal->actor()->label(),
            ]);
        }
    }
}
