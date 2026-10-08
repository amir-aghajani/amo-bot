<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Services\Reviewers;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderActions;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Telegram\Update\GroupHandler;
use App\Modules\Telegram\Update\Update;
use Psr\Log\LoggerInterface;

/**
 * «🔁 تلاش دوباره برای تحویل» under a failed delivery in the errors topic: a paid order whose delivery failed (a panel out
 * of reach, say) delivered again — by the bot's admins only (GroupButtons::admin()), the orders screen's retry
 * (OrderActions::retry(): its rules, the customer told only when it worked). What came of it reaches the group as
 * reports do: delivered, the sale's report and, under the failure, that it was — the button gone; failed again, a new
 * failure report with the button under the old one, which then loses its own (the button stays meanwhile, for another
 * try).
 */
final class DeliveryRetry implements GroupHandler
{
    /** What the button's callback data starts with: `rt:<order id>`. */
    public const PREFIX = 'rt:';

    private const LABEL = '🔁 تلاش دوباره برای تحویل';
    private const NOT_ADMIN = 'فقط مدیرهای ربات می‌توانند تحویل را دوباره امتحان کنند؛ نقش «مدیر ربات» در صفحه کاربران پنل داده می‌شود.';
    private const NOT_FOUND = 'این سفارش پیدا نشد.';
    private const DELIVERED = '✅ سفارش تحویل شد.';

    /** The whole reason is in the new failure report; the popup is cut at 200 characters. */
    private const FAILED_AGAIN = 'تحویل باز هم ناموفق بود. علت: %s';

    public function __construct(
        private readonly BotApi $api,
        private readonly GroupButtons $groupButtons,
        private readonly OrderActions $orders,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return list<list<array<string, mixed>>> The one button under a failed delivery. */
    public static function buttons(int $orderId): array
    {
        return InlineKeyboard::make()->row(InlineKeyboard::callback(self::LABEL, CallbackData::build(self::PREFIX, $orderId), 'primary'))->build()['inline_keyboard'];
    }

    /** A press on the button, from a group. */
    public function handle(Update $update): void
    {
        $callbackId = (string) $update->callbackId();
        $args = $update->callbackArgs(self::PREFIX) ?? [];
        if (count($args) !== 1 || !ctype_digit($args[0])) {
            $this->api->answerCallbackQuery($callbackId);

            return;
        }

        $admin = GroupButtons::admin($update->from());
        if ($admin === null) {
            $this->api->answerCallbackQuery($callbackId, self::NOT_ADMIN, alert: true);

            return;
        }

        $chatId = (int) $update->chatId();
        $messageId = (int) $update->messageId();
        $order = Order::query()->with(['payments', 'user'])->find((int) $args[0]);
        if ($order === null) {
            $this->groupButtons->set($chatId, $messageId, null);
            $this->api->answerCallbackQuery($callbackId, self::NOT_FOUND, alert: true);

            return;
        }

        try {
            $this->orders->retry($order);
        } catch (ValidationException $e) {
            // Delivered meanwhile, its money given back, or being delivered elsewhere this moment: nothing to retry here.
            $this->groupButtons->set($chatId, $messageId, null);
            $this->api->answerCallbackQuery($callbackId, $e->getMessage(), alert: true);

            return;
        }

        $this->logger->info('Order {id} retried from the report group by {admin}: {status}', ['id' => $order->id, 'admin' => Reviewers::forAdmin($admin), 'status' => $order->status->value]);
        if ($order->status !== OrderStatus::Fulfilled) {
            $this->api->answerCallbackQuery($callbackId, sprintf(self::FAILED_AGAIN, trim((string) ShopReports::failure($order))), alert: true);

            return;
        }
        $this->groupButtons->set($chatId, $messageId, null);
        $this->api->answerCallbackQuery($callbackId, self::DELIVERED);
    }
}
