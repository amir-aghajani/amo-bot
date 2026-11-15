<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\MiniApp;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Panel\Card;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsSource;

/** A card of «📱 مینی‌اپ تلگرام» (the owner's and an agent's), over the mini app's own fields (MiniAppSource). */
abstract class MiniAppCard extends Card
{
    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    protected function path(): array
    {
        return ['مدیریت', 'تنظیمات', 'مینی‌اپ تلگرام'];
    }

    protected function parent(): string
    {
        return MiniAppScreen::ID;
    }

    protected function source(): SettingsSource
    {
        return new MiniAppSource();
    }

    /**
     * Why Telegram does not open the app while APP_URL is not https — said to the owner with where to fix it, to an agent
     * as the owner's —; nothing while it is.
     *
     * @param array<string, mixed> $app
     */
    public static function notSecure(array $app, AdminSeat $seat): ?string
    {
        if (($app['secure'] ?? true) === true) {
            return null;
        }

        return "⚠️ تلگرام مینی‌اپ را فقط از آدرس https باز می‌کند و آدرس برنامه (\u{2068}APP_URL\u{2069}) این فروشگاه https نیست؛ تا https نشود، "
            . (Json::on($app, 'enabled') ? 'تلگرام مینی‌اپ را برای مشتری باز نمی‌کند.' : 'مینی‌اپ روشن نمی‌شود.') . ' '
            . ($seat->isOwner() ? 'آدرس را در «تنظیمات پنل › برنامه» با https بنویسید.' : 'آدرس برنامه را مالک فروشگاه تنظیم می‌کند.');
    }
}
