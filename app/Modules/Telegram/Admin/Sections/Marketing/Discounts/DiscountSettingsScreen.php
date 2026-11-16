<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Discounts;

use App\Modules\Telegram\Admin\Sections\Bot\Settings\BotCard;
use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsSource;

/**
 * «⚙️ تنظیمات تخفیف‌ها» (the panel's «کدهای تخفیف › تنظیمات»), reached from the codes' list: how long an unpaid order
 * made with a code holds the code's use (`discounts`), and whether those who pay agent prices go without the customers'
 * perks (`agent_perks`) — worded for the main shop, or for an agent's, which has no agents and no custom builder. Each
 * field is saved with its own group (GroupsSource); the bounds and the value a number comes with are the panel's own
 * (BotCard::described()).
 */
final class DiscountSettingsScreen extends BotCard
{
    public const ID = 'dcs';

    public const TITLE = Icons::SETTINGS . ' تنظیمات تخفیف‌ها';

    protected function group(): string
    {
        return 'discounts';
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return self::TITLE;
    }

    protected function path(): array
    {
        return Marketing::in(Marketing::DISCOUNTS);
    }

    protected function parent(): string
    {
        return DiscountCodesScreen::ID;
    }

    protected function intro(): string
    {
        // The main shop's perks card speaks of agents; an agent's shop has none.
        $main = $this->here?->seat->shop()->isMain() ?? true;
        $who = $main ? 'نماینده‌ها و کاربرهای گروه‌های «همکار»' : 'کاربرهای گروه‌های «همکار»';
        $regular = $main ? 'پلن بدون قیمت نماینده، حجم، زمان و کاربر اضافه، سرویس دلخواه' : 'پلن بدون قیمت نماینده، حجم، زمان و کاربر اضافه';

        return '<b>⏳ مهلت پرداخت با کد تخفیف</b>: سفارشی که با کد تخفیف ثبت شده و پرداخت نشده، یکی از دفعات استفاده از کد را نگه می‌دارد؛ اگر تا این مهلت پرداخت نشود لغو می‌شود و سهم کد برای بقیه آزاد می‌شود.' . "\n\n"
            . '<b>🤝 همکارها</b>: ' . $who . ' قیمت نماینده پلن‌ها را می‌پردازند. روشن که باشد، فروش ویژه، سطح وفاداری، کد تخفیف، قیمت خرید اول، کش‌بک، گردونه شانس و قرعه‌کشی شبانه به آن‌ها نمی‌رسد، هر چه بخرند؛ جایی که قیمت نماینده‌ای نیست (' . $regular . ') قیمت معمولی را می‌پردازند. تخفیف اختصاصی‌ای که خودتان به یک نفر داده‌اید می‌ماند.';
    }

    protected function fields(): array
    {
        return $this->described([
            FieldDef::number('hold_minutes', 'مهلت پرداخت با کد تخفیف', 'دقیقه', 'سفارش پرداخت‌نشده با کد، این‌قدر بعد از آخرین تغییرش لغو می‌شود؛ سفارشی که رسیدش در دست بررسی است لغو نمی‌شود. صفر یعنی مثل هر سفارش پرداخت‌نشده دیگر، که به‌طور پیش‌فرض بعد از دو روز لغو می‌شود؛ آن مهلت را مالک فروشگاه در «تنظیمات پنل › زمان‌بندی کارها» تعیین می‌کند.')->example('۳۰'),
            FieldDef::toggle('agents_without_perks', 'همکارها فقط با قیمت همکاری بخرند'),
        ]);
    }

    protected function shows(FieldDef $field, mixed $value): ?string
    {
        return $field->name === 'hold_minutes' && (int) $value === 0 ? 'جدا ندارد؛ مثل هر سفارش پرداخت‌نشده' : null;
    }

    protected function source(): SettingsSource
    {
        return new GroupsSource(['discounts', 'agent_perks'], $this->settings);
    }
}
