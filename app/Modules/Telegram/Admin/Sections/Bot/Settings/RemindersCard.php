<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Settings;

use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;

/** «یادآوری» (`reminders`): a service ending soon, its traffic running low — each number shown while its reminder is on. */
final class RemindersCard extends BotCard
{
    public const ID = 'bsm';

    protected function group(): string
    {
        return 'reminders';
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '⏰ یادآوری به مشتری';
    }

    protected function intro(): string
    {
        return 'مشتری در تلگرام یا با ایمیل خبردار می‌شود که سرویسش رو به پایان است یا حجمش دارد تمام می‌شود؛ هر یادآوری یک بار، و بعد از تمدید یا افزایش حجم دوباره. اطلاعات سرویس‌ها به‌طور پیش‌فرض هر ۱۵ دقیقه از پنل‌ها خوانده می‌شود؛ این فاصله را مالک فروشگاه در «تنظیمات پنل › زمان‌بندی کارها» تعیین می‌کند.';
    }

    protected function fields(): array
    {
        return [
            FieldDef::toggle('expiry_reminder', 'یادآوری نزدیک شدن تاریخ پایان', 'سرویسی که تمدید خودکارش روشن است این یادآوری را نمی‌گیرد؛ تمدید خودکار خودش خبر می‌دهد.'),
            FieldDef::number('expiry_reminder_days', 'چند روز پیش از پایان سرویس', 'روز', 'بین ۱ تا ۳۰ روز.')->when('expiry_reminder', [true]),
            FieldDef::toggle('traffic_reminder', 'یادآوری تمام شدن حجم', 'سرویس‌های با حجم نامحدود این یادآوری را نمی‌گیرند.'),
            FieldDef::number('traffic_reminder_percent', 'بعد از مصرف چند درصد حجم', 'درصد', 'بین ۵۰ تا ۹۹ درصد.')->when('traffic_reminder', [true]),
        ];
    }
}
