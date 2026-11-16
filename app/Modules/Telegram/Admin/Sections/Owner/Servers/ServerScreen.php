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
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * One server (the panel's server page), its summary card: its state as a chip and its last check, its connector and
 * address (to copy), its inbounds for sale and active services, its connection's facts in its connector's words (the
 * way in, the subscription links, the SOCKS5 proxy, TLS, the timeout) — why nothing is sold on it now over it, what a
 * check just found (or its last error) under it —; then its actions: «🔌 بررسی اتصال» (the main one), the inbounds read
 * again from its panel, its inbounds for sale, its connection's form, its free test accounts, the days and traffic given
 * to its services, moving its services, and «🗑 حذف سرور» (red, asked first). The server opened is kept for its sections
 * (ServerWords::current()).
 *
 * Actions: `check`, `sync` (the inbounds read again), `del` (asks), `gone` (deletes).
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
        ServerWords::opened($admin, $id);
        $answer = $admin->api->get("/servers/{$id}");

        return $this->view($admin, (array) ($answer['server'] ?? []), self::probe($admin, $id));
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
            case 'sync':
                $answer = $admin->api->post("/servers/{$id}/inbounds/sync");
                $inbounds = array_filter((array) ($answer['inbounds'] ?? []), is_array(...));
                $admin->notice('✅ اینباندها از پنل خوانده شدند: ' . Fmt::plain(Fmt::number(count($inbounds))) . ' اینباند');

                return $this->view($admin, (array) ($answer['server'] ?? []), self::probe($admin, $id));
            case 'del':
                $server = (array) ($admin->api->get("/servers/{$id}")['server'] ?? []);

                return $admin->confirm(
                    '«' . (string) ($server['name'] ?? '') . '» حذف شود؟',
                    Button::to(Words::yes('حذف شود', '🗑'), self::ID, 'gone', $id),
                    Button::to(Words::CANCEL, self::ID, 'show', $id),
                    '«' . Fmt::e((string) ($server['name'] ?? '')) . '» حذف می‌شود؛ این کار برگشت‌پذیر نیست.' . "\n"
                    . 'اینباندهایش، جایش در پلن‌ها، تاریخچه هدیه‌ها و انتقال‌هایش و فهرست کلاینت‌هایی که انتقال‌ها روی پنلش گذاشته‌اند هم پاک می‌شود، با اطلاعات اتصال و توکنش؛ سفارش‌ها سابقه‌شان را نگه می‌دارند. خود پنل دست نمی‌خورد.' . "\n\n"
                    . '<i>فقط وقتی ممکن است که هیچ اشتراکی روی این سرور ثبت نشده باشد؛ در غیر این صورت آن را غیرفعال کنید.</i>',
                    danger: true,
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
     * The connection test kept for server `$id`'s card (a check's, or what its panel answered as it was added); null for
     * none, or another server's.
     *
     * @return array<string, mixed>|null
     */
    private static function probe(Admin $admin, int $id): ?array
    {
        $kept = $admin->state(self::ID);

        return is_array($kept['probe'] ?? null) && (int) ($kept['id'] ?? 0) === $id ? $kept['probe'] : null;
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
        $counts = Json::part($server, 'counts');
        $address = (string) ($server['base_url'] ?? '');
        $view = View::make(Icons::SERVERS . ' ' . (string) ($server['name'] ?? ''), Words::ROOT, Fmt::bare(ServersScreen::TITLE));
        if (is_string($server['unsellable_reason'] ?? null)) {
            $view->line('⚠️ فعلا چیزی روی این سرور فروخته یا به آن منتقل نمی‌شود: ' . Fmt::e($server['unsellable_reason']));
        }

        $view->card([
            'وضعیت' => ServerWords::status($server),
            'آخرین بررسی' => is_string($server['last_checked_at'] ?? null) ? Fmt::date($server['last_checked_at'], true) : null,
            'کانکتور' => Fmt::ltr((string) ($server['driver_label'] ?? '')),
            'آدرس پنل' => Fmt::code($address),
            'اینباندهای فروش' => Fmt::number((int) ($counts['selectable_inbounds'] ?? 0)) . ' از ' . Fmt::number((int) ($counts['inbounds'] ?? 0)),
            'اشتراک‌های فعال' => $this->active($admin, $server),
            'احراز هویت' => $form === null || !array_key_exists('auth_mode', $form) && !array_key_exists('username', $form) ? null : $this->wayIn($form, $driver),
            'لینک اشتراک' => match ($server['serves_subscriptions'] ?? null) {
                true => is_string($form['subscription_url'] ?? null) && $form['subscription_url'] !== '' ? Fmt::ltr($form['subscription_url']) : 'از تنظیمات پنل',
                false => '⛔️ در پنل فعال نیست',
                default => '⚠️ نامشخص — سرور را بررسی کنید',
            },
            "پراکسی \u{2068}SOCKS5\u{2069}" => $form === null ? null : self::proxy($form),
            "گواهی \u{2068}TLS\u{2069}" => $form === null || !array_key_exists('verify_tls', $form) ? null : (Json::on($form, 'verify_tls') ? 'بررسی می‌شود' : 'بررسی نمی‌شود'),
            'تایم‌اوت' => is_int($form['timeout'] ?? null) ? Fmt::number($form['timeout']) . ' ثانیه' : null,
            'یادداشت' => is_string($server['notes'] ?? null) && $server['notes'] !== '' ? Fmt::e(Fmt::cut($server['notes'], 120)) : null,
            'افزوده‌شده' => Fmt::date(is_string($server['created_at'] ?? null) ? $server['created_at'] : null),
        ]);
        if ($probe !== null) {
            $view->section('🔌 نتیجه بررسی اتصال')->lines(...ServerWords::probe($probe));
        } elseif (is_string($server['last_error'] ?? null)) {
            $view->section('🔴 آخرین خطا')->line(Fmt::e($server['last_error']));
            if (is_string($server['last_error_detail'] ?? null)) {
                $view->line(Fmt::code($server['last_error_detail']));
            }
        }
        if ($driver === null) {
            $view->note(ServerWords::NO_FORM);
        }

        return $view
            ->row(Button::to('🔌 بررسی اتصال', self::ID, 'check', $id)->primary(), Button::to('🔄 خواندن اینباندها از پنل', self::ID, 'sync', $id))
            ->row(
                // How many are for sale is the card's: the button says only where it goes.
                Button::to(ServerInboundsScreen::TITLE, ServerInboundsScreen::ID, 'show', $id),
                $driver !== null ? Button::to(Words::edit('تنظیمات اتصال'), ServerFormScreen::ID, 'edit', $id) : null,
            )
            ->row(Button::to(Icons::TESTS . ' اکانت تست', TestServerScreen::ID, 'srv', $id, TestServerScreen::FROM_SERVER), Button::to(ServerGrantsScreen::TITLE, ServerGrantsScreen::ID, 'show', $id))
            ->row(Button::to(ServerMigrationsScreen::TITLE, ServerMigrationsScreen::ID, 'show', $id), $address !== '' ? Button::copy(Words::copy('آدرس پنل'), $address) : null)
            ->row(Button::to(Words::DELETE . ' سرور', self::ID, 'del', $id)->danger())
            ->help('<b>🔌 بررسی اتصال</b>: پنل همین حالا پرسیده می‌شود و وضعیت هسته، منابع سرور و اینباندهایش این‌جا نشان داده می‌شود. <b>🔄 خواندن اینباندها</b>: فهرست اینباندها دوباره از پنل خوانده می‌شود؛ علامت فروش هر کدام می‌ماند. <b>🗑 حذف</b> فقط برای سروری است که اشتراکی رویش نیست؛ وگرنه آن را در تنظیمات اتصال غیرفعال کنید.')
            ->back(ServersScreen::ID)
            ->refresh(self::ID, 'show', $id);
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
