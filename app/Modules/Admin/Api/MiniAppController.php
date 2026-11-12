<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Auth\Principal;
use App\Modules\Store\Exceptions\NoLogoException;
use App\Modules\Store\Services\MiniAppPreviews;
use App\Modules\Store\Services\MiniApps;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;

/** The shop's Telegram mini app settings (both panels, Store\Services\MiniApps), its logo, and a look at it as a customer. */
final class MiniAppController extends ApiController
{
    public function __construct(
        private readonly MiniApps $miniApps,
        private readonly MiniAppPreviews $previews,
    ) {}

    /** GET /mini-app — made, switched off, the first time it is asked for. */
    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, ['mini_app' => $this->miniApps->present($this->miniApps->current()), 'menu_button_error' => null]);
    }

    /**
     * PATCH /mini-app — any of the mini app's fields: a field sent is changed, one left out is kept. The bot's menu button
     * is set again as the app now stands; `menu_button_error` says why Telegram did not take it (the save stands).
     *
     * @throws ValidationException 422, every refusal under its field
     */
    public function update(Request $request, Response $response): Response
    {
        $app = $this->miniApps->current();
        $error = $this->miniApps->update($app, $this->input($request));

        return $this->json($response, ['mini_app' => $this->miniApps->present($app), 'menu_button_error' => $error]);
    }

    /**
     * GET /mini-app/logo — the logo itself, for the card's preview.
     *
     * @throws NoLogoException 404 while there is none
     */
    public function logo(Request $request, Response $response): Response
    {
        $logo = $this->miniApps->logo($this->miniApps->current()) ?? throw new NoLogoException();

        return $this->bytes($response, $logo['body'], $logo['mime'], 'private, no-cache');
    }

    /**
     * POST /mini-app/logo — multipart, `file`: the new logo, in place of the one before. The menu button is not touched.
     *
     * @throws ValidationException 422 on `file`
     */
    public function uploadLogo(Request $request, Response $response): Response
    {
        $app = $this->miniApps->current();
        $this->miniApps->storeLogo($app, $this->uploadedFile($request) ?? throw ValidationException::on('file', 'تصویری انتخاب نشده است.'));

        return $this->json($response, ['mini_app' => $this->miniApps->present($app), 'menu_button_error' => null]);
    }

    /** DELETE /mini-app/logo — the app shows the shop's initial instead. */
    public function removeLogo(Request $request, Response $response): Response
    {
        $app = $this->miniApps->current();
        $this->miniApps->removeLogo($app);

        return $this->json($response, ['mini_app' => $this->miniApps->present($app), 'menu_button_error' => null]);
    }

    /**
     * POST /mini-app/preview — {user_id}: the app as that customer sees it, to look and not to act, whether it is on or
     * off (MiniAppPreviews): the preview's token, its end, and the app's address from this host's root — under the folder
     * this request came in by.
     *
     * @throws ValidationException 422 on `user_id`
     */
    public function preview(Request $request, Response $response): Response
    {
        $preview = $this->previews->open($this->miniApps->current(), $this->input($request), Principal::of($request)->actor(), RouteContext::fromRequest($request)->getBasePath());

        return $this->json($response, ['preview' => $preview]);
    }
}
