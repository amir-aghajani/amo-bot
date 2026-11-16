<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\GiftCodes;

use App\Modules\Telegram\Admin\Sections\Bot\Settings\BotCard;
use App\Modules\Telegram\Admin\Sections\Marketing\ProgramSwitch;
use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «⚙️ تنظیمات کد هدیه» (the panel's card on «کدهای هدیه», `gift_codes`), reached from the codes' menu: whether customers
 * may redeem gift codes, in the bot and on the website — with the panel's warning, and the way to the keyboards, when it
 * is on and the bot's menu has no button for it.
 */
final class GiftCodeSettingsScreen extends BotCard
{
    use ProgramSwitch;

    public const ID = 'gcs';

    protected function group(): string
    {
        return 'gift_codes';
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return Marketing::settings('کد هدیه');
    }

    protected function path(): array
    {
        return Marketing::in(Marketing::GIFT_CODES);
    }

    protected function parent(): string
    {
        return GiftCodesScreen::ID;
    }

    protected function intro(): string
    {
        return 'کدها را هر وقت بخواهید می‌سازید؛ مشتری‌ها فقط وقتی می‌توانند از آن‌ها استفاده کنند که این گزینه روشن باشد.' . "\n\n"
            . 'روشن که باشد، دکمه «کد هدیه» در منوی ربات می‌آید و مشتری با فرستادن کد، اعتبار یا سرویس رایگانش را می‌گیرد؛ وب‌سایت فروشگاه هم کد را می‌پذیرد. خاموش، دکمه از منو برداشته می‌شود و کدها سر جایشان می‌مانند.';
    }

    protected function fields(): array
    {
        return [FieldDef::toggle('gift_codes_enabled', 'استفاده از کد هدیه فعال باشد')];
    }

    protected function warnings(Admin $admin, array $values): array
    {
        $notice = GiftCodesScreen::notice($admin, (bool) ($values['gift_codes_enabled'] ?? false));

        return $notice === null ? [] : [$notice[0]];
    }

    protected function extra(View $view, Admin $admin, array $values): void
    {
        $view->row(GiftCodesScreen::notice($admin, (bool) ($values['gift_codes_enabled'] ?? false))[1] ?? null);
    }
}
