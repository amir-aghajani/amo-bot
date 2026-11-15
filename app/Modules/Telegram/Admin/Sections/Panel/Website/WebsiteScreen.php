<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Website;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «🌐 تنظیمات وب‌سایت» (the owner's and an agent's): the shop's website at a glance — its API on or off, its address, the
 * ways its customers sign in, its captcha, reviews and admins — and the panel's sections, each a card of its own.
 */
final class WebsiteScreen extends BaseScreen
{
    public const ID = 'ws';

    /** The panel's sections, in its order: [screen, label]. */
    private const SECTIONS = [
        [ConnectionScreen::ID, '🔌 اتصال'],
        [TelegramLoginScreen::ID, '✈️ ورود با تلگرام'],
        [GoogleLoginScreen::ID, '🔵 ورود با گوگل'],
        [EmailScreen::ID, '📧 ورود با ایمیل'],
        [PhoneLoginScreen::ID, '📱 ورود با موبایل'],
        [WebsiteReviewsScreen::ID, '⭐️ نظرات'],
        [StaffScreen::ID, '🛡 مدیران سایت'],
    ];

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $values = WebsiteSource::flat($admin->api->get('/website'));
        $website = is_array($values['website']) ? $values['website'] : [];
        $captcha = Json::part($website, 'captcha');
        $ways = array_keys(array_filter([
            'تلگرام' => $values['telegram_login'],
            'گوگل' => $values['google_client_id'] !== '',
            'ایمیل' => $values['email_signup'],
            'موبایل' => $values['phone_login'],
        ]));

        $view = View::make('🌐 تنظیمات وب‌سایت', 'مدیریت', 'تنظیمات')
            ->line('وب‌سایت فروشگاه از راه API به فروشگاه وصل می‌شود و مشتری در آن با تلگرام، گوگل، ایمیل یا شماره موبایل وارد حسابش می‌شود.')
            ->line()
            ->facts([
                "\u{2068}API\u{2069} وب‌سایت" => Fmt::onOff($values['enabled'] === true),
                'آدرس وب‌سایت' => Fmt::ltr(is_string($values['url']) ? $values['url'] : ''),
                'ورود مشتری' => $ways === [] ? 'هیچ‌کدام' : Fmt::e(implode('، ', $ways)),
                'تایید امنیتی' => Fmt::e(CaptchaScreen::driverLabel($captcha)),
                'نظرات' => Fmt::onOff($values['reviews_enabled'] === true),
                'مدیران سایت' => Fmt::onOff($values['staff_enabled'] === true),
            ]);
        $buttons = array_map(static fn(array $section): Button => Button::to($section[1], $section[0]), self::SECTIONS);

        return $view->grid(2, ...$buttons)->back('hc');
    }
}
