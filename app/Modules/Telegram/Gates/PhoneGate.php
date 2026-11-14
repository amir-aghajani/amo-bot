<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Gates;

use App\Modules\Telegram\Context;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;

/**
 * Phone verification, as the shop's phone rule has it (PhoneRequirement): a customer it asks before everything is asked
 * for their number (Telegram's "request_contact" button) whatever they send. One it asks only at the moments the shop
 * chose is held back by nothing here — each moment asks for itself (PhoneAsk::before()) —, but their own card is taken
 * all the same, sent by such an ask's button or the mini app's «share my number». Only their own card counts: someone
 * else's gets PhoneNotYours and the button again — and, while the shop takes Iranian numbers alone, another country's
 * gets PhoneNotIranian, kept nowhere (as does a customer whose number on file is one). A number the rule takes is never
 * asked again. The card then goes on through the next gates to the `contact` route (ContactHandler: the menu) — a gate
 * never opens the menu itself. A card that comes unasked — the rule off, a number on file it takes, a bot admin, a
 * customer support excused — passes as it is: nothing the shop did not ask for is stored.
 */
final class PhoneGate implements Gate
{
    public function __construct(
        private readonly PhoneRequirement $phone,
        private readonly PhoneAsk $ask,
        private readonly BotTexts $texts,
    ) {}

    public function pass(Context $ctx): bool
    {
        if (!$this->phone->applies($ctx->user)) {
            return true;
        }

        $contact = $ctx->update->contact();
        if ($contact === null) {
            if (!$this->phone->asks($ctx->user)) {
                return true;
            }
            // One on file the rule does not take (another country's, while Iranian numbers alone count) is said so.
            $this->ask->ask($ctx, $this->phone->foreign($ctx->user) ? BotText::PhoneNotIranian : BotText::PhonePrompt);

            return false;
        }

        if (!$ctx->update->isOwnContact()) {
            $this->ask->ask($ctx, BotText::PhoneNotYours);

            return false;
        }
        $number = (string) ($contact['phone_number'] ?? '');
        if (!$this->phone->accepts($number)) {
            // Another country's, while the shop takes Iranian numbers alone: not kept; the button again, for another.
            $this->ask->ask($ctx, BotText::PhoneNotIranian);

            return false;
        }

        $ctx->user->verifyPhone($number);
        $ctx->reply($this->texts->get(BotText::PhoneVerified));

        return true;
    }
}
