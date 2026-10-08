<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * The shop's address is not one Telegram calls a webhook at: Telegram takes only HTTPS. Nothing is registered then —
 * the owner puts an https APP_URL in the panel's settings (or the operator passes bot:webhook:set --url).
 */
final class WebhookAddressException extends DomainRuleException
{
    public function __construct()
    {
        parent::__construct('تلگرام Webhook را فقط روی آدرس https می‌پذیرد؛ آدرس فروشگاه (APP_URL) را در «تنظیمات پنل ← برنامه» با https وارد کنید.');
    }
}
