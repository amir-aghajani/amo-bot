<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/** How a gateway driver settles a payment. */
enum GatewayKind: string
{
    /** The moment the customer confirms: the wallet. */
    case Instant = 'instant';
    /** The customer pays outside the shop and a person — or the method's review window — accepts the receipt: a card transfer. */
    case Manual = 'manual';
}
