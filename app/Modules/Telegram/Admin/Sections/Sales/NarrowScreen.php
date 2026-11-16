<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Sales;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «🎛 فیلتر و ترتیب» of a sales list: what is in force in one card — each of the panel's selects beside its tabs, its date
 * filter, the order —, then each group's chips, the one in force marked, its days' presets and a range typed. A pick
 * narrows the list at once (Narrowing), from its first page.
 *
 * Actions: `s` (group, value: `_` for none), `d` (preset: `_` for none), `dc` (asks a range), `clr` (all off).
 */
abstract class NarrowScreen extends BaseScreen
{
    /** A choice's value in a button for none («همه», the list's own order). */
    private const NONE = '_';

    /** The longest label of a group laid three a row; a group with a longer one goes two a row. */
    private const THREE_MOST = 11;

    /** The order of a list of sums (orders, payments), as the panel's sortable headers take it; '' the newest first. */
    protected const SORT_BY_AMOUNT = [
        '' => 'تازه‌ترین',
        'created.asc' => 'قدیمی‌ترین',
        'amount.desc' => 'بیشترین مبلغ',
        'amount.asc' => 'کمترین مبلغ',
    ];

    /** The screen's id. */
    abstract protected function screen(): string;

    /** The list it narrows. */
    abstract protected function list(): string;

    /** The list's name, for the path over it. */
    abstract protected function listTitle(): string;

    /**
     * Its groups: each a query name, its label and its choices — value and label, '' the one in force at first. The
     * order is the group `sort`, its values «key.dir».
     *
     * @return list<array{key: string, label: string, options: array<string, string>}>
     * @throws ApiRefusal
     */
    abstract protected function groups(Admin $admin): array;

    /** What the days are of («زمان ثبت سفارش»), for a list narrowed by days; null for none. */
    protected function days(): ?string
    {
        return null;
    }

    public function show(Admin $admin, array $args): View
    {
        $state = $admin->state($this->list());
        $query = Narrowing::query($state);
        $groups = $this->groups($admin);
        $days = $this->days();
        $period = Narrowing::period($state);
        $view = View::make(SalesList::FILTERS, ...Sales::under($this->listTitle()));

        $facts = [];
        foreach ($groups as $group) {
            $current = self::current($group['key'], $query);
            $facts[$group['label']] = Fmt::e($group['options'][$current] ?? (string) reset($group['options']));
        }
        if ($days !== null) {
            $facts['تاریخ'] = Fmt::e($period ?? 'همه روزها');
        }
        $view->card($facts);

        foreach ($groups as $group) {
            $current = self::current($group['key'], $query);
            $chips = [];
            foreach ($group['options'] as $value => $label) {
                $chips[] = Button::to(($current === (string) $value ? Words::CHIP : '') . $label, $this->screen(), 's', $group['key'], $value === '' ? self::NONE : (string) $value);
            }
            $view->grid(self::perRow($group['options']), ...$chips);
        }
        if ($days !== null) {
            $preset = Narrowing::presetOf($state);
            $chips = [Button::to(($period === null ? Words::CHIP : '') . 'همه روزها', $this->screen(), 'd', self::NONE)];
            foreach (Narrowing::DAYS as $key => $label) {
                $chips[] = Button::to(($preset === $key ? Words::CHIP : '') . $label, $this->screen(), 'd', $key);
            }
            $chips[] = Button::to(($period !== null && $preset === null ? Words::CHIP : '') . '📅 بازه دلخواه', $this->screen(), 'dc');
            $view->grid(3, ...$chips);
        }
        $view->row(
            Button::to('✔️ دیدن نتیجه', $this->list())->primary(),
            $query === [] ? null : Button::to(SalesList::UNFILTER, $this->screen(), 'clr'),
        );

        return $view->help('هر انتخاب همان لحظه روی لیست اعمال می‌شود و تا وقتی برش ندارید می‌ماند.' . ($days !== null ? ' تاریخ بر اساس ' . Fmt::e($days) . ' است.' : ''))
            ->back($this->list());
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        switch ($action) {
            case 's':
                $this->pick($admin, $args[0] ?? '', ($args[1] ?? self::NONE) === self::NONE ? '' : (string) $args[1]);
                break;
            case 'd':
                $preset = $args[0] ?? self::NONE;
                if (isset(Narrowing::DAYS[$preset])) {
                    [$from, $to] = Narrowing::preset($preset);
                    Narrowing::dates($admin, $this->list(), $from, $to, $preset);
                } else {
                    Narrowing::dates($admin, $this->list(), '', '');
                }
                break;
            case 'dc':
                return $admin->ask((new Prompt('بازه دلخواه', $this->screen(), 'dc', hint: 'روز شروع و روز پایان را بنویسید؛ برای یک روز، یک تاریخ.'))
                    ->current(Narrowing::period($admin->state($this->list())) ?? 'همه روزها', false)
                    ->example('۱۴۰۵/۰۷/۰۱ تا ۱۴۰۵/۰۷/۱۵')
                    ->limits('تاریخ شمسی یا میلادی، سال چهاررقمی'));
            case 'clr':
                Narrowing::clear($admin, $this->list());
                break;
        }

        return $this->show($admin, []);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        if ($action === 'dc') {
            $days = Narrowing::typed($answer->text()) ?? throw new ApiRefusal(422, 'تاریخ را به شکل «۱۴۰۵/۰۷/۰۱» بنویسید؛ برای یک بازه، دو تاریخ.');
            Narrowing::dates($admin, $this->list(), $days[0], $days[1]);
            $admin->notice('✅ لیست به همین روزها محدود شد');
        }

        return $this->show($admin, []);
    }

    /** One group's pick: its value (and its words) into the list's narrowing — the order's as its key and direction. */
    private function pick(Admin $admin, string $key, string $value): void
    {
        foreach ($this->groups($admin) as $group) {
            if ($group['key'] !== $key || !array_key_exists($value, $group['options'])) {
                continue;
            }
            $said = $group['label'] . ': ' . $group['options'][$value];
            if ($key === 'sort') {
                [$by, $dir] = $value === '' ? ['', ''] : explode('.', $value . '.');
                Narrowing::put($admin, $this->list(), ['sort' => $by, 'dir' => $dir], ['sort' => $said]);

                return;
            }
            Narrowing::put($admin, $this->list(), [$key => $value], [$key => $said]);

            return;
        }
    }

    /**
     * How many of a group's chips go a row: three while every label is short, else two.
     *
     * @param array<string, string> $options
     */
    private static function perRow(array $options): int
    {
        foreach ($options as $label) {
            if (mb_strlen($label) > self::THREE_MOST) {
                return 2;
            }
        }

        return 3;
    }

    /**
     * The value of group `$key` in force: the order as «key.dir», '' for none.
     *
     * @param array<string, string> $query
     */
    private static function current(string $key, array $query): string
    {
        if ($key === 'sort') {
            return isset($query['sort']) ? $query['sort'] . '.' . ($query['dir'] ?? 'desc') : '';
        }

        return $query[$key] ?? '';
    }
}
