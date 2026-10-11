<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Gates;

use App\Modules\Telegram\BotSettings;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Keyboard\ReplyKeyboard;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;

/**
 * Phone verification: a customer without a verified number is asked for it (Telegram's "request_contact" button)
 * whatever they send. Only their own card counts. Once verified they are never asked again. The card then goes on
 * through the next gates to the `contact` route (the menu) — a gate never opens the menu itself.
 */
final class PhoneGate implements Gate
{
    public function __construct(
        private readonly BotSettings $settings,
        private readonly BotTexts $texts,
    ) {}

    public function pass(Context $ctx): bool
    {
        if (!$this->settings->phoneRequired() || $ctx->user->hasVerifiedPhone() || $ctx->isAdmin()) {
            return true;
        }

        $contact = $ctx->update->contact();
        if ($contact === null) {
            $this->prompt($ctx, BotText::PhonePrompt);

            return false;
        }

        if (!$ctx->update->isOwnContact()) {
            $this->prompt($ctx, BotText::PhoneNotYours);

            return false;
        }

        $ctx->user->verifyPhone((string) ($contact['phone_number'] ?? ''));
        $ctx->reply($this->texts->get(BotText::PhoneVerified));

        return true;
    }

    /** The ask, with the one-button keyboard that answers it (replacing the menu until the number is in). */
    private function prompt(Context $ctx, BotText $text): void
    {
        $markup = ReplyKeyboard::make()->row(ReplyKeyboard::contactButton($this->texts->get(BotText::PhoneButton), 'primary'))->build();
        $ctx->reply($this->texts->get($text), ['reply_markup' => $markup]);
        $ctx->session->markReplyKeyboard(true);
    }
}
