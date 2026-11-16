<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Sales;

use App\Modules\Telegram\Admin\Sections\Marketing\Form\FormScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Form\Moment;
use App\Modules\Telegram\Admin\Sections\Marketing\Form\Step;
use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «➕ افزودن فروش ویژه» / «✏️ ویرایش» (the panel's SaleForm): the name customers see, its percent off, the kinds of order
 * and plans, its window walked through, and its switch from the review. One save: POST /sales, or the sale's PUT.
 */
final class SaleForm extends FormScreen
{
    public const ID = 'slf';

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(int $id, array $draft): string
    {
        return $id === 0 ? Words::add(Marketing::SALES) : Words::EDIT . ' «' . (string) ($draft['name'] ?? '') . '»';
    }

    protected function path(): array
    {
        return Marketing::in(Marketing::SALES);
    }

    protected function steps(Admin $admin): array
    {
        return [
            Step::text('name', 'نام', 'همان چیزی که مشتری کنار قیمت می‌بیند.')->asks('اسم این فروش ویژه چه باشد؟')->example('فروش ویژه نوروز'),
            Step::percent('percent', 'درصد تخفیف')->asks('چند درصد از قیمت کم شود؟')->range(1, 100)->quick('۱۰٪', 10)->quick('۲۰٪', 20)->quick('۳۰٪', 30)->quick('۵۰٪', 50),
            Step::multi('order_types', 'برای چه سفارش‌هایی', Marketing::KINDS, 'شارژ کیف پول و خرید حجم نمایندگی هیچ‌وقت تخفیف نمی‌گیرند.')->asks('برای چه سفارش‌هایی باشد؟')->all(Marketing::EVERY_KIND),
            Step::multi('plan_ids', 'پلن‌ها', static fn(): array => Marketing::plans($admin), 'برای تمدید و حجم، زمان و کاربر اضافه، پلن سرویس حساب می‌شود.')->asks('روی کدام پلن‌ها باشد؟')->all('همه پلن‌ها'),
            Step::moment('starts_at', 'شروع', 0, Moment::HINT)->asks('از کی شروع شود؟')->optional('از همین حالا'),
            Step::moment('ends_at', 'پایان', 23, Moment::HINT)->asks('تا کی بماند؟')->optional('بدون پایان')
                ->quick('۲۴ ساعت دیگر', Moment::inDays(1))->quick('۷ روز دیگر', Moment::inDays(7))->quick('۳۰ روز دیگر', Moment::inDays(30)),
            Step::active('enabled', 'فروش ویژه غیرفعال روی قیمتی اثر ندارد.')->later(),
        ];
    }

    protected function blank(Admin $admin): array
    {
        return ['name' => '', 'percent' => null, 'order_types' => null, 'plan_ids' => null, 'starts_at' => null, 'ends_at' => null, 'enabled' => true];
    }

    protected function row(Admin $admin, int $id): ?array
    {
        return Marketing::one($admin, '/sales', 'sales', $id);
    }

    protected function draftOf(array $row): array
    {
        return [
            'name' => (string) ($row['name'] ?? ''),
            'percent' => is_int($row['percent'] ?? null) ? $row['percent'] : null,
            'order_types' => is_array($row['order_types'] ?? null) ? array_values($row['order_types']) : null,
            'plan_ids' => Marketing::ids($row['plans'] ?? null),
            'starts_at' => Moment::fromIso($row['starts_at'] ?? null),
            'ends_at' => Moment::fromIso($row['ends_at'] ?? null),
            'enabled' => (bool) ($row['enabled'] ?? true),
        ];
    }

    protected function back(int $id): array
    {
        return $id === 0 ? [SalesScreen::ID, 'show', []] : [SaleScreen::ID, 'show', [$id]];
    }

    protected function body(array $draft): array
    {
        return [
            'name' => $draft['name'] ?? '',
            'percent' => $draft['percent'] ?? null,
            'order_types' => $draft['order_types'] ?? null,
            'plan_ids' => $draft['plan_ids'] ?? null,
            'starts_at' => $draft['starts_at'] ?? null,
            'ends_at' => $draft['ends_at'] ?? null,
            'enabled' => (bool) ($draft['enabled'] ?? true),
        ];
    }

    protected function store(Admin $admin, int $id, array $body): array
    {
        $answer = $id === 0 ? $admin->api->post('/sales', $body) : $admin->api->put("/sales/{$id}", $body);

        return Json::part($answer, 'sale');
    }

    protected function done(Admin $admin, array $row, bool $created): View
    {
        $admin->notice($created ? '✅ فروش ویژه ساخته شد' : '✅ تغییرهای فروش ویژه ذخیره شد');

        return $admin->open(SaleScreen::ID, 'show', [(int) ($row['id'] ?? 0)]);
    }
}
