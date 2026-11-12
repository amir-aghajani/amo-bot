<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;

/**
 * A contact card that passed the gates. The one the phone gate took just now — it stored the number on this update —
 * opens the bot to the customer: the greeting and the menu, as /start (StartHandler), the flow left. Any other is a card
 * nothing asked for — the mini app's «share my number» (Telegram's requestContact) sends the customer's own whenever it
 * is tapped, at whatever step the chat is — and gets a word alone, the chat left at its step and its menu as it is:
 * their number is verified (their own card, with a number on file), or the bot needs none and kept nothing (the phone
 * gate off, or someone else's card). A number is stored by the phone gate alone: nothing the shop did not ask for.
 */
final class ContactHandler implements Handler
{
    public function __construct(
        private readonly StartHandler $start,
        private readonly BotTexts $texts,
    ) {}

    public function handle(Context $ctx): void
    {
        if ($ctx->user->wasChanged('phone')) {
            $ctx->session->clear();
            $this->start->handle($ctx);

            return;
        }

        $verified = $ctx->update->isOwnContact() && $ctx->user->hasVerifiedPhone();
        $ctx->reply($this->texts->get($verified ? BotText::PhoneVerified : BotText::PhoneNotNeeded));
    }
}
