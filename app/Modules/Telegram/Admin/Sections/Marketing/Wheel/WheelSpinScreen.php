<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Wheel;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Marketing\Links;
use App\Modules\Telegram\Admin\Sections\Marketing\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One spin (from the list's kept page, WheelSpinsScreen): who spun, the slice it landed on, whether the prize was given —
 * and where it went, or why not — and when (the panel's row, with links to the customer, the order or the service).
 */
final class WheelSpinScreen extends BaseScreen
{
    public const ID = 'whx';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $rows = (array) ($admin->state(WheelSpinsScreen::ID)['rows'] ?? []);
        $row = $rows[self::id($args)] ?? $rows[(string) self::id($args)] ?? null;
        if (!is_array($row)) {
            return $admin->open(WheelSpinsScreen::ID);
        }
        $customer = is_array($row['customer'] ?? null) ? (int) ($row['customer']['id'] ?? 0) : 0;
        $order = is_array($row['order'] ?? null) ? (int) ($row['order']['id'] ?? 0) : 0;
        $service = is_array($row['subscription'] ?? null) ? $row['subscription'] : null;
        $given = (bool) ($row['given'] ?? false);

        return View::make('🎡 چرخش ' . Fmt::id((int) ($row['id'] ?? 0)), ...[...Words::PATH, 'گردونه شانس', 'چرخش‌ها'])
            ->facts([
                'مشتری' => Fmt::e(Words::customer($row['customer'] ?? null)),
                'جایزه' => Fmt::e((string) ($row['label'] ?? '')) . ' · ' . Fmt::e(Words::PRIZES[(string) ($row['kind'] ?? '')] ?? (string) ($row['kind'] ?? '')),
                'نتیجه' => ($given ? '✅ داده شد' : '❌ داده نشد') . (is_string($row['outcome'] ?? null) && $row['outcome'] !== '' ? ' · ' . Fmt::e($row['outcome']) : ''),
                'سفارش' => $order > 0 ? Fmt::id($order) : null,
                'سرویس' => $service !== null ? Fmt::ltr((string) ($service['name'] ?? '')) : null,
                'زمان' => Fmt::date(is_string($row['created_at'] ?? null) ? $row['created_at'] : null, true),
            ])
            ->row(
                $customer > 0 ? Links::to($admin, '👤 مشتری', Links::CUSTOMER, 'show', $customer) : null,
                $order > 0 ? Links::to($admin, '🛒 سفارش ' . Fmt::id($order), Links::ORDER, 'show', $order) : null,
                $service !== null ? Links::to($admin, '📡 سرویس', Links::SERVICE, 'show', (int) ($service['id'] ?? 0)) : null,
            )
            ->back(WheelSpinsScreen::ID);
    }
}
