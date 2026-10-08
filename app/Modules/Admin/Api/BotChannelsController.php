<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Http\ApiController;
use App\Modules\Telegram\Channels\RequiredChannels;
use App\Modules\Telegram\Models\BotChannel;
use App\Support\Input;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The channels a customer must join ("کانال‌های اجباری" on the bot settings screen).
 */
final class BotChannelsController extends ApiController
{
    public function __construct(private readonly RequiredChannels $channels) {}

    /** GET /bot/channels */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, ['channels' => $this->channels->list()]);
    }

    /** POST /bot/channels — {link}: looked up and refused unless the bot is an admin there. */
    public function store(Request $request, Response $response): Response
    {
        $channel = $this->channels->add(Input::text($this->input($request), 'link'));

        return $this->json($response, ['channel' => $this->channels->present($channel)], 201);
    }

    /** POST /bot/channels/reorder — {ids} */
    public function reorder(Request $request, Response $response): Response
    {
        $this->channels->reorder($this->reorderIds($request));

        return $this->json($response, ['channels' => $this->channels->list()]);
    }

    /**
     * POST /bot/channels/{id}/check — look the channel up again (admin rights, title, link).
     *
     * @param array<string, string> $args
     */
    public function check(Request $request, Response $response, array $args): Response
    {
        $channel = $this->channels->check($this->load(BotChannel::class, $args));

        return $this->json($response, ['channel' => $this->channels->present($channel)]);
    }

    /**
     * DELETE /bot/channels/{id}
     *
     * @param array<string, string> $args
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->channels->delete($this->load(BotChannel::class, $args));

        return $this->noContent($response);
    }
}
