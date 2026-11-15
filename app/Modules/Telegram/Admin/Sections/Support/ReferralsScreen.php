<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Bot\Keyboards\KeyboardScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «🔗 زیرمجموعه‌گیری» (the panel's referrals page, GET /referrals): the program's numbers — referrers, invitees, the
 * commissions paid and what they add up to, with the rules they were paid by —, whether the program is off or the
 * bot's menu lacks its button, and the four lists: referrers, invitees, commissions and alerts. The rules themselves are
 * the bot settings' («قوانین پورسانت»).
 */
final class ReferralsScreen extends BaseScreen
{
    public const ID = 'rf';

    /** The menu it is in (Screens: «🛍 فروش»). */
    public const PARENT = 'hl';

    /** The bot settings' referral card, where the program's rules are, and the menu of the cards (Sections\Bot). */
    private const RULES = 'bsf';
    private const BOT_SETTINGS = 'bs';

    /** The keyboards' screen, where a missing button is added (Sections\Bot). */
    private const KEYBOARDS = 'kb';

    /** The program in a paragraph (the panel's header's ⓘ). */
    public const PROGRAM = 'هر مشتری در ربات لینک دعوت خودش را دارد؛ کسی که اولین بار با آن لینک وارد ربات شود زیرمجموعه اوست و از پرداخت‌هایش پورسانتی به کیف پول معرف اضافه می‌شود؛ کسی که از قبل در ربات ثبت شده باشد، زیرمجموعه کسی نمی‌شود.';

    public function show(Admin $admin, array $args): View
    {
        $summary = (array) ($admin->api->get('/referrals')['summary'] ?? []);
        $enabled = Json::on($summary, 'enabled');
        $alerts = (int) ($summary['alerts'] ?? 0);

        $view = View::make('🔗 زیرمجموعه‌گیری', 'مدیریت', 'فروش');
        $fixes = [];
        if (!$enabled) {
            $view->line('⛔️ <b>زیرمجموعه‌گیری خاموش است:</b> لینک‌های دعوت کسی را زیرمجموعه نمی‌کنند و پرداختی پورسانت ندارد. زیرمجموعه‌ها و پورسانت‌های قبلی سر جایشان می‌مانند.')->line();
            $fixes[] = $this->rules($admin, '▶️ روشن کردن در تنظیمات ربات');
        } elseif ($this->menuLacksButton($admin)) {
            $view->line('⚠️ دکمه «زیرمجموعه‌گیری» در منوی ربات نیست؛ مشتری‌ها به صفحه لینک دعوتشان راهی ندارند.')->line();
            $fixes[] = Links::to($admin, '⌨️ افزودن دکمه در کیبوردها', self::KEYBOARDS);
        }

        $view->lines(
            '👥 معرف‌ها: <b>' . Fmt::number((int) ($summary['referrers'] ?? 0)) . '</b> — مشتری‌هایی که لینکشان کسی را آورده',
            '🙋 زیرمجموعه‌ها: <b>' . Fmt::number((int) ($summary['referred'] ?? 0)) . '</b> — مشتری‌هایی که با لینک دعوت آمده‌اند',
            '🧾 پورسانت‌های پرداخت‌شده: <b>' . Fmt::number((int) ($summary['commissions'] ?? 0)) . '</b> — هر کدام بابت یک پرداخت',
            '💰 جمع پورسانت‌ها: <b>' . Fmt::money(is_numeric($summary['paid'] ?? null) ? (string) $summary['paid'] : '0') . '</b> — ' . self::terms($summary),
            '⚠️ هشدارها: <b>' . Fmt::number($alerts) . '</b>' . ($alerts > 0 ? ' — هدیه شروع و جایزه دعوت این معرف‌ها نگه داشته می‌شود' : ''),
        )->note(Fmt::e(self::PROGRAM));

        $view->grid(
            2,
            Button::to('👥 معرف‌ها', ReferrersScreen::ID),
            Button::to('🙋 زیرمجموعه‌ها', InviteesScreen::ID),
            Button::to('🧾 پورسانت‌ها', CommissionsScreen::ID),
            Button::to('⚠️ هشدارها' . Fmt::badge($alerts), ReferralAlertsScreen::ID),
        );
        $view->grid(1, ...array_values(array_filter($fixes)));
        if ($enabled) {
            $rules = $this->rules($admin, '⚙️ قوانین پورسانت');
            if ($rules !== null) {
                $view->row($rules);
            }
        }

        return $view->row(Button::to('🔄 به‌روزرسانی', self::ID))->back(self::PARENT);
    }

    /** A button to the program's rules: its bot settings card, else the menu of the cards. */
    private function rules(Admin $admin, string $label): ?Button
    {
        return Links::to($admin, $label, self::RULES) ?? Links::to($admin, $label, self::BOT_SETTINGS);
    }

    /**
     * Whether the program runs but the bot's menu («start») has no «زیرمجموعه‌گیری» button, so customers have no way to
     * their invite screen (the panel's MenuButtonNotice) — asked only where the keyboards are the seat's to read.
     */
    private function menuLacksButton(Admin $admin): bool
    {
        if (!$admin->seat->configures()) {
            return false;
        }

        try {
            return !KeyboardScreen::hasButton($admin, 'affiliates');
        } catch (ApiRefusal) {
            return false;
        }
    }

    /**
     * What a payment earns (the panel's commissionTerms): the rate, of every payment or each one's first — and a
     * renewal's own rate.
     *
     * @param array<string, mixed> $summary
     */
    private static function terms(array $summary): string
    {
        $rate = (int) ($summary['rate'] ?? 0);
        $renewal = (int) ($summary['renewal_rate'] ?? $rate);
        $firstOnly = Json::on($summary, 'first_only');
        $terms = Fmt::percent($rate) . ' از ' . ($firstOnly ? 'اولین پرداخت هر زیرمجموعه' : 'هر پرداخت');
        if ($firstOnly || $renewal === $rate) {
            return $terms;
        }

        return $terms . '؛ ' . ($renewal === 0 ? 'تمدید بدون پورسانت' : 'تمدید ' . Fmt::percent($renewal));
    }
}
