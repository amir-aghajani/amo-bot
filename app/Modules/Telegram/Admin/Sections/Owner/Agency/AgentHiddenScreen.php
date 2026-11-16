<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Agency;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Owner\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words as UiWords;

/**
 * «🙈 پنهان از فروشگاه نماینده» (the panel's HiddenModal): every server of the installation and the agent's own
 * plans — one list at a time, its tab a chip, a page of it at a time (View::paged) —, a button each to keep it from
 * their shop or show it again; each saved at once (PUT …/hidden, exactly the ones hidden), as the API then has them.
 * Each button carries what it sets: a stale screen or a second tap never undoes the first.
 *
 * Actions: `srv` (server id, 1 hide / 0 show), `pln` (plan id, 1/0), `tab` (s servers / p plans), `page` (n).
 */
final class AgentHiddenScreen extends BaseScreen
{
    public const ID = 'agh';

    /** The two lists, by the tab's key: [the chip's words, the action that sets one of it]. */
    private const TABS = ['s' => [Icons::SERVERS . ' سرورها', 'srv'], 'p' => [Icons::PLANS . ' پلن‌ها', 'pln']];

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    public function show(Admin $admin, array $args): View
    {
        if (self::id($args) > 0) {
            AgentScreen::load($admin, self::id($args));
            $admin->remember(self::ID, ['agent' => self::id($args), 'tab' => 's', 'page' => 1]);
        }
        $id = (int) ($admin->state(self::ID)['agent'] ?? 0);

        return $id === 0 ? $admin->open(AgentsScreen::ID) : $this->view($admin, $id, (array) ($admin->api->get("/agency/agents/{$id}/hidden")['hidden'] ?? []));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = (int) ($admin->state(self::ID)['agent'] ?? 0);
        if ($id === 0) {
            return $admin->open(AgentsScreen::ID);
        }
        if ($action === 'page' || $action === 'tab') {
            $admin->remember(self::ID, $action === 'page' ? ['page' => self::page($args)] : ['tab' => isset(self::TABS[$args[0] ?? '']) ? $args[0] : 's', 'page' => 1]);

            return $this->show($admin, []);
        }
        if (!in_array($action, ['srv', 'pln'], true)) {
            return $this->show($admin, []);
        }
        $hidden = (array) ($admin->api->get("/agency/agents/{$id}/hidden")['hidden'] ?? []);
        $ticked = static fn(string $list): array => array_values(array_map(static fn(array $item): int => (int) ($item['id'] ?? 0), array_filter(array_filter((array) ($hidden[$list] ?? []), is_array(...)), static fn(array $item): bool => Json::on($item, 'hidden'))));
        $set = static fn(array $ids, int $one, bool $hide): array => $hide ? array_values(array_unique([...$ids, $one])) : array_values(array_diff($ids, [$one]));
        $servers = $ticked('servers');
        $plans = $ticked('plans');
        $hide = self::id($args, 1) === 1;
        if ($action === 'srv') {
            $servers = $set($servers, self::id($args), $hide);
        } else {
            $plans = $set($plans, self::id($args), $hide);
        }
        $answer = $admin->api->put("/agency/agents/{$id}/hidden", ['server_ids' => $servers, 'plan_ids' => $plans]);
        AgentScreen::keep($admin, (array) ($answer['agent'] ?? []));
        $admin->notice($hide ? '🙈 پنهان شد' : '👁 پیدا شد');

        return $this->view($admin, $id, (array) ($answer['hidden'] ?? []));
    }

    /**
     * @param array<string, mixed> $hidden
     */
    private function view(Admin $admin, int $id, array $hidden): View
    {
        $row = AgentScreen::row($admin, $id) ?? [];
        $state = $admin->state(self::ID);
        $tab = isset(self::TABS[$state['tab'] ?? '']) ? (string) $state['tab'] : 's';
        [, $action] = self::TABS[$tab];
        $servers = Json::rows($hidden, 'servers');
        $plans = Json::rows($hidden, 'plans');
        $items = $tab === 's' ? $servers : $plans;

        $view = View::make('🙈 پنهان از فروشگاه نماینده', ...[...AgencyScreen::path(), Words::user($row['user'] ?? null)]);
        if ($tab === 's') {
            $view->line('<b>سرورهای پنهان</b>')
                ->line('پلن‌های نماینده روی این سرورها فروخته نمی‌شود، اکانت تست در آن‌ها داده نمی‌شود و نماینده سرویسی به آن‌ها منتقل نمی‌کند. سرویس‌هایی که نماینده همین حالا روی آن‌ها دارد مثل قبل کار می‌کنند و تمدید می‌شوند.');
        } else {
            $view->line('<b>پلن‌های پنهان</b>')
                ->line('این پلن‌های نماینده فروخته نمی‌شوند؛ او آن‌ها را «پنهان‌شده توسط پشتیبانی» می‌بیند و نمی‌تواند دوباره نمایششان دهد. سرویس‌هایی که با آن‌ها فروخته شده تمدید می‌شوند.');
        }
        if ($items === []) {
            $view->line()->line($tab === 's' ? 'هنوز سروری نیست.' : 'نماینده هنوز پلنی ندارد.');
        }
        $count = static fn(array $all): int => count(array_filter($all, static fn(array $one): bool => Json::on($one, 'hidden')));
        $view->line()->line('پنهان: ' . Fmt::number($count($servers)) . ' از ' . Fmt::number(count($servers)) . ' سرور · ' . Fmt::number($count($plans)) . ' از ' . Fmt::number(count($plans)) . ' پلن');

        $view->row(...array_map(static fn(string $key, array $one): Button => Button::to(($key === $tab ? UiWords::CHIP : '') . $one[0], self::ID, 'tab', $key), array_keys(self::TABS), self::TABS));
        $buttons = array_map(static fn(array $one): Button => Button::to(
            (Json::on($one, 'hidden') ? '🙈 ' : '👁 ') . (string) ($one['name'] ?? '') . (($one['is_active'] ?? true) === true ? '' : ' (غیرفعال)'),
            self::ID,
            $action,
            (int) ($one['id'] ?? 0),
            Json::on($one, 'hidden') ? 0 : 1,
        ), $items);
        $view->paged(1, $buttons, (int) ($state['page'] ?? 1), static fn(int $page): array => [self::ID, 'page', [$page]]);
        $view->note('🙈 پنهان · 👁 پیدا؛ هر دکمه آن را پنهان یا دوباره پیدا می‌کند.');

        return $view->back(AgentScreen::ID, 'show', $id);
    }
}
