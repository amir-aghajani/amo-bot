<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsScreen;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsSource;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «تنظیمات سرویس دلخواه» (the bot settings' `custom_services` group, the main shop's alone, reached from «🛠 سرویس
 * دلخواه» alone): the switch on the card, and the panel's three parts each a screen of its own — each quantity's bounds
 * and the amounts offered as buttons, the devices of a built service and the servers it is built on, ticked among the
 * shop's; the tiers (CustomTiersScreen) and the calculator (CustomCalculatorScreen) beside them. Field places are its
 * buttons' data (`e:<n>`): a new field goes last.
 */
final class CustomSettingsScreen extends SettingsScreen
{
    public const ID = 'css';

    public const TITLE = Icons::SETTINGS . ' تنظیمات سرویس دلخواه';

    /**
     * @var array<string, string> The shop's servers by id, to tick: read with each request
     */
    private array $servers = [];

    public function __construct(private readonly BotSettingsScreen $settings) {}

    public function allows(AdminSeat $seat): bool
    {
        return CustomServicesScreen::opens($seat);
    }

    public function show(Admin $admin, array $args): View
    {
        $this->servers($admin);

        return parent::show($admin, $args);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $this->servers($admin);

        return parent::act($admin, $action, $args);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $this->servers($admin);

        return parent::input($admin, $action, $args, $answer);
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
        return CatalogFmt::under(Fmt::bare(CustomServicesScreen::TITLE));
    }

    /** The panel's parts of the card: the traffic, the days, the servers and devices. */
    protected function parts(): array
    {
        return [
            [CustomPricing::QUANTITIES['traffic']['title'], ['custom_traffic_min', 'custom_traffic_max', 'custom_traffic_options']],
            [CustomPricing::QUANTITIES['days']['title'], ['custom_days_min', 'custom_days_max', 'custom_days_options']],
            [Icons::SERVERS . ' سرور و کاربر', ['custom_devices', 'custom_servers']],
        ];
    }

    protected function parent(): string
    {
        return CustomServicesScreen::ID;
    }

    protected function intro(): string
    {
        return 'مشتری حجم و مدت سرویسش را خودش انتخاب می‌کند و قیمت هر کدام با پله‌های قیمت آن حساب می‌شود؛ قیمت حجم و قیمت مدت با هم جمع می‌شوند و تخفیف‌های مشتری از آن کم می‌شود.';
    }

    protected function fields(): array
    {
        $options = static fn(string $examples): string => "دکمه‌هایی که مشتری می‌بیند، مثل «{$examples}»؛ مشتری عدد دیگری بین کمترین و بیشترین را هم می‌نویسد. خالی یعنی مشتری عدد را خودش می‌نویسد.";

        return [
            FieldDef::toggle('custom_enabled', 'سرویس دلخواه فعال باشد', 'روشن که باشد، در ربات زیر پلن‌ها دکمه «ساخت سرویس دلخواه» می‌آید و وب‌سایت هم آن را می‌فروشد — تا وقتی هر دو قیمت پله دارند و یکی از سرورهای انتخاب‌شده مشتری تازه می‌گیرد.'),
            FieldDef::number('custom_traffic_min', 'کمترین حجم', 'گیگابایت', 'کمترین گیگابایتی که مشتری می‌سازد؛ هیچ‌وقت کمتر از شروع اولین پله نیست.')->example('۱'),
            FieldDef::number('custom_traffic_max', 'بیشترین حجم', 'گیگابایت', 'بیشترین گیگابایتی که مشتری می‌سازد.')->limits('نه کمتر از کمترین')->example('۵۰۰'),
            FieldDef::list('custom_traffic_options', 'حجم‌های پیشنهادی', 'گیگابایت', $options('۱۰ ۵۰ ۱۰۰'))->nullable()->limits('حداکثر ۸ دکمه'),
            FieldDef::number('custom_days_min', 'کمترین زمان', 'روز', 'کمترین روزی که مشتری می‌سازد؛ هیچ‌وقت کمتر از شروع اولین پله نیست.')->example('۱'),
            FieldDef::number('custom_days_max', 'بیشترین زمان', 'روز', 'بیشترین روزی که مشتری می‌سازد.')->limits('نه کمتر از کمترین')->example('۹۰'),
            FieldDef::list('custom_days_options', 'زمان‌های پیشنهادی', 'روز', $options('۳۰ ۶۰ ۹۰'))->nullable()->limits('حداکثر ۸ دکمه'),
            FieldDef::number('custom_devices', 'تعداد کاربر هم‌زمان', 'کاربر', '۰ یعنی نامحدود.')->limits('از ۰ تا ۱۰۰')->example('۲'),
            FieldDef::multi('custom_servers', 'سرورها', $this->servers, 'مشتری روی یکی از این سرورها سرویس می‌سازد؛ سروری که الان نمی‌فروشد (خاموش، پر یا بدون لینک اشتراک) به مشتری نشان داده نمی‌شود. سرویس روی همه اینباندهای قابل فروش سرور ساخته می‌شود.'),
        ];
    }

    protected function source(): SettingsSource
    {
        return new BotGroupSource('custom_services', $this->settings);
    }

    protected function extra(View $view, Admin $admin, array $values): void
    {
        $view->row(...CustomTiersScreen::buttons(self::ID))
            ->row(Button::to(CustomCalculatorScreen::TITLE, CustomCalculatorScreen::ID, Nav::SHOW, self::ID));
    }

    /** The shop's servers to tick, each marked when it cannot sell now. */
    private function servers(Admin $admin): void
    {
        $this->servers = [];
        foreach (PlanEditScreen::options($admin)['servers'] as $server) {
            $this->servers[(string) $server['id']] = (string) $server['name'] . (is_string($server['unsellable_reason'] ?? null) ? ' (الان نمی‌فروشد)' : '');
        }
    }
}
