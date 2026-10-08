<?php

declare(strict_types=1);

namespace App\Modules\Installer\Middleware;

use App\Core\Http\Json;
use App\Modules\Installer\Services\InstallKey;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The web installer opens only with its key (InstallKey): a request without it, or with another, is a 403 that says on
 * `key` where the key is — the installer page asks for it then.
 */
final class InstallKeyMiddleware implements MiddlewareInterface
{
    public const MISSING = 'برای نصب، کلید نصب را وارد کنید: فایل storage/install-key.txt را از File Manager هاست باز کنید و متن آن را اینجا بنویسید.';
    public const WRONG = 'کلید نصب درست نیست؛ متن فایل storage/install-key.txt را دوباره کپی کنید.';
    public const UNWRITABLE = 'کلید نصب در پوشه storage نوشته نشد؛ اجازه نوشتن در پوشه storage را به وب‌سرور بدهید و صفحه را دوباره باز کنید.';

    public function __construct(
        private readonly InstallKey $key,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $this->key->current();
        } catch (\RuntimeException) {
            return Json::error($this->responseFactory->createResponse(), self::UNWRITABLE, 503);
        }

        $given = $request->getHeaderLine(InstallKey::HEADER);
        if (!$this->key->matches($given)) {
            $message = trim($given) === '' ? self::MISSING : self::WRONG;

            return Json::error($this->responseFactory->createResponse(), $message, 403, ['key' => [$message]]);
        }

        return $handler->handle($request);
    }
}
