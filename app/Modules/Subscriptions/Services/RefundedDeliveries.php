<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Modules\Auth\Actor;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\ServiceChangedHandsException;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Contracts\RefundedOrders;
use App\Modules\Payments\Exceptions\RefundRefusedException;
use App\Modules\Providers\Exceptions\PanelFailedException;
use App\Modules\Subscriptions\Models\Subscription;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * A refund takes back what its delivered order gave a service, before the money goes back, without a message of its own
 * (the refund's says it):
 * - a purchase takes its service with it — the client off its panel and the service off the shop, through the services
 *   screen's own delete (SubscriptionActions: its rules for who deletes and how often) — refused while another order of
 *   the service is paid or still open (a renewal, an extra: removing the service would take what it paid for);
 * - a renewal or an extra takes back its term, traffic and devices (ProvisioningService::undo(), once), its client left
 *   switched on or off as it is — named in the refund's message only when something was taken: nothing is once it is no
 *   longer on the service (devices gone with their period, a part a later renewal started afresh).
 * Refused while the panel fails, and for a service handed to another customer since (a transfer: nothing of theirs
 * goes for the buyer's refund; an account merged into another moves its orders with its services, so stays the
 * buyer's). A refusal leaves everything as it was, the money unrefunded; a service already gone has nothing to take
 * back.
 */
final class RefundedDeliveries implements RefundedOrders
{
    /** Another order of the service that holds it: paid and not given back, or still open. */
    private const HOLDING = [OrderStatus::Pending->value, ...Order::KEPT];

    public function __construct(private readonly SubscriptionActions $actions) {}

    public function takeBack(Order $order, Actor $actor): ?string
    {
        $service = $order->subscription_id !== null && $order->type->forThemselves() ? Subscription::query()->find($order->subscription_id) : null;
        if ($service === null) {
            return null;
        }
        if ((int) $service->user_id !== (int) $order->user_id) {
            throw RefundRefusedException::serviceChangedHands();
        }

        $purchase = $order->type === OrderType::Purchase;
        try {
            if ($purchase) {
                return $this->remove($order, $service, $actor);
            }

            return $this->actions->takeBack($order, $service, $actor) ? $service->remote_name : null;
        } catch (PanelFailedException $e) {
            throw RefundRefusedException::servicePanel($e->getMessage(), $purchase);
        } catch (ServiceChangedHandsException) {
            // Handed on while the refund waited for the service.
            throw RefundRefusedException::serviceChangedHands();
        } catch (ModelNotFoundException) {
            return null; // Deleted meanwhile.
        }
    }

    /** The purchase's service removed; its panel name. */
    private function remove(Order $order, Subscription $service, Actor $actor): string
    {
        $other = Order::query()->where('subscription_id', $service->id)->whereKeyNot($order->id)->whereIn('status', self::HOLDING)->orderBy('id')->value('id');
        if ($other !== null) {
            throw RefundRefusedException::serviceInUse((int) $other);
        }
        $this->actions->delete($service, $actor, null, leavePanel: false, notify: false);

        return $service->remote_name;
    }
}
