<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Texts;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Bot\BotScreens;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «💎 ایموجی پرمیوم متن‌ها» (the owner's and an agent's; the panel's card atop «متن‌های ربات»): plain emoji the shop gave a
 * premium emoji each, drawn premium in every text the bot sends. Whether the bot may send premium emoji — found able,
 * found unable, or never checked — and «🔍 بررسی دوباره», the short test message the bot sends to this chat and deletes
 * again: offered in the bot of the shop on screen alone (another shop's bot has no chat with this account); the switch,
 * on only once the bot was found able — till then its button says why, and a tap is the API's refusal in its own words;
 * the map, a page at a time, each item drawn premium beside its plain emoji with how many texts it is in, taken off at a
 * tap, asked first; and «➕ افزودن ایموجی»: a premium emoji picked from the bot's library (PremiumEmojisScreen's, paged
 * as there; the ones mapped already left out), then the plain emoji it stands for — its own kept at a tap, or another
 * typed. An item is named by its premium emoji's id and the map read again at each tap, so an older window's tap acts
 * on that item or says it is gone; a change sends the switch alone, or the whole list as the panel has it now.
 *
 * Actions: `page` (n), `chk`, `on` (1 on, 0 off), `rm` (emoji id: asks), `rmd` (emoji id), `add` (the library's page),
 * `pk` (emoji id: asks the plain emoji, answered at `em`), `keep` (emoji id).
 */
final class PremiumTextsScreen extends BaseScreen
{
    public const ID = 'txp';

    public const TITLE = Icons::PREMIUM_TEXTS . ' ' . self::NAME;

    /** Why a bot may not send premium emoji, and what customers see meanwhile (PremiumEmojisScreen says it too). */
    public const WHY = "ربات فقط وقتی ایموجی پرمیوم می‌فرستد که حسابی که آن را در \u{2068}@BotFather\u{2069} ساخته تلگرام پرمیوم داشته باشد؛ تا آن موقع مشتری‌ها جایشان ایموجی معمولی می‌بینند.";

    /** Why the check is not offered from another shop's bot: the owner working an agent's shop from the main bot. */
    public const ELSEWHERE = 'این بررسی را ربات همان فروشگاه انجام می‌دهد و آن ربات به حساب شما در این گفتگو پیام نمی‌دهد: نماینده آن را از ربات یا پنل خودش انجام می‌دهد.';

    public const CHECK = '🔍 بررسی دوباره';

    /** Said of a tap on an item an older window still shows, taken off since. */
    public const GONE = 'این ایموجی دیگر در فهرست نیست';

    /** Said of a premium emoji picked twice (an older window, a second tap). */
    public const ALREADY = 'این ایموجی پرمیوم همین حالا در فهرست است';

    /** Said of a premium emoji picked from an older window, taken off the bot's library since. */
    public const NOT_KNOWN = 'این ایموجی پرمیوم دیگر در فهرست ایموجی‌های ربات نیست';

    /** The switch's button while the bot was not found able: what it waits for. */
    public const LOCKED = '🔒 روشن کردن، بعد از بررسی موفق';

    private const NAME = 'ایموجی پرمیوم متن‌ها';

    /** The map's items a page: their lines and their buttons. */
    private const PER_PAGE = 20;

    /** The library's premium emoji a page of the picker, as PremiumEmojisScreen pages them. */
    private const PICK_PAGE = 24;

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        return $this->view($admin, self::read($admin));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::emojiId($args);
        switch ($action) {
            case 'page':
                $admin->remember(self::ID, ['page' => self::page($args)]);
                break;
            case 'chk':
                return $this->check($admin);
            case 'on':
                return isset($args[0]) ? $this->turn($admin, $args[0] === '1') : $this->show($admin, []);
            case 'rm':
                return $this->askRemove($admin, $id);
            case 'rmd':
                return $this->remove($admin, $id);
            case 'add':
                return $this->picker($admin, self::page($args));
            case 'pk':
                return $this->picked($admin, $id);
            case 'keep':
                return $this->keep($admin, $id);
        }

        return $this->show($admin, []);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $id = self::emojiId($args);
        if ($action !== 'em' || $id === '') {
            return $this->show($admin, []);
        }

        // Refused (not one emoji, one the map has): the question again, the API's words over it (AdminHandler).
        return $this->add($admin, $id, $answer->text());
    }

    /**
     * The status, the switch and a page of the map.
     *
     * @param array<string, mixed> $data GET /bot/premium-texts's `premium_texts`, or a change's answer
     */
    private function view(Admin $admin, array $data): View
    {
        $items = self::items($data);
        $status = Json::part($data, 'status');
        $ok = $status === [] ? null : Json::on($status, 'ok');
        $enabled = Json::on($data, 'enabled');
        $max = self::most($data);
        $here = PremiumEmojisScreen::here($admin);
        [$shown, $page, $pages] = View::slice($items, (int) ($admin->state(self::ID)['page'] ?? 1), self::PER_PAGE);

        $view = View::make(self::TITLE, ...self::path())->card([
            'ربات' => match ($ok) {
                true => '✅ می‌تواند ایموجی پرمیوم بفرستد',
                false => '⚠️ نمی‌تواند ایموجی پرمیوم بفرستد',
                null => '❔ نامشخص، هنوز بررسی نشده',
            },
            'آخرین بررسی' => $ok === null ? null : Fmt::date(Json::text($status, 'checked_at'), true),
            self::NAME => Fmt::onOff($enabled),
            'فهرست' => Fmt::number(count($items)) . ' ایموجی از ' . Fmt::number($max),
        ]);
        if ($ok === false) {
            $view->line('⚠️ ' . self::WHY);
        } elseif ($ok === null && $here) {
            $view->line('ℹ️ «' . self::CHECK . '» را بزنید: ربات پیام کوتاهی با یک ایموجی پرمیوم به همین گفتگو می‌فرستد، می‌بیند تلگرام آن را نگه داشت یا نه، و پیام را پاک می‌کند.');
        }
        if (!$here) {
            $view->line('ℹ️ ' . self::ELSEWHERE);
        }
        if (!$enabled && $items !== []) {
            $view->line('ℹ️ خاموش است: تا روشن نشود، متن‌ها همان ایموجی معمولی را دارند.');
        }

        $view->line();
        if ($items === []) {
            $view->line('<b>هنوز ایموجی‌ای در فهرست نیست.</b> با «' . Words::add('ایموجی') . '» یک ایموجی پرمیوم از فهرست ربات انتخاب کنید و بگویید در متن‌ها جای کدام ایموجی معمولی بنشیند.');
        }
        foreach ($shown as $item) {
            $view->line(self::drawn($item) . ' · ' . ($item['texts'] > 0 ? 'در ' . Fmt::number($item['texts']) . ' متن' : 'در هیچ متنی نیست'));
        }
        if ($shown !== []) {
            $view->note('برای حذف یکی از فهرست، دکمه‌اش را بزنید.');
        }
        if (count($items) >= $max) {
            $view->note('فهرست پر است: حداکثر ' . Fmt::number($max) . ' ایموجی.');
        }

        $view->grid(4, ...array_map(static fn(array $item): Button => Button::to('🗑 ' . $item['emoji'], self::ID, 'rm', $item['emoji_id']), $shown))
            ->pager($page, $pages, self::ID, 'page')
            ->tiles(
                count($items) < $max ? Button::to(Words::add('ایموجی'), self::ID, 'add')->success() : null,
                self::switch($enabled, Json::on($data, 'can_enable')),
            )
            ->tiles($here ? Button::to(self::CHECK, self::ID, 'chk') : null, Button::to(PremiumEmojisScreen::TITLE, PremiumEmojisScreen::ID));

        return $view->help('هر ایموجی معمولی این فهرست در همه متن‌های ربات، پیش‌فرض یا تغییر یافته، با ایموجی پرمیوم خودش فرستاده می‌شود؛ ایموجی اول یک دکمه آیکن آن دکمه می‌شود. متنی که خودش ایموجی پرمیوم دارد همان را نگه می‌دارد. «در ۱۲ متن» کنار هر ایموجی یعنی در چند متن فعلی ربات آمده است. ' . self::WHY . ' ایموجی پرمیوم تازه را در «' . PremiumEmojisScreen::TITLE . '» اضافه کنید.')
            ->refresh(self::ID)
            ->back(TextsScreen::ID);
    }

    /**
     * The switch: «▶️ روشن کردن …» / «⏸ خاموش کردن …», carrying the state it sets; off while the bot was not found able,
     * what it waits for — a tap sets it all the same, and the API refuses it in its own words.
     */
    private static function switch(bool $enabled, bool $canEnable): Button
    {
        if (!$enabled && !$canEnable) {
            return Button::to(self::LOCKED, self::ID, 'on', 1);
        }

        return Button::to(Words::turn(!$enabled, self::NAME), self::ID, 'on', $enabled ? 0 : 1);
    }

    /**
     * The test: sent by this chat's bot to this chat (`?chat=`), which the API honours from the bot alone. Refused (no
     * premium emoji to test with, not sent), its words in a popup and the window drawn again: a test not sent found
     * nothing new, and what was found before stays.
     *
     * @throws ApiRefusal
     */
    private function check(Admin $admin): View
    {
        if (!PremiumEmojisScreen::here($admin)) {
            $admin->alert(self::ELSEWHERE);

            return $this->show($admin, []);
        }

        try {
            $data = Json::part($admin->api->post('/bot/premium-texts/check?chat=' . $admin->ctx->chatId()), 'premium_texts');
        } catch (ApiRefusal $refusal) {
            $admin->alert($refusal->lines());

            return $this->show($admin, []);
        }
        $admin->notice(Json::on(Json::part($data, 'status'), 'ok') ? '✅ ربات می‌تواند ایموجی پرمیوم بفرستد' : '⚠️ تلگرام ایموجی پرمیوم را برداشت؛ ربات هنوز نمی‌تواند');

        return $this->view($admin, $data);
    }

    /**
     * The switch set to `$on` — the switch alone sent, so the map stays as the panel has it; already so, said.
     *
     * @throws ApiRefusal
     */
    private function turn(Admin $admin, bool $on): View
    {
        $data = self::read($admin);
        if (Json::on($data, 'enabled') === $on) {
            $admin->notice(self::NAME . ' همین حالا ' . ($on ? 'روشن' : 'خاموش') . ' است');

            return $this->view($admin, $data);
        }
        $data = Json::part($admin->api->put('/bot/premium-texts', ['enabled' => $on]), 'premium_texts');
        $admin->notice(Json::on($data, 'enabled') ? '✅ ' . self::NAME . ' روشن شد' : '⏸ ' . self::NAME . ' خاموش شد');

        return $this->view($admin, $data);
    }

    /**
     * Asked before item `$id` is taken off; gone already, said.
     *
     * @throws ApiRefusal
     */
    private function askRemove(Admin $admin, string $id): View
    {
        $data = self::read($admin);
        $item = self::find(self::items($data), $id);
        if ($item === null) {
            $admin->notice(self::GONE);

            return $this->view($admin, $data);
        }

        return $admin->confirm(
            'این ایموجی از فهرست حذف شود؟',
            Button::to(Words::yes('حذف شود', '🗑'), self::ID, 'rmd', $id),
            Button::to(Words::CANCEL, self::ID),
            self::drawn($item) . ': از این به بعد متن‌ها همان ' . Fmt::e($item['emoji']) . ' معمولی را دارند. خود ایموجی پرمیوم در «' . PremiumEmojisScreen::TITLE . '» می‌ماند.',
        );
    }

    /**
     * Item `$id` taken off: the map as the panel has it now, without it; gone already, said.
     *
     * @throws ApiRefusal
     */
    private function remove(Admin $admin, string $id): View
    {
        $data = self::read($admin);
        $items = self::items($data);
        $item = self::find($items, $id);
        if ($item === null) {
            $admin->notice(self::GONE);

            return $this->view($admin, $data);
        }
        $data = self::save($admin, array_values(array_filter($items, static fn(array $row): bool => $row['emoji_id'] !== $id)));
        $admin->notice('🗑 ' . $item['emoji'] . ' از فهرست حذف شد');

        return $this->view($admin, $data);
    }

    /**
     * «➕ افزودن ایموجی»: the bot's premium emoji not in the map yet, page `$page`, each drawn and numbered as its button
     * is; none, how to show the bot one (/emoji — «✨ افزودن ایموجی تازه» in this shop's own bot).
     *
     * @throws ApiRefusal
     */
    private function picker(Admin $admin, int $page): View
    {
        $data = self::read($admin);
        $items = self::items($data);
        if (count($items) >= self::most($data)) {
            $admin->notice('فهرست پر است: حداکثر ' . Fmt::plain(Fmt::number(self::most($data))) . ' ایموجی');

            return $this->view($admin, $data);
        }
        $mapped = array_column($items, 'emoji_id');
        $library = self::library($admin);
        $free = array_values(array_filter($library, static fn(array $emoji): bool => !in_array($emoji['id'], $mapped, true)));
        [$shown, $page, $pages] = View::slice($free, $page, self::PICK_PAGE);
        $admin->remember(self::ID, ['pick' => $page]);
        $first = ($page - 1) * self::PICK_PAGE + 1;

        $view = View::make(Words::add('ایموجی'), ...[...self::path(), Fmt::bare(self::TITLE)]);
        if ($free === []) {
            $view->line($library === []
                ? '<b>ربات هنوز ایموجی پرمیومی نمی‌شناسد.</b> یک بار ' . Fmt::ltr('/emoji') . ' را در ربات بفرستید و بعد پیامی با ایموجی‌های پرمیوم؛ ربات آن‌ها را به خاطر می‌سپارد.'
                : '<b>همه ایموجی‌های پرمیومی که ربات می‌شناسد در فهرست هستند.</b> برای ایموجی تازه، پیامی با آن را بعد از ' . Fmt::ltr('/emoji') . ' به ربات بفرستید.');
            if (PremiumEmojisScreen::here($admin)) {
                $view->row(Button::to(PremiumEmojisScreen::ADD, PremiumEmojisScreen::ID, 'add')->success());
            } else {
                $view->line(PremiumEmojisScreen::OWN_BOT);
            }

            return $view->back(self::ID);
        }

        $drawn = [];
        foreach ($shown as $n => $emoji) {
            $drawn[] = Fmt::number($first + $n) . ' ' . self::premium($emoji['id'], $emoji['emoji']);
        }
        $view->total(count($free), 'ایموجی پرمیوم')
            ->line()
            ->line(implode('   ', $drawn))
            ->note('یکی را انتخاب کنید؛ بعد پرسیده می‌شود در متن‌ها جای کدام ایموجی معمولی بنشیند.');
        $buttons = [];
        foreach ($shown as $n => $emoji) {
            $buttons[] = Button::to(Fmt::plain(Fmt::number($first + $n)) . ' ' . $emoji['emoji'], self::ID, 'pk', $emoji['id']);
        }

        return $view->grid(4, ...$buttons)
            ->pager($page, $pages, self::ID, 'add')
            ->back(self::ID);
    }

    /**
     * Premium emoji `$id` picked: which plain emoji it stands for is asked — its own the value now, kept at a tap. Gone
     * from the library since, or in the map already, said.
     *
     * @throws ApiRefusal
     */
    private function picked(Admin $admin, string $id): View
    {
        $emoji = self::known($admin, $id);
        if ($emoji === null) {
            $admin->notice(self::NOT_KNOWN);

            return $this->picker($admin, (int) ($admin->state(self::ID)['pick'] ?? 1));
        }
        $data = self::read($admin);
        if (self::find(self::items($data), $id) !== null) {
            $admin->notice(self::ALREADY);

            return $this->view($admin, $data);
        }

        return $admin->ask($this->question($id, $emoji));
    }

    /**
     * The premium emoji's own plain emoji kept. A tap left the question: refused (that plain emoji has a premium one
     * already), it is asked again, the API's words in a popup.
     *
     * @throws ApiRefusal
     */
    private function keep(Admin $admin, string $id): View
    {
        $emoji = self::known($admin, $id);
        if ($emoji === null) {
            $admin->notice(self::NOT_KNOWN);

            return $this->picker($admin, (int) ($admin->state(self::ID)['pick'] ?? 1));
        }

        try {
            return $this->add($admin, $id, $emoji);
        } catch (ApiRefusal $refusal) {
            $admin->alert($refusal->lines());

            return $admin->ask($this->question($id, $emoji), again: true);
        }
    }

    /** Which plain emoji premium emoji `$id` (its own plain one `$emoji`) stands for in the texts. */
    private function question(string $id, string $emoji): Prompt
    {
        return (new Prompt(
            'ایموجی معمولی',
            self::ID,
            'em',
            [$id],
            'ایموجی پرمیوم ' . self::premium($id, $emoji) . ' در متن‌ها جای کدام ایموجی معمولی بنشیند؟ یک ایموجی بفرستید، یا همین ' . Fmt::e($emoji) . ' بماند.',
            Button::to(Words::CANCEL, self::ID),
        ))
            ->current($emoji)
            ->example('❤️')
            ->choices(Button::to('✔️ همین ' . $emoji . ' بماند', self::ID, 'keep', $id)->success());
    }

    /**
     * Premium emoji `$id` added for plain emoji `$emoji`: the whole list as the panel has it now, and it. In the map
     * already (a second answer), said. The page that shows it is shown.
     *
     * @throws ApiRefusal
     */
    private function add(Admin $admin, string $id, string $emoji): View
    {
        $data = self::read($admin);
        $items = self::items($data);
        if (self::find($items, $id) !== null) {
            $admin->notice(self::ALREADY);

            return $this->view($admin, $data);
        }
        $items[] = ['emoji' => $emoji, 'emoji_id' => $id, 'texts' => 0];
        $data = self::save($admin, $items);
        $saved = self::items($data);
        $admin->remember(self::ID, ['page' => max(1, (int) ceil(count($saved) / self::PER_PAGE))]);
        $admin->notice('✅ ' . (self::find($saved, $id)['emoji'] ?? $emoji) . ' به فهرست اضافه شد');

        return $this->view($admin, $data);
    }

    /**
     * The map as the panel has it now (GET /bot/premium-texts).
     *
     * @return array<string, mixed>
     * @throws ApiRefusal
     */
    private static function read(Admin $admin): array
    {
        return Json::part($admin->api->get('/bot/premium-texts'), 'premium_texts');
    }

    /**
     * `$items` kept as the whole list (PUT /bot/premium-texts, the switch left as it is): the map as the panel answers it.
     *
     * @param list<array{emoji: string, emoji_id: string, texts: int}> $items
     * @return array<string, mixed>
     * @throws ApiRefusal
     */
    private static function save(Admin $admin, array $items): array
    {
        $list = array_map(static fn(array $item): array => ['emoji' => $item['emoji'], 'emoji_id' => $item['emoji_id']], $items);

        return Json::part($admin->api->put('/bot/premium-texts', ['items' => $list]), 'premium_texts');
    }

    /**
     * The map's items, in the shop's order.
     *
     * @param array<string, mixed> $data
     * @return list<array{emoji: string, emoji_id: string, texts: int}>
     */
    private static function items(array $data): array
    {
        $items = [];
        foreach (Json::rows($data, 'items') as $item) {
            $id = (string) ($item['emoji_id'] ?? '');
            if (preg_match('/^\d{1,20}$/', $id) === 1 && is_string($item['emoji'] ?? null)) {
                $items[] = ['emoji' => $item['emoji'], 'emoji_id' => $id, 'texts' => (int) ($item['texts'] ?? 0)];
            }
        }

        return $items;
    }

    /**
     * Item `$id` of `$items`; null for none.
     *
     * @param list<array{emoji: string, emoji_id: string, texts: int}> $items
     * @return array{emoji: string, emoji_id: string, texts: int}|null
     */
    private static function find(array $items, string $id): ?array
    {
        foreach ($items as $item) {
            if ($id !== '' && $item['emoji_id'] === $id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * The premium emoji the bot knows, latest first (GET /bot/custom-emojis).
     *
     * @return list<array{id: string, emoji: string}>
     * @throws ApiRefusal
     */
    private static function library(Admin $admin): array
    {
        $library = [];
        foreach (Json::rows($admin->api->get('/bot/custom-emojis'), 'emojis') as $emoji) {
            $id = (string) ($emoji['id'] ?? '');
            if (preg_match('/^\d{1,20}$/', $id) === 1) {
                $library[] = ['id' => $id, 'emoji' => (string) ($emoji['emoji'] ?? '')];
            }
        }

        return $library;
    }

    /**
     * The plain emoji of premium emoji `$id` in the bot's library; null when it is not there.
     *
     * @throws ApiRefusal
     */
    private static function known(Admin $admin, string $id): ?string
    {
        foreach ($id === '' ? [] : self::library($admin) as $emoji) {
            if ($emoji['id'] === $id) {
                return $emoji['emoji'];
            }
        }

        return null;
    }

    /**
     * The most items the map takes (the API's `max`).
     *
     * @param array<string, mixed> $data
     */
    private static function most(array $data): int
    {
        return max(1, (int) ($data['max'] ?? 100));
    }

    /**
     * An item as the screen draws it: its plain emoji, then the premium one in its place.
     *
     * @param array{emoji: string, emoji_id: string, texts: int} $item
     */
    private static function drawn(array $item): string
    {
        return Fmt::e($item['emoji']) . ' ← ' . self::premium($item['emoji_id'], $item['emoji']);
    }

    /** Premium emoji `$id` drawn as itself, `$emoji` where Telegram draws it plain. */
    private static function premium(string $id, string $emoji): string
    {
        return '<tg-emoji emoji-id="' . $id . '">' . Fmt::e($emoji) . '</tg-emoji>';
    }

    /**
     * The argument at 0 as a premium emoji's id (digits); '' for none.
     *
     * @param list<string> $args
     */
    private static function emojiId(array $args): string
    {
        return preg_match('/^\d{1,20}$/', $args[0] ?? '') === 1 ? (string) $args[0] : '';
    }

    /** @return list<string> «مدیریت › ربات › متن‌های ربات». */
    private static function path(): array
    {
        return [...BotScreens::PATH, Fmt::bare(TextsScreen::TITLE)];
    }
}
