<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Backups;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «بازگردانی»: a backup file sent here, checked by the API and described — when it was made, by which version, of what,
 * its rows table by table and what a restore leaves out —, then restored only on a yes that what changed since is lost
 * (the panel's tick, as the confirmation's button); or dropped. What a restore did stays on the screen with the state
 * kept from just before, sent as a file at a tap.
 *
 * Actions: `up` (asks for the file), `rows`, `ask`, `go` (restores), `drop`, `before` (the state kept, as a file), `again`.
 */
final class RestoreScreen extends BaseScreen
{
    public const ID = 'ppbr';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $status = BackupsScreen::status($admin);
        $state = $admin->state(self::ID);
        $whole = ($status['scope'] ?? '') === 'installation';
        $subject = $whole ? 'کل نصب' : 'فروشگاه ' . Tables::shop(is_array($status['shop'] ?? null) ? $status['shop'] : null);
        $view = View::make('♻️ بازگردانی', 'مدیریت', 'تنظیمات پنل', 'پشتیبان‌گیری');

        $restored = is_array($state['restored'] ?? null) ? $state['restored'] : null;
        if ($restored !== null) {
            return $this->restored($view, $subject, $whole, $restored);
        }

        $inspection = is_array($state['inspection'] ?? null) ? $state['inspection'] : null;
        if ($inspection === null) {
            $view->line($subject . ' را به حالت یک فایل پشتیبان برگردانید. اول فایل بررسی و محتوایش نشان داده می‌شود؛ تا تایید نکنید، چیزی تغییر نمی‌کند.')
                ->line("<i>فایل پشتیبانی (\u{2068}.json.gz\u{2069}) که از همین‌جا یا از پنل گرفته شده است. تلگرام فایل بزرگ‌تر از ۲۰ مگابایت را به ربات نمی‌دهد؛ فایل بزرگ‌تر را از پنل بازگردانی کنید.</i>")
                ->row(Button::to('📤 فرستادن فایل پشتیبان', self::ID, 'up'));

            return $view->back(BackupsScreen::ID);
        }

        $made = Fmt::date(is_string($inspection['made_at'] ?? null) ? $inspection['made_at'] : null, true);
        $tables = Json::part($inspection, 'tables');
        $view->line('<b>فایل انتخاب‌شده</b>: ' . Fmt::ltr((string) ($inspection['name'] ?? '')))
            ->facts([
                'زمان پشتیبان' => $made,
                "نسخه \u{2068}AmoBot\u{2069}" => Fmt::ltr((string) ($inspection['app_version'] ?? '')),
                'محتوا' => ($inspection['scope'] ?? '') === 'installation' ? 'کل نصب: همه فروشگاه‌ها، سرورها و نماینده‌ها' : 'فروشگاه ' . Tables::shop(is_array($inspection['shop'] ?? null) ? $inspection['shop'] : null),
                'ردیف‌ها' => Fmt::number((int) ($inspection['rows'] ?? 0)) . ' ردیف در ' . Fmt::number(count($tables)) . ' جدول',
            ]);
        $warnings = array_filter(Json::part($inspection, 'warnings'), is_string(...));
        if ($warnings !== []) {
            $view->line()->line('⚠️ <b>پیش از بازگردانی بدانید:</b>');
            foreach ($warnings as $warning) {
                $view->line('• ' . Fmt::e($warning));
            }
        }
        $view->line()->line('🛑 ' . self::loss($whole, $status, $made))
            ->note('این فایل تا ' . Fmt::date(is_string($inspection['expires_at'] ?? null) ? $inspection['expires_at'] : null, true) . ' آماده بازگردانی می‌ماند؛ پس از آن باید دوباره فرستاده شود.');
        if ($tables !== []) {
            $view->row(Button::to('📋 ردیف‌های هر جدول', self::ID, 'rows'));
        }

        return $view->row(Button::to('♻️ بازگردانی', self::ID, 'ask'), Button::to('✖️ انصراف', self::ID, 'drop'))
            ->row(Button::to('📤 فایل دیگر', self::ID, 'up'))
            ->back(BackupsScreen::ID);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $inspection = $admin->state(self::ID)['inspection'] ?? null;
        $inspection = is_array($inspection) ? $inspection : null;
        switch ($action) {
            case 'up':
                return $admin->ask(new Prompt('فایل پشتیبان', self::ID, 'up', [], "فایل پشتیبان (\u{2068}.json.gz\u{2069}) را به صورت فایل همین‌جا بفرستید؛ تا ۲۰ مگابایت.", file: true));
            case 'rows':
                if ($inspection === null) {
                    break;
                }
                $view = View::make('📋 ردیف‌های هر جدول', 'مدیریت', 'پشتیبان‌گیری', 'بازگردانی');
                foreach (Json::part($inspection, 'tables') as $table) {
                    if (is_array($table)) {
                        $view->line('• ' . Tables::name((string) ($table['table'] ?? '')) . ': ' . Fmt::number((int) ($table['rows'] ?? 0)));
                    }
                }

                return $view->back(self::ID);
            case 'ask':
                if ($inspection === null) {
                    break;
                }
                $status = BackupsScreen::status($admin);
                $made = Fmt::date(is_string($inspection['made_at'] ?? null) ? $inspection['made_at'] : null, true);

                return $admin->confirm(
                    'به حالت این پشتیبان برگردد؟',
                    Button::to('✔️ می‌دانم؛ بازگردانی شود', self::ID, 'go'),
                    Button::to('✖️ انصراف', self::ID),
                    self::loss(($status['scope'] ?? '') === 'installation', $status, $made) . "\n\n<b>می‌دانم هرچه بعد از این پشتیبان تغییر کرده، از دست می‌رود.</b>",
                );
            case 'go':
                if ($inspection === null) {
                    break;
                }
                $restore = (array) ($admin->api->post('/backups/restore', ['token' => (string) ($inspection['token'] ?? ''), 'confirm' => true])['restore'] ?? []);
                $rows = array_map(intval(...), array_filter(Json::part($restore, 'rows'), is_numeric(...)));
                $restored = ['rows' => array_sum($rows), 'tables' => count($rows), 'kept' => (string) ($restore['kept'] ?? '')];
                // A restore starts the chats over — this one's state with them —: what it did is shown from its answer.
                $admin->remember(self::ID, ['inspection' => null, 'restored' => $restored]);
                $admin->notice('✅ به حالت پشتیبان برگشت.');
                $status = BackupsScreen::status($admin);
                $whole = ($status['scope'] ?? '') === 'installation';

                return $this->restored(
                    View::make('♻️ بازگردانی', 'مدیریت', 'تنظیمات پنل', 'پشتیبان‌گیری'),
                    $whole ? 'کل نصب' : 'فروشگاه ' . Tables::shop(is_array($status['shop'] ?? null) ? $status['shop'] : null),
                    $whole,
                    $restored,
                );
            case 'drop':
                $admin->remember(self::ID, ['inspection' => null]);
                break;
            case 'before':
                // The state kept from just before the last restore: the newest kept.
                $all = BackupsScreen::status($admin)['kept'] ?? null;
                $newest = is_array($all) && is_array($all[0] ?? null) ? $all[0] : [];
                $kept = is_string($newest['name'] ?? null) ? $newest['name'] : '';
                if ($kept !== '' && BackupsScreen::deliver($admin, $admin->api->file('/backups/kept/' . rawurlencode($kept)), '🗂 حالت پیش از بازگردانی')) {
                    $admin->notice('✅ فایل فرستاده شد.');
                }
                break;
            case 'again':
                $admin->remember(self::ID, ['restored' => null, 'inspection' => null]);
                break;
        }

        return $this->show($admin, []);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        if ($action === 'up') {
            $file = $answer->document() ?? throw new ApiRefusal(422, 'فایلی نرسید؛ فایل پشتیبان را به صورت فایل (نه عکس) بفرستید. تلگرام فایل بزرگ‌تر از ۲۰ مگابایت را به ربات نمی‌دهد.');
            $inspection = (array) ($admin->api->upload('/backups/inspect', 'file', $file['name'], $file['bytes'], $file['mime'])['inspection'] ?? []);
            $admin->remember(self::ID, ['inspection' => ['name' => $file['name']] + $inspection, 'restored' => null]);
        }

        return $this->show($admin, []);
    }

    /**
     * What a restore did: the rows put back, and the state from just before, kept, to send.
     *
     * @param array<string, mixed> $restored
     */
    private function restored(View $view, string $subject, bool $whole, array $restored): View
    {
        return $view->line('✅ ' . $subject . ' به حالت پشتیبان برگشت: ' . Fmt::number((int) ($restored['rows'] ?? 0)) . ' ردیف در ' . Fmt::number((int) ($restored['tables'] ?? 0)) . ' جدول.')
            ->line('حالت پیش از بازگردانی نگه داشته شد: ' . Fmt::ltr((string) ($restored['kept'] ?? '')))
            ->line($whole ? 'کاربران وب‌سایت‌ها و مینی‌اپ‌ها باید دوباره وارد شوند.' : 'مشتری‌های این فروشگاه باید دوباره وارد وب‌سایت و مینی‌اپ شوند.')
            ->row(Button::to('📥 دریافت حالت قبل', self::ID, 'before'))
            ->row(Button::to('♻️ بازگردانی فایل دیگر', self::ID, 'again'))
            ->back(BackupsScreen::ID);
    }

    /**
     * What a restore loses, in the panel's words: everything changed since the backup, and the sign-ins and half-way
     * chats it ends — every shop's for the whole installation, the shop's alone for a shop (HTML).
     *
     * @param array<string, mixed> $status
     */
    private static function loss(bool $whole, array $status, string $made): string
    {
        $words = $whole
            ? '<b>همه داده‌های نصب، یعنی همه فروشگاه‌ها، سرورها و نماینده‌ها، به حالت ' . $made . ' برمی‌گردد</b> و هرچه از آن زمان تغییر کرده، از سفارش‌ها و پرداخت‌ها تا کاربران تازه، از دست می‌رود. پس از بازگردانی، کاربرانی که در وب‌سایت‌ها و مینی‌اپ‌ها وارد شده‌اند باید دوباره وارد شوند، گفت‌وگوهای نیمه‌کاره با ربات‌ها از اول شروع می‌شود و گزارش‌های در صف فرستاده نمی‌شوند.'
            : '<b>داده‌های فروشگاه ' . Tables::shop(is_array($status['shop'] ?? null) ? $status['shop'] : null) . ' به حالت ' . $made . ' برمی‌گردد</b> و هرچه از آن زمان در این فروشگاه تغییر کرده، از سفارش‌ها و پرداخت‌ها تا کاربران تازه، از دست می‌رود. مشتری‌هایی که در وب‌سایت و مینی‌اپ این فروشگاه وارد شده‌اند باید دوباره وارد شوند، گفت‌وگوهای نیمه‌کاره با ربات این فروشگاه از اول شروع می‌شود و گزارش‌های در صفش فرستاده نمی‌شوند. فروشگاه‌های دیگر و سرورها دست نمی‌خورند.';

        return $words . ' حالت فعلی پیش از بازگردانی نگه داشته می‌شود تا اگر لازم شد، فایلش را دریافت کنید.';
    }
}
