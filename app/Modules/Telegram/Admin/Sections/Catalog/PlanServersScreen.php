<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Auth\StaffGrant;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Support\Persian;

/**
 * The servers a plan in the form is sold on (the panel's «سرورها» tab, the draft PlanEditScreen keeps): each entry the
 * whole server — every inbound marked sellable, now and later — or chosen inbounds; a server that cannot sell now stays
 * listed with why. Add one (pick the server, then the whole of it or its inbounds), or take one off; nothing is saved
 * before the form is.
 *
 * Every list of choices — the plan's servers, the servers to add, a server's inbounds — goes by pages (View::paged).
 *
 * Actions: `show` (page of the plan's servers), `add` (page), `pick` (server id), `all` (server id), `in` (server id:
 * its inbounds), `ip` (page of them), `t` (inbound id: ticked or not), `ok` (the ticked ones added), `del` (server id).
 */
final class PlanServersScreen extends BaseScreen
{
    public const ID = 'pls';

    public const TITLE = Icons::SERVERS . ' سرورهای پلن';

    /** The servers section (the owner's), where a shop without a server adds one. */
    private const SERVERS = 'sv';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->holds(StaffGrant::Catalog);
    }

    public function show(Admin $admin, array $args): View
    {
        $draft = PlanEditScreen::draft($admin);
        if ($draft === null) {
            return $admin->open(PlansScreen::ID);
        }
        $servers = PlanEditScreen::options($admin)['servers'];
        $entries = self::entries($draft);
        $view = View::make(self::TITLE, ...PlanEditScreen::under())
            ->lines(...self::lines($admin, $draft, $servers));
        $taken = array_column($entries, 'server_id');
        $remaining = array_filter($servers, static fn(array $server): bool => !in_array((int) $server['id'], $taken, true));
        if ($servers === []) {
            $view->line()->line($admin->seat->isOwner()
                ? 'هنوز سروری ندارید؛ اول یک سرور اضافه کنید، بعد این‌جا انتخابش کنید.'
                : 'فروشگاه هنوز سروری ندارد که پلن روی آن فروخته شود؛ از ' . self::who($admin) . ' بخواهید سروری اضافه کند.');
        } elseif ($remaining === []) {
            $view->note('همه سرورها به این پلن اضافه شده‌اند.');
        }

        if (PlanEditScreen::asking($admin)) {
            $view->row(Button::to('✔️ ادامه', PlanEditScreen::ID, 'next')->primary());
        }
        if ($remaining !== []) {
            $view->row(Button::to(Words::add('سرور'), self::ID, 'add')->success());
        }
        if ($servers === [] && $admin->seat->isOwner()) {
            $view->row(Button::to(Icons::SERVERS . ' افزودن سرور در «سرورها»', self::SERVERS));
        }
        $view->paged(1, array_map(static fn(array $entry): Button => Button::to('➖ برداشتن ' . self::name((int) $entry['server_id'], $servers), self::ID, 'del', (int) $entry['server_id'])->danger(), $entries), self::page($args), static fn(int $page): array => [self::ID, Nav::SHOW, [$page]]);

        return $view->help('مشتری هنگام خرید یکی از این سرورها را انتخاب می‌کند و یک اشتراک روی همه اینباندهای آن ردیف می‌گیرد. «کل سرور» یعنی هر اینباندی که روی سرور قابل فروش باشد، حالا و در آینده. تا پلن ذخیره نشود، چیزی ذخیره نمی‌شود.')
            ->back(PlanEditScreen::ID, 'sum', 'servers');
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $draft = PlanEditScreen::draft($admin);
        if ($draft === null) {
            return $admin->open(PlansScreen::ID);
        }
        $id = self::id($args);
        $servers = PlanEditScreen::options($admin)['servers'];
        $server = CatalogFmt::find($servers, $id);
        $entries = self::entries($draft);
        switch ($action) {
            case 'add':
                return $this->choices($entries, $servers, self::page($args));
            case 'pick':
                return $server === null ? $this->show($admin, []) : $this->mode($admin, $server);
            case 'all':
                if ($server !== null && !in_array($id, array_column($entries, 'server_id'), true)) {
                    PlanEditScreen::put($admin, ['servers' => [...$entries, ['server_id' => $id, 'all_inbounds' => true, 'inbound_ids' => []]]], 'servers');
                    $admin->notice('✅ «' . $server['name'] . '» به پلن اضافه شد');
                }
                break;
            case 'in':
                if ($server === null) {
                    break;
                }
                $admin->remember(self::ID, ['server' => $id, 'ids' => [], 'page' => 1]);

                return $this->inbounds($admin, $server);
            case 'ip':
                $server = CatalogFmt::find($servers, (int) ($admin->state(self::ID)['server'] ?? 0));
                if ($server === null) {
                    break;
                }
                $admin->remember(self::ID, ['page' => self::page($args)]);

                return $this->inbounds($admin, $server);
            case 't':
                $pick = $admin->state(self::ID);
                $server = CatalogFmt::find($servers, (int) ($pick['server'] ?? 0));
                if ($server === null) {
                    break;
                }
                foreach ((array) ($server['inbounds'] ?? []) as $inbound) {
                    if (is_array($inbound) && (int) ($inbound['id'] ?? 0) === $id) {
                        if (!(bool) ($inbound['enabled'] ?? false)) {
                            $admin->alert('این اینباند روی پنل غیرفعال است و قابل فروش نیست.');
                            break;
                        }
                        $ids = array_map(intval(...), (array) ($pick['ids'] ?? []));
                        $admin->remember(self::ID, ['ids' => in_array($id, $ids, true) ? array_values(array_diff($ids, [$id])) : [...$ids, $id]]);
                    }
                }

                return $this->inbounds($admin, $server);
            case 'ok':
                $pick = $admin->state(self::ID);
                $server = CatalogFmt::find($servers, (int) ($pick['server'] ?? 0));
                $ids = array_map(intval(...), (array) ($pick['ids'] ?? []));
                if ($server === null) {
                    break;
                }
                if ($ids === []) {
                    $admin->alert('یا کل سرور را انتخاب کنید یا دست‌کم یک اینباند.');

                    return $this->inbounds($admin, $server);
                }
                if (!in_array((int) $server['id'], array_column($entries, 'server_id'), true)) {
                    PlanEditScreen::put($admin, ['servers' => [...$entries, ['server_id' => (int) $server['id'], 'all_inbounds' => false, 'inbound_ids' => $ids]]], 'servers');
                    $admin->notice('✅ «' . $server['name'] . '» با ' . Persian::number(count($ids)) . ' اینباند به پلن اضافه شد');
                }
                $admin->forget(self::ID);
                break;
            case 'del':
                PlanEditScreen::put($admin, ['servers' => array_values(array_filter($entries, static fn(array $entry): bool => (int) $entry['server_id'] !== $id))], 'servers');
                $admin->notice('✖️ از پلن برداشته شد');
                break;
        }

        return $this->show($admin, []);
    }

    /**
     * The draft's entries as lines: each server with what a purchase gets on it, and why one cannot sell now.
     *
     * @param array<string, mixed> $draft
     * @param list<array<string, mixed>> $servers The form's choices (PlanEditScreen::options())
     * @return list<string>
     */
    public static function lines(Admin $admin, array $draft, array $servers): array
    {
        $entries = self::entries($draft);
        if ($entries === []) {
            return ['هنوز سروری انتخاب نشده است.'];
        }
        $lines = [];
        foreach ($entries as $entry) {
            $server = CatalogFmt::find($servers, (int) $entry['server_id']);
            if ($server === null) {
                $lines[] = '• ' . Fmt::e(self::name((int) $entry['server_id'], $servers));
                $lines[] = '⚠️ پشتیبانی فروشگاه اصلی این سرور را برای این فروشگاه پنهان کرده است؛ پلن روی آن فروخته نمی‌شود.';
                continue;
            }
            $all = (bool) $entry['all_inbounds'];
            $inbounds = array_filter((array) ($server['inbounds'] ?? []), static fn(mixed $inbound): bool => is_array($inbound) && ($all ? self::sellable($inbound) : in_array((int) ($inbound['id'] ?? 0), $entry['inbound_ids'], true)));
            $lines[] = '• ' . Fmt::e((string) $server['name']) . ' · ' . ($all ? 'کل سرور · ' . Persian::number(count($inbounds)) . ' اینباند قابل فروش' : Persian::number(count($inbounds)) . ' اینباند انتخاب‌شده');
            if (is_string($server['unsellable_reason'] ?? null)) {
                $lines[] = '⚠️ ' . Fmt::e($server['unsellable_reason'] . ' تا درست نشود، مشتری این سرور را نمی‌بیند.');
            } elseif ($inbounds === []) {
                $lines[] = '⚠️ فعلا اینباند قابل فروشی روی این سرور نیست؛ ' . ($admin->seat->isOwner() ? 'در صفحه سرور اینباندها را برای فروش علامت بزنید.' : 'از ' . self::who($admin) . ' بخواهید اینباندهایش را برای فروش علامت بزند.');
            }
        }

        return $lines;
    }

    /**
     * The servers not on the plan yet, to add one: page `$page` of them, each with what it sells now.
     *
     * @param list<array{server_id: int, all_inbounds: bool, inbound_ids: list<int>}> $entries
     * @param list<array<string, mixed>> $servers
     */
    private function choices(array $entries, array $servers, int $page): View
    {
        $view = View::make(Words::add('سرور'), ...PlanEditScreen::under(Fmt::bare(self::TITLE)))->line('کدام سرور؟');
        $remaining = array_values(array_filter($servers, static fn(array $server): bool => !in_array((int) $server['id'], array_column($entries, 'server_id'), true)));
        // Why a server of the page cannot sell now: its button says only that it cannot (paged() lays CHOICES_MOST a page the same way).
        [$shown, $page] = View::slice($remaining, $page, View::CHOICES_MOST);
        foreach ($shown as $server) {
            if (is_string($server['unsellable_reason'] ?? null)) {
                $view->line('⚠️ ' . Fmt::e((string) $server['name']) . ': ' . Fmt::e($server['unsellable_reason'] . ' تا درست نشود، مشتری این پلن را روی آن نمی‌بیند.'));
            }
        }
        $view->paged(1, array_map(static fn(array $server): Button => Button::to(
            (is_string($server['unsellable_reason'] ?? null) ? '⚠️ ' : CatalogFmt::emoji(Words::ACTIVE) . ' ') . Fmt::cut((string) $server['name'], 24) . ' · ' . Persian::number(self::sellableCount($server)) . ' اینباند',
            self::ID,
            'pick',
            (int) $server['id'],
        ), $remaining), $page, static fn(int $at): array => [self::ID, 'add', [$at]]);

        return $view->back(self::ID);
    }

    /**
     * How the server is added: whole, or chosen inbounds.
     *
     * @param array<string, mixed> $server
     */
    private function mode(Admin $admin, array $server): View
    {
        $view = View::make(Icons::SERVERS . ' ' . (string) $server['name'], ...PlanEditScreen::under(Fmt::bare(self::TITLE)));
        if (is_string($server['unsellable_reason'] ?? null)) {
            $view->line('⚠️ ' . Fmt::e($server['unsellable_reason'] . ' تا درست نشود، مشتری این پلن را روی آن نمی‌بیند.'));
        }
        $view->line('• کل سرور: هر اینباندی که ' . ($admin->seat->isOwner() ? 'در صفحه سرور «قابل فروش» باشد' : self::who($admin) . ' روی این سرور برای فروش گذاشته باشد') . '، حالا و در آینده، جزو این پلن است (الان ' . Persian::number(self::sellableCount($server)) . ' مورد).')
            ->line('• انتخاب اینباندها: فقط همان اینباندهایی که علامت می‌زنید.');

        return $view->row(Button::to(Icons::SERVERS . ' کل سرور', self::ID, 'all', (int) $server['id'])->primary(), Button::to('☑️ انتخاب اینباندها', self::ID, 'in', (int) $server['id']))
            ->back(self::ID, 'add');
    }

    /**
     * The server's inbounds to tick, by pages (the page the chat keeps); one switched off on its panel cannot be.
     *
     * @param array<string, mixed> $server
     */
    private function inbounds(Admin $admin, array $server): View
    {
        $pick = $admin->state(self::ID);
        $ticked = array_map(intval(...), (array) ($pick['ids'] ?? []));
        $view = View::make('☑️ اینباندهای «' . (string) $server['name'] . '»', ...PlanEditScreen::under(Fmt::bare(self::TITLE)))
            ->line('اینباندهایی را که این پلن روی آن‌ها فروخته می‌شود علامت بزنید؛ 🚫 روی پنل غیرفعال است.');
        $buttons = [];
        foreach ((array) ($server['inbounds'] ?? []) as $inbound) {
            if (!is_array($inbound)) {
                continue;
            }
            $id = (int) ($inbound['id'] ?? 0);
            $mark = !(bool) ($inbound['enabled'] ?? false) ? '🚫 ' : Words::tick(in_array($id, $ticked, true));
            $address = implode(':', array_filter([is_string($inbound['protocol'] ?? null) ? $inbound['protocol'] : null, is_int($inbound['port'] ?? null) ? (string) $inbound['port'] : null]));
            $buttons[] = Button::to($mark . self::inboundName($inbound) . ($address !== '' ? " \u{2068}{$address}\u{2069}" : ''), self::ID, 't', $id);
        }
        if ($buttons === []) {
            $view->line('این سرور هنوز اینباندی ندارد؛ ' . ($admin->seat->isOwner() ? 'از صفحه سرور به‌روزرسانی کنید.' : 'از ' . self::who($admin) . ' بخواهید آن را به‌روزرسانی کند.'));
        }
        $view->paged(1, $buttons, (int) ($pick['page'] ?? 1), static fn(int $page): array => [self::ID, 'ip', [$page]]);
        if ($buttons !== []) {
            $view->row(Button::to('➕ افزودن به پلن (' . Persian::number(count($ticked)) . ')', self::ID, 'ok')->success());
        }

        return $view->back(self::ID, 'pick', (int) $server['id']);
    }

    /**
     * @param array<string, mixed> $draft
     * @return list<array{server_id: int, all_inbounds: bool, inbound_ids: list<int>}>
     */
    private static function entries(array $draft): array
    {
        $entries = [];
        foreach (Json::part($draft, 'servers') as $entry) {
            if (is_array($entry)) {
                $entries[] = [
                    'server_id' => (int) ($entry['server_id'] ?? 0),
                    'all_inbounds' => (bool) ($entry['all_inbounds'] ?? false),
                    'inbound_ids' => array_values(array_map(intval(...), Json::part($entry, 'inbound_ids'))),
                ];
            }
        }

        return $entries;
    }

    /** @param list<array<string, mixed>> $servers */
    private static function name(int $id, array $servers): string
    {
        $server = CatalogFmt::find($servers, $id);

        return $server !== null ? (string) $server['name'] : 'سرور ' . Persian::digits((string) $id);
    }

    /**
     * An inbound sold whole-server: enabled on the panel and marked for sale on the server's page.
     *
     * @param array<string, mixed> $inbound
     */
    private static function sellable(array $inbound): bool
    {
        return (bool) ($inbound['enabled'] ?? false) && (bool) ($inbound['is_selectable'] ?? false);
    }

    /**
     * How many of the server's inbounds are sold whole-server now (sellable()).
     *
     * @param array<string, mixed> $server
     */
    private static function sellableCount(array $server): int
    {
        return count(array_filter((array) ($server['inbounds'] ?? []), static fn(mixed $inbound): bool => is_array($inbound) && self::sellable($inbound)));
    }

    /**
     * An inbound as the admin knows it: its remark, else its protocol.
     *
     * @param array<string, mixed> $inbound
     */
    private static function inboundName(array $inbound): string
    {
        foreach (['remark', 'protocol'] as $key) {
            if (is_string($inbound[$key] ?? null) && $inbound[$key] !== '') {
                return $inbound[$key];
            }
        }

        return '#' . (int) ($inbound['id'] ?? 0);
    }

    /** Who sets a server up for a seat without the servers' pages: the main shop's support for an agent, else the owner. */
    private static function who(Admin $admin): string
    {
        return $admin->seat->isAgent() ? 'پشتیبانی' : 'مالک فروشگاه';
    }
}
