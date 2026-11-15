<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Servers;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * Starting a move of a server's services (the panel's migration dialog): the target (a server that cannot take services
 * now says why, and is not offered), the way — «نجات» chosen at first when the server's last contact failed —, the
 * services switched off by support, the customers' message, and some plans only. What it would move is read from the API
 * as the choices change (POST …/migrations/preview): how many, of which shops, and whether the target has room for them;
 * a move of nothing, or to a target that cannot take it, does not start.
 *
 * The targets and the plans are picked a page at a time (View::paged), the page kept in the draft.
 *
 * Actions: `new` (server id), `target` (page: the targets), `tgt` (server id), `mode` (the ways), `md` (0 migrate, 1
 * rescue), `dis`, `ntf` (0/1: the switch set so), `plans` (page: the plans), `pl` (plan id, 0/1: ticked out or in),
 * `start`, `quit`.
 */
final class ServerMigrationFormScreen extends BaseScreen
{
    public const ID = 'svmn';

    /** What each way does, under its choice. */
    private const MODE_HINTS = [
        'migrate' => 'پنل این سرور جواب می‌دهد: هر سرویس از روی پنل خوانده می‌شود، با زمان و حجم باقی‌مانده‌اش روی سرور مقصد ساخته می‌شود و از این سرور حذف می‌شود.',
        'rescue' => 'پنل این سرور از دسترس خارج شده است: هر سرویس با آخرین اطلاعاتی که فروشگاه از آن دارد روی سرور مقصد ساخته می‌شود و پنل این سرور پرسیده نمی‌شود. کلاینت‌های قبلی روی آن می‌مانند و وقتی دوباره جواب بدهد، خودکار حذف می‌شوند.',
    ];

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    public function show(Admin $admin, array $args): View
    {
        $state = $admin->state(self::ID);

        return isset($state['server']) ? $this->form($admin, $state) : $admin->open(ServerMigrationsScreen::ID);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        if ($action === 'new') {
            $id = self::id($args);
            $server = (array) ($admin->api->get("/servers/{$id}")['server'] ?? []);
            $admin->forget(self::ID);
            $admin->remember(self::ID, [
                'server' => $id,
                'name' => (string) ($server['name'] ?? ''),
                'target' => 0,
                'mode' => is_string($server['last_error'] ?? null) ? 'rescue' : 'migrate',
                'include_disabled' => false,
                'notify' => true,
                'plans' => [],
            ]);

            return $this->form($admin, $admin->state(self::ID));
        }
        $state = $admin->state(self::ID);
        if (!isset($state['server'])) {
            return $admin->open(ServerMigrationsScreen::ID);
        }
        $id = (int) $state['server'];
        switch ($action) {
            case 'target':
                $admin->remember(self::ID, ['tp' => self::page($args)]);

                return $this->targets($admin, $admin->state(self::ID));
            case 'tgt':
                $admin->remember(self::ID, ['target' => self::id($args)]);
                break;
            case 'mode':
                return $this->modes($state);
            case 'md':
                $admin->remember(self::ID, ['mode' => self::id($args) === 1 ? 'rescue' : 'migrate']);
                break;
            case 'dis':
                $admin->remember(self::ID, ['include_disabled' => self::id($args) === 1]);
                break;
            case 'ntf':
                $admin->remember(self::ID, ['notify' => self::id($args) === 1]);
                break;
            case 'plans':
                $admin->remember(self::ID, ['pp' => self::page($args)]);

                return $this->plans($admin, $admin->state(self::ID));
            case 'pl':
                $plan = self::id($args);
                $plans = array_values(array_diff(array_map(intval(...), (array) ($state['plans'] ?? [])), [$plan]));
                $admin->remember(self::ID, ['plans' => self::id($args, 1) === 1 ? [...$plans, $plan] : $plans]);

                return $this->plans($admin, $admin->state(self::ID));
            case 'start':
                $plans = array_map(intval(...), (array) ($state['plans'] ?? []));
                $admin->api->post("/servers/{$id}/migrations", [
                    'target_server_id' => (int) ($state['target'] ?? 0),
                    'mode' => (string) ($state['mode'] ?? 'migrate'),
                    'include_disabled' => Json::on($state, 'include_disabled'),
                    'notify' => ($state['notify'] ?? true) === true,
                    'plan_ids' => $plans === [] ? null : $plans,
                ]);
                $admin->forget(self::ID);
                $admin->notice('✅ انتقال سرویس‌ها شروع شد');

                return $admin->open(ServerMigrationsScreen::ID, 'show', [$id]);
            case 'quit':
                $admin->forget(self::ID);

                return $admin->open(ServerMigrationsScreen::ID, 'show', [$id]);
        }

        return $this->form($admin, $admin->state(self::ID));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function form(Admin $admin, array $state): View
    {
        $preview = $this->preview($admin, $state);
        $target = $this->server($admin, (int) ($state['target'] ?? 0));
        $mode = (string) ($state['mode'] ?? 'migrate');
        $plans = array_map(intval(...), (array) ($state['plans'] ?? []));
        $choices = Json::rows($preview, 'plans');

        $view = View::make('🔀 انتقال سرویس‌ها', 'مدیریت', 'سرورها', (string) ($state['name'] ?? ''))
            ->line('از «' . Fmt::e((string) ($state['name'] ?? '')) . '» به سرور دیگر')
            ->line()
            ->line('• سرور مقصد: ' . ($target === null ? 'یک سرور انتخاب کنید' : Fmt::e((string) ($target['name'] ?? ''))))
            ->line('• روش: ' . Fmt::e(ServerMigrationsScreen::MODES[$mode] ?? $mode))
            ->line('   <i>' . Fmt::e(self::MODE_HINTS[$mode] ?? '') . '</i>')
            ->line('• سرویس‌های غیرفعال‌شده هم منتقل شوند: ' . Fmt::onOff(Json::on($state, 'include_disabled')))
            ->line('   <i>سرویس‌هایی که پشتیبانی غیرفعال کرده است، غیرفعال روی سرور مقصد ساخته می‌شوند. سرویس‌های تمام‌شده منتقل نمی‌شوند.</i>')
            ->line('• پیام به مشتری‌ها: ' . Fmt::onOff(($state['notify'] ?? true) === true))
            ->line('   <i>هر مشتری لینک جدید را در تلگرام، از ربات فروشگاه خودش، یا با ایمیل می‌گیرد.</i>');
        if (count($choices) > 1) {
            $names = array_map(static fn(array $plan): string => (string) ($plan['name'] ?? ''), array_filter($choices, static fn(array $plan): bool => in_array((int) ($plan['id'] ?? 0), $plans, true)));
            $view->line('• فقط سرویس‌های این پلن‌ها: ' . ($names === [] ? 'هیچ‌کدام انتخاب نشده است: همه سرویس‌ها منتقل می‌شوند.' : Fmt::e(implode('، ', $names))));
            if ($names !== [] && (int) ($preview['unplanned'] ?? 0) > 0) {
                $view->line('   <i>' . Fmt::number((int) $preview['unplanned']) . ' سرویس بدون پلن منتقل نمی‌شود.</i>');
            }
        }

        $view->line();
        $services = (int) ($preview['services'] ?? 0);
        $problem = is_array($preview['target'] ?? null) && is_string($preview['target']['problem'] ?? null) ? $preview['target']['problem'] : null;
        if ($services === 0) {
            $view->line('روی این سرور سرویسی با این انتخاب برای انتقال نیست.');
        } else {
            $shops = implode('، ', array_map(static fn(mixed $row): string => is_array($row) ? (string) ($row['shop']['name'] ?? '') . ' ' . Fmt::number((int) ($row['services'] ?? 0)) : '', (array) ($preview['shops'] ?? [])));
            $view->line('📦 ' . Fmt::number($services) . ' سرویس منتقل می‌شود (' . Fmt::e($shops) . ')؛ لینک هر کدام عوض می‌شود.');
            if ($problem !== null) {
                $view->line('⛔️ ' . Fmt::e($problem));
            }
            $room = is_array($preview['target'] ?? null) && is_int($preview['target']['room'] ?? null) ? $preview['target']['room'] : null;
            if ($target !== null && $room !== null && $room < $services) {
                $view->line('⚠️ ظرفیت سرور مقصد فقط برای ' . Fmt::number($room) . ' سرویس دیگر جا دارد؛ بعد از آن انتقال ناتمام می‌ماند و بقیه روی همین سرور هستند.');
            }
        }

        $disabled = Json::on($state, 'include_disabled');
        $notify = ($state['notify'] ?? true) === true;
        $view->row(Button::to('🎯 سرور مقصد: ' . ($target === null ? 'انتخاب' : (string) ($target['name'] ?? '')), self::ID, 'target', 1))
            ->row(Button::to('روش: ' . (ServerMigrationsScreen::MODES[$mode] ?? $mode), self::ID, 'mode'))
            ->row(Button::to(($disabled ? '✅ ' : '◻️ ') . 'سرویس‌های غیرفعال‌شده هم منتقل شوند', self::ID, 'dis', $disabled ? 0 : 1))
            ->row(Button::to(($notify ? '✅ ' : '◻️ ') . 'پیام به مشتری‌ها', self::ID, 'ntf', $notify ? 0 : 1));
        if (count($choices) > 1) {
            $view->row(Button::to('📦 پلن‌ها' . Fmt::badge(count($plans)), self::ID, 'plans', 1));
        }
        $view->row(
            $target !== null && $services > 0 && $problem === null ? Button::to(($mode === 'rescue' ? '🛟' : '✔️') . ' شروع انتقال', self::ID, 'start') : null,
            Button::to('✖️ انصراف', self::ID, 'quit'),
        );

        return $view->back(self::ID, 'quit');
    }

    /**
     * The servers it may move to: the others, each with its services against its capacity — one that cannot take
     * services now says why and is not offered.
     *
     * @param array<string, mixed> $state
     */
    private function targets(Admin $admin, array $state): View
    {
        $view = View::make('🎯 سرور مقصد', 'مدیریت', 'سرورها', (string) ($state['name'] ?? ''), 'انتقال سرویس‌ها');
        $buttons = [];
        foreach ((array) ($admin->api->get('/servers')['servers'] ?? []) as $server) {
            if (!is_array($server) || (int) ($server['id'] ?? 0) === (int) ($state['server'] ?? 0)) {
                continue;
            }
            $name = (string) ($server['name'] ?? '');
            if (is_string($server['unsellable_reason'] ?? null)) {
                $view->line('⛔️ ' . Fmt::e($name) . ' — ' . Fmt::e($server['unsellable_reason']));

                continue;
            }
            $active = (int) ($server['counts']['active_subscriptions'] ?? 0);
            $chosen = (int) ($server['id'] ?? 0) === (int) ($state['target'] ?? 0);
            $buttons[] = Button::to(($chosen ? '● ' : '') . $name . ' · ' . Fmt::number($active) . ' سرویس فعال' . (is_int($server['capacity'] ?? null) ? ' از ظرفیت ' . Fmt::number($server['capacity']) : ''), self::ID, 'tgt', (int) $server['id']);
        }
        if ($buttons === []) {
            $view->line('سرور دیگری نیست که سرویس‌ها به آن بروند.');
        }

        return $view->paged(1, $buttons, (int) ($state['tp'] ?? 1), static fn(int $page): array => [self::ID, 'target', [$page]])->back(self::ID);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function modes(array $state): View
    {
        $mode = (string) ($state['mode'] ?? 'migrate');

        return View::make('روش', 'مدیریت', 'سرورها', (string) ($state['name'] ?? ''), 'انتقال سرویس‌ها')
            ->line('<b>' . ServerMigrationsScreen::MODES['migrate'] . '</b>: ' . Fmt::e(self::MODE_HINTS['migrate']))
            ->line()
            ->line('<b>' . ServerMigrationsScreen::MODES['rescue'] . '</b>: ' . Fmt::e(self::MODE_HINTS['rescue']))
            ->row(
                Button::to(($mode === 'migrate' ? '● ' : '') . ServerMigrationsScreen::MODES['migrate'] . ' · پنل جواب می‌دهد', self::ID, 'md', 0),
                Button::to(($mode === 'rescue' ? '● ' : '') . ServerMigrationsScreen::MODES['rescue'] . ' · پنل از دسترس خارج شده', self::ID, 'md', 1),
            )
            ->back(self::ID);
    }

    /**
     * Some plans only: those the server's services are of, ticked in or out.
     *
     * @param array<string, mixed> $state
     */
    private function plans(Admin $admin, array $state): View
    {
        $preview = $this->preview($admin, $state);
        $plans = array_map(intval(...), (array) ($state['plans'] ?? []));
        $view = View::make('📦 فقط سرویس‌های این پلن‌ها', 'مدیریت', 'سرورها', (string) ($state['name'] ?? ''), 'انتقال سرویس‌ها')
            ->line($plans === [] ? 'هیچ‌کدام انتخاب نشده است: همه سرویس‌ها منتقل می‌شوند.' : Fmt::number((int) ($preview['services'] ?? 0)) . ' سرویس منتقل می‌شود.');
        if ($plans !== [] && (int) ($preview['unplanned'] ?? 0) > 0) {
            $view->line(Fmt::number((int) $preview['unplanned']) . ' سرویس بدون پلن منتقل نمی‌شود.');
        }
        $buttons = [];
        foreach ((array) ($preview['plans'] ?? []) as $plan) {
            if (is_array($plan)) {
                $ticked = in_array((int) ($plan['id'] ?? 0), $plans, true);
                $buttons[] = Button::to(($ticked ? '✅ ' : '◻️ ') . (string) ($plan['name'] ?? '') . ' · ' . (string) ($plan['shop'] ?? '') . ' · ' . Fmt::number((int) ($plan['services'] ?? 0)) . ' سرویس', self::ID, 'pl', (int) ($plan['id'] ?? 0), $ticked ? 0 : 1);
            }
        }

        return $view->paged(1, $buttons, (int) ($state['pp'] ?? 1), static fn(int $page): array => [self::ID, 'plans', [$page]])->back(self::ID);
    }

    /**
     * What the move would carry with these choices, as the API counts it.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function preview(Admin $admin, array $state): array
    {
        $target = (int) ($state['target'] ?? 0);

        return $admin->api->post('/servers/' . (int) ($state['server'] ?? 0) . '/migrations/preview', [
            'target_server_id' => $target === 0 ? null : $target,
            'include_disabled' => Json::on($state, 'include_disabled'),
            'plan_ids' => array_map(intval(...), (array) ($state['plans'] ?? [])),
        ]);
    }

    /**
     * A server of the list; null for none.
     *
     * @return array<string, mixed>|null
     */
    private function server(Admin $admin, int $id): ?array
    {
        if ($id === 0) {
            return null;
        }
        foreach ((array) ($admin->api->get('/servers')['servers'] ?? []) as $server) {
            if (is_array($server) && (int) ($server['id'] ?? 0) === $id) {
                return $server;
            }
        }

        return null;
    }
}
