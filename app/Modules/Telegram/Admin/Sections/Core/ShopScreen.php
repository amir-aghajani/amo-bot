<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Core;

use App\Modules\Telegram\Admin\AdminSeat;
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

/**
 * «🏪 تعویض فروشگاه» (the owner's, on the home): which shop the management works — the main one or an agent's, as the
 * panel's shop switcher picks one. The pick is the chat's: the home and every section then show that shop. Many agents'
 * shops are paged (View::paged()) and searched by name or @username.
 *
 * Actions: `pick` (shop id), `page` (page), `search` (asks), `clear`.
 */
final class ShopScreen extends BaseScreen
{
    private const ID = 'shop';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    public function show(Admin $admin, array $args): View
    {
        $state = $admin->state(self::ID);
        $search = is_string($state['search'] ?? null) ? $state['search'] : '';
        $current = $admin->seat->shop()->id;
        $view = View::make(Icons::SHOP_SWITCH . ' کدام فروشگاه؟', Words::ROOT)
            ->line('فروشگاهی که اینجا مدیریت می‌شود؛ مثل انتخاب فروشگاه در پنل.');

        $buttons = [];
        foreach (Json::rows($admin->api->get('/shops'), 'shops') as $shop) {
            if (!is_int($shop['id'] ?? null)) {
                continue;
            }
            $name = (string) ($shop['name'] ?? '');
            $username = is_string($shop['username'] ?? null) ? $shop['username'] : null;
            if ($search !== '' && mb_stripos($name . ' @' . $username, $search) === false && $shop['id'] !== $current) {
                continue;
            }
            $buttons[] = Button::to(($shop['id'] === $current ? Words::PICKED : '') . $name . ($username !== null ? ' · @' . $username : ''), self::ID, 'pick', $shop['id']);
        }
        if ($search !== '') {
            $view->line('🔎 «' . Fmt::e($search) . '»');
        }
        if ($buttons === []) {
            $view->line(Fmt::NONE);
        }
        $view->paged(1, $buttons, (int) ($state['page'] ?? 1), static fn(int $page): array => [self::ID, 'page', [$page]]);
        if ($search !== '' || count($buttons) > View::CHOICES_MOST) {
            $view->row($search === '' ? Button::to(Words::SEARCH, self::ID, 'search') : Button::to(Words::CLEAR_SEARCH, self::ID, 'clear'));
        }

        return $view->back(Screens::HOME);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        switch ($action) {
            case 'pick':
                if (self::id($args) > 0) {
                    $admin->pickShop(self::id($args));

                    return $admin->open(Screens::HOME);
                }
                break;
            case 'page':
                $admin->remember(self::ID, ['page' => self::page($args)]);
                break;
            case 'search':
                return $admin->ask(new Prompt('کدام فروشگاه؟', self::ID, 'search', hint: 'بخشی از نام فروشگاه یا نام کاربری ربات آن'));
            case 'clear':
                $admin->remember(self::ID, ['search' => '', 'page' => 1]);
                break;
        }

        return $this->show($admin, $args);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $admin->remember(self::ID, ['search' => ltrim(trim($answer->text()), '@'), 'page' => 1]);

        return $this->show($admin, []);
    }
}
