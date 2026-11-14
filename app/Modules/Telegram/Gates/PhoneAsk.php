<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Gates;

use App\Modules\Telegram\Context;
use App\Modules\Telegram\Keyboard\ReplyKeyboard;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;

/**
 * The bot asking a customer's number, with the one-button keyboard that answers it (Telegram's "request_contact") — by
 * PhoneGate before anything at all, by before() at a moment the shop chose (PhoneRequirement). The card the button
 * sends is PhoneGate's to take, and the menu follows it (ContactHandler).
 */
final class PhoneAsk
{
    public function __construct(
        private readonly PhoneRequirement $phone,
        private readonly BotTexts $texts,
    ) {}

    /**
     * Whether the customer is asked their number before what they are about to do — one of `$moments`
     * (PhoneRequirement::asks()) — and so was asked just now (PhoneNeeded): the screen tapped gives way to the ask, a
     * message of its own (a reply keyboard comes only with a message sent), and the chat leaves its flow. Nothing is
     * done meanwhile: once the number is in, the customer does it again from the menu.
     */
    public function before(Context $ctx, PhoneMoment ...$moments): bool
    {
        if (!$this->phone->asks($ctx->user, ...$moments)) {
            return false;
        }

        $ctx->session->clear();
        $ctx->delete();
        $this->ask($ctx, $this->phone->foreign($ctx->user) ? BotText::PhoneNotIranian : BotText::PhoneNeeded);

        return true;
    }

    /** `$text`, with the one-button keyboard that answers it (replacing the menu until the number is in). */
    public function ask(Context $ctx, BotText $text): void
    {
        $markup = ReplyKeyboard::make()->row(ReplyKeyboard::contactButton($this->texts->get(BotText::PhoneButton), 'primary'))->build();
        $ctx->reply($this->texts->get($text), ['reply_markup' => $markup]);
        $ctx->session->markReplyKeyboard(true);
    }
}
