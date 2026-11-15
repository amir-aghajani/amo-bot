<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Wheel;

use App\Modules\Telegram\Admin\Sections\Marketing\Form\FormScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Form\Step;
use App\Modules\Telegram\Admin\Sections\Marketing\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «➕ افزودن جایزه» / «✏️ ویرایش» (the panel's PrizeForm): the slice's label, what it gives — nothing, Toman, GB, days or a
 * plan's free service, each kind's amount kept apart so switching kinds loses nothing typed —, its weight, and its
 * switch from the summary. One save: POST /wheel/prizes, or the slice's PUT.
 */
final class WheelPrizeForm extends FormScreen
{
    public const ID = 'whf';

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(int $id, array $draft): string
    {
        return $id === 0 ? '➕ افزودن جایزه' : '✏️ ویرایش «' . (string) ($draft['label'] ?? '') . '»';
    }

    protected function path(): array
    {
        return [...Words::PATH, 'گردونه شانس'];
    }

    protected function createLabel(): string
    {
        return '✔️ افزودن';
    }

    protected function steps(Admin $admin): array
    {
        return [
            Step::text('label', 'متن روی گردونه', 'همان چیزی که مشتری روی گردونه و در نتیجه چرخش می‌بیند؛ حداکثر ۳۲ کاراکتر، مثل «۱۰ گیگ حجم».'),
            Step::choice('kind', 'جایزه', Words::PRIZES, '«پوچ» چیزی نمی‌دهد؛ برای این‌که هر چرخشی برنده نباشد.'),
            Step::money('wallet', 'مبلغ جایزه', 'به کیف پول مشتری اضافه می‌شود.')->as('amount')->when('kind', ['wallet']),
            Step::decimal('traffic', 'حجم جایزه', 'گیگابایت', 'به سرویس فعال مشتری اضافه می‌شود؛ اعشار هم می‌شود، مثل ۲.۵.')->as('amount')->when('kind', ['traffic']),
            Step::count('days', 'زمان جایزه', 'روز', 'به سرویس فعال مشتری اضافه می‌شود؛ بین ۱ تا ۳۶۵۰.')->as('amount')->range(1, 3650)->when('kind', ['days']),
            Step::choice('plan_id', 'پلن جایزه', static fn(): array => Words::plans($admin, 'غیرفعال؛ فقط برای هدیه'), 'یک سرویس رایگان از این پلن، روی اولین سروری که همان لحظه تحویل بدهد.')->when('kind', ['plan']),
            Step::count('weight', 'وزن', '', 'شانس هر جایزه، وزن آن تقسیم بر جمع وزن جایزه‌های فعال است؛ بین ۱ تا ۱۰۰۰۰.')->range(1, 10_000)->quick('۱', 1)->quick('۵', 5)->quick('۱۰', 10),
            Step::toggle('enabled', 'فعال', 'جایزه غیرفعال روی گردونه نمی‌آید.')->later(),
        ];
    }

    protected function blank(Admin $admin): array
    {
        return ['label' => '', 'kind' => 'wallet', 'wallet' => null, 'traffic' => null, 'days' => null, 'plan_id' => null, 'weight' => 1, 'enabled' => true];
    }

    protected function row(Admin $admin, int $id): ?array
    {
        return Words::one($admin, '/wheel/prizes', 'prizes', $id);
    }

    protected function draftOf(array $row): array
    {
        $kind = (string) ($row['kind'] ?? 'wallet');
        $amount = is_numeric($row['amount'] ?? null) ? (string) $row['amount'] : null;

        return [
            'label' => (string) ($row['label'] ?? ''),
            'kind' => $kind,
            'wallet' => $kind === 'wallet' && $amount !== null ? (int) $amount : null,
            'traffic' => $kind === 'traffic' && $amount !== null ? Words::trim($amount) : null,
            'days' => $kind === 'days' && $amount !== null ? (int) $amount : null,
            'plan_id' => is_array($row['plan'] ?? null) ? (int) ($row['plan']['id'] ?? 0) : null,
            'weight' => (int) ($row['weight'] ?? 1),
            'enabled' => (bool) ($row['enabled'] ?? true),
        ];
    }

    protected function back(int $id): array
    {
        return $id === 0 ? [WheelPrizesScreen::ID, 'show', []] : [WheelPrizeScreen::ID, 'show', [$id]];
    }

    protected function body(array $draft): array
    {
        $kind = $draft['kind'] ?? null;
        $body = ['label' => $draft['label'] ?? '', 'kind' => $kind, 'weight' => $draft['weight'] ?? null, 'enabled' => (bool) ($draft['enabled'] ?? true)];
        if (is_string($kind) && in_array($kind, ['wallet', 'traffic', 'days'], true)) {
            $body['amount'] = $draft[$kind] ?? null;
        }
        if ($kind === 'plan' && is_int($draft['plan_id'] ?? null)) {
            $body['plan_id'] = $draft['plan_id'];
        }

        return $body;
    }

    protected function store(Admin $admin, int $id, array $body): array
    {
        $answer = $id === 0 ? $admin->api->post('/wheel/prizes', $body) : $admin->api->put("/wheel/prizes/{$id}", $body);

        return Json::part($answer, 'prize');
    }

    protected function done(Admin $admin, array $row, bool $created): View
    {
        $admin->notice($created ? '✅ جایزه اضافه شد' : '✅ جایزه ذخیره شد');

        return $admin->open(WheelPrizeScreen::ID, 'show', [(int) ($row['id'] ?? 0)]);
    }
}
