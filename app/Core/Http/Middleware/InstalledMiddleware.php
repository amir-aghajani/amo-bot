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
 * On a route group: the shop's routes (panels' API, webhooks, cron) answer a 503 until the shop is installed; the
 * installer's (`installer: true`, the container's `installer.open`) answer a 404 once it is. /api/app and /health have
 * neither and answer either way.
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
