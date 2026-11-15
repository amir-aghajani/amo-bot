<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Gates;

use App\Modules\Telegram\Admin\AdminSeats;
use App\Modules\Telegram\BotSettings;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Keyboard\ReplyKeyboard;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;

/**
 * The master switch: while off, customers get BotText::BotOff and lose the menu keyboard, so the shop looks closed
 * rather than broken. Whoever manages the shop passes (AdminSeats::passGates(): the bot's admins, the owner's linked
 * account, the agent in their own bot, the owner's open link being opened), to try things out and to open it again.
 */
final class MaintenanceGate implements Gate
{
    public function __construct(
        private readonly BotSettings $settings,
        private readonly BotTexts $texts,
        private readonly AdminSeats $seats,
    ) {}

    public function pass(Context $ctx): bool
    {
        if ($this->settings->enabled() || $this->seats->passGates($ctx)) {
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
