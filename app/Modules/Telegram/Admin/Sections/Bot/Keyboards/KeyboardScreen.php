<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Keyboards;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One keyboard of the bot's (the panel's editor of it): its buttons laid out as the customer sees them — a row a row,
 * the first of each on the right —, each opening its own screen (KeyboardButtonScreen: text, colour, icon, place); its
 * kind (the keyboard under the typing box, or glass buttons under the greeting), «➕ افزودن دکمه» (a row, then one of the
 * bot's buttons not on it yet), the rows moved up or down or removed, and the built-in layout back, asked first. Each
 * change is saved at once (PUT /keyboards/{name}, the whole layout, as the panel's save): the bot shows it from then on.
 *
 * Opened with: the keyboard's name. Actions (the name first): `type`, `kind` (reply|inline), `add`, `addr` (row: n, or
 * the number of rows for a new one), `adda` (row, action), `rows`, `mr` (row, u|d), `dr` (row: asks), `drd` (row, its
 * first button's action: the row asked about), `rs` (asks), `rsd`.
 */
final class KeyboardScreen extends BaseScreen
{
    public const ID = 'kbl';

    /** The two kinds, with what each does (the panel's TYPES and TYPE_HINT). */
    public const TYPES = [
        'reply' => ['کیبورد پایین صفحه', 'دکمه‌ها زیر جعبه متن می‌مانند و همیشه در دسترس‌اند؛ متن هر دکمه همان پیامی است که مشتری با زدنش می‌فرستد.'],
        'inline' => ['دکمه‌های شیشه‌ای', 'دکمه‌ها زیر پیام خوش‌آمد می‌چسبند؛ صفحه‌های بعدی دکمه «بازگشت» به منو می‌گیرند.'],
    ];

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $data = self::read($admin, $args[0] ?? '');

        return $data === null ? $admin->open('hb') : $this->view($data);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $name = $args[0] ?? '';
        $data = self::read($admin, $name);
        if ($data === null) {
            return $admin->open('hb');
        }
        $rows = $data['keyboard']['rows'];
        $row = self::id($args, 1);
        switch ($action) {
            case 'type':
                return $this->types($data);
            case 'kind':
                $type = ($args[1] ?? '') === 'inline' ? 'inline' : 'reply';

                return $this->saved($admin, $data, $type, $rows, '✅ نوع کیبورد ذخیره شد: ' . self::TYPES[$type][0]);
            case 'add':
                return $this->rowsToAdd($data);
            case 'addr':
                return $this->actionsToAdd($data, $row);
            case 'adda':
                $key = $args[2] ?? '';
                $action = self::action($data, $key);
                if ($action === null || in_array($key, self::taken($rows), true)) {
                    return $this->view($data);
                }
                $button = ['action' => $key, 'label' => (string) ($action['label'] ?? $key), 'style' => null, 'icon' => null];
                if ($row >= count($rows)) {
                    $rows[] = [$button];
                } else {
                    $rows[$row][] = $button;
                }
                $data = self::put($admin, $data, $data['keyboard']['type'], $rows);
                $admin->notice('✅ دکمه «' . (string) ($action['title'] ?? $key) . '» اضافه شد');

                return $admin->open(KeyboardButtonScreen::ID, 'show', [$name, $key]);
            case 'rows':
                return $this->rows($data);
            case 'mr':
                $target = ($args[2] ?? '') === 'u' ? $row - 1 : $row + 1;
                if (isset($rows[$row], $rows[$target])) {
                    [$rows[$row], $rows[$target]] = [$rows[$target], $rows[$row]];
                    $data = self::put($admin, $data, $data['keyboard']['type'], $rows);
                    $admin->notice('✅ جای ردیف عوض شد');
                }

                return $this->rows($data);
            case 'dr':
                if (!isset($rows[$row])) {
                    return $this->rows($data);
                }

                // The confirmation carries the row's first button: the row it asked about, not whichever sits there then.
                return $admin->confirm(
                    'ردیف ' . Fmt::plain(Fmt::number($row + 1)) . ' و دکمه‌هایش حذف شود؟',
                    Button::to('🗑 بله، حذف شود', self::ID, 'drd', $name, $row, (string) ($rows[$row][0]['action'] ?? '')),
                    Button::to('✖️ انصراف', self::ID, 'rows', $name),
                    '«' . Fmt::e(implode('، ', array_map(static fn(array $button): string => (string) ($button['label'] ?? ''), $rows[$row]))) . '» — مشتری‌ها دیگر این دکمه‌ها را در منو نمی‌بینند؛ با «➕ افزودن دکمه» برمی‌گردند.',
                );
            case 'drd':
                if (!isset($rows[$row]) || ($rows[$row][0]['action'] ?? null) !== ($args[2] ?? '')) {
                    // A second tap, or the layout changed since it was asked: nothing removed.
                    $admin->alert('این ردیف از وقتی پرسیده شد عوض شده است؛ چیزی حذف نشد. ردیف‌ها را دوباره ببینید.');

                    return $this->rows($data);
                }
                array_splice($rows, $row, 1);
                $data = self::put($admin, $data, $data['keyboard']['type'], $rows);
                $admin->notice('🗑 ردیف حذف شد');

                return $this->rows($data);
            case 'rs':
                return $admin->confirm(
                    'بازگشت به چیدمان پیش‌فرض؟',
                    Button::to('↩️ بازگشت به پیش‌فرض', self::ID, 'rsd', $name),
                    Button::to('✖️ انصراف', self::ID, 'show', $name),
                    'چیدمان فعلی کنار گذاشته می‌شود و کیبورد به حالت اولیه برمی‌گردد. این کار برگشت‌پذیر نیست.',
                );
            case 'rsd':
                $admin->api->post("/keyboards/{$name}/reset");
                $admin->notice('✅ کیبورد به چیدمان پیش‌فرض برگشت');

                return $this->show($admin, [$name]);
        }

        return $this->view($data);
    }

    /**
     * The keyboards the panel lists (GET /keyboards).
     *
     * @return list<array<string, mixed>>
     * @throws ApiRefusal
     */
    public static function keyboards(Admin $admin): array
    {
        return array_values(array_filter((array) ($admin->api->get('/keyboards')['keyboards'] ?? []), static fn(mixed $keyboard): bool => is_array($keyboard) && is_string($keyboard['name'] ?? null)));
    }

    /**
     * Whether the start menu has a button for `$action` (a program's screen customers reach by it).
     *
     * @throws ApiRefusal
     */
    public static function hasButton(Admin $admin, string $action): bool
    {
        foreach (self::keyboards($admin) as $keyboard) {
            if ($keyboard['name'] === 'start') {
                return in_array($action, self::taken(self::rowsOf($keyboard)), true);
            }
        }

        return true;
    }

    /**
     * The keyboard `$name` with what its editor needs — the bot's buttons, the colours, the limits —; null for none.
     *
     * @return array{keyboard: array{name: string, title: string, is_default: bool, type: string, rows: list<list<array<string, mixed>>>}, actions: list<array<string, mixed>>, styles: list<string>, limits: array{rows: int, per_row: int, label: int}, count: int}|null
     * @throws ApiRefusal
     */
    public static function read(Admin $admin, string $name): ?array
    {
        $answer = $admin->api->get('/keyboards');
        $keyboards = Json::rows($answer, 'keyboards');
        foreach ($keyboards as $keyboard) {
            if (($keyboard['name'] ?? null) === $name) {
                return self::data($answer, $keyboard, count($keyboards));
            }
        }

        return null;
    }

    /**
     * Save the layout — the whole of it, as the panel's save — and the keyboard as the panel then has it.
     *
     * @param array{keyboard: array{name: string, title: string, is_default: bool, type: string, rows: list<list<array<string, mixed>>>}, actions: list<array<string, mixed>>, styles: list<string>, limits: array{rows: int, per_row: int, label: int}, count: int} $data
     * @param list<list<array<string, mixed>>> $rows
     * @return array{keyboard: array{name: string, title: string, is_default: bool, type: string, rows: list<list<array<string, mixed>>>}, actions: list<array<string, mixed>>, styles: list<string>, limits: array{rows: int, per_row: int, label: int}, count: int}
     * @throws ApiRefusal
     */
    public static function put(Admin $admin, array $data, string $type, array $rows): array
    {
        $rows = array_values(array_filter(array_map(array_values(...), $rows), static fn(array $row): bool => $row !== []));
        $keyboard = (array) ($admin->api->put('/keyboards/' . $data['keyboard']['name'], ['type' => $type, 'rows' => $rows])['keyboard'] ?? []);

        return self::data(['actions' => $data['actions'], 'styles' => $data['styles'], 'limits' => $data['limits']], $keyboard, $data['count']);
    }

    /**
     * The bot's button `$key` stands for; null for none.
     *
     * @param array{actions: list<array<string, mixed>>} $data
     * @return array<string, mixed>|null
     */
    public static function action(array $data, string $key): ?array
    {
        foreach ($data['actions'] as $action) {
            if (($action['key'] ?? null) === $key) {
                return $action;
            }
        }

        return null;
    }

    /**
     * The bot's buttons on the keyboard already (each once).
     *
     * @param list<list<array<string, mixed>>> $rows
     * @return list<string>
     */
    public static function taken(array $rows): array
    {
        return array_values(array_map(static fn(array $button): string => (string) ($button['action'] ?? ''), array_merge(...$rows)));
    }

    /**
     * A button as a label on a screen: its text, its colour marked, its icon said.
     *
     * @param array<string, mixed> $button
     */
    public static function label(array $button): string
    {
        $mark = ['primary' => '🔵 ', 'success' => '🟢 ', 'danger' => '🔴 '][(string) ($button['style'] ?? '')] ?? '';

        return $mark . (($button['icon'] ?? null) !== null ? '✨ ' : '') . (string) ($button['label'] ?? '');
    }

    /**
     * @param array{keyboard: array{name: string, title: string, is_default: bool, type: string, rows: list<list<array<string, mixed>>>}, actions: list<array<string, mixed>>, styles: list<string>, limits: array{rows: int, per_row: int, label: int}, count: int} $data
     */
    private function view(array $data): View
    {
        $keyboard = $data['keyboard'];
        $name = $keyboard['name'];
        $rows = $keyboard['rows'];
        $type = self::TYPES[$keyboard['type']] ?? self::TYPES['reply'];
        $view = View::make('⌨️ ' . $keyboard['title'] . ($keyboard['is_default'] ? ' · چیدمان پیش‌فرض' : ''), 'مدیریت', 'ربات', 'کیبوردها')
            ->line("کیبوردی که با \u{2068}/start\u{2069} به مشتری نشان داده می‌شود. ردیف اول، بالاترین ردیف است و دکمه اول هر ردیف سمت راست می‌نشیند؛ دکمه‌های زیر همان‌طور چیده شده‌اند.")
            ->line('نوع: <b>' . Fmt::e($type[0]) . '</b> — ' . Fmt::e($type[1]))
            ->note('روی هر دکمه بزنید تا متن، رنگ، آیکون یا جایش را عوض کنید. هر تغییر همان لحظه ذخیره می‌شود و ربات از آن به بعد همین را نشان می‌دهد. 🔵🟢🔴 رنگ دکمه و ✨ آیکون پرمیوم است.');
        foreach ($rows as $row) {
            $view->row(...array_map(static fn(array $button): Button => Button::to(self::label($button), KeyboardButtonScreen::ID, 'show', $name, (string) ($button['action'] ?? '')), $row));
        }
        $exhausted = array_diff(array_map(static fn(array $action): string => (string) ($action['key'] ?? ''), $data['actions']), self::taken($rows)) === [];
        if ($exhausted) {
            $view->line()->line('همه دکمه‌های ربات روی کیبورد هستند؛ هر دکمه یک بار می‌آید، پس برای دکمه تازه اول یکی را بردارید.');
        }
        $view->row(
            $exhausted ? null : Button::to('➕ افزودن دکمه', self::ID, 'add', $name),
            Button::to('↕️ ردیف‌ها', self::ID, 'rows', $name),
        )->row(Button::to('🔀 نوع: ' . $type[0], self::ID, 'type', $name));
        if (!$keyboard['is_default']) {
            $view->row(Button::to('↩️ بازگشت به چیدمان پیش‌فرض', self::ID, 'rs', $name));
        }

        return $view->back($data['count'] > 1 ? KeyboardsScreen::ID : 'hb');
    }

    /**
     * @param array{keyboard: array{name: string, title: string, is_default: bool, type: string, rows: list<list<array<string, mixed>>>}, actions: list<array<string, mixed>>, styles: list<string>, limits: array{rows: int, per_row: int, label: int}, count: int} $data
     */
    private function types(array $data): View
    {
        $name = $data['keyboard']['name'];
        $view = View::make('🔀 نوع کیبورد', 'مدیریت', 'ربات', 'کیبوردها', $data['keyboard']['title']);
        $buttons = [];
        foreach (self::TYPES as $type => [$label, $hint]) {
            $view->line('<b>' . Fmt::e($label) . '</b>: ' . Fmt::e($hint));
            $buttons[] = Button::to(($data['keyboard']['type'] === $type ? '● ' : '') . $label, self::ID, 'kind', $name, $type);
        }

        return $view->grid(1, ...$buttons)->back(self::ID, 'show', $name);
    }

    /**
     * Which row a new button goes on: one with room, or a new row while the keyboard has room for one.
     *
     * @param array{keyboard: array{name: string, title: string, is_default: bool, type: string, rows: list<list<array<string, mixed>>>}, actions: list<array<string, mixed>>, styles: list<string>, limits: array{rows: int, per_row: int, label: int}, count: int} $data
     */
    private function rowsToAdd(array $data): View
    {
        $name = $data['keyboard']['name'];
        $rows = $data['keyboard']['rows'];
        $view = View::make('➕ افزودن دکمه: کدام ردیف؟', 'مدیریت', 'ربات', 'کیبوردها', $data['keyboard']['title']);
        $buttons = [];
        foreach ($rows as $r => $row) {
            if (count($row) < $data['limits']['per_row']) {
                $buttons[] = Button::to('ردیف ' . Fmt::plain(Fmt::number($r + 1)) . ' · ' . implode('، ', array_map(static fn(array $button): string => (string) ($button['label'] ?? ''), $row)), self::ID, 'addr', $name, $r);
            }
        }
        if (count($rows) < $data['limits']['rows']) {
            $buttons[] = Button::to('➕ ردیف تازه زیر بقیه', self::ID, 'addr', $name, count($rows));
        }
        if ($buttons === []) {
            $view->line('ردیفی جا ندارد: هر ردیف حداکثر ' . Fmt::number($data['limits']['per_row']) . ' دکمه و کیبورد حداکثر ' . Fmt::number($data['limits']['rows']) . ' ردیف می‌گیرد.');
        }

        return $view->grid(1, ...$buttons)->back(self::ID, 'show', $name);
    }

    /**
     * The bot's buttons not on the keyboard yet, to put on row `$row`.
     *
     * @param array{keyboard: array{name: string, title: string, is_default: bool, type: string, rows: list<list<array<string, mixed>>>}, actions: list<array<string, mixed>>, styles: list<string>, limits: array{rows: int, per_row: int, label: int}, count: int} $data
     */
    private function actionsToAdd(array $data, int $row): View
    {
        $name = $data['keyboard']['name'];
        $taken = self::taken($data['keyboard']['rows']);
        $view = View::make('➕ افزودن دکمه: کدام دکمه؟', 'مدیریت', 'ربات', 'کیبوردها', $data['keyboard']['title'])
            ->line('کاری که این دکمه در ربات انجام می‌دهد. متن دکمه همان متن پیش‌فرض آن است و بعد عوض می‌شود.');
        $buttons = [];
        foreach ($data['actions'] as $action) {
            $key = (string) ($action['key'] ?? '');
            if ($key !== '' && !in_array($key, $taken, true)) {
                $buttons[] = Button::to((string) ($action['label'] ?? $key), self::ID, 'adda', $name, $row, $key);
            }
        }

        return $view->grid(2, ...$buttons)->back(self::ID, 'add', $name);
    }

    /**
     * The rows, each moved up or down or removed.
     *
     * @param array{keyboard: array{name: string, title: string, is_default: bool, type: string, rows: list<list<array<string, mixed>>>}, actions: list<array<string, mixed>>, styles: list<string>, limits: array{rows: int, per_row: int, label: int}, count: int} $data
     */
    private function rows(array $data): View
    {
        $name = $data['keyboard']['name'];
        $rows = $data['keyboard']['rows'];
        $view = View::make('↕️ ردیف‌ها', 'مدیریت', 'ربات', 'کیبوردها', $data['keyboard']['title'])
            ->line('ردیف اول، بالاترین ردیف است.');
        foreach ($rows as $r => $row) {
            $number = Fmt::plain(Fmt::number($r + 1));
            $view->line('<b>ردیف ' . Fmt::e($number) . '</b>: ' . Fmt::e(implode('، ', array_map(static fn(array $button): string => (string) ($button['label'] ?? ''), $row))));
            $view->row(
                $r > 0 ? Button::to('🔼 ' . $number, self::ID, 'mr', $name, $r, 'u') : null,
                $r < count($rows) - 1 ? Button::to('🔽 ' . $number, self::ID, 'mr', $name, $r, 'd') : null,
                Button::to('🗑 حذف ردیف ' . $number, self::ID, 'dr', $name, $r),
            );
        }

        return $view->back(self::ID, 'show', $name);
    }

    /**
     * Save the layout, saying so, and show the keyboard.
     *
     * @param array{keyboard: array{name: string, title: string, is_default: bool, type: string, rows: list<list<array<string, mixed>>>}, actions: list<array<string, mixed>>, styles: list<string>, limits: array{rows: int, per_row: int, label: int}, count: int} $data
     * @param list<list<array<string, mixed>>> $rows
     * @throws ApiRefusal
     */
    private function saved(Admin $admin, array $data, string $type, array $rows, string $notice): View
    {
        $data = self::put($admin, $data, $type, $rows);
        $admin->notice($notice);

        return $this->view($data);
    }

    /**
     * @param array<string, mixed> $answer GET /keyboards' answer (its actions, styles and limits)
     * @param array<string, mixed> $keyboard
     * @return array{keyboard: array{name: string, title: string, is_default: bool, type: string, rows: list<list<array<string, mixed>>>}, actions: list<array<string, mixed>>, styles: list<string>, limits: array{rows: int, per_row: int, label: int}, count: int}
     */
    private static function data(array $answer, array $keyboard, int $count): array
    {
        $limits = Json::part($answer, 'limits');

        return [
            'keyboard' => [
                'name' => (string) ($keyboard['name'] ?? ''),
                'title' => (string) ($keyboard['title'] ?? $keyboard['name'] ?? ''),
                'is_default' => Json::on($keyboard, 'is_default'),
                'type' => ($keyboard['type'] ?? 'reply') === 'inline' ? 'inline' : 'reply',
                'rows' => self::rowsOf($keyboard),
            ],
            'actions' => Json::rows($answer, 'actions'),
            'styles' => array_values(array_map(strval(...), array_filter((array) ($answer['styles'] ?? []), is_string(...)))),
            'limits' => ['rows' => (int) ($limits['rows'] ?? 12), 'per_row' => (int) ($limits['per_row'] ?? 8), 'label' => (int) ($limits['label'] ?? 64)],
            'count' => $count,
        ];
    }

    /**
     * A keyboard's rows of buttons.
     *
     * @param array<string, mixed> $keyboard
     * @return list<list<array<string, mixed>>>
     */
    private static function rowsOf(array $keyboard): array
    {
        $rows = [];
        foreach ((array) ($keyboard['rows'] ?? []) as $row) {
            $buttons = array_values(array_filter(is_array($row) ? $row : [], is_array(...)));
            if ($buttons !== []) {
                $rows[] = $buttons;
            }
        }

        return $rows;
    }
}
