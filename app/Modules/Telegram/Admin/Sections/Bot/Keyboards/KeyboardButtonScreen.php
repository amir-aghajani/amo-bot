<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Keyboards;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Bot\BotScreens;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * One button of a keyboard (the panel's button dialog), found by the bot's button it stands for — each is on a keyboard
 * once —: what it does, its text, its premium emoji icon, its colour and its place, «ردیف ۲، دکمه ۱ از ۳»; moved right,
 * left, to the row above or below (one past the last makes a new row), its text typed (a premium emoji sent in it
 * becomes its icon, as the panel lifts one pasted there), its colour picked, its icon sent or taken off, another of the
 * bot's buttons in its place, or removed, asked first. Each change is saved at once, the whole layout.
 *
 * Opened with: the keyboard's name and the button's action. Actions (those two first): `mv` (r|l|u|d), `lb` (asks),
 * `lbd` (its default text), `st` (pick), `sts` (style, `_` the default), `ic` (asks), `icx`, `ac` (pick), `acs` (action),
 * `del` (asks), `deld`.
 */
final class KeyboardButtonScreen extends BaseScreen
{
    public const ID = 'kbb';

    /** Telegram's colours (the panel's styles): their name, the colour in words and its mark. */
    public const STYLES = ['primary' => ['اصلی', 'آبی', '🔵'], 'success' => ['موفقیت', 'سبز', '🟢'], 'danger' => ['خطر', 'قرمز', '🔴']];

    private const NONE = '_';

    public function show(Admin $admin, array $args): View
    {
        $data = KeyboardScreen::read($admin, $args[0] ?? '');
        $at = $data === null ? null : self::find($data['keyboard']['rows'], $args[1] ?? '');

        return $data === null || $at === null ? $admin->open(KeyboardScreen::ID, 'show', [$args[0] ?? '']) : $this->view($data, $at);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $name = $args[0] ?? '';
        $key = $args[1] ?? '';
        $data = KeyboardScreen::read($admin, $name);
        $at = $data === null ? null : self::find($data['keyboard']['rows'], $key);
        if ($data === null || $at === null) {
            return $admin->open(KeyboardScreen::ID, 'show', [$name]);
        }
        [$r, $i] = $at;
        $rows = $data['keyboard']['rows'];
        $button = $rows[$r][$i];
        switch ($action) {
            case 'mv':
                $moved = $this->moved($rows, $r, $i, $args[2] ?? '', $data['limits']);
                if ($moved === null) {
                    return $this->view($data, $at);
                }

                return $this->saved($admin, $data, $moved, $key, '✅ جای دکمه عوض شد');
            case 'lb':
                $prompt = (new Prompt('متن دکمه', self::ID, 'lb', [$name, $key], "همان چیزی که مشتری روی دکمه می‌بیند؛ ایموجی هم می‌شود.\nایموجی پرمیوم در متن، آیکون دکمه می‌شود.", anyText: true))
                    ->current((string) ($button['label'] ?? ''))
                    ->limits('حداکثر ' . Fmt::plain(Fmt::number($data['limits']['label'])) . ' کاراکتر');
                $own = (string) (KeyboardScreen::action($data, $key)['label'] ?? '');
                if ($own !== '' && $own !== ($button['label'] ?? null)) {
                    // «↩️ پیش‌فرض» puts back the button's own words, shown as the example.
                    $prompt->example($own)->resettable(self::ID, 'lbd', $name, $key);
                }

                return $admin->ask($prompt);
            case 'lbd':
                $rows[$r][$i]['label'] = (string) (KeyboardScreen::action($data, $key)['label'] ?? $button['label'] ?? '');

                return $this->saved($admin, $data, $rows, $key, '✅ متن دکمه ذخیره شد');
            case 'st':
                return $this->styles($data, $button, $key);
            case 'sts':
                $style = $args[2] ?? self::NONE;
                $rows[$r][$i]['style'] = isset(self::STYLES[$style]) ? $style : null;

                return $this->saved($admin, $data, $rows, $key, '✅ رنگ دکمه ذخیره شد');
            case 'ic':
                $prompt = new Prompt('آیکون دکمه', self::ID, 'ic', [$name, $key], "یک ایموجی پرمیوم بفرستید؛ پیش از متن دکمه نشان داده می‌شود.\nفقط وقتی دیده می‌شود که حسابی که ربات را در \u{2068}@BotFather\u{2069} ساخته تلگرام پرمیوم داشته باشد؛ وگرنه دکمه بدون آیکون نشان داده می‌شود و همان کار را می‌کند.");
                if (($button['icon'] ?? null) !== null) {
                    $prompt->current('✨ ایموجی پرمیوم', false)->clearable(self::ID, 'icx', $name, $key);
                }

                return $admin->ask($prompt);
            case 'icx':
                $rows[$r][$i]['icon'] = null;

                return $this->saved($admin, $data, $rows, $key, '✅ آیکون دکمه برداشته شد');
            case 'ac':
                return $this->actions($data, $button, $key);
            case 'acs':
                $new = $args[2] ?? '';
                $action = KeyboardScreen::action($data, $new);
                if ($action === null || ($new !== $key && in_array($new, KeyboardScreen::taken($rows), true))) {
                    return $this->view($data, $at);
                }
                // The text follows the button while it is the button's own, not one typed.
                $own = (string) (KeyboardScreen::action($data, $key)['label'] ?? '');
                $rows[$r][$i]['action'] = $new;
                if (($button['label'] ?? null) === $own) {
                    $rows[$r][$i]['label'] = (string) ($action['label'] ?? $new);
                }

                return $this->saved($admin, $data, $rows, $new, '✅ دکمه عوض شد');
            case 'del':
                return $admin->confirm(
                    'این دکمه از کیبورد حذف شود؟',
                    Button::to(Words::yes('حذف شود', '🗑'), self::ID, 'deld', $name, $key),
                    Button::to(Words::CANCEL, self::ID, 'show', $name, $key),
                    'مشتری‌ها دیگر «' . Fmt::e((string) ($button['label'] ?? '')) . '» را در منو نمی‌بینند؛ با «➕ افزودن دکمه» برمی‌گردد.',
                );
            case 'deld':
                array_splice($rows[$r], $i, 1);
                KeyboardScreen::put($admin, $data, $data['keyboard']['type'], $rows);
                $admin->notice('🗑 دکمه حذف شد');

                return $admin->open(KeyboardScreen::ID, 'show', [$name]);
        }

        return $this->view($data, $at);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $name = $args[0] ?? '';
        $key = $args[1] ?? '';
        $data = KeyboardScreen::read($admin, $name);
        $at = $data === null ? null : self::find($data['keyboard']['rows'], $key);
        if ($data === null || $at === null) {
            return $admin->open(KeyboardScreen::ID, 'show', [$name]);
        }
        [$r, $i] = $at;
        $rows = $data['keyboard']['rows'];
        [$icon, $words] = self::lifted($answer->message());
        if ($action === 'lb') {
            if ($words === '') {
                throw new ApiRefusal(422, 'متن دکمه خالی است.');
            }
            $rows[$r][$i]['label'] = $words;
            if ($icon !== null) {
                $rows[$r][$i]['icon'] = $icon;
            }

            return $this->saved($admin, $data, $rows, $key, $icon !== null ? '✅ متن دکمه ذخیره شد و ایموجی پرمیوم آن آیکون دکمه شد' : '✅ متن دکمه ذخیره شد');
        }
        if ($action === 'ic') {
            $icon ??= preg_match('/^\d{1,20}$/', $answer->latin()) === 1 ? $answer->latin() : null;
            if ($icon === null) {
                throw new ApiRefusal(422, 'این پیام ایموجی پرمیوم ندارد؛ یک ایموجی پرمیوم بفرستید.');
            }
            $rows[$r][$i]['icon'] = $icon;

            return $this->saved($admin, $data, $rows, $key, '✅ آیکون دکمه ذخیره شد');
        }

        return $this->view($data, $at);
    }

    /**
     * @param array{keyboard: array{name: string, title: string, is_default: bool, type: string, rows: list<list<array<string, mixed>>>}, actions: list<array<string, mixed>>, styles: list<string>, limits: array{rows: int, per_row: int, label: int}} $data
     * @param array{0: int, 1: int} $at
     */
    private function view(array $data, array $at): View
    {
        [$r, $i] = $at;
        $name = $data['keyboard']['name'];
        $rows = $data['keyboard']['rows'];
        $button = $rows[$r][$i];
        $key = (string) ($button['action'] ?? '');
        $action = KeyboardScreen::action($data, $key);
        $style = self::STYLES[(string) ($button['style'] ?? '')] ?? null;
        $moves = $this->moves($rows, $r, $i, $data['limits']);

        $view = View::make('🔘 ' . (string) ($button['label'] ?? ''), ...[...BotScreens::PATH, Fmt::bare(KeyboardsScreen::TITLE), $data['keyboard']['title']])
            ->card([
                'کار دکمه' => Fmt::e((string) ($action['title'] ?? $key)) . ' ' . Fmt::ltr($key),
                'متن دکمه' => Fmt::e((string) ($button['label'] ?? '')),
                'آیکون دکمه' => ($button['icon'] ?? null) !== null ? '<tg-emoji emoji-id="' . Fmt::e((string) $button['icon']) . '">✨</tg-emoji> ایموجی پرمیوم' : 'بدون آیکون',
                'رنگ دکمه' => Fmt::e(self::chip($style)),
                'جای دکمه' => 'ردیف ' . Fmt::number($r + 1) . '، دکمه ' . Fmt::number($i + 1) . ' از ' . Fmt::number(count($rows[$r])),
            ]);
        $view->row(
            in_array('r', $moves, true) ? Button::to('➡️ به راست', self::ID, 'mv', $name, $key, 'r') : null,
            in_array('l', $moves, true) ? Button::to('⬅️ به چپ', self::ID, 'mv', $name, $key, 'l') : null,
        )->row(
            in_array('u', $moves, true) ? Button::to(Words::UP . ' ردیف بالا', self::ID, 'mv', $name, $key, 'u') : null,
            in_array('d', $moves, true) ? Button::to(Words::DOWN . ' ردیف پایین', self::ID, 'mv', $name, $key, 'd') : null,
        )
            ->tiles(
                Button::to(Words::edit('متن دکمه'), self::ID, 'lb', $name, $key),
                Button::to('🎨 رنگ دکمه', self::ID, 'st', $name, $key),
                Button::to('✨ آیکون دکمه', self::ID, 'ic', $name, $key),
                Button::to('🔁 کار دیگر', self::ID, 'ac', $name, $key),
            )
            ->row(Button::to(Words::DELETE . ' دکمه', self::ID, 'del', $name, $key)->danger());

        return $view->back(KeyboardScreen::ID, 'show', $name);
    }

    /**
     * A colour as its chip: «🔵 آبی (اصلی)», «⚪️ پیش‌فرض (رنگ خودکار)».
     *
     * @param array{0: string, 1: string, 2: string}|null $style
     */
    private static function chip(?array $style): string
    {
        return $style === null ? '⚪️ پیش‌فرض (رنگ خودکار)' : $style[2] . ' ' . $style[1] . ' (' . $style[0] . ')';
    }

    /**
     * Where a button's own pickers are: its keyboard's path, the keyboard, and the button.
     *
     * @param array{keyboard: array{name: string, title: string, is_default: bool, type: string, rows: list<list<array<string, mixed>>>}, actions: list<array<string, mixed>>, styles: list<string>, limits: array{rows: int, per_row: int, label: int}} $data
     * @param array<string, mixed> $button
     * @return list<string>
     */
    private static function under(array $data, array $button): array
    {
        $label = Fmt::bare((string) ($button['label'] ?? ''));

        return [...BotScreens::PATH, Fmt::bare(KeyboardsScreen::TITLE), $data['keyboard']['title'], ...($label === '' ? [] : [$label])];
    }

    /**
     * @param array{keyboard: array{name: string, title: string, is_default: bool, type: string, rows: list<list<array<string, mixed>>>}, actions: list<array<string, mixed>>, styles: list<string>, limits: array{rows: int, per_row: int, label: int}} $data
     * @param array<string, mixed> $button
     */
    private function styles(array $data, array $button, string $key): View
    {
        $name = $data['keyboard']['name'];
        $current = (string) ($button['style'] ?? self::NONE);
        $buttons = [Button::to(($current === self::NONE ? Words::PICKED : '') . self::chip(null), self::ID, 'sts', $name, $key, self::NONE)];
        foreach ($data['styles'] as $style) {
            if (isset(self::STYLES[$style])) {
                // Each in its own colour: the picker shows them as Telegram draws them.
                $buttons[] = Button::to(($current === $style ? Words::PICKED : '') . self::chip(self::STYLES[$style]), self::ID, 'sts', $name, $key, $style)->styled($style);
            }
        }

        return View::make('🎨 رنگ دکمه', ...self::under($data, $button))
            ->line('رنگی که تلگرام به دکمه می‌دهد؛ «پیش‌فرض» هر رنگی است که برنامه تلگرام برای دکمه‌های معمولی دارد.')
            ->grid(1, ...$buttons)->back(self::ID, 'show', $name, $key);
    }

    /**
     * The bot's buttons this one may become: those not on the keyboard, and itself.
     *
     * @param array{keyboard: array{name: string, title: string, is_default: bool, type: string, rows: list<list<array<string, mixed>>>}, actions: list<array<string, mixed>>, styles: list<string>, limits: array{rows: int, per_row: int, label: int}} $data
     * @param array<string, mixed> $button
     */
    private function actions(array $data, array $button, string $key): View
    {
        $name = $data['keyboard']['name'];
        $taken = KeyboardScreen::taken($data['keyboard']['rows']);
        $buttons = [];
        foreach ($data['actions'] as $action) {
            $other = (string) ($action['key'] ?? '');
            if ($other === $key || !in_array($other, $taken, true)) {
                $buttons[] = Button::to(($other === $key ? Words::PICKED : '') . (string) ($action['label'] ?? $other), self::ID, 'acs', $name, $key, $other);
            }
        }

        return View::make('🔁 کار دیگر برای دکمه', ...self::under($data, $button))
            ->line('کاری که این دکمه در ربات انجام می‌دهد؛ هر دکمه یک بار روی کیبورد می‌آید. متن دکمه اگر همان متن پیش‌فرضش باشد، با دکمه عوض می‌شود.')
            ->grid(2, ...$buttons)->back(self::ID, 'show', $name, $key);
    }

    /**
     * The moves open to the button at `$r`,`$i` (the panel's): along its row, or to the end of the row above or below
     * with room — from the last row, down onto a new one when it leaves a button behind.
     *
     * @param list<list<array<string, mixed>>> $rows
     * @param array{rows: int, per_row: int, label: int} $limits
     * @return list<string>
     */
    private function moves(array $rows, int $r, int $i, array $limits): array
    {
        $length = count($rows[$r]);
        $last = $r === count($rows) - 1;

        return array_values(array_filter([
            $i > 0 ? 'r' : null,
            $i < $length - 1 ? 'l' : null,
            $r > 0 && count($rows[$r - 1]) < $limits['per_row'] ? 'u' : null,
            ($last ? $length > 1 && count($rows) < $limits['rows'] : count($rows[$r + 1]) < $limits['per_row']) ? 'd' : null,
        ]));
    }

    /**
     * The rows with the button at `$r`,`$i` moved `$way`; null for a move not open to it.
     *
     * @param list<list<array<string, mixed>>> $rows
     * @param array{rows: int, per_row: int, label: int} $limits
     * @return list<list<array<string, mixed>>>|null
     */
    private function moved(array $rows, int $r, int $i, string $way, array $limits): ?array
    {
        if (!in_array($way, $this->moves($rows, $r, $i, $limits), true)) {
            return null;
        }
        $button = $rows[$r][$i];
        switch ($way) {
            case 'r':
                [$rows[$r][$i - 1], $rows[$r][$i]] = [$rows[$r][$i], $rows[$r][$i - 1]];
                break;
            case 'l':
                [$rows[$r][$i + 1], $rows[$r][$i]] = [$rows[$r][$i], $rows[$r][$i + 1]];
                break;
            case 'u':
                array_splice($rows[$r], $i, 1);
                $rows[$r - 1][] = $button;
                break;
            case 'd':
                array_splice($rows[$r], $i, 1);
                if ($r === count($rows) - 1) {
                    $rows[] = [$button];
                } else {
                    $rows[$r + 1][] = $button;
                }
                break;
        }

        return $rows;
    }

    /**
     * Save the layout, saying so, and show the button (by its action) where it now is.
     *
     * @param array{keyboard: array{name: string, title: string, is_default: bool, type: string, rows: list<list<array<string, mixed>>>}, actions: list<array<string, mixed>>, styles: list<string>, limits: array{rows: int, per_row: int, label: int}} $data
     * @param list<list<array<string, mixed>>> $rows
     * @throws ApiRefusal
     */
    private function saved(Admin $admin, array $data, array $rows, string $key, string $notice): View
    {
        $data = KeyboardScreen::put($admin, $data, $data['keyboard']['type'], $rows);
        $admin->notice($notice);
        $at = self::find($data['keyboard']['rows'], $key);

        return $at === null ? $admin->open(KeyboardScreen::ID, 'show', [$data['keyboard']['name']]) : $this->view($data, $at);
    }

    /**
     * Where the button standing for `$key` is: its row and its place in it; null when it is not on the keyboard.
     *
     * @param list<list<array<string, mixed>>> $rows
     * @return array{0: int, 1: int}|null
     */
    private static function find(array $rows, string $key): ?array
    {
        foreach ($rows as $r => $row) {
            foreach ($row as $i => $button) {
                if (($button['action'] ?? null) === $key) {
                    return [$r, $i];
                }
            }
        }

        return null;
    }

    /**
     * A message's premium emoji (its first, as the icon) and its words without it.
     *
     * @param array<string, mixed> $message
     * @return array{0: string|null, 1: string}
     */
    private static function lifted(array $message): array
    {
        $text = (string) ($message['text'] ?? $message['caption'] ?? '');
        foreach ((array) ($message['entities'] ?? []) as $entity) {
            if (is_array($entity) && ($entity['type'] ?? null) === 'custom_emoji' && preg_match('/^\d{1,20}$/', (string) ($entity['custom_emoji_id'] ?? '')) === 1) {
                $utf16 = (string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
                $words = substr($utf16, 0, (int) ($entity['offset'] ?? 0) * 2) . substr($utf16, ((int) ($entity['offset'] ?? 0) + (int) ($entity['length'] ?? 0)) * 2);

                return [(string) $entity['custom_emoji_id'], trim((string) preg_replace('/\s{2,}/u', ' ', (string) mb_convert_encoding($words, 'UTF-8', 'UTF-16LE')))];
            }
        }

        return [null, trim($text)];
    }
}
