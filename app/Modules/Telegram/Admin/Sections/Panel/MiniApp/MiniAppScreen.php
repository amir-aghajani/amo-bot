<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\MiniApp;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «📱 مینی‌اپ تلگرام» (the owner's and an agent's): the shop's mini app at a glance — a summary card of it on or off, its
 * menu button, name, logo and address, and why Telegram will not open it (not https) when it will not — and the panel's
 * sections, two a row: its connection, its look, its logo and the app as a customer sees it.
 */
final class MiniAppScreen extends BaseScreen
{
    public const ID = 'ma';

    public const TITLE = Icons::MINI_APP . ' مینی‌اپ تلگرام';

    /**
     * The path of a screen under it — «مدیریت › تنظیمات › مینی‌اپ تلگرام» and the sections `$more` between.
     *
     * @return list<string>
     */
    public static function under(string ...$more): array
    {
        return [Words::ROOT, Fmt::bare(Words::SETTINGS), Fmt::bare(self::TITLE), ...array_values($more)];
    }

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $app = MiniAppSource::app($admin->api->get('/mini-app'));
        $view = View::make(self::TITLE, Words::ROOT, Fmt::bare(Words::SETTINGS));
        $warning = MiniAppCard::notSecure($app, $admin->seat);
        if ($warning !== null) {
            $view->line('⚠️ ' . Fmt::e($warning));
        }

        return $view->line('مشتری مینی‌اپ را از دکمه‌های ربات باز می‌کند و همان حسابی را می‌بیند که در ربات و وب‌سایت دارد.')
            ->card([
                'مینی‌اپ' => Fmt::onOff(Json::on($app, 'enabled')),
                'دکمه منوی ربات' => Fmt::onOff(Json::on($app, 'menu_button')),
                'نام' => Fmt::e(is_string($app['title'] ?? null) && $app['title'] !== '' ? $app['title'] : (string) ($app['shop_name'] ?? '')),
                'لوگو' => Json::on($app, 'logo') ? 'دارد' : 'ندارد',
                'آدرس' => Fmt::code(is_string($app['url'] ?? null) ? $app['url'] : null),
            ])
            ->grid(
                2,
                Button::to('🔌 اتصال', MiniAppConnectionScreen::ID),
                Button::to('🎨 ظاهر', MiniAppLookScreen::ID),
                Button::to('🖼 لوگو', LogoScreen::ID),
                Button::to('👁 پیش‌نمایش', PreviewScreen::ID),
            )
            ->back('hc');
    }
}
