<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Website;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «🌐 تنظیمات وب‌سایت» (the owner's and an agent's): the shop's website at a glance — a summary card of its API, its
 * address, the ways its customers sign in, its captcha, reviews and admins — and the panel's sections, each a card of
 * its own, two a row.
 */
final class WebsiteScreen extends BaseScreen
{
    public const ID = 'ws';

    public const TITLE = Icons::WEBSITE . ' تنظیمات وب‌سایت';

    /** The panel's sections, in its order: [screen, label]. */
    private const SECTIONS = [
        [ConnectionScreen::ID, '🔌 اتصال'],
        [TelegramLoginScreen::ID, '✈️ ورود با تلگرام'],
        [GoogleLoginScreen::ID, '🔵 ورود با گوگل'],
        [EmailScreen::ID, '📧 ورود با ایمیل'],
        [PhoneLoginScreen::ID, '📱 ورود با موبایل'],
        [WebsiteReviewsScreen::ID, Icons::REVIEWS . ' نظرات'],
        [StaffScreen::ID, '🛡 مدیران سایت'],
    ];

    /**
     * The path of a screen under it — «مدیریت › تنظیمات › تنظیمات وب‌سایت» and the sections `$more` between.
     *
     * @return list<string>
     */
    public static function under(string ...$more): array
    {
        return [Words::ROOT, Fmt::bare(Words::SETTINGS), Fmt::bare(self::TITLE), ...array_values($more)];
    }

    public function show(Admin $admin, array $args): View
    {
        $values = WebsiteSource::flat($admin->api->get('/website'));
        $website = is_array($values['website']) ? $values['website'] : [];
        $ways = array_keys(array_filter([
            'تلگرام' => $values['telegram_login'],
            'گوگل' => $values['google_client_id'] !== '',
            'ایمیل' => $values['email_signup'],
            'موبایل' => $values['phone_login'],
        ]));

        return View::make(self::TITLE, Words::ROOT, Fmt::bare(Words::SETTINGS))
            ->line("وب‌سایت فروشگاه از راه \u{2068}API\u{2069} به فروشگاه وصل می‌شود و مشتری در آن با حساب خودش وارد می‌شود.")
            ->card([
                "\u{2068}API\u{2069} وب‌سایت" => Fmt::onOff($values['enabled'] === true),
                'آدرس وب‌سایت' => Fmt::ltr(is_string($values['url']) ? $values['url'] : ''),
                'ورود مشتری' => $ways === [] ? 'هیچ‌کدام' : Fmt::e(implode('، ', $ways)),
                'تایید امنیتی' => Fmt::e(CaptchaScreen::driverLabel(Json::part($website, 'captcha'))),
                'نظرات' => Fmt::onOff($values['reviews_enabled'] === true),
                'مدیران سایت' => Fmt::onOff($values['staff_enabled'] === true),
            ])
            ->grid(2, ...array_map(static fn(array $section): Button => Button::to($section[1], $section[0]), self::SECTIONS))
            ->back('hc');
    }
}
