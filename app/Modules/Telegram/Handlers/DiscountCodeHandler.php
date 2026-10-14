<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Discounts\Exceptions\DiscountCodeRefusedException;
use App\Modules\Discounts\Services\DiscountCodes;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\CallbackData;

/**
 * «🏷 کد تخفیف دارم» under a discountable checkout:
 *   dcode:a:{checkout} → the code awaited (state `dcode`); one that holds redraws the checkout with it, one that does
 *                        not is said why and another is awaited
 *   dcode:x:{checkout} → the code removed, the checkout redrawn
 * `{checkout}` is the checkout's own callback data, whose handler redraws it (Checkouts). Wrong codes count against the
 * customer's budget (DiscountCodes::tried()), so codes cannot be guessed.
 */
final class DiscountCodeHandler implements Handler
{
    public const PREFIX = 'dcode:';
    public const STATE = 'dcode';

    private const ASK = 'a';
    private const REMOVE = 'x';

    public function __construct(
        private readonly Checkouts $checkouts,
        private readonly CheckoutScreen $screen,
        private readonly DiscountCodes $codes,
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
            $this->checkouts->typed($ctx, BotText::DiscountCodeRefused, fn(string $checkout, string $code) => $this->typed($ctx, $checkout, $code));

            return;
        }

        $args = $ctx->update->callbackArgs(self::PREFIX) ?? [];
        $action = array_shift($args);
        $checkout = implode(':', $args);

        match (true) {
            !$this->checkouts->knows($checkout) => $ctx->edit($this->texts->get(BotText::Unknown)),
            $action === self::ASK => $this->checkouts->ask($ctx, self::STATE, $checkout, BotText::DiscountCodeAsk),
            $action === self::REMOVE => $this->remove($ctx, $checkout),
            default => $ctx->edit($this->texts->get(BotText::Unknown)),
        };
    }

    /** The code taken off: the checkout drawn again without it. */
    private function remove(Context $ctx, string $checkout): void
    {
        $this->screen->keepCode($ctx, $checkout, null);
        $this->checkouts->draw($ctx, $checkout);
    }

    /**
     * State `dcode`: the code typed — the checkout drawn again with it, as a new message — judged against the
     * customer's budget of wrong codes.
     */
    private function typed(Context $ctx, string $checkout, string $code): void
    {
        if ($code === '') {
            throw new DiscountCodeRefusedException(DiscountCodes::NOT_FOUND);
        }
        $this->codes->tried($ctx->user, fn() => $this->checkouts->draw($ctx, $checkout, $code));
    }
}
