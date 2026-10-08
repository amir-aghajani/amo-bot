<?php

declare(strict_types=1);

namespace App\Modules\Admin\Api;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\ApiController;
use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Telegram\Qr\QrBackground;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The bot's settings («تنظیمات ربات»), the shop's own in either panel — a group per subject (BotSettingsScreen), each
 * saved on its own — and the picture its QR codes are drawn on.
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
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            throw ValidationException::on('file', 'تصویری انتخاب نشده است.');
        }
        $this->background->store($file);

        return $this->json($response, ['qr_background' => $this->background->describe()]);
    }

    /** DELETE /bot/qr-background — back to the shipped one */
    public function resetBackground(Request $request, Response $response): Response
    {
        $this->background->reset();

        return $this->json($response, ['qr_background' => $this->background->describe()]);
    }

    /**
     * PUT /bot/settings/{group} — general: {enabled, phone_required, support_contact}; channels: {join_required};
     * wallet: {topup_min, topup_presets}; renewal: {carry_traffic}; auto_renew: {auto_renew_days, auto_renew_default};
     * reminders: {expiry_reminder, expiry_reminder_days, traffic_reminder, traffic_reminder_percent};
     * referral: {referral_enabled, referral_rate, referral_first_only}; reports: {report_<topic>, …} (a switch per topic
     * the shop's group has); qr: {qr_enabled}. A group the screen does not have is a 404.
     *
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $this->settings->save($args['group'], $this->input($request));

        return $this->json($response, ['settings' => $this->settings->present()]);
    }
}
