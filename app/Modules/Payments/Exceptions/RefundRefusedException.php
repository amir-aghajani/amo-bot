<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Telegram\Messages;
use App\Support\Money;

/**
 * A refund that must take back what the order added — a top-up's credit and its referrer's commission, an agent's
 * traffic, a purchase's service — and cannot: spent or sold since, held by another order, handed to another customer, or
 * on a panel that failed. Nothing was written (or refunded); the message says why and what to do.
 */
final class RefundRefusedException extends DomainRuleException
{
    /**
     * The purchase's service carries another order (a renewal, an extra) that is paid or still open: what to do with it
     * first, by its status. Only once that order is delivered may support delete the service by hand instead — the
     * services screen refuses while an order of it is open (Subscriptions\Services\SubscriptionActions::delete()).
     */
    public static function serviceInUse(Order $other): self
    {
        return new self(sprintf(match ($other->status) {
            OrderStatus::Pending => 'سرویس این خرید سفارش پرداخت‌نشده دیگری دارد (سفارش #%d)؛ اول آن سفارش را لغو کنید، بعد دوباره بازپرداخت کنید.',
            OrderStatus::Paid, OrderStatus::Processing => 'سفارش دیگری از سرویس این خرید در حال تحویل است (سفارش #%d)؛ وقتی تحویل شد، اول آن را بازپرداخت کنید، بعد این خرید را.',
            OrderStatus::Failed => 'تحویل سفارش دیگری از سرویس این خرید ناموفق مانده است (سفارش #%d)؛ اول آن سفارش را بازپرداخت کنید، بعد این خرید را.',
            default => 'سرویس این خرید سفارش دیگری هم دارد (سفارش #%d)؛ اول آن سفارش را بازپرداخت کنید، یا سرویس را خودتان از صفحه اشتراک‌ها حذف کنید و بعد دوباره بازپرداخت کنید.',
        }, $other->id));
    }

    /**
     * The order's service was handed to another customer since (a transfer): nothing of theirs goes for the buyer's
     * refund. Support takes it from them by hand — deletes it, or hands it back — and refunds again, or gives the money
     * back by the wallet.
     */
    public static function serviceChangedHands(): self
    {
        return new self('سرویس این سفارش به مشتری دیگری منتقل شده است و چیزی بازپرداخت نشد؛ سرویس را در صفحه اشتراک‌ها حذف کنید یا به همین مشتری برگردانید و دوباره بازپرداخت کنید، یا مبلغ را دستی به کیف پول او اضافه کنید.');
    }

    /**
     * The order's service could not be changed on its panel (`$panel`: the panel's failure, worded for the reader). A
     * purchase's service may be deleted from the shop alone, then refunded; anything else waits for the panel.
     */
    public static function servicePanel(string $panel, bool $purchase): self
    {
        return new self(sprintf('سرویس این سفارش روی پنلش تغییر نکرد و چیزی بازپرداخت نشد: %s %s', rtrim($panel), $purchase
            ? 'اگر پنل در دسترس نیست، سرویس را در صفحه اشتراک‌ها با «حذف فقط از فروشگاه» حذف کنید و دوباره بازپرداخت کنید.'
            : 'وقتی پنل جواب داد، دوباره بازپرداخت کنید.'));
    }

    public static function topUpSpent(string $balance): self
    {
        return new self(sprintf('این شارژ خرج شده است: موجودی کیف پول مشتری %s است و مبلغ شارژ از آن کم نمی‌شود.', Money::format($balance)));
    }

    /** The commission the top-up earned the customer's referrer is spent: its refund takes it back, by the same rule. */
    public static function commissionSpent(string $balance): self
    {
        return new self(sprintf('پورسانتی که این شارژ برای معرف مشتری آورد خرج شده است: موجودی کیف پول معرف %s است و پورسانت از آن کم نمی‌شود.', Money::format($balance)));
    }

    public static function trafficSold(int $left): self
    {
        return new self(sprintf('حجم این خرید فروخته شده است: حجم باقی‌مانده نماینده %s است و حجم این سفارش از آن کم نمی‌شود.', Messages::bytes($left)));
    }
}
