<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Sales;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * A list of the sales section's (orders, payments, services): the panel's status tabs as chips — those that count what
 * waits with the count beside them —, and what else narrows it (Narrowing: the panel's selects, set on its filter screen
 * «🎛 فیلتر و ترتیب»), said over the rows. Opened with `user` (a customer's id), it is that customer's alone, as a
 * customer's page links the panel's list.
 *
 * Actions: `user` (customer id), `unx` (every narrowing off).
 */
abstract class SalesList extends ListScreen
{
    /**
     * @var array<string, mixed> The list's state as this showing reads it (show())
     */
    protected array $state = [];

    /**
     * @var array<string, int> The tabs' counts, from the list's meta (summary())
     */
    private array $counts = [];

    /** The list's filter screen. */
    abstract protected function narrower(): string;

    /**
     * The panel's tabs: each status (or queue) and its label, «همه» first.
     *
     * @return array<string, string>
     */
    abstract protected function statuses(): array;

    /**
     * The tabs that count what waits, each by the meta's key of its count.
     *
     * @return list<string>
     */
    protected function counted(): array
    {
        return [];
    }

    /**
     * Lines of what the list adds up to (its meta's figures).
     *
     * @param array<string, mixed> $meta
     * @return list<string>
     */
    protected function figures(array $meta): array
    {
        return [];
    }

    /** Buttons of the list's own, beside the narrowing's. */
    protected function more(View $view, Admin $admin): void {}

    /**
     * An action of the list's own.
     *
     * @param list<string> $args
     * @throws ApiRefusal
     */
    protected function own(Admin $admin, string $action, array $args): View
    {
        return $this->show($admin, []);
    }

    public function show(Admin $admin, array $args): View
    {
        $this->state = $admin->state($this->screen());
        $this->counts = [];

        return parent::show($admin, $args);
    }

    protected function parent(): string
    {
        return 'hl';
    }

    protected function path(): array
    {
        return ['مدیریت', 'فروش'];
    }

    protected function filters(): array
    {
        $tabs = [];
        foreach ($this->statuses() as $value => $label) {
            $tabs[$value] = $label . Fmt::badge($this->counts[$value] ?? 0);
        }

        return ['status' => $tabs];
    }

    protected function fixed(): array
    {
        return Narrowing::query($this->state);
    }

    protected function summary(array $meta): array
    {
        foreach ($this->counted() as $tab) {
            $this->counts[$tab] = is_numeric($meta[$tab] ?? null) ? (int) $meta[$tab] : 0;
        }
        $words = Narrowing::words($this->state);

        return [...($words === [] ? [] : ['🎛 ' . Fmt::e(implode(' · ', $words))]), ...$this->figures($meta)];
    }

    protected function extra(View $view, Admin $admin): void
    {
        $this->more($view, $admin);
        $view->row(
            Button::to('🎛 فیلتر و ترتیب', $this->narrower()),
            Narrowing::query($this->state) === [] ? null : Button::to('✖️ حذف فیلترها', $this->screen(), 'unx'),
        );
    }

    protected function action(Admin $admin, string $action, array $args): View
    {
        switch ($action) {
            case 'user':
                $id = self::id($args);
                if ($id > 0) {
                    $admin->remember($this->screen(), ['x' => ['user' => (string) $id], 'said' => ['user' => 'مشتری ' . Fmt::id($id)], 'days' => null, 'f' => [], 'search' => '', 'page' => 1]);
                }

                return $this->show($admin, []);
            case 'unx':
                Narrowing::clear($admin, $this->screen());

                return $this->show($admin, []);
        }

        return $this->own($admin, $action, $args);
    }

    /** How many a counting tab holds, as the list's meta said it (0 before it is read). */
    protected function count(string $tab): int
    {
        return $this->counts[$tab] ?? 0;
    }

    /** The tab in force. */
    protected function tab(): string
    {
        $set = Json::part($this->state, 'f');

        return is_string($set['status'] ?? null) ? $set['status'] : (string) array_key_first($this->statuses());
    }

    /** Whether a search or a narrowing makes the list look like fewer than the tab holds. */
    protected function narrowed(): bool
    {
        return (string) ($this->state['search'] ?? '') !== '' || Narrowing::query($this->state) !== [];
    }

    /** Whether the list is narrowed to days. */
    protected function dated(): bool
    {
        $query = Narrowing::query($this->state);

        return isset($query['from']) || isset($query['to']);
    }

    /**
     * The panel's line of what the listed rows brought in (ListTotal): «۱۲ سفارش فروخته‌شده · جمع ۳٬۶۰۰٬۰۰۰ تومان», or
     * — narrowed to days that brought nothing — `$none`; nothing when nothing did and no days are picked.
     *
     * @param array<string, mixed> $meta
     * @return list<string>
     */
    protected function total(array $meta, string $count, string $amount, string $noun, string $none): array
    {
        if (!is_numeric($meta[$count] ?? null)) {
            return [];
        }
        $sold = (int) $meta[$count];
        if ($sold === 0) {
            return $this->dated() ? ['💰 ' . $none] : [];
        }

        return ['💰 ' . Fmt::number($sold) . ' ' . $noun . ' · جمع ' . Fmt::money(is_scalar($meta[$amount] ?? null) ? (string) $meta[$amount] : '0')];
    }
}
