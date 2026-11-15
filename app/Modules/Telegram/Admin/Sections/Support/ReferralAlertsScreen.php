<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «⚠️ هشدارها» (GET /referrals/alerts): the referrers whose referrals look made up, newest first — while an alert
 * stands, their start gifts and invite rewards are held —, each opening its own screen (ReferralAlertScreen) to clear
 * it; one referrer's alone as their screen leads here (`who`: the search «#id», as the panel's link does).
 *
 * Actions: `who` (referrer id).
 */
final class ReferralAlertsScreen extends ListScreen
{
    public const ID = 'rfa';

    /** Why an alert was raised, in the admins' words: how many and of what. */
    public const REASON = ['burst' => '%s عضویت پشت سر هم با یک لینک', 'idle' => '%s زیرمجموعه بدون هیچ خرید'];

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '⚠️ هشدارها';
    }

    protected function path(): array
    {
        return ['مدیریت', 'فروش', 'زیرمجموعه‌گیری'];
    }

    protected function endpoint(): string
    {
        return '/referrals/alerts';
    }

    protected function key(): string
    {
        return 'alerts';
    }

    protected function searchHint(): string
    {
        return 'نام، نام کاربری یا شناسه معرف';
    }

    protected function empty(): string
    {
        return 'هشداری نیست. معرفی که زیرمجموعه‌هایش ساختگی به نظر برسند، این‌جا و در گروه گزارش‌ها می‌آید.';
    }

    protected function summary(array $meta): array
    {
        return ['معرف‌هایی که زیرمجموعه‌هایشان ساختگی به نظر می‌رسد. تا هشدار رفع نشود هدیه شروع و جایزه دعوت به معرف داده نمی‌شود — و بعد از رفع هم آنچه نگه داشته شد داده نمی‌شود؛ پورسانت‌ها مثل قبل است.'];
    }

    protected function keepsRows(): bool
    {
        return true;
    }

    protected function parent(): string
    {
        return ReferralsScreen::ID;
    }

    protected function item(array $row): Button
    {
        $referrer = is_array($row['referrer'] ?? null) ? $row['referrer'] : null;

        return Button::to('⚠️ ' . Words::name($referrer) . ' · ' . self::reason($row), ReferralAlertScreen::ID, Nav::SHOW, (int) ($row['id'] ?? 0));
    }

    protected function action(Admin $admin, string $action, array $args): View
    {
        if ($action === 'who' && self::id($args) > 0) {
            $admin->remember(self::ID, ['search' => '#' . self::id($args), 'page' => 1]);
        }

        return $this->show($admin, []);
    }

    /**
     * Why the alert was raised, as plain text: «۱۰ عضویت پشت سر هم با یک لینک».
     *
     * @param array<string, mixed> $row
     */
    public static function reason(array $row): string
    {
        $reason = (string) ($row['reason'] ?? '');

        return isset(self::REASON[$reason]) ? sprintf(self::REASON[$reason], Fmt::number((int) ($row['count'] ?? 0))) : $reason;
    }
}
