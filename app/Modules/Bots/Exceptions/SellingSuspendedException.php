<?php

declare(strict_types=1);

namespace App\Modules\Bots\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Core\Exceptions\ValidationException;
use App\Modules\Bots\Models\Bot;

/**
 * An agent's shop sells nothing new now (Bot::sells()): a purchase, a renewal, an extra, a wallet top-up, a free test or
 * a gift refused before anything was ordered or drawn. The message is the customer's (a 422); the bot says it in its own
 * text, and its staff read STAFF (the owner OWNER).
 */
final class SellingSuspendedException extends DomainRuleException
{
    public const MESSAGE = 'فروش این فروشگاه فعلا متوقف است؛ سرویس‌های فعلی شما مثل قبل کار می‌کنند. برای اطلاعات بیشتر با پشتیبانی در تماس باشید.';

    /**
     * The shop's staff — the agent's panel, its website's admins — told why nothing new is sold: a plan not offered
     * (Catalog's ServerSelector), an order made by hand or an extension refused.
     */
    public const STAFF = 'فروش این فروشگاه متوقف است؛ دلیلش در «حساب نمایندگی» آمده است.';

    /** The owner, refused an order by hand in the agent's shop: they open its selling again first. */
    public const OWNER = 'فروش این نماینده متوقف است و سفارشی در فروشگاهش ثبت نمی‌شود؛ اول از صفحه «نمایندگان» فروشش را باز کنید.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    /** @throws self while the shop's selling is suspended */
    public static function unlessSelling(Bot $shop): void
    {
        if (!$shop->sells()) {
            throw new self();
        }
    }

    /** The refusal under the `status` field of a website's request about a service (an extra's quote, a renewal): a 422. */
    public static function onStatus(): ValidationException
    {
        return ValidationException::on('status', self::MESSAGE);
    }
}
