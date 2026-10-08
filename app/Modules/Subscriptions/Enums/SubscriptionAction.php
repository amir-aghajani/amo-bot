<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Enums;

/** What the subscriptions screen's modal can do with a service (Services\SubscriptionActions::allowed()). */
enum SubscriptionAction: string
{
    case Sync = 'sync';
    case Disable = 'disable';
    case Enable = 'enable';
    case Move = 'move';
    case Delete = 'delete';
    case Extend = 'extend';
}
