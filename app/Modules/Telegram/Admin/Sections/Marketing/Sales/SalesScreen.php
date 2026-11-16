<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Sales;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «🔥 فروش ویژه» (the panel's «فروش ویژه»): the shop's timed percent-off sales, newest first — a list the panel answers
 * whole —, each a button with its state's chip, name and percent, opening its own screen (SaleScreen); «➕ افزودن فروش
 * ویژه» (SaleForm) and the panel's ⓘ, folded.
 *
 * Actions: ListScreen's; `info` (an older window's «❔ راهنما»: the list, its help folded in it).
 */
final class SalesScreen extends ListScreen
{
    public const ID = 'sl';

    public const TITLE = Icons::SPECIAL_SALES . ' ' . Marketing::SALES;

    /** The program in a paragraph: the panel's ⓘ. */
    private const PROGRAM = 'فروش ویژه درصدی از قیمت پلن‌هایی که انتخاب می‌کنید کم می‌کند، از زمان شروع تا زمان پایانش. اگر مشتری تخفیف اختصاصی یا تخفیف سطح وفاداری هم داشته باشد، فقط بزرگ‌ترین آن‌ها اعمال می‌شود و کد تخفیف روی مبلغ باقی‌مانده می‌آید. قیمت تخفیف‌خورده همه‌جا دیده می‌شود: دکمه پلن‌ها و جزئیات پلن در ربات، صفحه پرداخت و قیمت پلن در وب‌سایت.';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return self::TITLE;
    }

    protected function path(): array
    {
        return Marketing::PATH;
    }

    protected function endpoint(): string
    {
        return '/sales';
    }

    protected function key(): string
    {
        return 'sales';
    }

    protected function searchable(): bool
    {
        return false;
    }

    protected function noun(): string
    {
        return 'فروش ویژه';
    }

    protected function empty(): string
    {
        return 'هنوز فروش ویژه‌ای نساخته‌اید. برای مناسبت‌ها — نوروز، یلدا، جمعه سیاه — درصدی از قیمت پلن‌ها کم کنید، از یک روز و ساعت تا روز و ساعت دیگر.';
    }

    protected function parent(): string
    {
        return Marketing::MENU;
    }

    protected function item(array $row): Button
    {
        // A long name (up to 64) cut, so its percent stays on its button.
        return Button::to(Marketing::mark($row['state'] ?? null) . ' ' . Fmt::cut((string) ($row['name'] ?? ''), 32) . ' · ' . Fmt::percent((int) ($row['percent'] ?? 0)), SaleScreen::ID, 'show', (int) ($row['id'] ?? 0));
    }

    protected function extra(View $view, Admin $admin): void
    {
        $view->row(Button::to(Words::add(Marketing::SALES), SaleForm::ID, 'new')->success())
            ->help(Fmt::e(self::PROGRAM));
    }
}
