<?php

declare(strict_types=1);

namespace App\Modules\Store\Api;

use App\Core\Http\ApiController;
use App\Modules\Store\Http\StoreMiddleware;
use App\Modules\Store\Services\Storefront;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The Store API's `GET /`: the shop the store key names, as a website introduces it (Store\Services\Storefront); and its
 * «آموزش», as the bot shows it.
 */
final class StoreController extends ApiController
{
    public function __construct(
        private readonly Storefront $storefront,
        private readonly BotTexts $texts,
    ) {}

    /** GET / */
    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, $this->storefront->present(StoreMiddleware::door($request)));
    }

    /** GET /tutorial — the text under the bot's «آموزش» button, the shop's wording, in Telegram's HTML. */
    public function tutorial(Request $request, Response $response): Response
    {
        return $this->json($response, ['text' => $this->texts->get(BotText::Tutorial)]);
    }
}
