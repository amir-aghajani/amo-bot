<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Discounts\Exceptions\DiscountCodeRefusedException;
use App\Modules\Telegram\Context;

/**
 * A handler whose checkout (a purchase, a renewal, an extra) a step under it draws again (Checkouts): with a discount
 * code just typed or after the kept one is removed (DiscountCodeHandler), or with a name chosen for a purchase's
 * service (ClientNameHandler).
 */
interface RedrawableCheckout
{
    /**
     * Draw the checkout `$args` names (its callback arguments under the handler's prefix) again: with `$code` just typed
     * (kept once it holds), else with the kept code, if any.
     *
     * @param list<string> $args
     * @throws DiscountCodeRefusedException `$code` does not hold for it
     */
    public function drawCheckout(Context $ctx, array $args, ?string $code = null): void;

    /**
     * The server the purchase whose checkout `$args` names makes its service on, where its name is judged; null for a
     * checkout of no purchase (a renewal, an extra).
     *
     * @param list<string> $args
     */
    public function serverOf(array $args): ?int;
}
