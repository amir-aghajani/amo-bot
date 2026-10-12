<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Modules\Auth\Actor;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Contracts\RefundedOrders;
use App\Modules\Payments\Exceptions\RefundRefusedException;
use App\Modules\Providers\Exceptions\PanelFailedException;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * A refund takes back what its delivered order gave a service, before the money goes back, without a message of its own
 * (the refund's says it):
 * - a purchase takes its service with it — the client off its panel and the service off the shop, through the services
 *   screen's own delete (SubscriptionActions: its rules for who deletes and how often) — refused while another order of
 *   the service is paid or still open (a renewal, an extra: removing the service would take what it paid for);
 * - a renewal or an extra takes back its term and traffic (ProvisioningService::undo(), once) — refused while the
 *   service is switched off by support (changing it on its panel would switch it on).
 * Refused too while the panel fails. A refusal leaves everything as it was, the money unrefunded; a service already gone
 * has nothing to take back.
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

        $purchase = $order->type === OrderType::Purchase;
        // Said before the panel-work limit and the service's hold are spent on it; undo() judges it again under the hold.
        if (!$purchase && $order->taken_back_at === null && $service->status === SubscriptionStatus::Disabled) {
            throw RefundRefusedException::serviceDisabled();
        }
        try {
            if ($purchase) {
                return $this->remove($order, $service, $actor);
            }

            return $this->actions->takeBack($order, $service, $actor) ? $service->remote_name : null;
        } catch (PanelFailedException $e) {
            throw RefundRefusedException::servicePanel($e->getMessage(), $purchase);
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
