<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

/**
 * A delivery that found its order taken over as it went to call a panel: it went quiet past
 * Services\OrderService::STALE_PROCESSING_MINUTES (a call that outlasted every timeout, a process paused), and another
 * process claimed the order meanwhile. It stops there, before any further call: the order is that process's to fulfil
 * or fail, so this one neither fails it nor gives back what it drew.
 */
final class DeliveryTakenOverException extends \RuntimeException
{
    public function __construct(int $order)
    {
        parent::__construct("Order #{$order} was taken over by another delivery while this one was at work.");
    }
}
