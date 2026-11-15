<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Discounts;

use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Marketing\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsScreen;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsSource;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «⚙️ تنظیمات تخفیف‌ها» (the panel's «کدهای تخفیف › تنظیمات»): how long an unpaid order made with a code holds the code's
 * use (`discounts`), and whether those who pay agent prices go without the customers' perks (`agent_perks`) — worded for
 * the main shop, or for an agent's, which has no agents and no custom builder.
 */
final class DiscountSettingsScreen extends SettingsScreen
{
    public const ID = 'dcs';

    /** Whether the shop worked is the main one: the perks card speaks of agents there. */
    private bool $main = true;

    public function __construct(private readonly BotSettingsScreen $settings) {}

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $this->main = $admin->seat->shop()->isMain();

        return parent::show($admin, $args);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $this->main = $admin->seat->shop()->isMain();

        return parent::act($admin, $action, $args);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $this->main = $admin->seat->shop()->isMain();

        return parent::input($admin, $action, $args, $answer);
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '⚙️ تنظیمات تخفیف‌ها';
    }

    protected function path(): array
    {
        return [...Words::PATH, 'کدهای تخفیف'];
    }

    protected function parent(): string
    {
        return DiscountCodesScreen::ID;
    }

    protected function intro(): string
    {
        $who = $this->main ? 'نماینده‌ها و کاربرهای گروه‌های «همکار»' : 'کاربرهای گروه‌های «همکار»';
        $regular = $this->main ? 'پلن بدون قیمت نماینده، حجم، زمان و کاربر اضافه، سرویس دلخواه' : 'پلن بدون قیمت نماینده، حجم، زمان و کاربر اضافه';

        return '⏳ <b>مهلت پرداخت با کد تخفیف</b>: سفارشی که با کد تخفیف ثبت شده و پرداخت نشده، یکی از دفعات استفاده از کد را نگه می‌دارد؛ اگر تا این مهلت پرداخت نشود لغو می‌شود و سهم کد برای بقیه آزاد می‌شود.' . "\n"
            . '🤝 <b>همکارها</b>: ' . $who . ' قیمت نماینده پلن‌ها را می‌پردازند. روشن که باشد، فروش ویژه، سطح وفاداری، کد تخفیف، قیمت خرید اول، کش‌بک، گردونه شانس و قرعه‌کشی شبانه به آن‌ها نمی‌رسد، هر چه بخرند؛ جایی که قیمت نماینده‌ای نیست (' . $regular . ') قیمت معمولی را می‌پردازند. تخفیف اختصاصی‌ای که خودتان به یک نفر داده‌اید می‌ماند.';
    }

    protected function fields(): array
    {
        return [
            FieldDef::number('hold_minutes', 'مهلت پرداخت با کد تخفیف', 'دقیقه', 'بین ۰ تا ۱۴۴۰ دقیقه. سفارش پرداخت‌نشده با کد، این‌قدر بعد از آخرین تغییرش لغو می‌شود؛ سفارشی که رسیدش در دست بررسی است لغو نمی‌شود. صفر یعنی سفارش با کد هم مثل هر سفارش پرداخت‌نشده دیگر، به‌طور پیش‌فرض بعد از دو روز لغو می‌شود؛ این مهلت را مالک فروشگاه در «تنظیمات پنل › زمان‌بندی کارها» تعیین می‌کند.'),
            FieldDef::toggle('agents_without_perks', 'همکارها فقط با قیمت همکاری بخرند'),
        ];
    }

    protected function source(): SettingsSource
    {
        return new GroupsSource(['discounts', 'agent_perks'], $this->settings);
    }
}
