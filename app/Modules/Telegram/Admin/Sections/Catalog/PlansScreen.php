<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Auth\StaffGrant;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «📦 پلن‌ها» (the panel's «پلن‌ها»): the catalogue in the order customers see it, each plan a button that is its row —
 * its chip (🟢 sold, ⏸ inactive, ⚠️ active but not seen in the bot), its name and its price — to its page (PlanScreen);
 * over them only how many there are, what narrows them and how many active plans the bot does not show. With the
 * catalogue's grant: «➕ افزودن پلن» (PlanEditScreen), «💹 تغییر گروهی قیمت» (PlanPricesScreen), and a draft left half
 * made, to go on with. Narrowed to a category by its chips, or searched by name. The panel's route answers every plan at
 * once: the pages are cut here.
 *
 * Actions: `page` (n), `cat` (category id, `none` for none, `_` for every one), `cp` (page of the category chips: many
 * categories go by pages), `search` (asks), `clear` (the search off).
 */
final class PlansScreen extends BaseScreen
{
    public const ID = 'pl';

    public const TITLE = Icons::PLANS . ' پلن‌ها';

    /** The chip of every category, and of the plans in none. */
    public const ANY = '_';
    public const NONE = 'none';

    public function show(Admin $admin, array $args): View
    {
        $plans = self::plans($admin);
        $state = $admin->state(self::ID);
        $filter = (string) ($state['cat'] ?? self::ANY);
        $search = (string) ($state['q'] ?? '');
        $categories = [];
        foreach ($plans as $plan) {
            if (is_array($plan['category'] ?? null)) {
                $categories[(int) $plan['category']['id']] = (string) $plan['category']['name'];
            }
        }
        if ($filter !== self::ANY && $filter !== self::NONE && !isset($categories[(int) $filter])) {
            $filter = self::ANY;
        }
        $shown = array_values(array_filter($plans, static fn(array $plan): bool => match ($filter) {
            self::ANY => true,
            self::NONE => !is_array($plan['category'] ?? null),
            default => is_array($plan['category'] ?? null) && (string) $plan['category']['id'] === $filter,
        } && ($search === '' || mb_stripos((string) ($plan['name'] ?? ''), $search) !== false)));
        [$rows, $page, $pages] = View::slice($shown, (int) ($state['page'] ?? 1));

        $view = View::make(self::TITLE, ...CatalogFmt::under())
            ->total(count($shown), 'پلن', match ($filter) {
                self::ANY => '',
                self::NONE => 'بی‌دسته',
                default => $categories[(int) $filter],
            }, $search === '' ? '' : '🔎 «' . $search . '»');
        $unseen = count(array_filter($shown, self::unseen(...)));
        if ($unseen > 0) {
            $view->line('⚠️ ' . Fmt::number($unseen) . ' پلن فعال در ربات دیده نمی‌شود؛ دلیلش در صفحه همان پلن است.');
        }
        if ($plans === []) {
            $view->line()->line('<b>هنوز پلنی تعریف نشده است</b>')->line('اولین بسته را بسازید: حجم، مدت، تعداد کاربر و قیمت.');
        } elseif ($shown === []) {
            $view->line()->line(Words::NOTHING_FOUND);
        }

        foreach ($rows as $plan) {
            $view->row(Button::to(self::label($plan), PlanScreen::ID, Nav::SHOW, (int) $plan['id']));
        }
        if ($admin->seat->holds(StaffGrant::Catalog)) {
            $draft = $admin->state(PlanEditScreen::ID);
            if (is_array($draft['d'] ?? null) && (int) ($draft['id'] ?? 0) === 0 && (string) ($draft['d']['name'] ?? '') !== '') {
                $view->row(Button::to('📝 ادامه پیش‌نویس: ' . $draft['d']['name'], PlanEditScreen::ID, 'sum'));
            }
            $view->row(
                Button::to(Words::add('پلن'), PlanEditScreen::ID, 'new')->success(),
                $plans !== [] ? Button::to(PlanPricesScreen::TITLE, PlanPricesScreen::ID, 'new') : null,
            );
        }
        if ($categories !== []) {
            $chips = [Button::to(($filter === self::ANY ? Words::CHIP : '') . 'همه', self::ID, 'cat', self::ANY)];
            foreach ($categories as $id => $name) {
                $chips[] = Button::to(($filter === (string) $id ? Words::CHIP : '') . $name, self::ID, 'cat', $id);
            }
            $chips[] = Button::to(($filter === self::NONE ? Words::CHIP : '') . 'بی‌دسته', self::ID, 'cat', self::NONE);
            $view->paged(3, $chips, (int) ($state['cp'] ?? 1), static fn(int $at): array => [self::ID, 'cp', [$at]]);
        }
        if ($plans !== []) {
            $view->row($search === '' ? Button::to(Words::SEARCH, self::ID, 'search') : Button::to(Words::CLEAR_SEARCH, self::ID, 'clear'));
        }
        $view->pager($page, $pages, self::ID, 'page');

        return $view->help('بسته‌هایی که مشتری در ربات می‌خرد؛ ترتیب همین لیست، ترتیب نمایش در ربات است و هر پلن در صفحه خودش با 🔼 و 🔽 جابه‌جا می‌شود. پلنی که دسته‌اش غیرفعال است زیر «سایر پلن‌ها» فروخته می‌شود.')
            ->back(CatalogFmt::HUB)
            ->refresh(self::ID);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        switch ($action) {
            case 'page':
                $admin->remember(self::ID, ['page' => self::page($args)]);
                break;
            case 'cat':
                $admin->remember(self::ID, ['cat' => (string) ($args[0] ?? self::ANY), 'page' => 1]);
                break;
            case 'cp':
                $admin->remember(self::ID, ['cp' => self::page($args)]);
                break;
            case 'search':
                return $admin->ask(new Prompt('چه پلنی؟', self::ID, 'search', [], 'چند حرف از نام پلن را بنویسید.', Button::to(Words::CANCEL, self::ID)));
            case 'clear':
                $admin->remember(self::ID, ['q' => '', 'page' => 1]);
                break;
        }

        return $this->show($admin, []);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        if ($action === 'search') {
            $admin->remember(self::ID, ['q' => mb_substr($answer->text(), 0, 50), 'page' => 1]);
        }

        return $this->show($admin, []);
    }

    /**
     * Every plan, in display order, as the panel lists them.
     *
     * @return list<array<string, mixed>>
     */
    public static function plans(Admin $admin): array
    {
        return Json::rows($admin->api->get('/plans'), 'plans');
    }

    /**
     * Whether an active plan is not seen in the bot now: hidden by the main shop's support, or unsellable (no server
     * that sells it).
     *
     * @param array<string, mixed> $plan
     */
    public static function unseen(array $plan): bool
    {
        return (bool) ($plan['is_active'] ?? false) && ((bool) ($plan['hidden_by_owner'] ?? false) || is_string($plan['unsellable_reason'] ?? null));
    }

    /**
     * A plan's row as its button: its chip, its name and its price.
     *
     * @param array<string, mixed> $plan
     */
    private static function label(array $plan): string
    {
        return CatalogFmt::dot((bool) ($plan['is_active'] ?? false), self::unseen($plan)) . ' ' . Fmt::cut((string) ($plan['name'] ?? ''), 26) . ' · ' . Fmt::plain(CatalogFmt::price(is_scalar($plan['price'] ?? null) ? (string) $plan['price'] : null));
    }
}
