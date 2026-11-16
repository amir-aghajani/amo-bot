<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Sales;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «📡 اشتراک‌ها» (the panel's «اشتراک‌ها»): every service as the shop last saw it on its panel, those ending soon a tab
 * of their own with their count, searched by number, client name, link or customer and narrowed by server, kind and
 * order (SubscriptionFilters). A row opens its service (SubscriptionScreen); «🔀 انتقال چند سرویس» picks several to move
 * to another server at once (MoveScreen), as the panel's selection does.
 */
final class SubscriptionsScreen extends SalesList
{
    public const ID = 'sb';

    /** The list's title. */
    public const TITLE = Icons::SERVICES . ' ' . Sales::SERVICES;

    /** The panel's tabs: «همه», then the statuses as the rows say them, those ending soon after the active ones. */
    private const TABS = [
        self::ANY => 'همه',
        'active' => Sales::SUBSCRIPTION_STATUS['active'],
        'expiring' => '⏳ رو به انقضا',
        'expired' => Sales::SUBSCRIPTION_STATUS['expired'],
        'disabled' => Sales::SUBSCRIPTION_STATUS['disabled'],
        'deleted' => Sales::SUBSCRIPTION_STATUS['deleted'],
    ];

    /** The window of «رو به انقضا», in days, from the list's meta. */
    private int $window = 0;

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return self::TITLE;
    }

    protected function endpoint(): string
    {
        return '/subscriptions';
    }

    protected function key(): string
    {
        return 'subscriptions';
    }

    protected function noun(): string
    {
        return 'اشتراک';
    }

    protected function narrower(): string
    {
        return SubscriptionFilters::ID;
    }

    protected function statuses(): array
    {
        return self::TABS;
    }

    protected function counted(): array
    {
        return ['expiring'];
    }

    protected function searchHint(): string
    {
        return 'شماره یا نام کاربری سرویس، لینک اشتراک، یا نام، یوزرنیم، ایمیل یا شناسه تلگرام مشتری.';
    }

    protected function figures(array $meta): array
    {
        $this->window = is_numeric($meta['expiring_days'] ?? null) ? (int) $meta['expiring_days'] : 0;

        return [];
    }

    protected function empty(): string
    {
        return match (true) {
            $this->narrowed() => 'اشتراکی پیدا نشد؛ با این جستجو یا فیلتر چیزی نیست.',
            $this->tab() === 'expiring' && $this->window > 0 => 'فعلا سرویسی در ' . Fmt::number($this->window) . ' روز آینده تمام نمی‌شود.',
            $this->tab() !== self::ANY => 'اشتراکی در این وضعیت نیست.',
            default => 'هنوز سرویسی فروخته نشده است؛ اولین خرید از ربات این‌جا ظاهر می‌شود.',
        };
    }

    protected function extra(View $view, Admin $admin): void
    {
        $view->row(Button::to(MoveScreen::TITLE . ' به سرور دیگر', MoveScreen::ID));
    }

    protected function item(array $row): Button
    {
        // What is left of a running one (the list's «پایان»), «⏳» marking one ending soon.
        $expires = Json::text($row, 'expires_at');
        $left = match (true) {
            ($row['status'] ?? '') !== 'active' => null,
            $expires !== null => (Json::on($row, 'expiring_soon') ? '⏳ ' : '') . Sales::left($expires),
            (int) ($row['duration_hours'] ?? 0) > 0 => 'منتظر اتصال',
            default => null,
        };

        return Button::to(
            Sales::mark(Sales::SUBSCRIPTION_STATUS, $row['status'] ?? '') . ' ' . implode(' · ', array_filter([
                (string) ($row['name'] ?? ''),
                $left,
                Fmt::cut(Sales::customer(Json::part($row, 'user')), 14),
                (string) (Json::part($row, 'server')['name'] ?? ''),
            ])),
            SubscriptionScreen::ID,
            'show',
            (int) ($row['id'] ?? 0),
        );
    }
}
