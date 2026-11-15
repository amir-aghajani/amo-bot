<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Support\Persian;
use Illuminate\Support\Carbon;

/**
 * One test a customer took (from the list's kept page, TestsScreen — the panel lists tests, it has no route for one):
 * the customer, the server, the terms it got and where its hours count from, the traffic its service used, where it
 * stands — how long a running one has left —, whether the customer bought after it, and its service a tap away.
 */
final class TestScreen extends BaseScreen
{
    public const ID = 'tsv';

    /** A service's own screen (the subscriptions section's). */
    private const SERVICE = 'sbv';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $rows = (array) ($admin->state(TestsScreen::ID)['rows'] ?? []);
        $test = $rows[self::id($args)] ?? $rows[(string) self::id($args)] ?? null;
        if (!is_array($test)) {
            return $admin->open(TestsScreen::ID);
        }
        $customer = Json::part($test, 'customer');
        $service = is_array($test['subscription'] ?? null) ? $test['subscription'] : null;
        $traffic = is_array($service['traffic'] ?? null) ? $service['traffic'] : [];
        $state = (string) ($test['state'] ?? '');
        $expires = is_string($service['expires_at'] ?? null) ? Fmt::moment($service['expires_at']) : null;

        $view = View::make('🧪 اکانت تست ' . Fmt::id((int) ($test['id'] ?? 0)), 'مدیریت', 'فروشگاه', 'اکانت تست')
            ->facts([
                'مشتری' => Fmt::e((string) ($customer['name'] ?? '')) . (is_string($customer['username'] ?? null) ? ' · ' . Fmt::ltr('@' . $customer['username']) : '') . (isset($customer['telegram_id']) ? ' · ' . Fmt::code((string) $customer['telegram_id']) : ''),
                'سرور' => is_array($test['server'] ?? null) ? Fmt::e((string) ($test['server']['name'] ?? '')) : 'سرور حذف شده',
                'حجم و مدت' => Fmt::bytes((int) ($test['traffic_bytes'] ?? 0)) . ' · ' . Fmt::hours((int) ($test['duration_hours'] ?? 0)) . ' · ' . ((bool) ($test['on_hold'] ?? false) ? 'از اولین اتصال' : 'از لحظه ساخت'),
                'مصرف' => $service === null ? null : Fmt::bytes((int) ($traffic['used'] ?? 0)) . ' از ' . Fmt::quota((int) ($traffic['limit'] ?? 0)),
                'وضعیت' => Fmt::e(TestsScreen::STATES[$state] ?? $state) . ($state === 'running' && $expires !== null ? ' · ' . self::left($expires) : ''),
                'سرویس' => $service === null ? null : Fmt::ltr((string) ($service['name'] ?? '')),
                'بعد از آن خرید کرد' => Fmt::yesNo((bool) ($test['bought_after'] ?? false)),
                'گرفته شده' => Fmt::date(is_string($test['created_at'] ?? null) ? $test['created_at'] : null, true),
            ]);
        if ($service !== null) {
            $view->row(Button::to('📡 سرویس این اکانت', self::SERVICE, 'show', (int) ($service['id'] ?? 0)));
        }

        return $view->back(TestsScreen::ID);
    }

    /** How long a running test has left: «۵ ساعت مانده», «۲ روز مانده». */
    private static function left(Carbon $expires): string
    {
        $seconds = $expires->getTimestamp() - Carbon::now()->getTimestamp();
        if ($seconds <= 0) {
            return 'تمام شده';
        }

        return $seconds <= 86400 ? Persian::number((int) ceil($seconds / 3600)) . ' ساعت مانده' : Persian::number((int) ceil($seconds / 86400)) . ' روز مانده';
    }
}
