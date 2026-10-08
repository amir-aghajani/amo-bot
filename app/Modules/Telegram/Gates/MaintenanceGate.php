<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Gates;

use App\Modules\Telegram\BotSettings;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Keyboard\ReplyKeyboard;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;

/**
 * The master switch ("ربات فعال باشد"): while it is off customers get one notice per message and lose the
 * menu keyboard, so the shop looks closed rather than broken. Admins (role admin) are let through so
 * they can try things out before opening again; the next /start (or any text) brings the keyboard back.
 */
final class MaintenanceGate implements Gate
{
    public function __construct(
        private readonly BotSettings $settings,
        private readonly BotTexts $texts,
    ) {}

    public function pass(Context $ctx): bool
    {
        if ($this->settings->enabled() || $ctx->isAdmin()) {
            return true;
        }

        if ($ctx->update->isCallback()) {
            $ctx->answer($this->texts->get(BotText::BotOff), alert: true);

            return false;
        }

        $ctx->reply($this->texts->message(BotText::BotOff), ['reply_markup' => ReplyKeyboard::remove()]);
        $ctx->session->markReplyKeyboard(false);

        return false;
    }
}
