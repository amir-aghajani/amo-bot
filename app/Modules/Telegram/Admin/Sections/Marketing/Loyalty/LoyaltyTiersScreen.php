<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Loyalty;

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
 * «🏅 سطوح وفاداری» (the panel's «سطوح وفاداری»): the tiers by what a customer has spent, lowest threshold first — a list
 * the panel answers whole —, each a button with its threshold, discount and extra cashback, opening its own screen
 * (LoyaltyTierScreen); «➕ افزودن سطح» (LoyaltyTierForm) and the panel's ⓘ, folded.
 *
 * Actions: ListScreen's; `info` (an older window's «❔ راهنما»: the list, its help folded in it).
 */
final class LoyaltyTiersScreen extends ListScreen
{
    public const ID = 'ly';

    public const TITLE = Icons::LOYALTY . ' ' . Marketing::LOYALTY;

    /** The program in a paragraph: the panel's ⓘ. */
    private const PROGRAM = 'هر مشتری روی بالاترین سطحی است که جمع خریدهایش به حد آن رسیده؛ خرید، تمدید و حجم، زمان و کاربر اضافه حساب می‌شود، شارژ کیف پول و سفارش برگشت‌خورده نه. مشتری سطحش را در کیف پول ربات و در وب‌سایت می‌بیند و وقتی به سطح بالاتری برسد خبردار می‌شود.' . "\n\n"
        . 'تخفیف سطح یکی از تخفیف‌های خودکار است: از میان فروش ویژه، تخفیف اختصاصی مشتری و تخفیف سطح، فقط بزرگ‌ترین روی قیمت اعمال می‌شود و کد تخفیف بعد از آن. کش‌بک سطح به درصد قانون‌های کش‌بک اضافه می‌شود، تا وقتی کش‌بک روشن است.';

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
        return '/loyalty/tiers';
    }

    protected function key(): string
    {
        return 'tiers';
    }

    protected function searchable(): bool
    {
        return false;
    }

    protected function noun(): string
    {
        return 'سطح';
    }

    protected function empty(): string
    {
        return 'هنوز سطحی تعریف نشده است؛ بدون سطح، برنامه وفاداری خاموش است. مثلا برنزی از صفر، نقره‌ای از ۵۰۰ هزار و طلایی از ۲ میلیون تومان خرید؛ هر کدام با درصد تخفیف و کش‌بک خودش.';
    }

    protected function parent(): string
    {
        return Marketing::MENU;
    }

    protected function item(array $row): Button
    {
        $parts = [Icons::LOYALTY . ' ' . (string) ($row['name'] ?? ''), self::from($row)];
        if ((int) ($row['discount_percent'] ?? 0) > 0) {
            $parts[] = self::percent($row['discount_percent']) . ' تخفیف';
        }
        if ((int) ($row['cashback_percent'] ?? 0) > 0) {
            $parts[] = self::percent($row['cashback_percent']) . ' کش‌بک';
        }

        return Button::to(implode(' · ', $parts), LoyaltyTierScreen::ID, 'show', (int) ($row['id'] ?? 0));
    }

    protected function extra(View $view, Admin $admin): void
    {
        $view->row(Button::to(Words::add('سطح'), LoyaltyTierForm::ID, 'new')->success())
            ->help(Fmt::e(self::PROGRAM));
    }

    /**
     * Where a tier starts: «از ۵۰۰٬۰۰۰ تومان», or «از ابتدا» for none.
     *
     * @param array<string, mixed> $tier
     */
    public static function from(array $tier): string
    {
        $spent = is_numeric($tier['min_spent'] ?? null) ? (string) $tier['min_spent'] : '0';

        return (float) $spent > 0 ? 'از ' . Fmt::money($spent) : 'از ابتدا';
    }

    /** A tier's percent, «۵٪», or «—» for none (the panel's Dash). */
    public static function percent(mixed $value): string
    {
        return is_numeric($value) && (int) $value > 0 ? Fmt::percent((int) $value) : Fmt::NONE;
    }
}
