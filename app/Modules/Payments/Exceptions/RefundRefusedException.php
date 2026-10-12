<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Telegram\Messages;
use App\Support\Money;

/**
 * A refund that must take back what the order added — a top-up's credit, an agent's traffic, a purchase's service — and
 * cannot: spent or sold since, held by another order, or on a panel that failed. Nothing was written (or refunded); the
 * message says why and what to do.
 */
final class RefundRefusedException extends DomainRuleException
{
    /** The purchase's service carries another order (a renewal, an extra) that is paid or still open. */
    public static function serviceInUse(int $orderId): self
    {
        return new self(sprintf('سرویس این خرید سفارش دیگری هم دارد (سفارش #%d)؛ اول آن سفارش را بازپرداخت یا لغو کنید، یا سرویس را خودتان از صفحه اشتراک‌ها حذف کنید.', $orderId));
    }

    /** The service a renewal or an extra was for is switched off: changing it on its panel would switch it on. */
    public static function serviceDisabled(): self
    {
        return new self('سرویس این سفارش غیرفعال است و چیزی از آن پس گرفته نمی‌شود؛ اول آن را فعال یا حذف کنید، بعد بازپرداخت کنید.');
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

    public static function trafficSold(int $left): self
    {
        return new self(sprintf('حجم این خرید فروخته شده است: حجم باقی‌مانده نماینده %s است و حجم این سفارش از آن کم نمی‌شود.', Messages::bytes($left)));
    }
}
