<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Enums;

/** Where a service stands, as the shop last saw it on its panel. */
enum SubscriptionStatus: string
{
    case Active = 'active';
    /** Its time or its traffic ran out; a renewal (or an extension on the panel) makes it run again. */
    case Expired = 'expired';
    /** Switched off by support; switched back on the same way. */
    case Disabled = 'disabled';
    /** Its panel no longer has the client (a sync or a screen found out); only deleting the row is left. */
    case Deleted = 'deleted';
}
