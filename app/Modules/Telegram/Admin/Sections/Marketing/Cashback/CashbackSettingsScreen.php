<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Cashback;

use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Marketing\Words;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsScreen;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsSource;

/** «⚙️ تنظیمات کش‌بک» (the panel's card over the rules, `cashback`): whether delivered orders earn cashback at all. */
final class CashbackSettingsScreen extends SettingsScreen
{
    public const ID = 'cbs';

    public function __construct(private readonly BotSettingsScreen $settings) {}

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '⚙️ تنظیمات کش‌بک';
    }

    protected function path(): array
    {
        return [...Words::PATH, 'کش‌بک'];
    }

    protected function parent(): string
    {
        return CashbackRulesScreen::ID;
    }

    protected function intro(): string
    {
        return 'وقتی سفارشی تحویل شد، درصدی از مبلغی که مشتری پرداخت کرده به کیف پول او برمی‌گردد و در تلگرام یا با ایمیل خبردار می‌شود؛ با هر روش پرداختی، حتی کیف پول. اگر سفارش بعدا برگشت داده شود، کش‌بک آن از مبلغ برگشتی کم می‌شود.' . "\n"
            . 'درصد هر سفارش بالاترین قانون فعالی است که با آن جور است، به اضافه کش‌بک سطح وفاداری مشتری؛ حداکثر ۱۰۰ درصد. خاموش که باشد، هیچ سفارشی کش‌بک نمی‌گیرد، کش‌بک سطح‌ها هم نه.';
    }

    protected function fields(): array
    {
        return [FieldDef::toggle('cashback_enabled', 'کش‌بک فعال باشد')];
    }

    protected function source(): SettingsSource
    {
        return new BotGroupSource('cashback', $this->settings);
    }
}
