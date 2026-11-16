<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Auth\StaffGrant;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Support\Persian;

/**
 * One plan category (from the panel's list — it has no route for one): its name, plans and whether customers see it,
 * its plans in the plans list, and with the catalogue's grant what the panel's form and row menu do — a new name (a
 * premium emoji at its start is kept as the name's), the name's premium emoji, the «فعال» switch (on or off as its
 * button says), a place up or down, and «🗑 حذف دسته», asked first with what becomes of its plans. «➕ افزودن دسته» asks
 * the name and adds it switched on. Each change starts from the category as the panel lists it now.
 *
 * Actions: `new` (asks), `name` (id: asks), `emo` (id: asks), `emx` (id), `tog` (id, 1|0: the switch set), `up` (id),
 * `down` (id), `plans` (id), `del` (id: asks), `gone` (id).
 */
final class CategoryScreen extends BaseScreen
{
    public const ID = 'ctv';

    /** What the name's question says (the panel's hint). */
    private const NAME_HINT = 'همان‌طور که مشتری هنگام انتخاب دسته می‌بیند؛ مثلا «ماهانه» یا «اقتصادی». نامی که با ایموجی پرمیوم شروع شود، در ربات پرمیوم دیده می‌شود.';

    public function show(Admin $admin, array $args): View
    {
        $categories = CategoriesScreen::categories($admin);
        $category = CatalogFmt::find($categories, self::id($args));
        if ($category === null) {
            if (self::id($args) > 0) {
                $admin->notice('این دسته دیگر نیست.');
            }

            return $admin->open(CategoriesScreen::ID);
        }

        return $this->view($admin, $category, $categories);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        if ($action === 'new') {
            return $admin->ask((new Prompt('نام دسته', self::ID, 'new', [], Fmt::e(self::NAME_HINT), Button::to(Words::CANCEL, CategoriesScreen::ID)))->example('ماهانه'));
        }
        $categories = CategoriesScreen::categories($admin);
        $category = CatalogFmt::find($categories, $id);
        if ($category === null) {
            return $this->show($admin, [(string) $id]);
        }
        switch ($action) {
            case 'name':
                return $admin->ask((new Prompt('نام دسته', self::ID, 'name', [$id], Fmt::e(self::NAME_HINT), Button::to(Words::CANCEL, self::ID, Nav::SHOW, $id)))->current((string) $category['name'])->example('ماهانه'));
            case 'emo':
                return $admin->ask((new Prompt(
                    'ایموجی پرمیوم نام',
                    self::ID,
                    'emo',
                    [$id],
                    'یک ایموجی پرمیوم بفرستید: جای ایموجی اول نام می‌نشیند و ربات آن را پرمیوم نشان می‌دهد؛ روی دکمه به شکل آیکون. فقط وقتی دیده می‌شود که حسابی که ربات را در ' . Fmt::ltr('@BotFather') . ' ساخته تلگرام پرمیوم داشته باشد.',
                    Button::to(Words::CANCEL, self::ID, Nav::SHOW, $id),
                ))->current(CatalogFmt::hasPremium(is_string($category['emoji_id'] ?? null)), false));
            case 'emx':
                $admin->api->put("/plans/categories/{$id}", ['name' => (string) $category['name'], 'emoji_id' => null, 'is_active' => (bool) $category['is_active']]);
                $admin->notice('✅ ایموجی پرمیوم برداشته شد');
                break;
            case 'tog':
                if (!in_array($args[1] ?? null, ['1', '0'], true)) {
                    break;
                }
                $on = $args[1] === '1';
                $admin->api->patch("/plans/categories/{$id}", ['is_active' => $on]);
                $admin->notice($on ? '✅ فعال شد' : Words::INACTIVE . ' شد');
                break;
            case 'up':
            case 'down':
                $ids = CatalogFmt::moved($categories, $id, $action === 'up' ? -1 : 1, static fn(array $row): bool => true);
                if ($ids !== null) {
                    $admin->api->post('/plans/categories/reorder', ['ids' => $ids]);
                    $admin->notice($action === 'up' ? Words::UP . ' یک جا بالاتر رفت' : Words::DOWN . ' یک جا پایین‌تر رفت');
                }
                break;
            case 'plans':
                $admin->remember(PlansScreen::ID, ['cat' => (string) $id, 'q' => '', 'page' => 1]);

                return $admin->open(PlansScreen::ID);
            case 'del':
                $plans = (int) (($category['counts'] ?? [])['plans'] ?? 0);

                return $admin->confirm(
                    'این دسته حذف شود؟',
                    Button::to(Words::yes('حذف شود', CatalogFmt::emoji(Words::DELETE)), self::ID, 'gone', $id),
                    Button::to(Words::CANCEL, self::ID, Nav::SHOW, $id),
                    Fmt::e('«' . $category['name'] . '» حذف می‌شود.') . "\n" . Fmt::e(match (true) {
                        $plans === 0 => 'این دسته پلنی ندارد.',
                        $plans === 1 => 'پلن این دسته حذف نمی‌شود؛ بدون دسته می‌ماند و زیر «سایر پلن‌ها» فروخته می‌شود.',
                        default => Persian::number($plans) . ' پلن این دسته حذف نمی‌شوند؛ بدون دسته می‌مانند و زیر «سایر پلن‌ها» فروخته می‌شوند.',
                    }),
                    danger: true,
                );
            case 'gone':
                $admin->api->delete("/plans/categories/{$id}");
                $admin->notice('🗑 دسته حذف شد');

                return $admin->open(CategoriesScreen::ID);
        }

        return $this->show($admin, [(string) $id]);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $id = self::id($args);
        if ($action === 'new') {
            $name = $answer->text();
            $category = (array) ($admin->api->post('/plans/categories', ['name' => $name, 'emoji_id' => CatalogFmt::nameEmoji($answer, $name, null), 'is_active' => true])['category'] ?? []);
            $admin->notice('✅ دسته اضافه شد');

            return $this->show($admin, [(string) ($category['id'] ?? 0)]);
        }
        $category = CatalogFmt::find(CategoriesScreen::categories($admin), $id);
        if ($category === null) {
            return $this->show($admin, [(string) $id]);
        }
        if ($action === 'name') {
            $premium = CatalogFmt::premium($answer);
            // Left out, the server keeps its premium emoji while the name still starts with an emoji.
            $admin->api->put("/plans/categories/{$id}", ['name' => $answer->text(), 'is_active' => (bool) $category['is_active']] + ($premium !== null && $premium['leading'] ? ['emoji_id' => $premium['id']] : []));
            $admin->notice('✅ دسته ذخیره شد');
        } elseif ($action === 'emo') {
            $premium = CatalogFmt::premium($answer) ?? throw new ApiRefusal(422, 'یک ایموجی پرمیوم بفرستید؛ ایموجی معمولی پرمیوم نیست.');
            $admin->api->put("/plans/categories/{$id}", ['name' => trim(CatalogFmt::withLeading((string) $category['name'], $premium['emoji'])), 'emoji_id' => $premium['id'], 'is_active' => (bool) $category['is_active']]);
            $admin->notice('✅ ایموجی پرمیوم گذاشته شد');
        }

        return $this->show($admin, [(string) $id]);
    }

    /**
     * @param array<string, mixed> $category
     * @param list<array<string, mixed>> $categories
     */
    private function view(Admin $admin, array $category, array $categories): View
    {
        $id = (int) $category['id'];
        $at = array_search($id, array_map(static fn(array $row): int => (int) $row['id'], $categories), true);
        $active = (bool) ($category['is_active'] ?? false);
        $premium = is_string($category['emoji_id'] ?? null);
        $view = View::make(Icons::CATEGORIES . ' ' . (string) $category['name'], ...CatalogFmt::under(Fmt::bare(CategoriesScreen::TITLE)))
            ->card([
                'وضعیت' => Fmt::active($active),
                'پلن‌ها' => Fmt::number((int) (($category['counts'] ?? [])['plans'] ?? 0)) . ' پلن',
                'ایموجی پرمیوم نام' => CatalogFmt::hasPremium($premium),
                'جایگاه' => $at === false ? null : CatalogFmt::place($at + 1, count($categories)),
            ]);
        if (!$active) {
            $view->note('دسته غیرفعال از لیست خرید کنار می‌رود؛ پلن‌هایش زیر «سایر پلن‌ها» می‌مانند.');
        }
        if ($admin->seat->holds(StaffGrant::Catalog)) {
            $view->row(Button::to(Words::edit('نام دسته'), self::ID, 'name', $id)->primary(), $active ? Button::to(Words::DEACTIVATE, self::ID, 'tog', $id, 0)->danger() : Button::to(Words::ACTIVATE, self::ID, 'tog', $id, 1)->success());
        }
        $view->row(Button::to(Icons::PLANS . ' پلن‌های این دسته', self::ID, 'plans', $id));
        if ($admin->seat->holds(StaffGrant::Catalog)) {
            $view->row(
                Button::to(Words::edit('ایموجی پرمیوم'), self::ID, 'emo', $id),
                $premium ? Button::to('🚫 برداشتن ایموجی پرمیوم', self::ID, 'emx', $id) : null,
            )
                ->row(
                    $at !== false && $at > 0 ? Button::to(Words::UP . ' بالاتر', self::ID, 'up', $id) : null,
                    $at !== false && $at < count($categories) - 1 ? Button::to(Words::DOWN . ' پایین‌تر', self::ID, 'down', $id) : null,
                )
                ->row(Button::to(Words::DELETE . ' دسته', self::ID, 'del', $id)->danger());
        }

        return $view->back(CategoriesScreen::ID);
    }
}
