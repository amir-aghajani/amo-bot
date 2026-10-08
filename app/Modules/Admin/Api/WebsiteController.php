<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Store\Models\Website;
use App\Modules\Store\Services\Websites;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The shop's website («وب‌سایت», both panels — an agent sets their own shop's): on or off, its address and the other
 * origins its pages call the API from, the store key and the base address its developer is handed — a new key ends the
 * old address —, the ways its customers sign in: Telegram with the Client ID and secret @BotFather shows, email
 * sign-up, Google with the site's client id —, and the captcha its forms ask, a driver and its form. A secret never comes
 * back.
 */
final class WebsiteController extends ApiController
{
    public function __construct(private readonly Websites $websites) {}

    /** GET /website — made, switched off, the first time it is asked for. */
    public function show(Request $request, Response $response): Response
    {
        return $this->present($response, $this->websites->current());
    }

    /**
     * PATCH /website — any of {enabled, url, origins, telegram_login, telegram_client_id, telegram_client_secret,
     * clear_telegram_client_secret, email_signup, google_client_id, captcha: {driver, …its form's fields}}: a field sent is
     * changed, one left out stays as it is kept; nothing sent, nothing changes.
     *
     * @throws ValidationException 422, every refusal under its field
     */
    public function update(Request $request, Response $response): Response
    {
        return $this->present($response, $this->websites->update($this->websites->current(), $this->input($request)));
    }

    /** POST /website/key — a new store key: the old base address stops working at once. */
    public function rotateKey(Request $request, Response $response): Response
    {
        return $this->present($response, $this->websites->rotateKey($this->websites->current()));
    }

    private function present(Response $response, Website $website): Response
    {
        return $this->json($response, ['website' => $this->websites->present($website)]);
    }
}
