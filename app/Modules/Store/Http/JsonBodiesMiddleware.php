<?php

declare(strict_types=1);

namespace App\Modules\Store\Http;

use App\Core\Http\Json;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;

/**
 * The Store API takes JSON bodies only: a POST, PUT or PATCH carrying anything else is a 415, except multipart forms on
 * the routes routes/api.php marks UPLOAD (a receipt, a ticket's message). Why: a form or text body is a CORS "simple
 * request" any site's page can make a visitor's browser send without a preflight; refusing them means a sign-in posted
 * from another site needs the preflight only the website's own origins pass.
 */
final class JsonBodiesMiddleware implements MiddlewareInterface
{
    /** The argument a route that takes a picture is registered with (any value): a form is its body too. */
    public const UPLOAD = 'upload';

    public const NOT_JSON = 'بدنه این درخواست باید JSON باشد؛ آن را با Content-Type: application/json بفرستید.';
    public const NOT_JSON_OR_FORM = 'بدنه این درخواست باید JSON یا فرم چندبخشی (multipart/form-data) باشد.';

    /** The methods that carry a body. */
    private const WRITES = ['POST', 'PUT', 'PATCH'];

    public function __construct(private readonly ResponseFactoryInterface $responses) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array($request->getMethod(), self::WRITES, true) || !self::carriesBody($request)) {
            return $handler->handle($request);
        }
        $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
        $upload = RouteContext::fromRequest($request)->getRoute()?->getArgument(self::UPLOAD) !== null;
        if ($type === 'application/json' || ($upload && $type === 'multipart/form-data')) {
            return $handler->handle($request);
        }

        return Json::error($this->responses->createResponse(), $upload ? self::NOT_JSON_OR_FORM : self::NOT_JSON, 415);
    }

    /** Whether the request says it carries a body: a content type, a non-zero length, or chunked transfer. */
    private static function carriesBody(ServerRequestInterface $request): bool
    {
        $length = $request->getHeaderLine('Content-Length');

        return $request->getHeaderLine('Content-Type') !== ''
            || (ctype_digit($length) && (int) $length > 0)
            || $request->getHeaderLine('Transfer-Encoding') !== '';
    }
}
