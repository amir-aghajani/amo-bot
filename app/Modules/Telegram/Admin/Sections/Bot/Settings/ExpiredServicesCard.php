<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Settings;

use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;

/**
 * «سرویس‌های منقضی‌شده» (`expired_services`): whether an ended service is removed from its panel and the shop by itself,
 * and how many hours after its end — the hours shown while the removal is on.
 */
final class ExpiredServicesCard extends BotCard
{
    public const ID = 'bse';

    protected function group(): string
    {
        return 'expired_services';
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '🗑 حذف سرویس‌های منقضی‌شده';
    }

    protected function intro(): string
    {
        return 'سرویسی که زمان یا حجمش تمام شده، یا دیگر روی پنلش نیست، منقضی‌شده است. با حذف خودکار، چنین سرویسی بعد از فاصله‌ای که تعیین می‌کنید حذف می‌شود: کلاینت از پنل و سرویس از فروشگاه. به مشتری پیامی فرستاده نمی‌شود و سفارش‌هایش در سوابق می‌ماند؛ گروه گزارش‌ها در تاپیک «حذف سرویس‌ها» خبردار می‌شود.';
    }

    protected function fields(): array
    {
        return [
            FieldDef::toggle('expired_cleanup', 'حذف خودکار سرویس‌های منقضی‌شده', 'سرویس‌ها به‌طور پیش‌فرض هر ۱۵ دقیقه بررسی می‌شوند؛ این فاصله را مالک فروشگاه در «تنظیمات پنل › زمان‌بندی کارها» تعیین می‌کند. خاموش، سرویس‌های منقضی‌شده می‌مانند تا مشتری تمدیدشان کند یا خودتان حذفشان کنید.'),
            FieldDef::number('expired_cleanup_hours', 'فاصله حذف', 'ساعت', 'بین ۰ تا ۸۷۶۰ ساعت؛ ۰ یعنی همان وقت که تمام شد.')->when('expired_cleanup', [true]),
        ];
    }

    protected function notes(): array
    {
        return ['سرویسی که در این فاصله تمدید شود، سرویسی که پشتیبانی غیرفعالش کرده و سرویسی که سفارشش هنوز باز است (مثلا تمدیدی که رسیدش منتظر بررسی است) حذف نمی‌شود. مشتری در ربات و سایت می‌بیند سرویس تمام‌شده‌اش کی حذف می‌شود. اکانت‌های تست رایگان قانون خودشان را دارند: اکانت تست › تنظیمات.'];
    }

    protected function explain(FieldDef $field, array $values): ?string
    {
        if ($field->name !== 'expired_cleanup_hours') {
            return null;
        }
        $hours = (int) ($values['expired_cleanup_hours'] ?? 0);

        return $hours === 0 ? 'همان وقت که تمام شد حذف می‌شود.' : Fmt::plain(Fmt::hours($hours)) . ' بعد از پایان حذف می‌شود.';
    }
}
