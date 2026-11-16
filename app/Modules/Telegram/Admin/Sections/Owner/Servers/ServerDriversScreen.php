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
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «➕ افزودن سرور», its first step (the panel's add-server picker): which software manages the server's panel — each
 * connector as it describes itself (its mark, vendor and project's address) —; the form on the one picked follows
 * (ServerFormScreen).
 */
final class ServerDriversScreen extends BaseScreen
{
    public const ID = 'sva';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    public function show(Admin $admin, array $args): View
    {
        $view = View::make(Words::add('سرور'), Words::ROOT, Fmt::bare(ServersScreen::TITLE))
            ->line('پنل این سرور با کدام نرم‌افزار مدیریت می‌شود؟')
            ->line();
        $buttons = [];
        foreach ((array) ($admin->api->get('/servers/drivers')['drivers'] ?? []) as $driver) {
            if (!is_array($driver) || !is_string($driver['key'] ?? null)) {
                continue;
            }
            $traits = Json::part($driver, 'traits');
            $mark = is_string($traits['mark'] ?? null) ? $traits['mark'] : mb_substr((string) ($driver['label'] ?? ''), 0, 2);
            $about = array_filter([
                is_string($traits['vendor'] ?? null) ? Fmt::ltr($traits['vendor']) : null,
                is_string($traits['docs_url'] ?? null) ? Fmt::ltr(preg_replace('#^https?://#', '', $traits['docs_url']) ?? '') : null,
            ]);
            $view->line('• <b>' . Fmt::ltr((string) ($driver['label'] ?? '')) . '</b>' . ($about === [] ? '' : ' — ' . implode(' · ', $about)));
            $buttons[] = Button::to($mark . ' · ' . (string) ($driver['label'] ?? ''), ServerFormScreen::ID, 'new', $driver['key']);
        }

        return $view->tiles(...$buttons)->back(ServersScreen::ID);
    }
}
