<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Settings;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\View;
use App\Support\Persian;

/**
 * «حجم، زمان و کاربر اضافه» (`extras`, «خرید اضافه» in the menu): whether customers may buy extra traffic, time and
 * devices for a service, and the shop's price list — per extra the unit price, the amounts offered as buttons (each at
 * its own price or the unit price: typed «۱۰=۳۰۰۰۰، ۲۰»), and the least and most a customer may type —, shown while
 * extras are sold, as the panel holds them while not.
 */
final class ExtrasCard extends BotCard
{
    public const ID = 'bsx';

    /** Each extra's words: [title, unit price, amounts offered, least, most, unit, its most]. */
    private const KINDS = [
        'traffic' => ['حجم اضافه', 'قیمت هر گیگابایت', 'حجم‌های پیشنهادی', 'کمترین حجم دلخواه', 'بیشترین حجم دلخواه', 'گیگابایت', 10000],
        'time' => ['زمان اضافه', 'قیمت هر روز', 'زمان‌های پیشنهادی', 'کمترین زمان دلخواه', 'بیشترین زمان دلخواه', 'روز', 365],
        'devices' => ['کاربر اضافه', 'قیمت هر کاربر برای ۳۰ روز', 'تعدادهای پیشنهادی', 'کمترین تعداد دلخواه', 'بیشترین تعداد دلخواه', 'کاربر', 20],
    ];

    protected function group(): string
    {
        return 'extras';
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '➕ حجم، زمان و کاربر اضافه';
    }

    protected function intro(): string
    {
        return 'مشتری برای سرویسی که دارد، جدا از تمدید، حجم، زمان یا کاربر بیشتر می‌خرد؛ هر کدام جدا، از صفحه همان سرویس در ربات و وب‌سایت. قیمت‌های این‌جا برای همه پلن‌هاست، مگر پلنی که قیمت‌های خودش را دارد (فرم پلن، بخش «خرید اضافه»).';
    }

    protected function fields(): array
    {
        $fields = [FieldDef::toggle('extras_enabled', 'فروش حجم، زمان و کاربر اضافه', 'خاموش که باشد، هیچ‌کدام فروخته نمی‌شود: نه با این قیمت‌ها و نه با قیمت‌های خود پلن‌ها.')];
        foreach (self::KINDS as $kind => [, $price, $options, $least, $most, $unit, $max]) {
            $fields[] = FieldDef::money("extra_{$kind}_price", $price, "خالی: فقط {$options} با قیمت خودشان فروخته می‌شوند و مشتری مقدار دلخواه نمی‌نویسد.")->nullable()->when('extras_enabled', [true]);
            $fields[] = FieldDef::text("extra_{$kind}_options", $options, "دکمه‌هایی که مشتری می‌بیند، حداکثر ۸ تا؛ قیمت خالی یعنی به {$price}.")->nullable()->when('extras_enabled', [true]);
            $fields[] = FieldDef::number("extra_{$kind}_min", $least, $unit, 'بین ۱ تا ' . Persian::number($max) . " {$unit}.")->when('extras_enabled', [true]);
            $fields[] = FieldDef::number("extra_{$kind}_max", $most, $unit, 'بین ۱ تا ' . Persian::number($max) . " {$unit}، نه کمتر از کمترین.")->when('extras_enabled', [true]);
        }

        return $fields;
    }

    protected function notes(): array
    {
        return [
            'حجم اضافه: گیگابایت‌هایی که مشتری روی حجم سرویسش می‌خرد. در فروشگاه نماینده از حجم نمایندگی کم می‌شود، و بیشتر از حجمی که مانده پیشنهاد نمی‌شود.',
            'زمان اضافه: روزهایی که مشتری به زمان سرویسش اضافه می‌کند.',
            "کاربر اضافه: کاربرهایی که مشتری به تعداد کاربر هم‌زمان سرویسش اضافه می‌کند، تا پایان دوره فعلی سرویس؛ قیمتشان هم برای روزهای مانده تا همان روز حساب می‌شود و تمدید، تعداد کاربر پلن را می‌دهد. سرویسی که تعداد کاربرش نامحدود است، یا روی سروری است که تعداد کاربر را محدود نمی‌کند (\u{2068}PasarGuard\u{2069} یا \u{2068}Marzban\u{2069})، کاربر اضافه نمی‌خرد.",
            'قیمت‌های کاربر اضافه برای ۳۰ روز است؛ هر سرویس به نسبت روزهای مانده‌اش می‌پردازد، مثلا سرویسی که ۱۵ روز مانده دارد نصف آن را.',
        ];
    }

    protected function warnings(Admin $admin, array $values): array
    {
        if (($values['extras_enabled'] ?? false) !== true) {
            return [];
        }
        $warnings = [];
        foreach (self::KINDS as $kind => [, $price, , , , $unit]) {
            if (($values["extra_{$kind}_price"] ?? null) !== null) {
                continue;
            }
            foreach (self::rows($values["extra_{$kind}_options"] ?? null) as $row) {
                if ($row['price'] === null) {
                    $warnings[] = self::amount($row['quantity'], $unit) . " قیمت ندارد؛ قیمت آن، یا {$price} را بنویسید.";
                    break;
                }
            }
        }

        return $warnings;
    }

    protected function shows(FieldDef $field, mixed $value): ?string
    {
        $kind = self::optionsKind($field);
        if ($kind === null) {
            return null;
        }
        $rows = self::rows($value);
        if ($rows === []) {
            return 'هیچ';
        }

        return implode('، ', array_map(static fn(array $row): string => Fmt::e(self::amount($row['quantity'], self::KINDS[$kind][5])) . ' · ' . ($row['price'] === null ? 'به قیمت واحد' : Fmt::money($row['price'])), $rows));
    }

    protected function explain(FieldDef $field, array $values): ?string
    {
        foreach (self::KINDS as $kind => [$title, $price, , , , $unit, $max]) {
            if ($field->name !== "extra_{$kind}_max") {
                continue;
            }

            return ($values["extra_{$kind}_price"] ?? null) !== null
                ? "{$title}: مشتری می‌تواند مقدار دلخواهش را هم بنویسد، بین کمترین و بیشترین (۱ تا " . Persian::number($max) . " {$unit})."
                : "{$title}: تا {$price} خالی است، مشتری مقدار دلخواه نمی‌نویسد و کمترین و بیشترین به کار نمی‌آیند.";
        }

        return null;
    }

    protected function question(Admin $admin, int $index, FieldDef $field, array $values): ?View
    {
        $kind = self::optionsKind($field);
        if ($kind === null) {
            return null;
        }
        $unit = self::KINDS[$kind][5];
        $hint = implode("\n", [
            $field->hint,
            "هر مقدار در یک خط یا با «،» جدا؛ قیمت اختیاری بعد از «=»، به تومان. مثل: \u{2068}10=30000، 20=55000، 50\u{2069} یعنی ۱۰ {$unit} ۳۰٬۰۰۰ تومان، ۲۰ {$unit} ۵۵٬۰۰۰ تومان، ۵۰ {$unit} به قیمت واحد.",
            'الان: ' . ($this->shows($field, $values[$field->name] ?? null) ?? Fmt::NONE),
        ]);
        $prompt = new Prompt($field->label, $this->screen(), 'e', [$index], $hint, Button::to('✖️ لغو', $this->screen(), self::VIEW));
        if (self::rows($values[$field->name] ?? null) !== []) {
            $prompt->choices(Button::to('🗑 خالی کردن', $this->screen(), 'x', $index));
        }

        return $admin->ask($prompt);
    }

    protected function parse(FieldDef $field, Answer $answer): ?array
    {
        if (self::optionsKind($field) === null) {
            return null;
        }
        $rows = [];
        foreach (preg_split('/[\n،,;؛]+/u', $answer->latin()) ?: [] as $part) {
            $part = trim(str_replace(['٬', ' '], '', $part));
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(\d+)(?:[=:](\d+))?$/', $part, $match) !== 1) {
                // The part that failed, under the field too: a refusal says its field's words first.
                $why = '«' . Fmt::cut($part, 30) . '» خوانده نشد؛ هر مقدار یک عدد است، و قیمتش اگر دارد بعد از «=».';

                throw ApiRefusal::typed($field->name, $why);
            }
            $rows[] = ['quantity' => (int) $match[1], 'price' => isset($match[2]) ? (int) $match[2] : null];
        }

        return ['value' => $rows];
    }

    /** The extra whose amounts offered `$field` is; null for another field. */
    private static function optionsKind(FieldDef $field): ?string
    {
        return preg_match('/^extra_(traffic|time|devices)_options$/', $field->name, $match) === 1 ? $match[1] : null;
    }

    /**
     * The amounts offered as the panel answers them: each its quantity and its own price (null: the unit price).
     *
     * @return list<array{quantity: int, price: int|string|null}>
     */
    private static function rows(mixed $value): array
    {
        $rows = [];
        foreach (is_array($value) ? $value : [] as $row) {
            if (is_array($row) && is_numeric($row['quantity'] ?? null)) {
                $price = $row['price'] ?? null;
                $rows[] = ['quantity' => (int) $row['quantity'], 'price' => is_int($price) || is_string($price) ? $price : null];
            }
        }

        return $rows;
    }

    private static function amount(int $quantity, string $unit): string
    {
        return Persian::number($quantity) . ' ' . $unit;
    }
}
