<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Telegram\Context;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Support\Input;
use App\Support\Unit;

/**
 * A whole number typed at a step that awaits one — GB, days or devices to buy, Toman to charge («۲۰ گیگ» and
 * «۱۵۰٬۰۰۰ تومان» are fine, Input::amountOf()) —, read and judged the one way every such step does.
 */
final class TypedAmounts
{
    public function __construct(private readonly Buttons $buttons) {}

    /**
     * The number of `$unit` the customer typed, while `$allowed` takes it; null after replying `$refusal` (what may be
     * typed) with «بازگشت» to `$back` — the step still awaits one. Another unit's word («۲۰ روز» where GB are awaited)
     * is refused as any text that is no amount.
     *
     * @param \Closure(int): bool $allowed
     */
    public function read(Context $ctx, Unit $unit, \Closure $allowed, string $refusal, string $back): ?int
    {
        $amount = Input::amountOf($ctx->update->text(), $unit);
        if ($amount !== null && $allowed($amount)) {
            return $amount;
        }

        $ctx->reply($refusal, ['reply_markup' => $this->buttons->backOnly($back)]);

        return null;
    }
}
