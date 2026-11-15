<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Auth\StaffGrant;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «📦 پلن‌ها» (the panel's «پلن‌ها»): the catalogue in the order customers see it — narrowed to a category by its
 * chips —, each plan a line with its quota and price and a button to its page (PlanScreen), a plan the bot does not
 * show flagged. With the catalogue's grant: «➕ افزودن پلن» (PlanEditScreen), «تغییر گروهی قیمت» (PlanPricesScreen),
 * and a draft left half made, to go on with. The panel's route answers every plan at once: the pages are cut here.
 *
 * Actions: `page` (n), `cat` (category id, `none` for none, `_` for every one), `cp` (page of the category chips: many
 * categories go by pages).
 */
final class PlansScreen extends BaseScreen
{
    public const ID = 'pl';

    /** The chip of every category, and of the plans in none. */
    public const ANY = '_';
    public const NONE = 'none';

    public function show(Admin $admin, array $args): View
    {
        $plans = self::plans($admin);
        $state = $admin->state(self::ID);
        $filter = (string) ($state['cat'] ?? self::ANY);
        $categories = [];
        foreach ($plans as $plan) {
            if (is_array($plan['category'] ?? null)) {
                $categories[(int) $plan['category']['id']] = (string) $plan['category']['name'];
            }
        }
        if ($filter !== self::ANY && $filter !== self::NONE && !isset($categories[(int) $filter])) {
            $filter = self::ANY;
        }
        $shown = array_values(array_filter($plans, static fn(array $plan): bool => match ($filter) {
            self::ANY => true,
            self::NONE => !is_array($plan['category'] ?? null),
            default => is_array($plan['category'] ?? null) && (string) $plan['category']['id'] === $filter,
        }));
        [$rows, $page, $pages] = View::slice($shown, (int) ($state['page'] ?? 1));

        $view = View::make('📦 پلن‌ها', 'مدیریت', 'فروشگاه')
            ->line('بسته‌هایی که مشتری در ربات می‌خرد؛ ترتیب همین لیست، ترتیب نمایش است.')
            ->line(Fmt::number(count($shown)) . ' پلن' . ($pages > 1 ? ' · صفحه ' . Fmt::number($page) . ' از ' . Fmt::number($pages) : ''));
        if ($plans === []) {
            $view->line()->line('<b>هنوز پلنی تعریف نشده است</b>')->line('اولین بسته را بسازید: حجم، مدت، تعداد کاربر و قیمت.');
        } elseif ($shown === []) {
            $view->line()->line('در این دسته پلنی نیست.');
        }
        foreach ($rows as $plan) {
            $view->line()->lines(...self::lines($plan));
        }

        if ($categories !== []) {
            $chips = [Button::to(($filter === self::ANY ? '● ' : '') . 'همه', self::ID, 'cat', self::ANY)];
            foreach ($categories as $id => $name) {
                $chips[] = Button::to(($filter === (string) $id ? '● ' : '') . $name, self::ID, 'cat', $id);
            }
            $chips[] = Button::to(($filter === self::NONE ? '● ' : '') . 'بی‌دسته', self::ID, 'cat', self::NONE);
            $view->paged(3, $chips, (int) ($state['cp'] ?? 1), static fn(int $at): array => [self::ID, 'cp', [$at]]);
        }
        foreach ($rows as $plan) {
            $view->row(Button::to(self::label($plan), PlanScreen::ID, 'show', (int) $plan['id']));
        }
        $view->pager($page, $pages, self::ID, 'page');

        if ($admin->seat->holds(StaffGrant::Catalog)) {
            $draft = $admin->state(PlanEditScreen::ID);
            if (is_array($draft['d'] ?? null) && (int) ($draft['id'] ?? 0) === 0 && (string) ($draft['d']['name'] ?? '') !== '') {
                $view->row(Button::to('📝 ادامه پیش‌نویس: ' . $draft['d']['name'], PlanEditScreen::ID, 'sum'));
            }
            $view->row(
                Button::to('➕ افزودن پلن', PlanEditScreen::ID, 'new'),
                $plans !== [] ? Button::to('💹 تغییر گروهی قیمت', PlanPricesScreen::ID, 'new') : null,
            );
        }

        return $view->back('hs');
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        switch ($action) {
            case 'page':
                $admin->remember(self::ID, ['page' => self::page($args)]);
                break;
            case 'cat':
                $admin->remember(self::ID, ['cat' => (string) ($args[0] ?? self::ANY), 'page' => 1]);
                break;
            case 'cp':
                $admin->remember(self::ID, ['cp' => self::page($args)]);
                break;
        }

        return $this->show($admin, []);
    }

    /**
     * Every plan, in display order, as the panel lists them.
     *
     * @return list<array<string, mixed>>
     */
    public static function plans(Admin $admin): array
    {
        return Json::rows($admin->api->get('/plans'), 'plans');
    }

    /**
     * A plan's line in the list: its name, quota and price, and why a customer does not see it.
     *
     * @param array<string, mixed> $plan
     * @return list<string>
     */
    private static function lines(array $plan): array
    {
        $badge = CatalogFmt::BADGES[(string) ($plan['badge'] ?? '')] ?? null;
        $lines = [
            ((bool) ($plan['is_active'] ?? false) ? '' : '⏸ ') . '<b>' . Fmt::e((string) ($plan['name'] ?? '')) . '</b>' . ($badge !== null ? ' · 🏷 ' . Fmt::e($badge) : ''),
            CatalogFmt::traffic($plan['traffic_gb'] ?? 0) . ' · ' . Fmt::hours((int) ($plan['duration_hours'] ?? 0)) . ' · ' . CatalogFmt::devices($plan['ip_limit'] ?? 0) . ' · ' . CatalogFmt::price(is_scalar($plan['price'] ?? null) ? (string) $plan['price'] : null),
        ];
        if ((bool) ($plan['hidden_by_owner'] ?? false)) {
            $lines[] = '🚫 پنهان‌شده توسط پشتیبانی';
        } elseif (is_string($plan['unsellable_reason'] ?? null)) {
            $lines[] = '⚠️ در ربات دیده نمی‌شود';
        }

        return $lines;
    }

    /** @param array<string, mixed> $plan */
    private static function label(array $plan): string
    {
        $flag = match (true) {
            !(bool) ($plan['is_active'] ?? false) => '⏸ ',
            (bool) ($plan['hidden_by_owner'] ?? false), is_string($plan['unsellable_reason'] ?? null) => '⚠️ ',
            default => '',
        };

        return $flag . Fmt::cut((string) ($plan['name'] ?? ''), 28) . ' · ' . Fmt::plain(CatalogFmt::price(is_scalar($plan['price'] ?? null) ? (string) $plan['price'] : null));
    }
}
