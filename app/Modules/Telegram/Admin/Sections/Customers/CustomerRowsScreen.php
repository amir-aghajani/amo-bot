<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One of a customer's lists on their page — services, payments, orders, tickets —: the shop's list narrowed to them
 * (`user=`), as the panel's cards read it and its «همه …» link opens it, newest first, how many over the rows, each row a
 * button opening the screen the list's own section has for it — whose «⬅️ بازگشت» then comes back here (open(),
 * opened(), back()), not to the shop's whole list —, what its rows' marks mean folded under them (legend()). Opened with
 * the customer's id (none: the one it was last on; another customer's: from its first page, unfiltered); «⬅️ بازگشت»
 * goes to their page.
 */
abstract class CustomerRowsScreen extends ListScreen
{
    /** What a row's button carries after the row's id: the customer's list it was opened from. */
    private const FROM = 'from.';

    /** Where a row's screen keeps the list it was opened from, in its own state: {id: the row, list: the list}. */
    private const KEPT = '_list';

    /** The lists a row's screen may go back to. */
    private const LISTS = [CustomerServicesScreen::ID, CustomerPaymentsScreen::ID, CustomerOrdersScreen::ID, CustomerTicketsScreen::ID];

    /** The customer whose rows are drawn now. */
    private int $customer = 0;

    /** Their name on the path, as their page last read it. */
    private string $name = '';

    public function show(Admin $admin, array $args): View
    {
        $id = self::id($args);
        if ($id > 0 && $id !== ($admin->state($this->screen())['user'] ?? null)) {
            $admin->remember($this->screen(), ['user' => $id, 'page' => 1, 'f' => []]);
        }
        $user = $admin->state($this->screen())['user'] ?? 0;
        $this->customer = is_int($user) ? $user : 0;
        if ($this->customer === 0) {
            return $admin->open(UsersScreen::ID);
        }
        if (Customer::current($admin) !== $this->customer) {
            $admin->remember(CustomerScreen::ID, ['id' => $this->customer, 'name' => null]);
        }
        $this->name = Customer::known($admin, $this->customer);

        return parent::show($admin, []);
    }

    protected function path(): array
    {
        return Customer::under($this->name);
    }

    protected function parent(): string
    {
        return CustomerScreen::ID;
    }

    protected function fixed(): array
    {
        return ['user' => $this->customer];
    }

    protected function searchable(): bool
    {
        return false;
    }

    /** What the rows' marks mean, folded under them (plain text); '' for none. */
    protected function legend(): string
    {
        return '';
    }

    protected function extra(View $view, Admin $admin): void
    {
        if ($this->legend() !== '') {
            $view->help(Fmt::e($this->legend()));
        }
    }

    /**
     * Take a row's screen's show() arguments: opened from one of these lists (its mark after the row's id), the list is
     * kept as where row `$id` goes back to. The row screen's own buttons carry no mark, and keep it.
     *
     * @param list<string> $args
     */
    public static function opened(Admin $admin, string $screen, int $id, array $args): void
    {
        foreach (array_slice($args, 1) as $arg) {
            $list = str_starts_with($arg, self::FROM) ? substr($arg, strlen(self::FROM)) : null;
            if ($list !== null && in_array($list, self::LISTS, true)) {
                $admin->remember($screen, [self::KEPT => ['id' => $id, 'list' => $list]]);

                return;
            }
        }
    }

    /** Where «⬅️ بازگشت» on `$screen` goes from row `$id`: the customer's list it was opened from, else `$parent`. */
    public static function back(Admin $admin, string $screen, int $id, string $parent): string
    {
        $kept = $admin->state($screen)[self::KEPT] ?? null;

        return is_array($kept) && ($kept['id'] ?? null) === $id && in_array($kept['list'] ?? null, self::LISTS, true) ? (string) $kept['list'] : $parent;
    }

    /** A row's button: `$screen` opened at row `$id`, its «⬅️ بازگشت» coming back to this list. */
    protected function open(string $label, string $screen, int $id): Button
    {
        return Button::to($label, $screen, Nav::SHOW, $id, self::FROM . $this->screen());
    }
}
