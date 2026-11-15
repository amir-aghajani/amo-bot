<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\MiniApp;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «📱 مینی‌اپ تلگرام» (the owner's and an agent's): the shop's mini app at a glance — on or off, its address, its menu
 * button, whether Telegram can open it (https) — and the panel's sections: its connection, its look, its logo and the
 * app as a customer sees it.
 */
final class MiniAppScreen extends BaseScreen
{
    public const ID = 'ma';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $app = MiniAppSource::app($admin->api->get('/mini-app'));
        $view = View::make('📱 مینی‌اپ تلگرام', 'مدیریت', 'تنظیمات')
            ->line('مشتری مینی‌اپ را از دکمه‌های ربات باز می‌کند و همان حسابی را می‌بیند که در ربات و وب‌سایت دارد.')
            ->line()
            ->facts([
                'مینی‌اپ' => Fmt::onOff(Json::on($app, 'enabled')),
                'دکمه منوی ربات' => Fmt::onOff(Json::on($app, 'menu_button')),
                'نام' => Fmt::e(is_string($app['title'] ?? null) && $app['title'] !== '' ? $app['title'] : (string) ($app['shop_name'] ?? '')),
                'لوگو' => Json::on($app, 'logo') ? 'دارد' : 'ندارد',
                'آدرس' => Fmt::ltr(is_string($app['url'] ?? null) ? $app['url'] : null),
            ]);
        $warning = MiniAppCard::notSecure($app, $admin->seat);
        if ($warning !== null) {
            $view->line()->line($warning);
        }

        return $view->grid(
            2,
            Button::to('🔌 اتصال', MiniAppConnectionScreen::ID),
            Button::to('🎨 ظاهر', MiniAppLookScreen::ID),
            Button::to('🖼 لوگو', LogoScreen::ID),
            Button::to('👁 پیش‌نمایش', PreviewScreen::ID),
        )->back('hc');
    }
}
