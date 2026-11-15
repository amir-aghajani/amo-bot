<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\GiftCodes;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiFile;
use App\Modules\Telegram\Admin\Sections\Marketing\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One batch of gift codes (the panel's bar over a list narrowed to it): its codes counted — unused, redeemed, expired —,
 * its unused codes as a text file to hand out (the panel's «کپی کدهای استفاده‌نشده»), its codes listed
 * (GiftCodeListScreen), and «🗑 حذف کدهای استفاده‌نشده» — asked first, with the panel's words. The batch is the one picked
 * on GiftCodesScreen (by its place there), kept by name for every action after.
 *
 * Actions: `file`, `codes`, `drop` (asks), `dropped` (discards).
 */
final class GiftBatchScreen extends BaseScreen
{
    public const ID = 'gcb';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        if (isset($args[0]) && ctype_digit($args[0])) {
            $names = (array) ($admin->state(GiftCodesScreen::ID)['batches'] ?? []);
            $name = $names[(int) $args[0]] ?? null;
            if (!is_string($name)) {
                return $admin->open(GiftCodesScreen::ID);
            }
            $admin->remember(self::ID, ['batch' => $name]);
        }
        $batch = $this->batch($admin);

        return $batch === null ? $admin->open(GiftCodesScreen::ID) : $this->view($batch);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $batch = $this->batch($admin);
        if ($batch === null) {
            return $admin->open(GiftCodesScreen::ID);
        }
        $name = (string) $batch['batch'];
        switch ($action) {
            case 'file':
                $codes = array_values(array_filter((array) ($admin->api->get('/gift-codes/unused', ['batch' => $name])['codes'] ?? []), is_string(...)));
                if ($codes === []) {
                    $admin->alert('این سری کد استفاده‌نشده‌ای ندارد.');

                    return $this->view($batch);
                }
                if ($admin->send(new ApiFile(implode("\n", $codes) . "\n", 'text/plain', 'gift-codes.txt'), '🎟 کدهای استفاده‌نشده سری «' . Fmt::e($name) . '»: ' . Fmt::number(count($codes)) . ' کد')) {
                    $admin->notice('📄 ' . Fmt::number(count($codes)) . ' کد فرستاده شد');
                }

                return $this->view($batch);
            case 'codes':
                $admin->remember(GiftCodeListScreen::ID, ['batch' => $name, 'page' => 1]);

                return $admin->open(GiftCodeListScreen::ID);
            case 'drop':
                $unused = (int) ($batch['unused'] ?? 0) + (int) ($batch['expired'] ?? 0);
                $redeemed = (int) ($batch['redeemed'] ?? 0);

                return $admin->confirm(
                    'کدهای استفاده‌نشده سری «' . $name . '» حذف شوند؟',
                    Button::to('🗑 بله، حذف شوند', self::ID, 'dropped'),
                    Button::to('✖️ انصراف', self::ID),
                    Fmt::number($unused) . ' کدی که کسی استفاده نکرده حذف می‌شود و دیگر پذیرفته نمی‌شود.' . ($redeemed > 0 ? ' ' . Fmt::number($redeemed) . ' کد استفاده‌شده برای سابقه می‌ماند.' : ''),
                );
            case 'dropped':
                $deleted = (int) ($admin->api->post('/gift-codes/discard', ['batch' => $name])['deleted'] ?? 0);
                $admin->notice('🗑 ' . Fmt::number($deleted) . ' کد استفاده‌نشده حذف شد');
                $batch = $this->batch($admin);

                return $batch === null ? $admin->open(GiftCodesScreen::ID) : $this->view($batch);
        }

        return $this->view($batch);
    }

    /**
     * The kept batch as the panel counts it now; null once none of its codes is left.
     *
     * @return array<string, mixed>|null
     */
    private function batch(Admin $admin): ?array
    {
        $name = $admin->state(self::ID)['batch'] ?? null;
        foreach (is_string($name) ? GiftCodesScreen::batches($admin) : [] as $batch) {
            if (($batch['batch'] ?? null) === $name) {
                return $batch;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $batch */
    private function view(array $batch): View
    {
        $view = View::make('🗂 سری «' . (string) $batch['batch'] . '»', ...[...Words::PATH, 'کدهای هدیه'])
            ->line(Fmt::number((int) ($batch['total'] ?? 0)) . ' کد — ' . Fmt::number((int) ($batch['unused'] ?? 0)) . ' استفاده‌نشده، ' . Fmt::number((int) ($batch['redeemed'] ?? 0)) . ' استفاده‌شده، ' . Fmt::number((int) ($batch['expired'] ?? 0)) . ' منقضی‌شده')
            ->facts(['ساخته شده' => is_string($batch['created_at'] ?? null) ? Fmt::date($batch['created_at'], true) : null])
            ->row(Button::to('📄 فایل کدهای استفاده‌نشده', self::ID, 'file'), Button::to('📋 کدهای این سری', self::ID, 'codes'));
        if ((int) ($batch['unused'] ?? 0) + (int) ($batch['expired'] ?? 0) > 0) {
            $view->row(Button::to('🗑 حذف کدهای استفاده‌نشده', self::ID, 'drop'));
        }

        return $view->back(GiftCodesScreen::ID);
    }
}
