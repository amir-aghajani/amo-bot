<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «🔐 ورود به وب‌سایت» (the panel's card of that name): how the customer signs in on the shop's website — Telegram,
 * Google, email and password, two-factor — and the devices signed in, as a summary card, then the accounts merged into
 * theirs; then two-factor sign-in turned off (a lost phone) and a sign-out on every device, each asked first in the
 * panel's words, red.
 *
 * Opened with the customer's id. Actions: `tf` (id: asks), `tfy` (id: turned off), `so` (id: asks), `soy` (id: signed out).
 */
final class CustomerAccountScreen extends BaseScreen
{
    public const ID = 'usa';

    public const TITLE = '🔐 ورود به وب‌سایت';

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
                    Button::to(Words::yes('خاموش شود'), self::ID, 'tfy', $id),
                    Button::to(Words::CANCEL, self::ID, Nav::SHOW, $id),
                    Fmt::e('از این پس کد برنامه احراز هویت از ' . $name . ' خواسته نمی‌شود: ایمیل و رمز عبورش برای ورود به وب‌سایت کافی است.')
                    . "\n" . 'برای مشتری‌ای که گوشی برنامه احراز هویتش را از دست داده است. به او خبر داده می‌شود (در ربات و با ایمیل، هر کدام که دارد) و هر وقت بخواهد دوباره روشنش می‌کند.',
                    danger: true,
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
                    Button::to(Words::yes('خارج شود'), self::ID, 'soy', $id),
                    Button::to(Words::CANCEL, self::ID, Nav::SHOW, $id),
                    Fmt::e($name . ' از همه دستگاه‌هایی که با آن وارد وب‌سایت شده است خارج می‌شود.')
                    . "\n" . 'برای ادامه باید دوباره وارد وب‌سایت شود؛ ربات تلگرام و سرویس‌هایش دست نمی‌خورد.',
                    danger: true,
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
     * The account's ways in and its devices as a card, the merged accounts under it, and what the seat may do to it.
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

        $view = View::make(self::TITLE, ...Customer::under(Customer::name($user)))
            ->card([
                'تلگرام' => is_int($user['telegram_id'] ?? null) ? 'وصل است' : 'وصل نیست',
                'گوگل' => Json::on($account, 'google') ? 'وصل است' : 'وصل نیست',
                'ایمیل' => $email === null ? '' : Fmt::ltr($email) . ' · ' . (Json::on($account, 'has_password') ? 'با رمز عبور' : 'بدون رمز عبور'),
                'ورود دو مرحله‌ای' => Fmt::onOff($twoFactor),
                'دستگاه‌ها' => $sessions > 0 ? Fmt::number($sessions) . ' دستگاه' : 'در هیچ دستگاهی وارد نیست',
            ]);

        $merges = Json::rows($account, 'merges');
        if ($merges !== []) {
            $view->section('🖇 حساب‌های ادغام‌شده');
            foreach ($merges as $merge) {
                $merged = Json::part($merge, 'merged');
                $telegram = is_int($merged['telegram_id'] ?? null) ? $merged['telegram_id'] : null;
                $view->line('• ' . implode(' · ', array_filter([
                    Fmt::e(Customer::name(['id' => Customer::int($merge, 'merged_user_id')] + $merged)),
                    Fmt::code('#' . Customer::int($merge, 'merged_user_id')),
                    $telegram !== null ? 'تلگرام ' . Fmt::code((string) $telegram) : null,
                    Json::on($merged, 'google') ? 'گوگل' : null,
                    Fmt::date(Customer::text($merge, 'created_at')),
                ])));
            }
        }
        $view->row($twoFactor ? Button::to('🔓 خاموش کردن ورود دو مرحله‌ای', self::ID, 'tf', $id)->danger() : null)
            ->row($sessions > 0 ? Button::to('🚪 خروج از همه دستگاه‌ها', self::ID, 'so', $id)->danger() : null);

        return $view->help('راه‌های ورود مشتری به وب‌سایت فروشگاه و دستگاه‌هایی که با آن وارد شده است.' . ($merges === [] ? '' : ' حساب‌های ادغام‌شده را خود مشتری با حسابش یکی کرده است.'))
            ->back(CustomerScreen::ID, Nav::SHOW, $id)
            ->refresh(self::ID, Nav::SHOW, $id);
    }
}
