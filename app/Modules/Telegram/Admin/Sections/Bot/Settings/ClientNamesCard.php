<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Settings;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsSource;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «نام کاربری سرویس» (`client_names`): the pattern a sold service's client is named by on its panel and a free test's —
 * each with the name it gives the panel's sample customer, its variables listed where it is typed (GET /bot/settings'
 * `name_variables`) — and whether a buyer may take a random name or type their own instead.
 */
final class ClientNamesCard extends BotCard
{
    public const ID = 'bsn';

    /** Where the panel's variables are kept among the card's values. */
    private const VARIABLES = '_name_variables';

    /** What a pattern left empty is kept as. */
    private const DEFAULTS = ['name_pattern' => '{username}_{seq}', 'test_name_pattern' => 'test_{username}_{seq}'];

    protected function group(): string
    {
        return 'client_names';
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '🪪 نام کاربری سرویس';
    }

    protected function intro(): string
    {
        return 'نام کلاینت هر سرویس روی پنل سرور، که در لیست کلاینت‌های پنل هم همین را می‌بینید. نام کاربری سرویس‌های تازه با الگو ساخته می‌شود؛ سرویس‌هایی که از قبل ساخته شده‌اند نام کاربری‌شان عوض نمی‌شود.';
    }

    protected function fields(): array
    {
        $rule = "بیرون از متغیرها فقط حروف و رقم انگلیسی و \u{2068}_ - . @\u{2069} پذیرفته می‌شود، و الگو باید \u{2068}{n}\u{2069} یا \u{2068}{random}\u{2069} را داشته باشد، یا \u{2068}{seq}\u{2069} را کنار \u{2068}{username}\u{2069}، \u{2068}{tg_id}\u{2069} یا \u{2068}{user_id}\u{2069}، تا نام کاربری سرویس‌ها تکراری نشود.";

        return [
            FieldDef::text('name_pattern', 'الگوی نام کاربری سرویس', $rule)->nullable(),
            FieldDef::text('test_name_pattern', 'الگوی نام کاربری اکانت تست', $rule)->nullable(),
            FieldDef::toggle('name_random', 'نام رندوم', 'در صفحه پرداخت خرید، مشتری با دکمه «🎲 رندوم» یک نام کاربری تصادفی شش‌حرفی برای سرویسش می‌گیرد.'),
            FieldDef::toggle('name_custom', 'نام دلخواه', 'در صفحه پرداخت خرید، مشتری با دکمه «✏️ دلخواه» نام کاربری سرویسش را می‌نویسد: بین ۳ تا ۳۲ کاراکتر از حروف و رقم انگلیسی، _ و -، و نامی که روی همان سرور گرفته نشده باشد.'),
        ];
    }

    protected function notes(): array
    {
        return ['تا یکی از «نام رندوم» و «نام دلخواه» روشن نباشد، از مشتری چیزی پرسیده نمی‌شود و همه سرویس‌ها با الگو نام می‌گیرند. اگر نامی که مشتری انتخاب کرده تا تحویل سرویس روی پنل گرفته شود، سرویس با الگو نام می‌گیرد.'];
    }

    protected function source(): SettingsSource
    {
        $group = new BotGroupSource($this->group(), $this->settings);

        // The group's values, and the variables a pattern may hold beside them (the same answer's).
        return new class ($group) implements SettingsSource {
            public function __construct(private readonly BotGroupSource $group) {}

            public function load(Admin $admin): array
            {
                $answer = $admin->api->get('/bot/settings');

                return (Json::part($answer, 'settings')) + ['_name_variables' => Json::part($answer, 'name_variables')];
            }

            public function save(Admin $admin, array $values, string $field, mixed $value): array
            {
                return $this->group->save($admin, $values, $field, $value) + ['_name_variables' => $values['_name_variables'] ?? []];
            }
        };
    }

    protected function shows(FieldDef $field, mixed $value): ?string
    {
        return isset(self::DEFAULTS[$field->name]) ? Fmt::code(is_string($value) && $value !== '' ? $value : self::DEFAULTS[$field->name]) : null;
    }

    protected function explain(FieldDef $field, array $values): ?string
    {
        if (!isset(self::DEFAULTS[$field->name])) {
            return null;
        }
        $pattern = trim((string) ($values[$field->name] ?? ''));

        return "نمونه: \u{2068}" . self::example($pattern === '' ? self::DEFAULTS[$field->name] : $pattern, self::variables($values)) . "\u{2069}";
    }

    protected function question(Admin $admin, int $index, FieldDef $field, array $values): ?View
    {
        if (!isset(self::DEFAULTS[$field->name])) {
            return null;
        }
        $lines = [$field->hint, '', 'متغیرها:'];
        foreach (self::variables($values) as $variable) {
            $lines[] = Fmt::code('{' . (string) ($variable['name'] ?? '') . '}') . ' ' . Fmt::e((string) ($variable['description'] ?? ''));
        }
        $lines[] = '';
        $lines[] = 'الان: ' . Fmt::code((string) ($values[$field->name] ?? self::DEFAULTS[$field->name]));
        $prompt = new Prompt($field->label, $this->screen(), 'e', [$index], implode("\n", $lines), Button::to('✖️ لغو', $this->screen(), self::VIEW));

        return $admin->ask($prompt->choices(Button::to('↩️ الگوی پیش‌فرض', $this->screen(), 'x', $index)));
    }

    /**
     * The panel's variables a pattern may hold, as the card's values carry them.
     *
     * @param array<string, mixed> $values
     * @return list<array<string, mixed>>
     */
    private static function variables(array $values): array
    {
        return array_values(array_filter((array) ($values[self::VARIABLES] ?? []), is_array(...)));
    }

    /**
     * The name `$pattern` gives the sample customer: each variable by its example, one not listed as typed.
     *
     * @param list<array<string, mixed>> $variables
     */
    private static function example(string $pattern, array $variables): string
    {
        $examples = array_column($variables, 'example', 'name');

        return (string) preg_replace_callback('/\{([a-z_]+)\}/', static fn(array $token): string => isset($examples[$token[1]]) && is_scalar($examples[$token[1]]) ? (string) $examples[$token[1]] : $token[0], $pattern);
    }
}
