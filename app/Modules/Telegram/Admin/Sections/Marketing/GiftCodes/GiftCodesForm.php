<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\GiftCodes;

use App\Modules\Telegram\Admin\Door\ApiFile;
use App\Modules\Telegram\Admin\Sections\Marketing\Form\FormScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Form\Step;
use App\Modules\Telegram\Admin\Sections\Marketing\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Support\LocalTime;
use App\Support\Persian;

/**
 * «➕ ساخت کدها» (the panel's MakeCodesModal): what each code is worth — Toman for the wallet, or a plan's free service —,
 * how many, the batch's name, a prefix and how many days they work. One save: POST /gift-codes; the codes made are sent
 * as a text file to hand out (the panel shows them to copy), and the new batch opens.
 */
final class GiftCodesForm extends FormScreen
{
    public const ID = 'gcf';

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(int $id, array $draft): string
    {
        return '🎟 ساخت کدهای هدیه';
    }

    protected function path(): array
    {
        return [...Words::PATH, 'کدهای هدیه'];
    }

    protected function steps(Admin $admin): array
    {
        $most = self::daysMost();

        return [
            Step::choice('kind', 'ارزش هر کد', ['wallet' => 'شارژ کیف پول', 'plan' => 'سرویس رایگان']),
            Step::money('amount', 'مبلغ هر کد', 'مبلغی که هر کد به کیف پول مشتری اضافه می‌کند.')->when('kind', ['wallet']),
            Step::choice('plan_id', 'پلن', static fn(): array => Words::plans($admin, 'غیرفعال؛ فقط برای هدیه'), 'هر کد یک سرویس رایگان از این پلن می‌دهد؛ روی اولین سروری که همان لحظه تحویل بدهد. پلن غیرفعال هم می‌شود — مخصوص هدیه.')->when('kind', ['plan']),
            Step::count('count', 'تعداد کدها', 'کد', 'بین ۱ تا ۵۰۰')->range(1, 500)->quick('۱۰', 10)->quick('۵۰', 50)->quick('۱۰۰', 100),
            Step::text('batch', 'نام سری', 'کدهای یک سری با هم دیده، فرستاده و حذف می‌شوند؛ مثلا «نوروز ۱۴۰۵».'),
            Step::code('prefix', 'پیشوند', 'حروف انگلیسی و عدد، حداکثر ۱۲ کاراکتر؛ اول همه کدها می‌آید، مثل ' . Words::iso('NOWRUZ') . '.')->optional('ندارد'),
            Step::count('expires_days', 'مهلت استفاده', 'روز', 'از امروز، بین ۰ تا ' . Fmt::number($most) . ' روز؛ خالی بماند، کدها همیشه معتبرند.')->range(0, $most)->optional('همیشه معتبر')
                ->quick('۷ روز', 7)->quick('۳۰ روز', 30)->quick('۹۰ روز', 90)
                ->says(static fn(mixed $days): ?string => is_int($days) ? Fmt::number($days) . ' روز — تا پایان ' . Persian::date(self::day($days)) : null),
        ];
    }

    protected function blank(Admin $admin): array
    {
        return ['kind' => 'wallet', 'amount' => null, 'plan_id' => null, 'count' => 10, 'batch' => '', 'prefix' => null, 'expires_days' => null];
    }

    protected function back(int $id): array
    {
        return [GiftCodesScreen::ID, 'show', []];
    }

    protected function body(array $draft): array
    {
        $body = ['count' => $draft['count'] ?? null, 'kind' => $draft['kind'] ?? null, 'batch' => $draft['batch'] ?? ''];
        if (($draft['kind'] ?? null) === 'wallet') {
            $body['amount'] = $draft['amount'] ?? null;
        } elseif (is_int($draft['plan_id'] ?? null)) {
            $body['plan_id'] = $draft['plan_id'];
        }
        if (is_string($draft['prefix'] ?? null) && $draft['prefix'] !== '') {
            $body['prefix'] = $draft['prefix'];
        }
        if (is_int($draft['expires_days'] ?? null)) {
            $body['expires_on'] = self::day($draft['expires_days'])->format('Y-m-d');
        }

        return $body;
    }

    protected function store(Admin $admin, int $id, array $body): array
    {
        return $admin->api->post('/gift-codes', $body);
    }

    protected function done(Admin $admin, array $row, bool $created): View
    {
        $codes = array_values(array_filter((array) ($row['codes'] ?? []), is_string(...)));
        $batch = (string) ($row['batch'] ?? '');
        if ($codes !== []) {
            $admin->send(new ApiFile(implode("\n", $codes) . "\n", 'text/plain', 'gift-codes.txt'), '🎟 کدهای «' . Fmt::e($batch) . '»: ' . Fmt::number(count($codes)) . ' کد؛ آن‌ها را به مشتری‌ها بدهید.');
        }
        $admin->notice('✅ ' . Fmt::number(count($codes)) . ' کد هدیه ساخته شد');
        $admin->remember(GiftBatchScreen::ID, ['batch' => $batch]);

        return $admin->open(GiftBatchScreen::ID);
    }

    /** The shop's day `$days` from today, at noon on its clock. */
    private static function day(int $days): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('now', LocalTime::zone()))->setTime(12, 0)->modify("+{$days} days");
    }

    /** The longest life a batch is given today, in days: to the last day the shop keeps (LocalTime::LAST_DAY). */
    private static function daysMost(): int
    {
        $last = new \DateTimeImmutable(LocalTime::LAST_DAY . ' 12:00', LocalTime::zone());

        return max(0, (int) floor(($last->getTimestamp() - self::day(0)->getTimestamp()) / 86_400));
    }
}
