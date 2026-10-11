<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/** The shop's address is not HTTPS, the only kind Telegram calls a webhook at: nothing is registered. */
final class WebhookAddressException extends DomainRuleException
{
    public function __construct()
    {
        parent::__construct('تلگرام Webhook را فقط روی آدرس https می‌پذیرد؛ آدرس فروشگاه (APP_URL) را در «تنظیمات پنل ← برنامه» با https وارد کنید.');
    }
}
