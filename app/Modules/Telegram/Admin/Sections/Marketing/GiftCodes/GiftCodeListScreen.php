<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\GiftCodes;

use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «🎟 همه کدها» (the panel's list on «کدهای هدیه»): every code, newest first, by the panel's status tabs (chips), searched
 * by code or by the customer who took it, narrowed to one batch when GiftBatchScreen opened it — each a button with its
 * status, text and worth, opening its own screen (GiftCodeScreen). The panel has no route for one code: the page's rows
 * are kept for it.
 *
 * Actions: ListScreen's; `all` (every batch), `nob` (the batch off).
 */
final class GiftCodeListScreen extends ListScreen
{
    public const ID = 'gcl';

    /** The panel's status tabs, «همه» first. */
    public const STATUSES = [self::ANY => 'همه', 'unused' => '🟢 استفاده‌نشده', 'redeemed' => '✅ استفاده‌شده', 'expired' => '⌛️ منقضی‌شده'];

    /** The batch the list is narrowed to, read from the state as each update opens it; '' for every one. */
    private string $batch = '';

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
        return $this->batch === '' ? Icons::GIFT_CODES . ' همه کدها' : Icons::GIFT_CODES . ' کدهای سری «' . $this->batch . '»';
    }

    protected function path(): array
    {
        return Marketing::in(Marketing::GIFT_CODES);
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
        return 'کد، یا مشتری‌ای که استفاده کرد';
    }

    protected function noun(): string
    {
        return 'کد';
    }

    protected function empty(): string
    {
        return Words::NOTHING_FOUND;
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
        return Button::to(explode(' ', self::STATUSES[(string) ($row['status'] ?? '')] ?? '')[0] . ' ' . Marketing::iso((string) ($row['code'] ?? '')) . ' · ' . self::worth($row), GiftCodeScreen::ID, 'show', (int) ($row['id'] ?? 0));
    }

    protected function tools(Admin $admin): array
    {
        return [$this->batch === '' ? null : Button::to('✖️ همه سری‌ها', self::ID, 'nob')];
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
