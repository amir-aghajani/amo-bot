<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;
use App\Support\Persian;
use Illuminate\Support\Carbon;

/**
 * One test a customer took (from the list's kept page, TestsScreen — the panel lists tests, it has no route for one), in
 * its card: where it stands — how long a running one has left —, the customer, the server, the terms it got and where
 * its hours count from, the traffic its service used, whether the customer bought after it; its service, the customer's
 * own page and their other tests a tap away.
 */
final class TestScreen extends BaseScreen
{
    public const ID = 'tsv';

    /** A service's own screen (the subscriptions section's). */
    private const SERVICE = 'sbv';

    /** A customer's own screen (the customers section's). */
    private const CUSTOMER = 'usv';

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

        $view = View::make(Icons::TESTS . ' اکانت تست ' . Fmt::id((int) ($test['id'] ?? 0)), ...CatalogFmt::under(Fmt::bare(TestsScreen::TITLE)))
            ->card([
                'وضعیت' => Fmt::e(TestsScreen::STATES[$state] ?? $state) . ($state === 'running' && $expires !== null ? ' · ' . self::left($expires) : ''),
                'مشتری' => Fmt::e((string) ($customer['name'] ?? '')) . (is_string($customer['username'] ?? null) ? ' · ' . Fmt::ltr('@' . $customer['username']) : ''),
                'شناسه تلگرام' => isset($customer['telegram_id']) ? Fmt::code((string) $customer['telegram_id']) : null,
                'سرور' => is_array($test['server'] ?? null) ? Fmt::e((string) ($test['server']['name'] ?? '')) : 'سرور حذف شده',
                'حجم و مدت' => Fmt::bytes((int) ($test['traffic_bytes'] ?? 0)) . ' · ' . Fmt::hours((int) ($test['duration_hours'] ?? 0)) . ' · ' . ((bool) ($test['on_hold'] ?? false) ? 'از اولین اتصال' : 'از لحظه ساخت'),
                'مصرف' => $service === null ? null : Fmt::bytes((int) ($traffic['used'] ?? 0)) . ' از ' . Fmt::quota((int) ($traffic['limit'] ?? 0)),
                'سرویس' => $service === null ? null : Fmt::ltr((string) ($service['name'] ?? '')),
                'بعد از آن خرید کرد' => Fmt::yesNo((bool) ($test['bought_after'] ?? false)),
                'گرفته شده' => Fmt::date(is_string($test['created_at'] ?? null) ? $test['created_at'] : null, true),
            ]);
        if ($service !== null && $admin->opens(self::SERVICE)) {
            $view->row(Button::to(Icons::SERVICES . ' سرویس این اکانت', self::SERVICE, Nav::SHOW, (int) ($service['id'] ?? 0))->primary());
        }
        $user = (int) ($customer['id'] ?? 0);
        if ($user > 0) {
            $view->row(
                $admin->opens(self::CUSTOMER) ? Button::to(Icons::CUSTOMER . ' مشتری', self::CUSTOMER, Nav::SHOW, $user) : null,
                Button::to(Icons::TESTS . ' اکانت‌های تست این مشتری', TestsScreen::ID, 'user', $user),
            );
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
