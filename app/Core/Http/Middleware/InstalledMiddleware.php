<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Http\Json;
use App\Core\Installation;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Which routes answer before the shop is installed, and which after — put on route groups, so the router decides what
 * a request is (an encoded spelling of an address reaches the same group as the plain one): the shop's routes — the
 * panels' API, the webhooks, the cron address — wait for the installation (a JSON 503), and the installer's
 * (`installer: true`, the container's `installer.open`) are gone once it is done (a 404). What tells a panel which it
 * is — /api/app, /health — answers either way.
 */
final class InstalledMiddleware implements MiddlewareInterface
{
    /** What the installer answers once the shop is installed. */
    public const INSTALLER_GONE = 'فروشگاه نصب شده است؛ نصب‌کننده دیگر در دسترس نیست.';

    /** What the shop's routes answer before it is installed. */
    public const NOT_INSTALLED = 'فروشگاه هنوز نصب نشده است؛ پنل را باز کنید تا نصب‌کننده شروع شود.';

    /** @param bool $installer The installer's routes: open only until the shop is installed */
    public function __construct(
        private readonly Installation $installation,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly bool $installer = false,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $installed = $this->installation->isInstalled();

        if ($this->installer && $installed) {
            return Json::error($this->responseFactory->createResponse(), self::INSTALLER_GONE, 404);
        }
        if (!$this->installer && !$installed) {
            return Json::error($this->responseFactory->createResponse(), self::NOT_INSTALLED, 503);
        }

        return $handler->handle($request);
    }
}
