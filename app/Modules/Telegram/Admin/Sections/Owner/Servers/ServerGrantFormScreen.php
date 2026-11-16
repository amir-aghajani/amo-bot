<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Servers;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Owner\Forms\DescribedForm;
use App\Modules\Telegram\Admin\Sections\Owner\Forms\FormScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * A new «افزودن زمان و حجم» to a server's services (the panel's grant dialog): the days, the traffic and the reason
 * customers read, walked one by one with the panel's presets, then whether the services still waiting for their first
 * connection take it too and whether customers are told — and how many services it reaches as it stands (one that
 * reaches none does not start). One POST /servers/{id}/grants.
 *
 * Actions besides the form's: `new` (server id).
 */
final class ServerGrantFormScreen extends FormScreen
{
    public const ID = 'svgn';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function form(Admin $admin, array $state): DescribedForm
    {
        $unstarted = (int) ($state['reach']['unstarted'] ?? 0);

        return new DescribedForm(array_values(array_filter([
            DescribedForm::field('days', 'number', 'تعداد روز', 'به تاریخ پایان هر سرویس اضافه می‌شود.', presets: ['1' => '۱ روز', '3' => '۳ روز', '7' => '۷ روز']),
            DescribedForm::field('traffic_gb', 'decimal', 'حجم', 'روی حجم هر سرویس اضافه می‌شود.', presets: ['5' => '۵ گیگ', '10' => '۱۰ گیگ', '20' => '۲۰ گیگ'], unit: 'گیگابایت'),
            DescribedForm::field('reason', 'textarea', 'دلیل', 'در پیامی که مشتری در تلگرام یا با ایمیل می‌گیرد نشان داده می‌شود.', placeholder: 'جبران قطعی سرور'),
            $unstarted > 0 ? DescribedForm::field('include_unstarted', 'toggle', 'سرویس‌های در انتظار اولین اتصال هم شامل شوند', 'سرویس‌هایی که مشتری هنوز به آن‌ها وصل نشده و زمانشان شروع نشده است (' . Fmt::number($unstarted) . ' سرویس روی این سرور).', default: false, walk: false) : null,
            DescribedForm::field('notify', 'toggle', 'پیام به مشتری‌ها', 'هر مشتری در تلگرام یا با ایمیل می‌بیند چه چیزی به سرویسش اضافه شد.', default: true, walk: false),
        ])), $this->crypt);
    }

    protected function title(array $state): string
    {
        return ServerGrantsScreen::TITLE;
    }

    protected function path(array $state): array
    {
        return [Words::ROOT, Fmt::bare(ServersScreen::TITLE), (string) ($state['name'] ?? '')];
    }

    protected function saveLabel(array $state): string
    {
        return '✔️ افزودن به سرویس‌ها';
    }

    protected function ready(array $state): bool
    {
        return $this->reach($state) > 0;
    }

    protected function leave(Admin $admin, array $state): View
    {
        return $admin->open(ServerGrantsScreen::ID, 'show', [(int) ($state['server'] ?? 0)]);
    }

    protected function intro(View $view, Admin $admin, array $state): void
    {
        $running = (int) ($state['reach']['running'] ?? 0);
        $unstarted = (int) ($state['reach']['unstarted'] ?? 0);
        $total = $this->reach($state);
        $untouched = 'سرویس‌های تمام‌شده و غیرفعال‌شده دست نمی‌خورند.';
        $view->line('به سرویس‌های «' . Fmt::e((string) ($state['name'] ?? '')) . '»');
        $view->line($total > 0
            ? ($running + $unstarted > $total ? 'از ' . Fmt::number($running + $unstarted) . ' سرویس فعال روی این سرور، به ' . Fmt::number($total) . ' سرویس' : 'به ' . Fmt::number($total) . ' سرویس روی این سرور') . ' اضافه می‌شود؛ ' . $untouched
            : 'فعلا سرویسی روی این سرور نیست که این به آن اضافه شود؛ ' . $untouched . ($unstarted > 0 ? ' سرویس‌های در انتظار اولین اتصال را پایین‌تر می‌شود شامل کرد.' : ''));
        if ($unstarted === 0) {
            $view->line('سرویسی روی این سرور در انتظار اولین اتصال نیست.');
        }
        $view->line();
    }

    protected function action(Admin $admin, string $action, array $args, array $state, DescribedForm $form): View
    {
        if ($action === 'new') {
            $id = self::id($args);
            $server = (array) ($admin->api->get("/servers/{$id}")['server'] ?? []);
            $reach = (array) ($admin->api->get("/servers/{$id}/grants")['audience'] ?? []);
            $state = ['key' => 0, 'server' => $id, 'name' => (string) ($server['name'] ?? ''), 'reach' => $reach, 'stored' => null];

            return $this->begin($admin, $state, $this->form($admin, $state), walk: true);
        }

        return parent::action($admin, $action, $args, $state, $form);
    }

    protected function save(Admin $admin, array $state, DescribedForm $form): View
    {
        $id = (int) ($state['server'] ?? 0);
        $admin->api->post("/servers/{$id}/grants", $form->payload(self::draftOf($state)));
        $admin->forget(self::ID);
        $admin->notice('✅ افزودن زمان و حجم شروع شد');

        return $admin->open(ServerGrantsScreen::ID, 'show', [$id]);
    }

    /**
     * How many services it reaches as the draft stands: the running ones, and those waiting when ticked in.
     *
     * @param array<string, mixed> $state
     */
    private function reach(array $state): int
    {
        $draft = self::draftOf($state);

        return (int) ($state['reach']['running'] ?? 0) + (Json::on($draft, 'include_unstarted') ? (int) ($state['reach']['unstarted'] ?? 0) : 0);
    }
}
