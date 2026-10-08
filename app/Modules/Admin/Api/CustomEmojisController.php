<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Telegram\Emoji\CustomEmojis;
use App\Modules\Telegram\Emoji\PremiumEmojiStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The premium emoji the editors offer (Telegram\Emoji\CustomEmojis): the ones an admin showed the bot with /emoji,
 * whether the bot may send them (PremiumEmojiStatus), and each one's picture — and animation, when it moves — for the
 * picker and the previews.
 */
final class CustomEmojisController extends ApiController
{
    /** An emoji's picture or animation never changes: the browser keeps it for a day. */
    private const CACHE = 'private, max-age=86400';

    public function __construct(
        private readonly CustomEmojis $emojis,
        private readonly PremiumEmojiStatus $status,
    ) {}

    /** GET /bot/custom-emojis */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, ['emojis' => $this->emojis->present(), 'status' => $this->status->current()]);
    }

    /**
     * GET /bot/custom-emojis/{id}/image — a still picture of any premium emoji, kept or not (a text's preview shows the
     * ones it has), as Telegram has it; 404 when it has none.
     *
     * @param array<string, string> $args
     */
    public function image(Request $request, Response $response, array $args): Response
    {
        $picture = $this->emojis->picture($args['id']) ?? throw new ModelNotFoundException();

        return $this->bytes($response, $picture['body'], $picture['mime'], self::CACHE);
    }

    /**
     * GET /bot/custom-emojis/{id}/animation — how a premium emoji moves, kept or not: its Lottie animation as Telegram
     * has it (a gzipped .tgs — the panel unzips and plays it) or its WebM video; 404 for a still one, or when Telegram has
     * none.
     *
     * @param array<string, string> $args
     */
    public function animation(Request $request, Response $response, array $args): Response
    {
        $animation = $this->emojis->animation($args['id']) ?? throw new ModelNotFoundException();

        return $this->bytes($response, $animation['body'], $animation['mime'], self::CACHE);
    }

    /**
     * DELETE /bot/custom-emojis/{id} — off the picker; texts that use it keep it.
     *
     * @param array<string, string> $args
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        if (!$this->emojis->forget($args['id'])) {
            throw new ModelNotFoundException();
        }

        return $this->noContent($response);
    }
}
