<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Core;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Customers\Customer;
use App\Modules\Telegram\Admin\Sections\Customers\CustomerScreen;
use App\Modules\Telegram\Admin\Sections\Customers\UsersScreen;
use App\Modules\Telegram\Admin\Sections\Panel\Search\SettingsSearchScreen;
use App\Modules\Telegram\Admin\Sections\Sales\OrderScreen;
use App\Modules\Telegram\Admin\Sections\Sales\OrdersScreen;
use App\Modules\Telegram\Admin\Sections\Sales\PaymentScreen;
use App\Modules\Telegram\Admin\Sections\Sales\PaymentsScreen;
use App\Modules\Telegram\Admin\Sections\Sales\Sales;
use App\Modules\Telegram\Admin\Sections\Sales\SubscriptionScreen;
use App\Modules\Telegram\Admin\Sections\Sales\SubscriptionsScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\Screens;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Support\Persian;

/**
 * «🔎 جستجو» (the home's): one value — a number or «#number», a @username, a phone number, a service's link or name —
 * looked for at once among the customers, the orders, the payments and the services, through each list's own search
 * (the panel's search box: «#12» is row 12 alone, a bare number any row it names). Each found row is a button to its
 * own page; past SHOWN of one kind, a button opens that list with the search in it. A list the seat may not open, or
 * the panel refuses, is left out. Its other mode is «⚙️ جستجوی تنظیمات» (a setting found by its words:
 * Panel\Search\SettingsSearchScreen), offered beside the question and under the results.
 *
 * Actions: `all` (list screen: that list, searched); any other shows the last search's results again (a question
 * for one when there is none).
 */
final class SearchScreen extends BaseScreen
{
    private const ID = Screens::SEARCH;

    /** The rows of one kind shown here; more are the list's to show. */
    private const SHOWN = 5;

    /** The lists searched, in the order shown: list screen => [route, rows' key, what one row is called]. */
    private const LISTS = [
        UsersScreen::ID => ['/users', 'users', 'مشتری'],
        OrdersScreen::ID => ['/orders', 'orders', 'سفارش'],
        PaymentsScreen::ID => ['/payments', 'payments', 'پرداخت'],
        SubscriptionsScreen::ID => ['/subscriptions', 'subscriptions', 'سرویس'],
    ];

    public function __construct(private readonly Screens $screens) {}

    public function allows(AdminSeat $seat): bool
    {
        foreach (array_keys(self::LISTS) as $list) {
            if ($this->screens->get($list)?->allows($seat) === true) {
                return true;
            }
        }

        return false;
    }

    public function show(Admin $admin, array $args): View
    {
        $admin->forget(self::ID);
        $prompt = (new Prompt(
            'جستجو',
            self::ID,
            'q',
            hint: 'شماره یا «#شماره»، نام کاربری با @، شماره موبایل، لینک یا نام سرویس؛ در مشتری‌ها، سفارش‌ها، پرداخت‌ها و سرویس‌ها.',
            cancel: Button::to(Words::CANCEL, Screens::HOME),
        ))->example('@ali_r');
        // The other mode: a setting found by its words.
        $settings = self::settingsSearch($admin);

        return $admin->ask($settings === null ? $prompt : $prompt->choices($settings));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $term = (string) ($admin->state(self::ID)['q'] ?? '');
        if ($term === '') {
            return $this->show($admin, []);
        }
        $list = $args[0] ?? '';
        if ($action === 'all' && isset(self::LISTS[$list]) && $admin->opens($list)) {
            // The list as a fresh search: no tab, narrowing or page left on it hides a row.
            $admin->forget($list);
            $admin->remember($list, ['search' => $term]);

            return $admin->open($list);
        }

        return $this->results($admin, $term);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $term = self::term($answer->text());
        if ($term === '') {
            return $this->show($admin, []);
        }
        $admin->remember(self::ID, ['q' => $term]);

        return $this->results($admin, $term);
    }

    /** What `$term` finds in each list the seat may open: a button a row, the count of each kind said once. */
    private function results(Admin $admin, string $term): View
    {
        $view = View::make(Icons::SEARCH . ' جستجو', Words::ROOT);
        $said = [];
        $buttons = [];
        foreach (self::LISTS as $list => [$route, $key, $noun]) {
            if (!$admin->opens($list)) {
                continue;
            }
            try {
                $answer = $admin->api->get($route, ['search' => $term]);
            } catch (ApiRefusal) {
                continue;
            }
            $rows = Json::rows($answer, $key);
            $total = (int) (Json::part($answer, 'meta')['total'] ?? count($rows));
            if ($total === 0) {
                continue;
            }
            $said[] = Fmt::number($total) . ' ' . $noun;
            foreach (array_slice($rows, 0, self::SHOWN) as $row) {
                $buttons[] = self::found($list, $row);
            }
            if ($total > self::SHOWN) {
                $buttons[] = Button::to('📋 همه ' . Fmt::number($total) . ' ' . $noun, self::ID, 'all', $list);
            }
        }

        $view->line('🔎 «' . Fmt::ltr($term) . '»' . ($said === [] ? '' : ' · ' . Fmt::e(implode(' · ', $said))));
        if ($buttons === []) {
            $view->line()->line(Words::NOTHING_FOUND);
        }

        return $view->grid(1, ...$buttons)
            ->row(Button::to(Icons::SEARCH . ' جستجوی دیگر', self::ID), self::settingsSearch($admin))
            ->back(Screens::HOME);
    }

    /** «⚙️ جستجوی تنظیمات», the search's other mode — a setting found by its words —, for a seat that may open it. */
    private static function settingsSearch(Admin $admin): ?Button
    {
        return $admin->opens(SettingsSearchScreen::ID) ? Button::to(Icons::SETTINGS . ' جستجوی تنظیمات', SettingsSearchScreen::ID) : null;
    }

    /**
     * A row found, as a button to its page: what it is (its icon), its number and what tells it apart.
     *
     * @param array<string, mixed> $row
     */
    private static function found(string $list, array $row): Button
    {
        $id = (int) ($row['id'] ?? 0);
        $who = Sales::customer(Json::part($row, 'user'));

        return match ($list) {
            UsersScreen::ID => Button::to(Customer::label($row), CustomerScreen::ID, 'show', $id),
            OrdersScreen::ID => Button::to(Icons::ORDERS . ' ' . Fmt::id($id) . ' · ' . Fmt::money(Json::text($row, 'amount')) . ' · ' . $who, OrderScreen::ID, 'show', $id),
            PaymentsScreen::ID => Button::to(Icons::PAYMENTS . ' ' . Fmt::id($id) . ' · ' . Fmt::money(Json::text($row, 'amount')) . ' · ' . $who, PaymentScreen::ID, 'show', $id),
            default => Button::to(Icons::SERVICES . ' ' . implode(' · ', array_filter([(string) ($row['name'] ?? ''), $who])), SubscriptionScreen::ID, 'show', $id),
        };
    }

    /**
     * What is looked for, as typed: Latin digits, and a phone typed the Iranian way («۰۹۱۲…», «+98 912…») as its last
     * ten digits — the part every way of keeping it holds.
     */
    private static function term(string $typed): string
    {
        $term = trim(Persian::latinDigits($typed));
        $digits = (string) preg_replace('/[\s\-()]/', '', $term);
        if (preg_match('/^(?:\+?98|0)(9\d{9})$/', $digits, $phone) === 1) {
            return $phone[1];
        }

        return mb_substr($term, 0, 100);
    }
}
