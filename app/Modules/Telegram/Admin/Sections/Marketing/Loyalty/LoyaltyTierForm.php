<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Loyalty;

use App\Modules\Telegram\Admin\Sections\Marketing\Form\FormScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Form\Step;
use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «➕ افزودن سطح» / «✏️ ویرایش» (the panel's TierForm): the tier's name, its spending threshold, its discount and its
 * extra cashback. One save: POST /loyalty/tiers, or the tier's PUT.
 */
final class LoyaltyTierForm extends FormScreen
{
    public const ID = 'lyf';

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(int $id, array $draft): string
    {
        return $id === 0 ? Words::add('سطح') : Words::EDIT . ' «' . (string) ($draft['name'] ?? '') . '»';
    }

    protected function path(): array
    {
        return Marketing::in(Marketing::LOYALTY);
    }

    protected function steps(Admin $admin): array
    {
        return [
            Step::text('name', 'نام سطح', 'همان‌طور که مشتری در کیف پول ربات و در وب‌سایت می‌بیند.')->asks('اسم این سطح چه باشد؟')->example('طلایی'),
            Step::money('min_spent', 'حد خرید', 'جمع خرید، تمدید، حجم، زمان و کاربر اضافه — پرداخت‌شده و برگشت‌نخورده. صفر یعنی همه مشتری‌ها از ابتدا.')->asks('مشتری با چه مبلغ خریدی به این سطح برسد؟')->example('۵۰۰۰۰۰')
                ->quick('۰ (همه از ابتدا)', 0)->says(static fn(mixed $value): ?string => $value === 0 ? 'از ابتدا (همه مشتری‌ها)' : null),
            Step::percent('discount_percent', 'درصد تخفیف', 'روی هر خرید، تمدید و حجم، زمان و کاربر اضافه؛ از تخفیف‌های خودکار فقط بزرگ‌ترین اعمال می‌شود.')->asks('چند درصد تخفیف بگیرد؟')->range(0, 100)->quick('۰٪', 0)->quick('۵٪', 5)->quick('۱۰٪', 10),
            Step::percent('cashback_percent', 'درصد کش‌بک اضافه', 'روی درصد قانون‌های کش‌بک اضافه می‌شود، تا وقتی کش‌بک روشن است.')->asks('چند درصد کش‌بک اضافه بگیرد؟')->range(0, 100)->quick('۰٪', 0)->quick('۲٪', 2)->quick('۵٪', 5),
        ];
    }

    protected function blank(Admin $admin): array
    {
        return ['name' => '', 'min_spent' => null, 'discount_percent' => 0, 'cashback_percent' => 0];
    }

    protected function row(Admin $admin, int $id): ?array
    {
        return Marketing::one($admin, '/loyalty/tiers', 'tiers', $id);
    }

    protected function draftOf(array $row): array
    {
        return [
            'name' => (string) ($row['name'] ?? ''),
            'min_spent' => is_numeric($row['min_spent'] ?? null) ? (int) $row['min_spent'] : null,
            'discount_percent' => (int) ($row['discount_percent'] ?? 0),
            'cashback_percent' => (int) ($row['cashback_percent'] ?? 0),
        ];
    }

    protected function back(int $id): array
    {
        return $id === 0 ? [LoyaltyTiersScreen::ID, 'show', []] : [LoyaltyTierScreen::ID, 'show', [$id]];
    }

    protected function body(array $draft): array
    {
        return [
            'name' => $draft['name'] ?? '',
            'min_spent' => $draft['min_spent'] ?? null,
            'discount_percent' => $draft['discount_percent'] ?? null,
            'cashback_percent' => $draft['cashback_percent'] ?? null,
        ];
    }

    protected function store(Admin $admin, int $id, array $body): array
    {
        $answer = $id === 0 ? $admin->api->post('/loyalty/tiers', $body) : $admin->api->put("/loyalty/tiers/{$id}", $body);

        return Json::part($answer, 'tier');
    }

    protected function done(Admin $admin, array $row, bool $created): View
    {
        $admin->notice($created ? '✅ سطح اضافه شد' : '✅ تغییرهای سطح ذخیره شد');

        return $admin->open(LoyaltyTierScreen::ID, 'show', [(int) ($row['id'] ?? 0)]);
    }
}
