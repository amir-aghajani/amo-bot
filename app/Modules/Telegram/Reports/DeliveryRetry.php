<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Services\Reviewers;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderActions;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Telegram\Update\GroupHandler;
use App\Modules\Telegram\Update\Update;
use Psr\Log\LoggerInterface;

/**
 * «🔁 تلاش دوباره برای تحویل» under a failed delivery, for bot admins only: the orders screen's retry
 * (OrderActions::retry()). The outcome reaches the group as reports do; a repeated failure keeps the button for another try.
 */
final class DeliveryRetry implements GroupHandler
{
    /** What the button's callback data starts with: `rt:<order id>`. */
    public const PREFIX = 'rt:';

    private const LABEL = '🔁 تلاش دوباره برای تحویل';
    private const NOT_FOUND = 'این سفارش پیدا نشد.';
    private const DELIVERED = '✅ سفارش تحویل شد.';

    /** The whole reason is in the new failure report; the popup is cut at 200 characters. */
    private const FAILED_AGAIN = 'تحویل باز هم ناموفق بود. علت: %s';

    public function __construct(
        private readonly GroupButtons $groupButtons,
        private readonly OrderActions $orders,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return list<list<array<string, mixed>>> The one button under a failed delivery. */
    public static function buttons(int $orderId): array
    {
        return InlineKeyboard::make()->row(InlineKeyboard::callback(self::LABEL, CallbackData::build(self::PREFIX, $orderId), 'primary'))->build()['inline_keyboard'];
    }

    /** A press on the button. */
    public function handle(Update $update): void
    {
        $args = $update->callbackArgs(self::PREFIX) ?? [];
        if (count($args) !== 1 || !ctype_digit($args[0])) {
            $this->groupButtons->answer($update);

            return;
        }

        $admin = $this->groupButtons->presser($update, GroupButtons::onlyAdmins('تحویل را دوباره امتحان کنند'));
        if ($admin === null) {
            return;
        }

        $order = Order::query()->with(['payments', 'user'])->find((int) $args[0]);
        if ($order === null) {
            $this->groupButtons->dismiss($update, self::NOT_FOUND);

            return;
        }

        try {
            $this->orders->retry($order);
        } catch (ValidationException $e) {
            // Delivered meanwhile, refunded, or being delivered elsewhere right now: nothing to retry here.
            $this->groupButtons->dismiss($update, $e->getMessage());

            return;
        }

        $this->logger->info('Order {id} retried from the report group by {admin}: {status}', ['id' => $order->id, 'admin' => Reviewers::forAdmin($admin), 'status' => $order->status->value]);
        if ($order->status !== OrderStatus::Fulfilled) {
            $this->groupButtons->answer($update, sprintf(self::FAILED_AGAIN, trim((string) ShopReports::failure($order))), alert: true);

            return;
        }
        $this->groupButtons->buttons($update, null);
        $this->groupButtons->answer($update, self::DELIVERED);
    }
}
