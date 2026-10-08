<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Captcha\CaptchaUnavailableException;
use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Store\Exceptions\NoCaptchaException;
use App\Modules\Store\Http\StoreMiddleware;
use App\Modules\Store\Services\WebsiteCaptcha;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The website's captcha for forms of its own (Store\Services\WebsiteCaptcha): a challenge for a widget that asks the
 * shop for one — fresh every time, never kept by a cache —, and a token its backend asks the shop to judge.
 */
final class CaptchaController extends ApiController
{
    public function __construct(private readonly WebsiteCaptcha $captcha) {}

    /**
     * GET /captcha/challenge — ?action=: the challenge the widget solves.
     *
     * @throws ValidationException 422 on `action`
     * @throws NoCaptchaException 404 the website's captcha takes none from the shop
     */
    public function challenge(Request $request, Response $response): Response
    {
        return $this->json($response, $this->captcha->challenge(StoreMiddleware::website($request), $request->getQueryParams()));
    }

    /**
     * POST /captcha/verify — {token, action?, remoteip?}: whether it passed — 200 either way.
     *
     * @throws ValidationException 422 on `token`, on `action`, on `remoteip`
     * @throws NoCaptchaException 409 the website asks no captcha
     * @throws TooManyAttemptsException 429
     * @throws CaptchaUnavailableException 503
     */
    public function verify(Request $request, Response $response): Response
    {
        return $this->json($response, $this->captcha->verify(StoreMiddleware::website($request), $request, $this->input($request)));
    }
}
