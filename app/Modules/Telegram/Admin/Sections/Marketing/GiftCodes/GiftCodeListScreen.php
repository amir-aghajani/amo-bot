<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\GiftCodes;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Marketing\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «📋 کدهای هدیه» (the panel's list on «کدهای هدیه»): every code, newest first, by the panel's status tabs, searched by
 * code or by the customer who took it, narrowed to one batch when GiftBatchScreen opened it — each opening its own
 * screen (GiftCodeScreen). The panel has no route for one code: the page's rows are kept for it.
 *
 * Actions: `all` (every batch), `nob` (the batch off).
 */
final class GiftCodeListScreen extends ListScreen
{
    public const ID = 'gcl';

    /** The panel's status tabs, «همه» first. */
    public const STATUSES = [self::ANY => 'همه', 'unused' => '🟢 استفاده‌نشده', 'redeemed' => '✅ استفاده‌شده', 'expired' => '⌛️ منقضی‌شده'];

    /** The batch the list is narrowed to, read from the state as each update opens it; '' for every one. */
    private string $batch = '';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $batch = $admin->state(self::ID)['batch'] ?? '';
        $this->batch = is_string($batch) ? $batch : '';

        return parent::show($admin, $args);
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '📋 کدهای هدیه';
    }

    protected function path(): array
    {
        return [...Words::PATH, 'کدهای هدیه'];
    }

    protected function endpoint(): string
    {
        return '/gift-codes';
    }

    protected function key(): string
    {
        return 'codes';
    }

    protected function filters(): array
    {
        return ['status' => self::STATUSES];
    }

    protected function fixed(): array
    {
        return $this->batch === '' ? [] : ['batch' => $this->batch];
    }

    protected function searchHint(): string
    {
        return 'کد یا مشتری‌ای که استفاده کرد';
    }

    protected function empty(): string
    {
        return 'کدی پیدا نشد.';
    }

    protected function summary(array $meta): array
    {
        return $this->batch === '' ? [] : ['🗂 سری «' . Fmt::e($this->batch) . '»'];
    }

    protected function keepsRows(): bool
    {
        return true;
    }

    protected function parent(): string
    {
        return $this->batch === '' ? GiftCodesScreen::ID : GiftBatchScreen::ID;
    }

    protected function item(array $row): Button
    {
        return Button::to(explode(' ', self::STATUSES[(string) ($row['status'] ?? '')] ?? '')[0] . ' ' . Words::iso((string) ($row['code'] ?? '')) . ' · ' . self::worth($row), GiftCodeScreen::ID, 'show', (int) ($row['id'] ?? 0));
    }

    protected function extra(View $view, Admin $admin): void
    {
        if ($this->batch !== '') {
            $view->row(Button::to('✖️ همه سری‌ها', self::ID, 'nob'));
        }
    }

    protected function action(Admin $admin, string $action, array $args): View
    {
        if ($action === 'all' || $action === 'nob') {
            $admin->remember(self::ID, ['batch' => '', 'page' => 1]);
        }

        return $this->show($admin, []);
    }

    /**
     * What a code is worth (the panel's Worth): Toman for the wallet, or a plan's service.
     *
     * @param array<string, mixed> $code
     */
    public static function worth(array $code): string
    {
        if (($code['kind'] ?? null) === 'wallet') {
            return Fmt::money(is_numeric($code['amount'] ?? null) ? (string) $code['amount'] : '0');
        }

        return is_array($code['plan'] ?? null) ? 'سرویس «' . (string) ($code['plan']['name'] ?? '') . '»' : 'پلن حذف شده';
    }
}
