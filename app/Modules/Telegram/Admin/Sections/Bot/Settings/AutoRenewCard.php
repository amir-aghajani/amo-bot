<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Settings;

use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;

/** «تمدید خودکار» (`auto_renew`, under «تمدید سرویس»): how many days before its end a service is renewed from the wallet, and how new services start. */
final class AutoRenewCard extends BotCard
{
    public const ID = 'bsa';

    protected function group(): string
    {
        return 'auto_renew';
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '⏱ تمدید خودکار';
    }

    protected function path(): array
    {
        return [...parent::path(), 'تمدید سرویس'];
    }

    protected function parent(): string
    {
        return RenewalCard::ID;
    }

    protected function intro(): string
    {
        return 'مشتری می‌تواند تمدید خودکار هر سرویسش را از صفحه همان سرویس در ربات روشن کند: چند روز پیش از پایان سرویس، قیمت پلن از کیف پولش کسر و سرویس تمدید می‌شود. اگر موجودی کافی نباشد، یک بار در تلگرام یا با ایمیل خبردار می‌شود.';
    }

    protected function fields(): array
    {
        return [
            FieldDef::number('auto_renew_days', 'چند روز پیش از پایان سرویس', 'روز', 'بین ۱ تا ۳۰ روز.'),
            FieldDef::toggle('auto_renew_default', 'برای سرویس‌های جدید روشن باشد', 'روشن که باشد، هر سرویسی که از این به بعد خریده می‌شود با تمدید خودکار روشن شروع می‌شود و مشتری می‌تواند آن را خاموش کند. روی سرویس‌های فعلی اثری ندارد.'),
        ];
    }
}
