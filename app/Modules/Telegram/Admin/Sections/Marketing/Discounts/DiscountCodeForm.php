<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Discounts;

use App\Modules\Telegram\Admin\Sections\Marketing\Form\FormScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Form\Moment;
use App\Modules\Telegram\Admin\Sections\Marketing\Form\Step;
use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «➕ افزودن کد تخفیف» / «✏️ ویرایش» (the panel's CodeForm): the code — typed, or made at random —, what it takes off (a
 * percent with an optional cap, Toman, or everything), the kinds of order, its window and its use limits walked through;
 * the plans — categories and plans in one list (Marketing::planReach()) —, servers, least amount, first purchases only,
 * note and switch (the panel's «محدودیت‌های بیشتر») set from the review. One save: POST /discount-codes, or the code's
 * PUT.
 */
final class DiscountCodeForm extends FormScreen
{
    public const ID = 'dcf';

    /** Letters and digits nobody misreads (no 0/O, 1/I/L): the panel's «ساخت کد تصادفی». */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(int $id, array $draft): string
    {
        return $id === 0 ? Words::add('کد تخفیف') : Words::EDIT . ' کد ' . Marketing::iso((string) ($draft['code'] ?? ''));
    }

    protected function path(): array
    {
        return Marketing::in(Marketing::DISCOUNTS);
    }

    protected function laterTitle(): string
    {
        return '🎯 محدودیت‌های بیشتر';
    }

    protected function steps(Admin $admin): array
    {
        return [
            Step::code('code', 'کد', 'از ۳ تا ۳۲ حرف انگلیسی، رقم، خط تیره یا زیرخط؛ بزرگی و کوچکی حروف فرقی ندارد.')->asks('مشتری چه کدی بنویسد؟')->example('NOWRUZ')
                ->quick('🎲 ساخت کد تصادفی', self::random()),
            Step::choice('type', 'نوع تخفیف', ['percent' => 'درصدی', 'fixed' => 'مبلغ ثابت', 'free' => 'رایگان'], '«رایگان» کل مبلغ سفارش را کم می‌کند و سفارش رایگان تحویل می‌شود.')->asks('تخفیف از چه نوعی باشد؟'),
            Step::percent('percent', 'درصد تخفیف')->asks('چند درصد کم شود؟')->as('value')->range(1, 100)->when('type', ['percent'])
                ->quick('۱۰٪', 10)->quick('۲۰٪', 20)->quick('۳۰٪', 30)->quick('۵۰٪', 50),
            Step::money('fixed', 'مبلغ تخفیف', 'از مبلغ سفارش کم می‌شود، نه بیشتر از خود مبلغ.')->asks('چه مبلغی کم شود؟')->example('۲۰۰۰۰')->as('value')->when('type', ['fixed']),
            Step::money('max_discount', 'سقف تخفیف', 'تخفیف درصدی بیشتر از این مبلغ نمی‌شود.')->asks('تخفیف تا چه مبلغی باشد؟')->example('۵۰۰۰۰')->optional('بدون سقف')->when('type', ['percent']),
            Step::multi('order_types', 'برای چه سفارش‌هایی', Marketing::KINDS, 'شارژ کیف پول و خرید حجم نمایندگی هیچ‌وقت تخفیف نمی‌گیرند.')->asks('برای چه سفارش‌هایی کار کند؟')->all(Marketing::EVERY_KIND),
            Step::moment('starts_at', 'شروع', 0, Moment::HINT)->asks('از کی کار کند؟')->optional('از همین حالا'),
            Step::moment('ends_at', 'پایان', 23, Moment::HINT . ' برای کد ساعتی، روز و ساعت پایانش را بنویسید.')->asks('تا کی کار کند؟')->optional('بدون پایان')
                ->quick('۲۴ ساعت دیگر', Moment::inDays(1))->quick('۷ روز دیگر', Moment::inDays(7))->quick('۳۰ روز دیگر', Moment::inDays(30)),
            Step::count('max_uses', 'سقف کل استفاده', 'بار', 'سفارشی که پرداخت نشود و لغو شود، سهمش را پس می‌دهد.')->asks('روی هم چند بار استفاده شود؟')->range(1, 1_000_000)->optional('بدون سقف'),
            Step::count('per_user', 'سقف استفاده هر مشتری', 'بار')->asks('هر مشتری چند بار استفاده کند؟')->range(1, 1_000_000)->optional('بدون سقف')->quick('۱ (یک‌بار مصرف)', 1)
                ->says(static fn(mixed $value): ?string => $value === 1 ? 'یک‌بار مصرف برای هر مشتری' : null),
            Step::multi('plans', 'پلن‌ها', static fn(): array => Marketing::planReach($admin), 'هر ' . Icons::CATEGORIES . ' دسته یعنی همه پلن‌های آن، حتی پلنی که بعدا به آن اضافه شود. برای تمدید و حجم، زمان و کاربر اضافه، پلن سرویس حساب می‌شود.')
                ->asks('برای کدام دسته‌ها و پلن‌ها؟')->all('همه پلن‌ها')->refusesNone(Marketing::REACH_NONE)->as('plan_ids', 'category_ids')->later(),
            Step::multi('server_ids', 'سرورها', static fn(): array => Marketing::servers($admin), 'برای خرید، سروری که مشتری انتخاب می‌کند؛ برای تمدید و حجم، زمان و کاربر اضافه، سرور سرویس.')->asks('برای کدام سرورها؟')->all('همه سرورها')->later(),
            Step::money('min_amount', 'حداقل مبلغ سفارش', 'مبلغ سفارش بعد از تخفیف خودکار.')->asks('سفارش دست‌کم چه مبلغی باشد؟')->example('۱۰۰۰۰۰')->optional('هر مبلغی')->later(),
            Step::toggle('first_purchase_only', 'فقط برای خرید اول', 'فقط مشتری‌ای که هنوز سرویسی نخریده از این کد استفاده می‌کند.')->asks('فقط برای خرید اول باشد؟')->later(),
            Step::text('note', 'یادداشت', 'فقط برای خودتان؛ مشتری آن را نمی‌بیند.')->asks('یادداشتی برای خودتان؟')->example('کمپین اینستاگرام')->optional('ندارد')->later(),
            Step::active('enabled', 'کد غیرفعال از هیچ مشتری‌ای پذیرفته نمی‌شود.')->later(),
        ];
    }

    protected function blank(Admin $admin): array
    {
        return [
            'code' => '',
            'type' => 'percent',
            'percent' => null,
            'fixed' => null,
            'max_discount' => null,
            'order_types' => null,
            'starts_at' => null,
            'ends_at' => null,
            'max_uses' => null,
            'per_user' => null,
            'plans' => null,
            'server_ids' => null,
            'min_amount' => null,
            'first_purchase_only' => false,
            'note' => null,
            'enabled' => true,
        ];
    }

    protected function row(Admin $admin, int $id): ?array
    {
        return DiscountCodesScreen::fresh($admin, $id);
    }

    protected function draftOf(array $row): array
    {
        $type = (string) ($row['type'] ?? 'percent');
        $value = is_numeric($row['value'] ?? null) ? (int) $row['value'] : null;

        return [
            'code' => (string) ($row['code'] ?? ''),
            'type' => $type,
            'percent' => $type === 'percent' ? $value : null,
            'fixed' => $type === 'fixed' ? $value : null,
            'max_discount' => is_numeric($row['max_discount'] ?? null) ? (int) $row['max_discount'] : null,
            'order_types' => is_array($row['order_types'] ?? null) ? array_values($row['order_types']) : null,
            'starts_at' => Moment::fromIso($row['starts_at'] ?? null),
            'ends_at' => Moment::fromIso($row['ends_at'] ?? null),
            'max_uses' => is_int($row['max_uses'] ?? null) ? $row['max_uses'] : null,
            'per_user' => is_int($row['per_user'] ?? null) ? $row['per_user'] : null,
            'plans' => Marketing::reachOf($row['plans'] ?? null, $row['categories'] ?? null),
            'server_ids' => Marketing::ids($row['servers'] ?? null),
            'min_amount' => is_numeric($row['min_amount'] ?? null) ? (int) $row['min_amount'] : null,
            'first_purchase_only' => (bool) ($row['first_purchase_only'] ?? false),
            'note' => is_string($row['note'] ?? null) ? $row['note'] : null,
            'enabled' => (bool) ($row['enabled'] ?? true),
        ];
    }

    protected function back(int $id): array
    {
        return $id === 0 ? [DiscountCodesScreen::ID, 'show', []] : [DiscountCodeScreen::ID, 'show', [$id]];
    }

    protected function body(array $draft): array
    {
        $type = $draft['type'] ?? null;
        $body = ['code' => $draft['code'] ?? '', 'note' => $draft['note'] ?? '', 'type' => $type];
        if ($type === 'percent') {
            $body += ['value' => $draft['percent'] ?? null, 'max_discount' => $draft['max_discount'] ?? null];
        } elseif ($type === 'fixed') {
            $body['value'] = $draft['fixed'] ?? null;
        }

        return $body + [
            'min_amount' => $draft['min_amount'] ?? null,
            'order_types' => $draft['order_types'] ?? null,
            ...Marketing::reachBody($draft['plans'] ?? null),
            'server_ids' => $draft['server_ids'] ?? null,
            'first_purchase_only' => (bool) ($draft['first_purchase_only'] ?? false),
            'starts_at' => $draft['starts_at'] ?? null,
            'ends_at' => $draft['ends_at'] ?? null,
            'max_uses' => $draft['max_uses'] ?? null,
            'per_user' => $draft['per_user'] ?? null,
            'enabled' => (bool) ($draft['enabled'] ?? true),
        ];
    }

    protected function store(Admin $admin, int $id, array $body): array
    {
        $answer = $id === 0 ? $admin->api->post('/discount-codes', $body) : $admin->api->put("/discount-codes/{$id}", $body);

        return Json::part($answer, 'code');
    }

    protected function done(Admin $admin, array $row, bool $created): View
    {
        DiscountCodesScreen::keep($admin, $row);
        $admin->notice($created ? '✅ کد تخفیف ساخته شد' : '✅ تغییرهای کد ذخیره شد');

        return $admin->open(DiscountCodeScreen::ID, 'show', [(int) ($row['id'] ?? 0)]);
    }

    protected function notes(Admin $admin, int $id, array $draft): array
    {
        $uses = $id === 0 ? 0 : (int) (ListScreen::keptRow($admin, DiscountCodesScreen::ID, $id)['uses'] ?? 0);
        $notes = $uses > 0 ? ['این کد تا حالا ' . Fmt::number($uses) . ' بار استفاده شده است؛ تغییرها روی سفارش‌های قبلی اثری ندارند.'] : [];

        return [...$notes, ...Marketing::reachNotes($draft['plans'] ?? null)];
    }

    /** A draft from before 0.11.0 keeps its plans (Marketing::reachDraft()). */
    protected function upgrade(array $draft): array
    {
        return Marketing::reachDraft($draft);
    }

    /** Eight characters nobody misreads, from the system's randomness. */
    private static function random(): string
    {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }
}
