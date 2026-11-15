<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Loyalty;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Marketing\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «🏅 سطوح وفاداری» (the panel's «سطوح وفاداری»): the tiers by what a customer has spent, lowest threshold first — a list
 * the panel answers whole —, each with its discount and extra cashback, opening its own screen (LoyaltyTierScreen);
 * «➕ افزودن سطح» (LoyaltyTierForm) and the panel's ⓘ.
 *
 * Actions: `info`.
 */
final class LoyaltyTiersScreen extends ListScreen
{
    public const ID = 'ly';

    /** The program in a paragraph: the panel's ⓘ. */
    private const PROGRAM = 'تخفیف سطح یکی از تخفیف‌های خودکار است: از میان فروش ویژه، تخفیف اختصاصی مشتری و تخفیف سطح، فقط بزرگ‌ترین روی قیمت اعمال می‌شود و کد تخفیف بعد از آن. کش‌بک سطح به درصد قانون‌های کش‌بک اضافه می‌شود، تا وقتی کش‌بک در صفحه «کش‌بک» روشن است.';

    /** How many tiers there are, as summary() read it for extra(). */
    private int $total = 0;

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
        return '🏅 سطوح وفاداری';
    }

    protected function path(): array
    {
        return Words::PATH;
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

    protected function empty(): string
    {
        return 'هنوز سطحی تعریف نشده است. بدون سطح، برنامه وفاداری خاموش است. مثلا برنزی از صفر، نقره‌ای از ۵۰۰ هزار و طلایی از ۲ میلیون تومان خرید؛ هر کدام با درصد تخفیف و کش‌بک خودش.';
    }

    protected function summary(array $meta): array
    {
        $this->total = (int) ($meta['total'] ?? 0);

        return ['هر مشتری روی بالاترین سطحی است که جمع خریدهایش به حد آن رسیده؛ خرید، تمدید و حجم، زمان و کاربر اضافه حساب می‌شود، شارژ کیف پول و سفارش برگشت‌خورده نه. مشتری سطحش را در کیف پول ربات و در وب‌سایت می‌بیند و وقتی به سطح بالاتری برسد خبردار می‌شود.'];
    }

    protected function parent(): string
    {
        return 'hm';
    }

    protected function item(array $row): Button
    {
        $spent = is_numeric($row['min_spent'] ?? null) ? (string) $row['min_spent'] : '0';
        $from = (float) $spent > 0 ? 'از ' . Fmt::money($spent) : 'از ابتدا';

        return Button::to('🏅 ' . (string) ($row['name'] ?? '') . ' · ' . $from . ' · ' . self::percent($row['discount_percent'] ?? 0) . ' / ' . self::percent($row['cashback_percent'] ?? 0), LoyaltyTierScreen::ID, 'show', (int) ($row['id'] ?? 0));
    }

    protected function extra(View $view, Admin $admin): void
    {
        if ($this->total > 0) {
            $view->note('هر ردیف: حد خرید · تخفیف / کش‌بک اضافه');
        }
        $view->row(Button::to('➕ افزودن سطح', LoyaltyTierForm::ID, 'new'), Button::to('❔ راهنما', self::ID, 'info'));
    }

    protected function action(Admin $admin, string $action, array $args): View
    {
        return $action === 'info' ? Words::help(self::ID, 'سطوح وفاداری', self::PROGRAM) : $this->show($admin, []);
    }

    /** A tier's percent, «۵٪», or «—» for none (the panel's Dash). */
    public static function percent(mixed $value): string
    {
        return is_numeric($value) && (int) $value > 0 ? Fmt::percent((int) $value) : Fmt::NONE;
    }
}
