<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Bot\Keyboards\KeyboardScreen;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\ReferralCard;
use App\Modules\Telegram\Admin\Sections\Sales\Sales;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words as UiWords;

/**
 * «🔗 زیرمجموعه‌گیری» (the panel's referrals page, GET /referrals): the program in one card — what a payment earns, how
 * many referrers and invitees, the commissions paid and what they add up to —, whether it is off or the bot's menu
 * lacks its button (each with its way to fix it), the four lists (the alerts' with their count: a queue), and its rules
 * (ReferralCard, «🔗 قوانین زیرمجموعه‌گیری»), which live here.
 */
final class ReferralsScreen extends BaseScreen
{
    public const ID = 'rf';

    public const TITLE = Icons::REFERRALS . ' زیرمجموعه‌گیری';

    /** The menu it is in (Screens: «🛍 فروش»). */
    public const PARENT = 'hl';

    /** Its place in the menus, for the path over its lists and rows (under()). */
    private const PATH = [UiWords::ROOT, Sales::TITLE, 'زیرمجموعه‌گیری'];

    /** The lists' icons, one each (Icons'; an alert's the general mark): on their buttons, titles and rows' titles. */
    public const REFERRER = Icons::REFERRERS;
    public const INVITEE = Icons::INVITEES;
    public const COMMISSION = Icons::COMMISSIONS;
    public const ALERT = '⚠️';

    /** The lists' names, as their buttons and titles say them. */
    public const REFERRERS = self::REFERRER . ' معرف‌ها';
    public const INVITEES = self::INVITEE . ' زیرمجموعه‌ها';
    public const COMMISSIONS = self::COMMISSION . ' پورسانت‌ها';
    public const ALERTS = self::ALERT . ' هشدارها';

    /** The program in a paragraph (the panel's header's ⓘ). */
    public const PROGRAM = 'هر مشتری در ربات لینک دعوت خودش را دارد؛ کسی که اولین بار با آن لینک وارد ربات شود زیرمجموعه اوست و از پرداخت‌هایش پورسانتی به کیف پول معرف اضافه می‌شود؛ کسی که از قبل در ربات ثبت شده باشد، زیرمجموعه کسی نمی‌شود.';

    /**
     * The sections the program's lists and rows are under, then `$more`: «مدیریت › فروش › زیرمجموعه‌گیری › …».
     *
     * @return list<string>
     */
    public static function under(string ...$more): array
    {
        return [...self::PATH, ...array_values($more)];
    }

    public function show(Admin $admin, array $args): View
    {
        $summary = (array) ($admin->api->get('/referrals')['summary'] ?? []);
        $enabled = Json::on($summary, 'enabled');
        $alerts = (int) ($summary['alerts'] ?? 0);
        $paid = Words::figure($summary['paid'] ?? null);
        $lacksButton = $enabled && $this->menuLacksButton($admin);
        $rules = Links::to($admin, ReferralCard::TITLE, ReferralCard::ID);

        $view = View::make(self::TITLE, UiWords::ROOT, Sales::TITLE);
        if (!$enabled) {
            $view->line('⛔️ <b>زیرمجموعه‌گیری خاموش است:</b> لینک‌های دعوت کسی را زیرمجموعه نمی‌کنند و پرداختی پورسانت ندارد. زیرمجموعه‌ها و پورسانت‌های قبلی سر جایشان می‌مانند.' . ($rules !== null ? ' روشن کردنش در «' . Fmt::e(Fmt::bare(ReferralCard::TITLE)) . '» است.' : ''));
        } elseif ($lacksButton) {
            $view->line('⚠️ دکمه «زیرمجموعه‌گیری» در منوی ربات نیست؛ مشتری‌ها به صفحه لینک دعوتشان راهی ندارند.');
        }

        $view->card([
            'پورسانت' => self::terms($summary),
            'معرف‌ها' => Fmt::number((int) ($summary['referrers'] ?? 0)),
            'زیرمجموعه‌ها' => Fmt::number((int) ($summary['referred'] ?? 0)),
            'پورسانت‌های پرداخت‌شده' => Fmt::number((int) ($summary['commissions'] ?? 0)),
            'جمع پورسانت‌ها' => Fmt::money($paid),
        ]);

        $view->grid(
            2,
            Button::to(self::REFERRERS, ReferrersScreen::ID),
            Button::to(self::INVITEES, InviteesScreen::ID),
            Button::to(self::COMMISSIONS, CommissionsScreen::ID),
            Button::to(self::ALERTS . Fmt::badge($alerts), ReferralAlertsScreen::ID),
        );
        if ($lacksButton) {
            $view->row(Links::to($admin, Icons::KEYBOARDS . ' افزودن دکمه در منوی ربات', KeyboardScreen::ID, Nav::SHOW, 'start')?->primary());
        }
        $view->row($enabled ? $rules : $rules?->primary());

        return $view->help(Fmt::e(self::PROGRAM) . ($alerts > 0 ? "\n\n<b>" . Fmt::e(self::ALERTS) . '</b>: ' . Fmt::e(ReferralAlertsScreen::HELD) : ''))
            ->back(self::PARENT)
            ->refresh(self::ID);
    }

    /**
     * Whether the program runs but the bot's menu («start») has no «زیرمجموعه‌گیری» button, so customers have no way to
     * their invite screen (the panel's MenuButtonNotice).
     */
    private function menuLacksButton(Admin $admin): bool
    {
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
