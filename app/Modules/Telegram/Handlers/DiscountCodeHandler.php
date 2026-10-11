<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Modules\Discounts\Exceptions\DiscountCodeRefusedException;
use App\Modules\Discounts\Services\DiscountCodes;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\CallbackData;

/**
 * «🏷 کد تخفیف دارم» under a discountable checkout:
 *   dcode:a:{checkout} → the code awaited (state `dcode`); one that holds redraws the checkout with it, one that does
 *                        not is said why and another is awaited
 *   dcode:x:{checkout} → the code removed, the checkout redrawn
 * `{checkout}` is the checkout's own callback data (`plan:…`, `renew:…`, `extra:…`), whose handler redraws it. Wrong
 * codes count against the customer's budget (DiscountCodes::tried()), so codes cannot be guessed.
 */
final class DiscountCodeHandler implements Handler
{
    public const PREFIX = 'dcode:';
    public const STATE = 'dcode';

    private const ASK = 'a';
    private const REMOVE = 'x';
    /** The scratch's checkout while a code is awaited. */
    private const AT = 'at';

    public function __construct(
        private readonly PurchaseHandler $purchases,
        private readonly RenewalHandler $renewals,
        private readonly ExtrasHandler $extras,
        private readonly CheckoutScreen $screen,
        private readonly DiscountCodes $codes,
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
    ) {}

    /** «🏷 کد تخفیف دارم» under the checkout `$checkout` (its callback data). */
    public static function askCallback(string $checkout): string
    {
        return CallbackData::build(self::PREFIX, self::ASK, $checkout);
    }

    /** «❌ حذف کد تخفیف» under the checkout `$checkout`. */
    public static function removeCallback(string $checkout): string
    {
        return CallbackData::build(self::PREFIX, self::REMOVE, $checkout);
    }

    public function handle(Context $ctx): void
    {
        if (!$ctx->update->isCallback()) {
            $this->typed($ctx);

            return;
        }

        $args = $ctx->update->callbackArgs(self::PREFIX) ?? [];
        $action = array_shift($args);
        $checkout = implode(':', $args);
        $target = $this->target($checkout);
        if ($target === null) {
            $ctx->edit($this->texts->get(BotText::Unknown));

            return;
        }

        match ($action) {
            self::ASK => $this->ask($ctx, $checkout),
            self::REMOVE => $this->remove($ctx, $checkout, ...$target),
            default => $ctx->edit($this->texts->get(BotText::Unknown)),
        };
    }

    /** The code awaited for the checkout: its prompt in the checkout's place, «بازگشت» to it. */
    private function ask(Context $ctx, string $checkout): void
    {
        $ctx->session->enter(self::STATE, [self::AT => $checkout]);
        $ctx->edit($this->texts->get(BotText::DiscountCodeAsk), ['reply_markup' => $this->buttons->backOnly($checkout)]);
    }

    /**
     * The code taken off: the checkout drawn again without it.
     *
     * @param list<string> $args
     */
    private function remove(Context $ctx, string $checkout, DiscountableCheckout $handler, array $args): void
    {
        $this->screen->keepCode($ctx, $checkout, null);
        $handler->drawCheckout($ctx, $args);
    }

    /** State `dcode`: the code typed — the checkout redrawn with it as a new message, or why not (or the wait). */
    private function typed(Context $ctx): void
    {
        $checkout = (string) $ctx->session->get(self::AT, '');
        $target = $this->target($checkout);
        if ($target === null) {
            // No checkout of this step's: nothing is awaited.
            $ctx->session->clear();
            $ctx->reply($this->texts->get(BotText::Unknown));

            return;
        }
        [$handler, $args] = $target;

        $code = trim((string) $ctx->update->text());
        try {
            if ($code === '') {
                throw new DiscountCodeRefusedException(DiscountCodes::NOT_FOUND);
            }
            $this->codes->tried($ctx->user, static fn() => $handler->drawCheckout($ctx, $args, $code));
        } catch (DiscountCodeRefusedException|TooManyAttemptsException $e) {
            $ctx->reply($this->texts->render(BotText::DiscountCodeRefused, ['reason' => $e->getMessage()]), ['reply_markup' => $this->buttons->backOnly($checkout)]);
        }
    }

    /**
     * The handler that draws the checkout `$checkout`, and its callback arguments under the handler's prefix; null for none.
     *
     * @return array{DiscountableCheckout, list<string>}|null
     */
    private function target(string $checkout): ?array
    {
        $handlers = [
            PurchaseHandler::PLAN => $this->purchases,
            RenewalHandler::PREFIX => $this->renewals,
            ExtrasHandler::PREFIX => $this->extras,
        ];
        foreach ($handlers as $prefix => $handler) {
            $args = CallbackData::args($checkout, $prefix);
            if ($args !== null && $args !== []) {
                return [$handler, $args];
            }
        }

        return null;
    }
}
