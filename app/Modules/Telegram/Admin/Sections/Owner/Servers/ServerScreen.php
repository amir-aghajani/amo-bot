<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Servers;

use App\Modules\Auth\Services\SessionPresenter;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Catalog\TestServerScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One server (the panel's server page): its connector and address, its state and last check — or what a check just
 * found —, why nothing is sold on it now, its connection's facts in its connector's words (the way in, the subscription
 * links, its active services, the SOCKS5 proxy, TLS, the timeout), and its sections: «🔌 بررسی اتصال», its inbounds,
 * its free test accounts, its connection's form, the days and traffic given to its services, moving its services, and
 * «🗑 حذف سرور» asked first. The server opened is kept for its sections (ServerWords::current()).
 *
 * Actions: `check`, `del` (asks), `gone` (deletes).
 */
final class ServerScreen extends BaseScreen
{
    public const ID = 'svv';

    public function __construct(private readonly SessionPresenter $shops) {}

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    public function show(Admin $admin, array $args): View
    {
        $id = ServerWords::current($admin, $args);
        if ($id === 0) {
            return $admin->open(ServersScreen::ID);
        }
        $kept = $admin->state(self::ID);
        if ((int) ($kept['id'] ?? 0) !== $id) {
            $admin->remember(self::ID, ['id' => $id, 'probe' => null]);
        }
        $answer = $admin->api->get("/servers/{$id}");
        $probe = is_array($kept['probe'] ?? null) && (int) ($kept['id'] ?? 0) === $id ? $kept['probe'] : null;

        return $this->view($admin, (array) ($answer['server'] ?? []), $probe);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = ServerWords::current($admin, $args);
        switch ($action) {
            case 'check':
                $answer = $admin->api->post("/servers/{$id}/test");
                $probe = (array) ($answer['probe'] ?? []);
                $admin->remember(self::ID, ['id' => $id, 'probe' => $probe]);
                $admin->notice(Json::on($probe, 'ok') ? '✅ اتصال برقرار است' : '❌ اتصال برقرار نشد');

                return $this->view($admin, (array) ($answer['server'] ?? []), $probe);
            case 'del':
                $server = (array) ($admin->api->get("/servers/{$id}")['server'] ?? []);

                return $admin->confirm(
                    '«' . (string) ($server['name'] ?? '') . '» حذف شود؟',
                    Button::to('🗑 بله، حذف شود', self::ID, 'gone', $id),
                    Button::to('✖️ انصراف', self::ID, 'show', $id),
                    '«' . Fmt::e((string) ($server['name'] ?? '')) . '» حذف می‌شود؛ این کار برگشت‌پذیر نیست.' . "\n"
                    . 'اینباندهایش، جایش در پلن‌ها، تاریخچه هدیه‌ها و انتقال‌هایش و فهرست کلاینت‌هایی که انتقال‌ها روی پنلش گذاشته‌اند هم پاک می‌شود، با اطلاعات اتصال و توکنش؛ سفارش‌ها سابقه‌شان را نگه می‌دارند. خود پنل دست نمی‌خورد.' . "\n\n"
                    . '<i>فقط وقتی ممکن است که هیچ اشتراکی روی این سرور ثبت نشده باشد؛ در غیر این صورت آن را غیرفعال کنید.</i>',
                );
            case 'gone':
                $admin->api->delete("/servers/{$id}");
                $admin->forget(self::ID);
                $admin->notice('🗑 سرور حذف شد');

                return $admin->open(ServersScreen::ID);
        }

        return $this->show($admin, $args);
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed>|null $probe
     */
    private function view(Admin $admin, array $server, ?array $probe): View
    {
        $id = (int) ($server['id'] ?? 0);
        $driver = ServerWords::driver($admin, (string) ($server['driver'] ?? ''));
        $form = is_array($server['form'] ?? null) ? $server['form'] : null;
        $view = View::make('🖥 ' . (string) ($server['name'] ?? ''), 'مدیریت', 'سرورها')
            ->line(Fmt::ltr((string) ($server['driver_label'] ?? '')) . ' · ' . Fmt::ltr((string) ($server['base_url'] ?? '')))
            ->line('وضعیت: ' . ServerWords::status($server))
            ->line(is_string($server['last_checked_at'] ?? null) ? 'آخرین بررسی: ' . Fmt::date($server['last_checked_at'], true) : 'هنوز بررسی نشده است.');
        if ($probe !== null) {
            $view->line()->lines(...ServerWords::probe($probe));
        } elseif (is_string($server['last_error'] ?? null)) {
            $view->line()->line('❌ ' . Fmt::e($server['last_error']));
            if (is_string($server['last_error_detail'] ?? null)) {
                $view->line(Fmt::code($server['last_error_detail']));
            }
        }
        if (is_string($server['unsellable_reason'] ?? null)) {
            $view->line()->line('⚠️ فعلا چیزی روی این سرور فروخته یا به آن منتقل نمی‌شود: ' . Fmt::e($server['unsellable_reason']));
        }

        $view->line()->facts([
            'احراز هویت' => $form === null || !array_key_exists('auth_mode', $form) && !array_key_exists('username', $form) ? null : $this->wayIn($form, $driver),
            'لینک اشتراک' => match ($server['serves_subscriptions'] ?? null) {
                true => is_string($form['subscription_url'] ?? null) && $form['subscription_url'] !== '' ? Fmt::ltr($form['subscription_url']) : 'از تنظیمات پنل',
                false => '⛔️ در پنل فعال نیست',
                default => '⚠️ نامشخص — سرور را بررسی کنید',
            },
            'اشتراک‌های فعال' => $this->active($admin, $server),
            "پراکسی \u{2068}SOCKS5\u{2069}" => $form === null ? null : self::proxy($form),
            "گواهی \u{2068}TLS\u{2069}" => $form === null || !array_key_exists('verify_tls', $form) ? null : (Json::on($form, 'verify_tls') ? 'بررسی می‌شود' : 'بررسی نمی‌شود'),
            'تایم‌اوت' => is_int($form['timeout'] ?? null) ? Fmt::number($form['timeout']) . ' ثانیه' : null,
            'یادداشت' => is_string($server['notes'] ?? null) && $server['notes'] !== '' ? Fmt::e(Fmt::cut($server['notes'], 120)) : null,
            'افزوده‌شده' => Fmt::date(is_string($server['created_at'] ?? null) ? $server['created_at'] : null),
        ]);
        if ($driver === null) {
            $view->note('فرم تنظیمات این کانکتور در این نسخه در دسترس نیست.');
        }

        $counts = Json::part($server, 'counts');

        return $view
            ->row(Button::to('🔌 بررسی اتصال', self::ID, 'check', $id), Button::to('📡 اینباندها (' . Fmt::number((int) ($counts['selectable_inbounds'] ?? 0)) . '/' . Fmt::number((int) ($counts['inbounds'] ?? 0)) . ')', ServerInboundsScreen::ID, 'show', $id))
            ->row(
                Button::to('🧪 اکانت تست', TestServerScreen::ID, 'srv', $id, TestServerScreen::FROM_SERVER),
                $driver !== null ? Button::to('✏️ تنظیمات اتصال', ServerFormScreen::ID, 'edit', $id) : null,
            )
            ->row(Button::to('🎁 افزودن زمان و حجم', ServerGrantsScreen::ID, 'show', $id), Button::to('🔀 انتقال سرویس‌ها', ServerMigrationsScreen::ID, 'show', $id))
            ->row(Button::to('🗑 حذف سرور', self::ID, 'del', $id))
            ->back(ServersScreen::ID);
    }

    /**
     * The way in, in the connector's words: its own label for a token («توکن API»), or the username (and TOTP).
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed>|null $driver
     */
    private function wayIn(array $form, ?array $driver): ?string
    {
        if (($form['auth_mode'] ?? null) === 'token') {
            foreach ((array) ($driver['fields'] ?? []) as $field) {
                if (is_array($field) && ($field['name'] ?? null) === 'auth_mode') {
                    foreach ((array) ($field['options'] ?? []) as $option) {
                        if (is_array($option) && ($option['value'] ?? null) === 'token') {
                            return Fmt::e((string) ($option['label'] ?? ''));
                        }
                    }
                }
            }

            return null;
        }
        $username = is_string($form['username'] ?? null) && $form['username'] !== '' ? ' (' . Fmt::ltr($form['username']) . ')' : '';
        $totp = is_array($form['totp_secret'] ?? null) && ($form['totp_secret']['set'] ?? false) === true ? ' + ' . Fmt::ltr('TOTP') : '';

        return 'نام کاربری' . $username . $totp;
    }

    /**
     * The SOCKS5 proxy the panel is reached through — its address and username, never its password —; null for none.
     *
     * @param array<string, mixed> $form
     */
    private static function proxy(array $form): ?string
    {
        $host = is_string($form['proxy_host'] ?? null) ? $form['proxy_host'] : '';
        if (($form['proxy_enabled'] ?? false) !== true || $host === '') {
            return null;
        }
        $address = (str_contains($host, ':') ? "[{$host}]" : $host) . ':' . (is_int($form['proxy_port'] ?? null) ? $form['proxy_port'] : '');
        $username = is_string($form['proxy_username'] ?? null) && $form['proxy_username'] !== '' ? ' · نام کاربری ' . Fmt::ltr($form['proxy_username']) : '';

        return Fmt::ltr($address) . $username;
    }

    /**
     * Its running services across all shops (what its capacity counts) and, of them, the open shop's.
     *
     * @param array<string, mixed> $server
     */
    private function active(Admin $admin, array $server): string
    {
        $counts = Json::part($server, 'counts');
        $all = (int) ($counts['active_subscriptions'] ?? 0);
        $here = (int) ($counts['shop_active_subscriptions'] ?? 0);
        $text = Fmt::number($all) . (is_int($server['capacity'] ?? null) ? ' از ' . Fmt::number($server['capacity']) : '');
        if ($all === $here) {
            return $text;
        }
        $shop = $admin->seat->shop();

        return $text . ' · ' . Fmt::number($here) . ' در «' . Fmt::e((string) $this->shops->shop($shop)['name']) . '» · ' . Fmt::number($all - $here) . ' در ' . ($shop->isMain() ? 'فروشگاه نماینده‌ها' : 'فروشگاه‌های دیگر');
    }
}
