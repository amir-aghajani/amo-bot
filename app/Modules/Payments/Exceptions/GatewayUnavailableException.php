<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Modules\Payments\Enums\GatewayRefusal;

/**
 * An online gateway could not be asked, or would not open a payment: its message is the customer's word; `detail` — what
 * the gateway said, never a secret — is the log's. `held`: the gateway's answer did not fit the payment (another order,
 * a smaller amount — AtlasPay's underpayment its customer accepted among them), or the payment is held already, so it is
 * left for support and never settled by the shop's own asking (Services\OnlinePayments). `refusal`: why the gateway
 * refused, when the owner can act on it (a wrong key, an IP not allowed…) — none for one out of reach.
 */
final class GatewayUnavailableException extends \RuntimeException
{
    /** What the customer reads: none of the gateway's own reasons, which are the owner's. */
    public const UNAVAILABLE = 'درگاه پرداخت الان در دسترس نیست؛ کمی بعد دوباره امتحان کنید یا روش دیگری را انتخاب کنید.';

    public function __construct(
        public readonly string $detail,
        ?\Throwable $previous = null,
        public readonly bool $held = false,
        public readonly ?GatewayRefusal $refusal = null,
    ) {
        parent::__construct(self::UNAVAILABLE, 0, $previous);
    }
}
