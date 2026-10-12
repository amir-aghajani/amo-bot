<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Auth\Actor;
use App\Modules\Orders\Models\Order;

/**
 * What a refund takes back of what its delivered order gave a service, before the money goes back: a purchase's service,
 * a renewal's or an extra's term and traffic. Payments owns this interface and Subscriptions' RefundedDeliveries
 * implements it, so the dependency runs one way — Payments names no service (LayersTest holds it). A refusal, worded for
 * whoever refunds, leaves the refund undone.
 */
interface RefundedOrders
{
    /**
     * Take back what the delivered order gave, as `$actor`; returns the panel name of the service it was taken from (the
     * purchase's, removed), or null when there was nothing to take back.
     *
     * @throws DomainRuleException worded for the reader: nothing taken back, nothing to refund yet
     */
    public function takeBack(Order $order, Actor $actor): ?string;
}
