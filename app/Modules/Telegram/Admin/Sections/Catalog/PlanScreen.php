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
use App\Support\Persian;

/**
 * One plan (from the panel's plans list — it has no route for one): everything its row and form show — category, quota,
 * the three prices, the servers it is sold on and why one cannot sell now, its sales, its button and badge, its extras
 * prices — and, with the catalogue's grant, what the panel's row menu does: «✏️ ویرایش» (PlanEditScreen), the
 * «قابل خرید» switch (on or off as its button says), «📄 ساخت کپی» (opened in its form at once, switched off), a place
 * up or down among its category's plans, and «🗑 حذف پلن» — asked first, or refused up front once an order or a service
 * points at it.
 *
 * Actions: `tog` (id, 1|0: the switch set), `dup` (id), `up` (id), `down` (id), `del` (id: asks), `gone` (id: deletes).
 */
final class PlanScreen extends BaseScreen
{
    public const ID = 'plv';

    public function show(Admin $admin, array $args): View
    {
        $plans = PlansScreen::plans($admin);
        $plan = CatalogFmt::find($plans, self::id($args));
        if ($plan === null) {
            if (self::id($args) > 0) {
                $admin->notice('این پلن دیگر نیست.');
            }

            return $admin->open(PlansScreen::ID);
        }

        return $this->view($admin, $plan, $plans);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        switch ($action) {
            case 'tog':
                if (!in_array($args[1] ?? null, ['1', '0'], true)) {
                    break;
                }
                $on = $args[1] === '1';
                $admin->api->patch("/plans/{$id}", ['is_active' => $on]);
                $admin->notice($on ? '✅ فعال شد' : '⏸ غیرفعال شد');
                break;
            case 'dup':
                $copy = (array) ($admin->api->post("/plans/{$id}/duplicate")['plan'] ?? []);
                $admin->notice('📄 کپی پلن ساخته شد (غیرفعال)');

                return $admin->open(PlanEditScreen::ID, 'edit', [(int) ($copy['id'] ?? 0)]);
            case 'up':
            case 'down':
                $plans = PlansScreen::plans($admin);
                $plan = CatalogFmt::find($plans, $id);
                $category = self::category($plan ?? []);
                $ids = $plan === null ? null : CatalogFmt::moved($plans, $id, $action === 'up' ? -1 : 1, static fn(array $row): bool => self::category($row) === $category);
                if ($ids !== null) {
                    $admin->api->post('/plans/reorder', ['ids' => $ids]);
                    $admin->notice($action === 'up' ? '🔼 یک جا بالاتر رفت' : '🔽 یک جا پایین‌تر رفت');
                }
                break;
            case 'del':
                $plan = CatalogFmt::find(PlansScreen::plans($admin), $id);
                if ($plan === null) {
                    break;
                }
                $kept = self::inUse($plan);
                if ($kept !== null) {
                    return View::make('🗑 حذف پلن', 'مدیریت', 'فروشگاه', 'پلن‌ها')
                        ->line(Fmt::e($kept))
                        ->row((bool) ($plan['is_active'] ?? false) ? Button::to('⏸ غیرفعال کردن', self::ID, 'tog', $id, 0) : null)
                        ->back(self::ID, 'show', $id);
                }

                return $admin->confirm(
                    'این پلن حذف شود؟',
                    Button::to('🗑 بله، حذف شود', self::ID, 'gone', $id),
                    Button::to('✖️ انصراف', self::ID, 'show', $id),
                    Fmt::e('«' . $plan['name'] . '» حذف می‌شود. این کار برگشت‌پذیر نیست.') . "\n" . Fmt::e('هنوز سفارش یا اشتراکی با این پلن ثبت نشده است؛ سرورهایش فقط از پلن جدا می‌شوند.'),
                );
            case 'gone':
                $admin->api->delete("/plans/{$id}");
                $admin->notice('🗑 پلن حذف شد');

                return $admin->open(PlansScreen::ID);
        }

        return $this->show($admin, [(string) $id]);
    }

    /**
     * @param array<string, mixed> $plan
     * @param list<array<string, mixed>> $plans
     */
    private function view(Admin $admin, array $plan, array $plans): View
    {
        $id = (int) $plan['id'];
        $category = is_array($plan['category'] ?? null) ? $plan['category'] : null;
        $counts = Json::part($plan, 'counts');
        $badge = CatalogFmt::BADGES[(string) ($plan['badge'] ?? '')] ?? null;
        $view = View::make('📦 ' . (string) $plan['name'], 'مدیریت', 'فروشگاه', 'پلن‌ها');
        if (is_string($plan['description'] ?? null) && $plan['description'] !== '') {
            $view->line('<i>' . Fmt::e(Fmt::cut($plan['description'], 300)) . '</i>');
        }
        $siblings = array_values(array_filter($plans, static fn(array $row): bool => self::category($row) === self::category($plan)));
        $at = array_search($id, array_map(static fn(array $row): int => (int) $row['id'], $siblings), true);
        $view->facts([
            'دسته' => $category === null ? 'بدون دسته' : Fmt::e((string) $category['name']) . ((bool) ($category['is_active'] ?? true) ? '' : ' (غیرفعال)'),
            'جایگاه' => $at === false ? null : CatalogFmt::place($at + 1, count($siblings)),
            'برچسب' => $badge === null ? null : Fmt::e($badge),
            'حجم' => CatalogFmt::traffic($plan['traffic_gb'] ?? 0),
            'مدت اعتبار' => Fmt::hours((int) ($plan['duration_hours'] ?? 0)),
            'تعداد کاربر' => CatalogFmt::devices($plan['ip_limit'] ?? 0),
            'قیمت' => CatalogFmt::price(is_scalar($plan['price'] ?? null) ? (string) $plan['price'] : null),
            'قیمت خرید اول' => is_scalar($plan['first_purchase_price'] ?? null) ? CatalogFmt::price((string) $plan['first_purchase_price']) : null,
            'قیمت نماینده' => is_scalar($plan['agent_price'] ?? null) ? CatalogFmt::price((string) $plan['agent_price']) : null,
            'وضعیت' => (bool) ($plan['is_active'] ?? false) ? '🟢 فعال' : '⏸ غیرفعال',
            'اشتراک' => Fmt::number((int) ($counts['active_subscriptions'] ?? 0)) . ' فعال · ' . Fmt::number((int) ($counts['sales'] ?? 0)) . ' فروش',
            'خرید اضافه' => is_array($plan['extras'] ?? null) ? 'قیمت‌های این پلن' : 'قیمت‌های فروشگاه',
            'دکمه در ربات' => Fmt::e((CatalogFmt::STYLES[(string) ($plan['button_style'] ?? '')] ?? CatalogFmt::STYLES['']) . ' · ' . (CatalogFmt::ROWS[(int) ($plan['button_row'] ?? 1)] ?? CatalogFmt::ROWS[1])),
            'یادداشت پیش‌فاکتور' => is_string($plan['checkout_note'] ?? null) && $plan['checkout_note'] !== '' ? Fmt::e(Fmt::cut($plan['checkout_note'], 120)) : null,
        ]);
        if ($category !== null && !(bool) ($category['is_active'] ?? true)) {
            $view->line('⚠️ این دسته غیرفعال است؛ پلن در ربات زیر «سایر پلن‌ها» فروخته می‌شود.');
        }

        $view->line()->line('<b>🖥 سرورها</b>');
        $entries = Json::part($plan, 'servers');
        if ($entries === []) {
            $view->line(Fmt::NONE);
        }
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_array($entry['server'] ?? null)) {
                continue;
            }
            $name = Fmt::e((string) ($entry['server']['name'] ?? ''));
            $inbounds = count(Json::part($entry, 'inbounds'));
            $view->line(is_string($entry['unsellable_reason'] ?? null)
                ? '⚠️ ' . $name . ': ' . Fmt::e($entry['unsellable_reason'] . ' تا درست نشود، مشتری این پلن را روی «' . $entry['server']['name'] . '» نمی‌بیند.')
                : '• ' . $name . ' · ' . ((bool) ($entry['all_inbounds'] ?? false) ? 'کل سرور: هر اینباندی که روی این سرور قابل فروش باشد (الان ' . Persian::number($inbounds) . ')' : Persian::number($inbounds) . ' اینباند انتخاب‌شده'));
        }

        if ((bool) ($plan['hidden_by_owner'] ?? false)) {
            $view->line()->line('🚫 پنهان‌شده توسط پشتیبانی: ' . Fmt::e(is_string($plan['unsellable_reason'] ?? null) ? $plan['unsellable_reason'] : 'پشتیبانی فروشگاه اصلی این پلن را پنهان کرده است.'));
        } elseif (is_string($plan['unsellable_reason'] ?? null)) {
            $view->line()->line('⚠️ در ربات دیده نمی‌شود: ' . Fmt::e($plan['unsellable_reason']));
        }

        if ($admin->seat->holds(StaffGrant::Catalog)) {
            $active = (bool) ($plan['is_active'] ?? false);
            $view->row(Button::to('✏️ ویرایش', PlanEditScreen::ID, 'edit', $id), Button::to($active ? '⏸ غیرفعال کردن' : '▶️ فعال کردن', self::ID, 'tog', $id, $active ? 0 : 1))
                ->row(Button::to('📄 ساخت کپی', self::ID, 'dup', $id), Button::to('🗑 حذف پلن', self::ID, 'del', $id))
                ->row(
                    $at !== false && $at > 0 ? Button::to('🔼 بالاتر', self::ID, 'up', $id) : null,
                    $at !== false && $at < count($siblings) - 1 ? Button::to('🔽 پایین‌تر', self::ID, 'down', $id) : null,
                );
        }

        return $view->back(PlansScreen::ID);
    }

    /** @param array<string, mixed> $plan */
    private static function category(array $plan): int
    {
        return is_array($plan['category'] ?? null) ? (int) ($plan['category']['id'] ?? 0) : 0;
    }

    /**
     * Why the plan is not deleted — the server's rule: an order or a service points at it, and the history stays (the
     * panel's words); null when it may go.
     *
     * @param array<string, mixed> $plan
     */
    private static function inUse(array $plan): ?string
    {
        $counts = Json::part($plan, 'counts');
        $orders = (int) ($counts['orders'] ?? 0);
        $services = (int) ($counts['subscriptions'] ?? 0);
        if ($orders === 0 && $services === 0) {
            return null;
        }
        $history = implode(' و ', array_filter([$orders > 0 ? Persian::number($orders) . ' سفارش' : null, $services > 0 ? Persian::number($services) . ' اشتراک' : null]));

        return "با «{$plan['name']}» {$history} ثبت شده و برای حفظ تاریخچه حذف نمی‌شود؛ اگر نمی‌خواهید مشتری آن را بخرد، آن را غیرفعال کنید.";
    }
}
