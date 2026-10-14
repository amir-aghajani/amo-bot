<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Subscriptions\Enums\ClientNameMode;
use App\Modules\Subscriptions\Services\ClientNameSettings;
use App\Modules\Subscriptions\Services\ClientNaming;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\CallbackData;

/**
 * The name of a purchase's service, chosen under its checkout (CheckoutScreen) while the shop lets buyers choose:
 *   cname:{way}:{checkout} → by that way (its place among ClientNameMode's cases, CallbackData::place()): «🔢 ترتیبی»
 *                            the shop's pattern, «🎲 رندوم» a name drawn at random (another at each tap) — the checkout
 *                            drawn again —, or «✏️ دلخواه»: the name awaited (state `cname`); one that holds draws the
 *                            checkout again as a new message, one that does not is said why and another is awaited
 * `{checkout}` is the checkout's own callback data, whose handler draws it again (Checkouts). The name is judged on the
 * server that checkout's service will be on (Checkouts::server(), ClientNaming::requested()) and kept for that checkout
 * alone (CheckoutScreen::keepName()); a way switched off since the checkout was drawn draws it again, the name dropped.
 */
final class ClientNameHandler implements Handler
{
    public const PREFIX = 'cname:';
    public const STATE = 'cname';

    public function __construct(
        private readonly ClientNaming $naming,
        private readonly ClientNameSettings $names,
        private readonly CheckoutScreen $screen,
        private readonly Checkouts $checkouts,
        private readonly BotTexts $texts,
    ) {}

    /** The button for naming the service by `$mode`, under the purchase's checkout `$checkout`. */
    public static function modeCallback(ClientNameMode $mode, string $checkout): string
    {
        return CallbackData::build(self::PREFIX, CallbackData::place($mode), $checkout);
    }

    public function handle(Context $ctx): void
    {
        if (!$ctx->update->isCallback()) {
            $this->checkouts->typed($ctx, BotText::CheckoutNameRefused, fn(string $checkout, string $name) => $this->typed($ctx, $checkout, $name));

            return;
        }

        $args = $ctx->update->callbackArgs(self::PREFIX) ?? [];
        $mode = CallbackData::caseAt(ClientNameMode::class, (int) array_shift($args));
        $checkout = implode(':', $args);
        $server = $this->checkouts->server($checkout);
        if ($mode === null || $server === null) {
            $ctx->edit($this->texts->get(BotText::Unknown));

            return;
        }

        if (!in_array($mode, $this->names->modes(), true)) {
            // Switched off since the checkout was drawn: the checkout with the ways that are left.
            $this->screen->keepName($ctx, $checkout, null);
        } elseif ($mode === ClientNameMode::Custom) {
            $this->checkouts->ask($ctx, self::STATE, $checkout, BotText::CheckoutNameAsk);

            return;
        } else {
            $this->screen->keepName($ctx, $checkout, $mode, $this->naming->requested($mode, null, $server));
        }
        $this->checkouts->draw($ctx, $checkout);
    }

    /**
     * State `cname`: the name typed, judged on the checkout's server — the checkout drawn again with it, as a new
     * message; without one once typed names are switched off meanwhile (or the server is gone).
     */
    private function typed(Context $ctx, string $checkout, string $name): void
    {
        $server = $this->checkouts->server($checkout);
        $kept = $server !== null && in_array(ClientNameMode::Custom, $this->names->modes(), true) ? $this->naming->requested(ClientNameMode::Custom, $name, $server) : null;
        $this->screen->keepName($ctx, $checkout, ClientNameMode::Custom, $kept);
        $this->checkouts->draw($ctx, $checkout);
    }
}
