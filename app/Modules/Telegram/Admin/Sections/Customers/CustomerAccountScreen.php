<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Auth\StaffGrant;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «🔐 ورود به وب‌سایت» (the panel's card of that name): how the customer signs in on the shop's website — Telegram,
 * Google, email and password, two-factor —, the devices signed in and the accounts merged into theirs. For a seat the
 * `account_security` grant opens: two-factor sign-in turned off (a lost phone) and a sign-out on every device, each asked
 * first in the panel's words. One of the shop's admins only looks at a bot admin's or an agent's.
 *
 * Opened with the customer's id. Actions: `tf` (id: asks), `tfy` (id: turned off), `so` (id: asks), `soy` (id: signed out).
 */
final class CustomerAccountScreen extends BaseScreen
{
    public const ID = 'usa';

    public function show(Admin $admin, array $args): View
    {
        $id = Customer::current($admin, $args);
        if ($id === 0) {
            return $admin->open(UsersScreen::ID);
        }

        return $this->view($admin, Customer::read($admin, $id));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        if ($id === 0) {
            return $this->show($admin, []);
        }
        switch ($action) {
            case 'tf':
                $name = Customer::name(Customer::row(Customer::read($admin, $id)));

                return $admin->confirm(
                    'ورود دو مرحله‌ای این کاربر خاموش شود؟',
                    Button::to('🔓 بله، خاموش شود', self::ID, 'tfy', $id),
                    Button::to('✖️ انصراف', self::ID, 'show', $id),
                    Fmt::e('از این پس کد برنامه احراز هویت از ' . $name . ' خواسته نمی‌شود: ایمیل و رمز عبورش برای ورود به وب‌سایت کافی است.')
                    . "\n" . 'برای مشتری‌ای که گوشی برنامه احراز هویتش را از دست داده است. به او خبر داده می‌شود — در ربات تلگرام و با ایمیل، هر کدام که دارد — و در اعلان‌های وب‌سایتش هم می‌ماند. هر وقت بخواهد می‌تواند دوباره آن را روشن کند.',
                );
            case 'tfy':
                $admin->api->post("/users/{$id}/two-factor/disable");
                $view = $this->view($admin, Customer::read($admin, $id));
                $admin->notice('✅ ورود دو مرحله‌ای ' . Customer::known($admin, $id) . ' خاموش شد');

                return $view;
            case 'so':
                $name = Customer::name(Customer::row(Customer::read($admin, $id)));

                return $admin->confirm(
                    'این کاربر از همه دستگاه‌ها خارج شود؟',
                    Button::to('🚪 بله، خارج شود', self::ID, 'soy', $id),
                    Button::to('✖️ انصراف', self::ID, 'show', $id),
                    Fmt::e($name . ' از همه دستگاه‌هایی که با آن وارد وب‌سایت شده است خارج می‌شود.')
                    . "\n" . 'برای ادامه باید دوباره وارد وب‌سایت شود؛ ربات تلگرام و سرویس‌هایش دست نمی‌خورد.',
                );
            case 'soy':
                $admin->api->post("/users/{$id}/sessions/end");
                $view = $this->view($admin, Customer::read($admin, $id));
                $admin->notice('✅ ' . Customer::known($admin, $id) . ' از همه دستگاه‌ها خارج شد');

                return $view;
        }

        return $this->show($admin, [(string) $id]);
    }

    /**
     * The account's ways in, its devices and merged accounts as the panel answered them, and what the seat may do to it.
     *
     * @param array<string, mixed> $detail
     */
    private function view(Admin $admin, array $detail): View
    {
        $user = Customer::row($detail);
        $id = Customer::int($user, 'id');
        $account = Json::part($detail, 'account');
        $email = Customer::text($account, 'email');
        $twoFactor = Json::on($account, 'two_factor');
        $sessions = Customer::int($account, 'sessions');
        $lock = Customer::lock($admin, $detail);

        $view = View::make('🔐 ورود به وب‌سایت', 'مدیریت', 'فروش', 'کاربران', Customer::name($user))
            ->line('راه‌های ورود مشتری به وب‌سایت فروشگاه و دستگاه‌هایی که با آن وارد شده است.')
            ->line()
            ->facts([
                'تلگرام' => is_int($user['telegram_id'] ?? null) ? 'وصل است' : 'وصل نیست',
                'گوگل' => Json::on($account, 'google') ? 'وصل است' : 'وصل نیست',
                'ایمیل' => $email === null ? 'ایمیلی ندارد' : Fmt::ltr($email) . ' · ' . (Json::on($account, 'has_password') ? 'با رمز عبور' : 'بدون رمز عبور'),
                'ورود دو مرحله‌ای' => $twoFactor ? '✅ روشن' : 'خاموش',
                'دستگاه‌ها' => $sessions > 0 ? Fmt::number($sessions) . ' دستگاه' : 'در هیچ دستگاهی وارد نیست',
            ]);

        $merges = Json::rows($account, 'merges');
        if ($merges !== []) {
            $view->line()->line('<b>حساب‌های ادغام‌شده</b>');
            foreach ($merges as $merge) {
                $merged = Json::part($merge, 'merged');
                $telegram = is_int($merged['telegram_id'] ?? null) ? $merged['telegram_id'] : null;
                $view->line('• ' . Fmt::e(Customer::name(['id' => Customer::int($merge, 'merged_user_id')] + $merged)) . ' · حساب ' . Fmt::code('#' . Customer::int($merge, 'merged_user_id'))
                    . ($telegram !== null ? ' · شناسه تلگرام ' . Fmt::code((string) $telegram) : '')
                    . (Json::on($merged, 'google') ? ' · با گوگل' : '')
                    . ' · به درخواست خود مشتری · ' . Fmt::date(Customer::text($merge, 'created_at')));
            }
        }
        if ($lock !== null) {
            $view->line()->line($lock);
        } elseif ($admin->seat->holds(StaffGrant::AccountSecurity)) {
            if ($twoFactor) {
                $view->row(Button::to('🔓 خاموش کردن ورود دو مرحله‌ای', self::ID, 'tf', $id));
            }
            if ($sessions > 0) {
                $view->row(Button::to('🚪 خروج از همه دستگاه‌ها', self::ID, 'so', $id));
            }
        }

        return $view->back(CustomerScreen::ID, 'show', $id);
    }
}
