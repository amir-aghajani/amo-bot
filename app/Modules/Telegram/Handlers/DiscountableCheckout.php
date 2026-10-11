<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Discounts\Exceptions\DiscountCodeRefusedException;
use App\Modules\Telegram\Context;

/**
 * A handler whose checkout may take a discount code (a purchase, a renewal, an extra), so DiscountCodeHandler can draw
 * it again with a code just typed or after the kept one is removed.
 */
interface DiscountableCheckout
{
    /**
     * Draw the checkout `$args` names (its callback arguments under the handler's prefix) again: with `$code` just typed
     * (kept once it holds), else with the kept code, if any.
     *
     * @param list<string> $args
     * @throws DiscountCodeRefusedException `$code` does not hold for it
     */
    public function drawCheckout(Context $ctx, array $args, ?string $code = null): void;
}
