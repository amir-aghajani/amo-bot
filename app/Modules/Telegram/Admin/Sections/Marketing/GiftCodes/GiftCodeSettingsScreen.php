<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\GiftCodes;

use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Marketing\Links;
use App\Modules\Telegram\Admin\Sections\Marketing\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsScreen;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsSource;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «⚙️ تنظیمات کد هدیه» (the panel's card on «کدهای هدیه», `gift_codes`): whether customers may redeem gift codes, in the
 * bot and on the website — with the panel's warning when it is on and the bot's menu has no button for it.
 */
final class GiftCodeSettingsScreen extends SettingsScreen
{
    public const ID = 'gcs';

    public function __construct(
        private readonly BotSettingsScreen $settings,
    ) {}

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
        return '⚙️ تنظیمات کد هدیه';
    }

    protected function path(): array
    {
        return [...Words::PATH, 'کدهای هدیه'];
    }

    protected function parent(): string
    {
        return GiftCodesScreen::ID;
    }

    protected function intro(): string
    {
        return 'کدها را هر وقت بخواهید می‌سازید؛ مشتری‌ها فقط وقتی می‌توانند از آن‌ها استفاده کنند که این گزینه روشن باشد.' . "\n"
            . 'روشن که باشد، دکمه «کد هدیه» در منوی ربات می‌آید و مشتری با فرستادن کد، اعتبار یا سرویس رایگانش را می‌گیرد؛ وب‌سایت فروشگاه هم کد را می‌پذیرد. خاموش، دکمه از منو برداشته می‌شود و کدها سر جایشان می‌مانند.';
    }

    protected function fields(): array
    {
        return [FieldDef::toggle('gift_codes_enabled', 'استفاده از کد هدیه فعال باشد')];
    }

    protected function source(): SettingsSource
    {
        return new BotGroupSource('gift_codes', $this->settings);
    }

    protected function extra(View $view, Admin $admin, array $values): void
    {
        $notice = Links::menuNotice($admin, (bool) ($values['gift_codes_enabled'] ?? false), 'gift', 'کد هدیه', 'فرستادن کد هدیه در ربات');
        if ($notice !== null) {
            $view->line()->line(Fmt::e($notice[0]))->row($notice[1]);
        }
    }
}
