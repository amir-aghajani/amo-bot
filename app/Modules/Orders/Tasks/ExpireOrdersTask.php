<?php

declare(strict_types=1);

namespace App\Modules\Orders\Tasks;

use App\Core\Scheduling\Task;
use App\Modules\Discounts\Services\DiscountSettings;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\RequestKey;
use App\Modules\Payments\Services\PaymentService;

/**
 * Drops unpaid orders nobody touched for EXPIRE_HOURS, quietly (PaymentService::expire()); an order made with a discount
 * code goes sooner, once the code's hold is over (DiscountSettings::holdMinutes()), which frees its use. An order whose
 * money may be with support — a receipt in review, a card transfer taken in support's private chat (Order::expirable()),
 * an online payment held for support — is never dropped: support decides it; nor one whose online gateway's page still
 * takes money (PaymentService::expire()). Also forgets request keys older than RequestKey::KEEP_DAYS. Runs every
 * INTERVAL_MINUTES in every shop.
 */
final class ExpireOrdersTask implements Task
{
    public const EXPIRE_HOURS = 48;
    public const INTERVAL_MINUTES = 10;

    /** `orders.notes` of an order made with a code, dropped as the code's hold ran out. */
    public const NOTE_CODE_HOLD = 'مهلت استفاده از کد تخفیف تمام شد';

    public function __construct(
        private readonly PaymentService $payments,
        private readonly DiscountSettings $discounts,
    ) {}

    public function run(): void
    {
        $idleSince = now()->subHours(self::EXPIRE_HOURS);

        foreach (Order::expirable()->where('updated_at', '<=', $idleSince)->get() as $order) {
            $this->payments->expire($order, $idleSince);
        }

        $hold = $this->discounts->holdMinutes();
        if ($hold > 0) {
            $heldSince = now()->subMinutes($hold);
            foreach (Order::expirable()->whereNotNull('discount_code_id')->where('updated_at', '<=', $heldSince)->get() as $order) {
                $this->payments->expire($order, $heldSince, self::NOTE_CODE_HOLD);
            }
        }

        RequestKey::query()->where('created_at', '<', now()->subDays(RequestKey::KEEP_DAYS))->delete();
    }
}
