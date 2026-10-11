<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Telegram\Qr\QrBackground;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The bot's settings («تنظیمات ربات», either panel, per shop): a group per subject (BotSettingsScreen), each saved on its
 * own, and the picture its QR codes are drawn on.
 */
final class BotSettingsController extends ApiController
{
    public function __construct(
        private readonly BotSettingsScreen $settings,
        private readonly QrBackground $background,
    ) {}

    /** GET /bot/settings */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, ['settings' => $this->settings->present(), 'qr_background' => $this->background->describe()]);
    }

    /** GET /bot/qr-background — the picture itself, for the screen's preview */
    public function background(Request $request, Response $response): Response
    {
        $image = $this->background->image();

        return $this->bytes($response, (string) file_get_contents($image['path']), $image['mime'], 'private, no-cache');
    }

    /** POST /bot/qr-background — multipart, `file`: the new background */
    public function uploadBackground(Request $request, Response $response): Response
    {
        $this->background->store($this->uploadedFile($request) ?? throw ValidationException::on('file', 'تصویری انتخاب نشده است.'));

        return $this->json($response, ['qr_background' => $this->background->describe()]);
    }

    /** DELETE /bot/qr-background — back to the shipped one */
    public function resetBackground(Request $request, Response $response): Response
    {
        $this->background->reset();

        return $this->json($response, ['qr_background' => $this->background->describe()]);
    }

    /**
     * PUT /bot/settings/{group} — that group's fields (BotSettingsScreen; the API description lists each group's form). A
     * group the screen does not have is a 404.
     *
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $this->settings->save($args['group'], $this->input($request));

        return $this->json($response, ['settings' => $this->settings->present()]);
    }
}
