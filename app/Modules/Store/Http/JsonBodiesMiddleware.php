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
 * What the Store API takes as a body: JSON. A POST, PUT or PATCH that carries anything else — a form, text, a body that
 * says nothing of itself — is a 415 in the error shape, but on a route that takes a picture (one routes/api.php marks
 * with UPLOAD: a receipt, a ticket's message), which takes a form too (multipart/form-data). A form or a text body is
 * what a page of any site may make its visitors' browsers send without asking first (a CORS "simple request"): refused,
 * a sign-up, a sign-in or a reset asked from another site's page needs the preflight only the website's own origins
 * pass. A request that carries nothing is its route's to judge.
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

    /** Whether the request says it carries a body: its type, its length past none, or the chunks it comes in. */
    private static function carriesBody(ServerRequestInterface $request): bool
    {
        $length = $request->getHeaderLine('Content-Length');

        return $request->getHeaderLine('Content-Type') !== ''
            || (ctype_digit($length) && (int) $length > 0)
            || $request->getHeaderLine('Transfer-Encoding') !== '';
    }
}
