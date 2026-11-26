<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\ExtrasCard;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Support\Input;
use App\Support\Persian;
use App\Support\Unit;

/**
 * A plan's «خرید اضافه» in the form (the draft PlanEditScreen keeps): its services buy extra traffic, time and devices
 * at the shop's prices, or at the plan's own list — which then replaces the shop's entirely. For each extra: the price
 * of a unit, the amounts offered as buttons (each at its own price or the unit's), the least and the most a customer may
 * type, and what the bot then offers. The plan's list is kept while the shop's prices are chosen, as the panel keeps it.
 *
 * Actions: `mode` (shop|own), `k` (kind), `f` (kind, field: price|options|min|max), `x` (kind, field: emptied).
 */
final class PlanExtrasScreen extends BaseScreen
{
    public const ID = 'plx';

    /** Each extra in the panel's words. */
    private const KINDS = [
        'traffic' => [
            'title' => '📶 حجم اضافه',
            'about' => 'گیگابایت‌هایی که مشتری روی حجم سرویسش می‌خرد.',
            'unitPrice' => 'قیمت هر گیگابایت',
            'options' => 'حجم‌های پیشنهادی',
            'unit' => 'گیگابایت',
            'typed' => 'حجم دلخواه',
            'none' => 'با این قیمت‌ها حجم اضافه فروخته نمی‌شود.',
        ],
        'time' => [
            'title' => '⏳ زمان اضافه',
            'about' => 'روزهایی که مشتری به زمان سرویسش اضافه می‌کند.',
            'unitPrice' => 'قیمت هر روز',
            'options' => 'زمان‌های پیشنهادی',
            'unit' => 'روز',
            'typed' => 'زمان دلخواه',
            'none' => 'با این قیمت‌ها زمان اضافه فروخته نمی‌شود.',
        ],
        'devices' => [
            'title' => '👥 کاربر اضافه',
            'about' => 'کاربرهایی که مشتری به تعداد کاربر هم‌زمان سرویسش اضافه می‌کند، تا پایان دوره فعلی سرویس؛ قیمتشان هم برای روزهای مانده تا همان روز حساب می‌شود و تمدید، تعداد کاربر پلن را می‌دهد. سرویسی که تعداد کاربرش نامحدود است، یا روی سروری است که تعداد کاربر را محدود نمی‌کند، کاربر اضافه نمی‌خرد.',
            'unitPrice' => 'قیمت هر کاربر برای ۳۰ روز',
            'options' => 'تعدادهای پیشنهادی',
            'unit' => 'کاربر',
            'typed' => 'تعداد دلخواه',
            'none' => 'با این قیمت‌ها کاربر اضافه فروخته نمی‌شود.',
        ],
    ];

    /** The bounds a plan's own list starts within while the shop's are not read (ExtrasSettings' defaults). */
    private const BOUNDS = ['traffic' => [1, 100], 'time' => [1, 30], 'devices' => [1, 5]];

    /** The most amounts a list offers as buttons (ExtraOptions::MOST). */
    private const MOST = 8;

    public function show(Admin $admin, array $args): View
    {
        $draft = PlanEditScreen::draft($admin);
        if ($draft === null) {
            return $admin->open(PlansScreen::ID);
        }
        $own = is_array($draft['extras'] ?? null) ? $draft['extras'] : null;
        $view = View::make(PlanDraft::TABS['extras'], ...PlanEditScreen::under());
        $off = (self::shop($admin)['extras_enabled'] ?? null) === false;
        if ($off) {
            $view->line('⚠️ فروش حجم، زمان و کاربر اضافه در تنظیمات ربات خاموش است؛ تا روشن نشود، مشتری‌ها هیچ‌کدام را نمی‌خرند، نه با قیمت‌های فروشگاه و نه با قیمت‌های این پلن.');
        }
        $view->card(['قیمت‌ها' => CatalogFmt::extras($own !== null)]);
        if ($own !== null) {
            foreach (array_keys(self::KINDS) as $kind) {
                $view->section(self::KINDS[$kind]['title'])->lines(...self::offer($own, $kind));
            }
        }

        $view->row(
            Button::to(($own === null ? Words::PICKED : '') . CatalogFmt::extras(false), self::ID, 'mode', 'shop'),
            Button::to(($own !== null ? Words::PICKED : '') . CatalogFmt::extras(true), self::ID, 'mode', 'own'),
        );
        if ($own !== null) {
            $view->tiles(...array_map(static fn(string $kind): Button => Button::to(Words::edit(Fmt::bare(self::KINDS[$kind]['title'])), self::ID, 'k', $kind), array_keys(self::KINDS)));
        }
        if ($off || $own === null) {
            // Opened from here, the shop's card goes back here (Origin): the plan's draft is not left behind.
            $view->row($admin->opens(ExtrasCard::ID) ? Button::to(ExtrasCard::TITLE, ExtrasCard::ID, Nav::SHOW, 'from.' . self::ID) : null);
        }

        return $view->help($own === null
            ? 'سرویس‌های این پلن حجم، زمان و کاربر اضافه را با قیمت‌های فروشگاه می‌خرند؛ آن قیمت‌ها در تنظیمات ربات، بخش «' . Fmt::e(Fmt::bare(ExtrasCard::TITLE)) . '» است. «' . Fmt::e(CatalogFmt::extras(true)) . '» فهرستی جدا فقط برای همین پلن می‌سازد.'
            : 'این قیمت‌ها فقط برای سرویس‌های همین پلن است و جای قیمت‌های فروشگاه را می‌گیرد؛ چیزی که این‌جا قیمت ندارد برای این پلن فروخته نمی‌شود. فهرست تا ذخیره پلن در پیش‌نویس می‌ماند.')
            ->back(PlanEditScreen::ID, 'sum', 'extras');
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $draft = PlanEditScreen::draft($admin);
        if ($draft === null) {
            return $admin->open(PlansScreen::ID);
        }
        $kind = (string) ($args[0] ?? '');
        $field = (string) ($args[1] ?? '');
        $own = is_array($draft['extras'] ?? null) ? $draft['extras'] : null;
        if ($action === 'mode') {
            if ($kind === 'shop') {
                PlanEditScreen::put($admin, ['extras' => null, 'extras_kept' => $own ?? $draft['extras_kept'] ?? null], 'extras');
            } elseif ($own === null) {
                $kept = is_array($draft['extras_kept'] ?? null) ? $draft['extras_kept'] : self::start($admin);
                PlanEditScreen::put($admin, ['extras' => $kept, 'extras_kept' => $kept], 'extras');
            }

            return $this->show($admin, []);
        }
        if ($own === null || !isset(self::KINDS[$kind])) {
            return $this->show($admin, []);
        }

        return match ($action) {
            'f' => $this->ask($admin, $own, $kind, $field),
            'x' => $this->kept($admin, $own, $kind, [$field === 'options' ? "{$kind}_options" : "{$kind}_price" => $field === 'options' ? [] : '']),
            default => $this->kind($admin, $own, $kind),
        };
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $draft = PlanEditScreen::draft($admin);
        $kind = (string) ($args[0] ?? '');
        $field = (string) ($args[1] ?? '');
        $own = is_array($draft['extras'] ?? null) ? $draft['extras'] : null;
        if ($action !== 'f' || $own === null || !isset(self::KINDS[$kind])) {
            return $this->show($admin, []);
        }
        $name = "{$kind}_{$field}";
        $value = match ($field) {
            'price' => (string) ($answer->amount(Unit::Toman) ?? throw self::refused($name, 'مبلغ را به تومان و بدون اعشار بنویسید، مثل ۵۰۰۰.')),
            'options' => self::options($answer, $name),
            'min', 'max' => (string) ($answer->integer() ?? throw self::refused($name, Words::NOT_NUMBER)),
            default => null,
        };

        return $value === null ? $this->kind($admin, $own, $kind) : $this->kept($admin, $own, $kind, [$name => $value]);
    }

    /**
     * One extra's prices, what the bot offers by them, and its fields to edit.
     *
     * @param array<string, mixed> $own
     */
    private function kind(Admin $admin, array $own, string $kind): View
    {
        $words = self::KINDS[$kind];
        $price = (string) ($own["{$kind}_price"] ?? '');
        $view = View::make($words['title'], ...PlanEditScreen::under(Fmt::bare(PlanDraft::TABS['extras'])))
            ->card([
                $words['unitPrice'] => $price === '' ? 'ندارد' : Fmt::money($price),
                $words['options'] => Fmt::e(self::optionsWords($own, $kind)),
                'کمترین ' . $words['typed'] => Persian::digits((string) ($own["{$kind}_min"] ?? '')) . ' ' . Fmt::e($words['unit']),
                'بیشترین ' . $words['typed'] => Persian::digits((string) ($own["{$kind}_max"] ?? '')) . ' ' . Fmt::e($words['unit']),
            ])
            ->section('🛒 آنچه مشتری می‌بیند')
            ->lines(...array_slice(self::offer($own, $kind), 1));

        return $view->tiles(
            Button::to(Words::edit($words['unitPrice']), self::ID, 'f', $kind, 'price'),
            Button::to(Words::edit($words['options']), self::ID, 'f', $kind, 'options'),
            Button::to(Words::edit('کمترین'), self::ID, 'f', $kind, 'min'),
            Button::to(Words::edit('بیشترین'), self::ID, 'f', $kind, 'max'),
        )
            ->help(Fmt::e($words['about'] . ($kind === 'traffic' && !$admin->seat->shop()->isMain() ? ' در فروشگاه نماینده از حجم نمایندگی کم می‌شود، و بیشتر از حجمی که مانده پیشنهاد نمی‌شود.' : '')))
            ->back(self::ID);
    }

    /**
     * The question of one field of an extra.
     *
     * @param array<string, mixed> $own
     */
    private function ask(Admin $admin, array $own, string $kind, string $field): View
    {
        $words = self::KINDS[$kind];
        $priced = (string) ($own["{$kind}_price"] ?? '') !== '';
        // The least and the most count only while a unit price lets the customer type an amount.
        $bounds = $priced ? "مشتری می‌تواند {$words['typed']}ش را هم بنویسد، بین کمترین و بیشترین." : "تا {$words['unitPrice']} خالی است، مشتری {$words['typed']} نمی‌نویسد و این عدد به کار نمی‌آید.";
        [$question, $hint, $current, $example] = match ($field) {
            'price' => [$words['unitPrice'] . ' (تومان)', "خالی: فقط {$words['options']} با قیمت خودشان فروخته می‌شوند و مشتری {$words['typed']} نمی‌نویسد.", $priced ? Fmt::plain(Fmt::money((string) $own["{$kind}_price"])) : null, '۵۰۰۰'],
            'options' => [$words['options'], "دکمه‌هایی که مشتری می‌بیند. هر مقدار در یک خط، و اگر قیمت خودش را دارد پشت سرش؛ قیمت خالی یعنی به {$words['unitPrice']}.", self::optionsTyped($own, $kind), "۱۰\n۵۰ ۱۲۰۰۰۰"],
            'min' => ["کمترین {$words['typed']} ({$words['unit']})", $bounds, Persian::digits((string) ($own["{$kind}_min"] ?? '')), '۱'],
            'max' => ["بیشترین {$words['typed']} ({$words['unit']})", $bounds, Persian::digits((string) ($own["{$kind}_max"] ?? '')), '۱۰۰'],
            default => [null, '', null, ''],
        };
        if ($question === null) {
            return $this->kind($admin, $own, $kind);
        }
        $prompt = (new Prompt($question, self::ID, 'f', [$kind, $field], Fmt::e($hint), Button::to(Words::CANCEL, self::ID, 'k', $kind)))
            ->current($current)
            ->example($example)
            ->limits(match ($field) {
                'options' => 'حداکثر ' . Persian::number(self::MOST) . ' مقدار',
                'min', 'max' => 'بیش از صفر؛ بیشترین نه کمتر از کمترین',
                default => '',
            });
        if (($field === 'price' && $priced) || ($field === 'options' && (array) ($own["{$kind}_options"] ?? []) !== [])) {
            $prompt->clearable(self::ID, 'x', $kind, $field);
        }

        return $admin->ask($prompt);
    }

    /**
     * The plan's own list with `$changes`, kept in the draft; the extra's page again.
     *
     * @param array<string, mixed> $own
     * @param array<string, mixed> $changes
     */
    private function kept(Admin $admin, array $own, string $kind, array $changes): View
    {
        $own = array_replace($own, $changes);
        PlanEditScreen::put($admin, ['extras' => $own, 'extras_kept' => $own], 'extras');
        $admin->notice('✅ در پیش‌نویس پلن نوشته شد');

        return $this->kind($admin, $own, $kind);
    }

    /**
     * What the list sells of `$kind`, by the server's rule (the panel's preview): each amount offered at its own price or
     * the unit price times it, and with a unit price any amount between the bounds.
     *
     * @param array<string, mixed> $own
     * @return list<string>
     */
    private static function offer(array $own, string $kind): array
    {
        $words = self::KINDS[$kind];
        $unit = self::positive(Input::amountOf($own["{$kind}_price"] ?? '', Unit::Toman));
        $buttons = [];
        foreach ((array) ($own["{$kind}_options"] ?? []) as $option) {
            $quantity = self::positive(Input::wholeOf(is_array($option) ? ($option['quantity'] ?? '') : ''));
            if (!is_array($option) || $quantity === null || array_key_exists($quantity, $buttons)) {
                continue;
            }
            $typed = trim((string) ($option['price'] ?? ''));
            $price = $typed === '' ? null : self::positive(Input::amountOf($typed, Unit::Toman));
            if ($typed !== '' && $price === null) {
                continue;
            }
            $buttons[$quantity] = $price ?? ($unit === null ? null : $unit * $quantity);
        }
        ksort($buttons);
        $min = Input::wholeOf($own["{$kind}_min"] ?? '');
        $max = Input::wholeOf($own["{$kind}_max"] ?? '');

        $lines = [Fmt::e($words['unitPrice']) . ': ' . ($unit === null ? 'ندارد' : Fmt::money($unit))];
        foreach ($buttons as $quantity => $price) {
            $lines[] = '🔘 ' . Persian::number($quantity) . ' ' . Fmt::e($words['unit']) . ' · ' . ($price === null ? '⚠️ بدون قیمت' : Fmt::money($price));
        }
        if ($unit !== null && $min !== null && $max !== null && $min <= $max) {
            $lines[] = '✍️ ' . ($buttons === [] ? '' : 'یا ') . Fmt::e($words['typed']) . ' بین ' . Persian::number($min) . ' تا ' . Persian::number($max) . ' ' . Fmt::e($words['unit']) . '، هر ' . Fmt::e($words['unit']) . ' ' . Fmt::money($unit);
        } elseif (array_filter($buttons, static fn(?int $price): bool => $price !== null) === []) {
            $lines[] = Fmt::e($words['none']);
        }
        if ($kind === 'devices' && ($unit !== null || array_filter($buttons) !== [])) {
            $lines[] = '<i>این قیمت‌ها برای ۳۰ روز است؛ هر سرویس به نسبت روزهای مانده‌اش می‌پردازد.</i>';
        }
        $unpriced = array_search(null, $buttons, true);
        if ($unpriced !== false) {
            $lines[] = '⚠️ ' . Persian::number($unpriced) . ' ' . Fmt::e($words['unit'] . ' قیمت ندارد؛ قیمت آن، یا ' . $words['unitPrice'] . ' را بنویسید.');
        }

        return $lines;
    }

    /**
     * The amounts typed as buttons: one a line (or «،» between them), each with its own price after it or none.
     *
     * @return list<array{quantity: string, price: string}>
     */
    private static function options(Answer $answer, string $name): array
    {
        $text = str_replace('٬', ',', $answer->latin());
        $parts = str_contains($text, "\n") ? explode("\n", $text) : (preg_split('/[،;]|,(?!\d{3}(?!\d))/u', $text) ?: [$text]);
        $options = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(\d{1,6})\s*(?:[:=\-]\s*)?(.*)$/u', $part, $match) !== 1) {
                throw self::refused($name, 'هر مقدار را با یک عدد بنویسید، و اگر قیمت خودش را دارد پشت سرش، مثل «۱۰ ۴۵۰۰۰».');
            }
            $price = trim($match[2]);
            $amount = $price === '' ? null : Input::amountOf($price, Unit::Toman);
            if ($price !== '' && $amount === null) {
                throw self::refused($name, "قیمت «{$part}» را به تومان و بدون اعشار بنویسید.");
            }
            $options[] = ['quantity' => $match[1], 'price' => $amount === null ? '' : (string) $amount];
        }

        return $options;
    }

    /**
     * The amounts offered, in words.
     *
     * @param array<string, mixed> $own
     */
    private static function optionsWords(array $own, string $kind): string
    {
        $words = [];
        foreach ((array) ($own["{$kind}_options"] ?? []) as $option) {
            if (is_array($option)) {
                $words[] = Persian::digits((string) ($option['quantity'] ?? '')) . ((string) ($option['price'] ?? '') !== '' ? ' (' . Fmt::money((string) $option['price']) . ')' : '');
            }
        }

        return $words === [] ? 'هیچ' : implode('، ', $words);
    }

    /**
     * The amounts offered as they are typed back (options()): a line each, its own price after it; null for none.
     *
     * @param array<string, mixed> $own
     */
    private static function optionsTyped(array $own, string $kind): ?string
    {
        $lines = [];
        foreach ((array) ($own["{$kind}_options"] ?? []) as $option) {
            if (is_array($option)) {
                $lines[] = trim((string) ($option['quantity'] ?? '') . ' ' . (string) ($option['price'] ?? ''));
            }
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * A list that sells nothing, where a plan's own list starts: within the shop's bounds when they are read.
     *
     * @return array<string, mixed>
     */
    private static function start(Admin $admin): array
    {
        $shop = self::shop($admin);
        $list = [];
        foreach (self::BOUNDS as $kind => [$least, $most]) {
            $list["{$kind}_price"] = '';
            $list["{$kind}_options"] = [];
            $list["{$kind}_min"] = (string) (is_int($shop["extra_{$kind}_min"] ?? null) ? $shop["extra_{$kind}_min"] : $least);
            $list["{$kind}_max"] = (string) (is_int($shop["extra_{$kind}_max"] ?? null) ? $shop["extra_{$kind}_max"] : $most);
        }

        return $list;
    }

    /**
     * The shop's bot settings, for its extras switch and bounds; none when they cannot be read.
     *
     * @return array<string, mixed>
     */
    private static function shop(Admin $admin): array
    {
        try {
            return BotGroupSource::settings($admin);
        } catch (ApiRefusal) {
            return [];
        }
    }

    private static function positive(?int $number): ?int
    {
        return $number !== null && $number >= 1 ? $number : null;
    }

    private static function refused(string $field, string $message): ApiRefusal
    {
        return ApiRefusal::typed("extras.{$field}", $message);
    }
}
