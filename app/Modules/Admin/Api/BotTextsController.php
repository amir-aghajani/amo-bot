<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Texts\TextCatalog;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * «متن‌های ربات»: every text the bot sends a customer, by group — what it is, where it shows, its
 * `%variables%`, the shop's wording and the admin's — and the admin's rewording of one, or its reset.
 */
final class BotTextsController extends ApiController
{
    public function __construct(private readonly BotTexts $texts) {}

    /** GET /bot/texts */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, ['groups' => $this->texts->groups()]);
    }

    /**
     * PUT /bot/texts/{key} — {value}; the default's own wording is the same as a reset.
     *
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $text = self::text($request, $args);
        $this->texts->save($text, $this->input($request)['value'] ?? null);

        return $this->json($response, ['text' => $this->texts->present($text)]);
    }

    /**
     * POST /bot/texts/{key}/reset — back to the shop's wording.
     *
     * @param array<string, string> $args
     */
    public function reset(Request $request, Response $response, array $args): Response
    {
        $text = self::text($request, $args);
        $this->texts->reset($text);

        return $this->json($response, ['text' => $this->texts->present($text)]);
    }

    /**
     * The text the route names; one the bot does not have — or never says in this shop (an agent's has no agency) — is a 404.
     *
     * @param array<string, string> $args
     */
    private static function text(Request $request, array $args): BotText
    {
        $text = BotText::tryFrom((string) $args['key']);

        return $text !== null && TextCatalog::says($text) ? $text : throw new HttpNotFoundException($request);
    }
}
