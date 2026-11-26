<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Texts;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Bot\BotScreens;
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
use App\Modules\Telegram\Emoji\EmojiMap;

/**
 * «💎 ایموجی پرمیوم متن‌ها» (the owner's and an agent's; the panel's card atop «متن‌های ربات»): plain emoji the shop gave a
 * premium emoji each, drawn premium in every text the bot sends. Whether the bot may send premium emoji — found able,
 * found unable, or never checked — and «🔍 بررسی دوباره», the short test message the bot sends to this chat and deletes
 * again: offered in the bot of the shop on screen alone (another shop's bot has no chat with this account); the switch,
 * on only once the bot was found able — till then its button says why, and a tap is the API's refusal in its own words;
 * the plain emoji the texts carry (the API's `emojis`: most carried first, then the map's ones no text carries), a button
 * each, marked 💎 once swapped, a page at a time — a tap opens its page: where it is, the premium emoji swapped in, one
 * picked from the bot's library (PremiumEmojisScreen's, paged as there; the ones standing for a plain emoji left out) or
 * taken off, asked first; and «➕ ایموجی دیگر» for one the texts do not carry: a premium emoji picked, then the plain
 * emoji it stands for — its own kept at a tap, or another typed. An emoji is named by token() and the map read again at
 * each tap, so an older window's tap acts on that emoji or says it is gone; a change sends the switch alone, or the whole
 * list as the panel has it now.
 *
 * Actions: `page` (n), `chk`, `on` (1 on, 0 off), `e` (token, the list's page), `ch` (token, the library's page), `to`
 * (token, premium emoji id), `un` (token: asks), `und` (token), `add` (the library's page), `pk` (premium emoji id: asks
 * the plain emoji, answered at `em`), `keep` (premium emoji id). 0.10.2's `rm`/`rmd` (a premium emoji's id) show the list.
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

    /** Said of a tap on an emoji an older window still shows, carried by no text and swapped for nothing since. */
    public const GONE = 'این ایموجی دیگر در فهرست نیست';

    /** Said of a premium emoji picked that stands for a plain emoji already (an older window, a second tap). */
    public const ALREADY = 'این ایموجی پرمیوم همین حالا در فهرست است';

    /** Said of a premium emoji picked from an older window, taken off the bot's library since. */
    public const NOT_KNOWN = 'این ایموجی پرمیوم دیگر در فهرست ایموجی‌های ربات نیست';

    /** Said of a premium emoji taken off an emoji that has none any more (an older window). */
    public const NOT_SWAPPED = 'این ایموجی همین حالا ایموجی پرمیوم ندارد';

    /** Said of a plain emoji typed for «ایموجی دیگر» that has a premium emoji already: its page, to change it there. */
    public const HAS_ONE = 'این ایموجی همین حالا ایموجی پرمیوم دارد؛ از همین صفحه عوضش کنید';

    /** The switch's button while the bot was not found able: what it waits for. */
    public const LOCKED = '🔒 روشن کردن، بعد از بررسی موفق';

    /** An emoji's page: its premium emoji picked, another picked in its place, or taken off. */
    public const PICK = '💎 انتخاب ایموجی پرمیوم';
    public const CHANGE = '🔁 تغییر ایموجی پرمیوم';
    public const UNSWAP = '🗑 برداشتن';

    /** A plain emoji the texts do not carry, given a premium emoji all the same (the library first, then the plain emoji). */
    public const OTHER = '➕ ایموجی دیگر';

    /** The mark beside a swapped emoji on its button. */
    public const SWAPPED = '💎';

    private const NAME = 'ایموجی پرمیوم متن‌ها';

    /** The emoji a page of the list (View::CHOICES_MOST), and a row. */
    private const PER_PAGE = 40;
    private const PER_ROW = 5;

    /** The library's premium emoji a page of the picker, as PremiumEmojisScreen pages them. */
    private const PICK_PAGE = 24;

    public function show(Admin $admin, array $args): View
    {
        return $this->view($admin, self::read($admin));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        switch ($action) {
            case 'page':
                $admin->remember(self::ID, ['page' => self::page($args)]);
                break;
            case 'chk':
                return $this->check($admin);
            case 'on':
                return isset($args[0]) ? $this->turn($admin, $args[0] === '1') : $this->show($admin, []);
            case 'e':
                if (isset($args[1])) {
                    $admin->remember(self::ID, ['page' => self::page($args, 1)]);
                }

                return $this->opened($admin, self::tokenAt($args));
            case 'ch':
                return $this->choose($admin, self::tokenAt($args), self::page($args, 1));
            case 'to':
                return $this->swap($admin, self::tokenAt($args), self::emojiId($args, 1));
            case 'un':
                return $this->askUnswap($admin, self::tokenAt($args));
            case 'und':
                return $this->unswap($admin, self::tokenAt($args));
            case 'add':
                return $this->other($admin, self::page($args));
            case 'pk':
                return $this->picked($admin, self::emojiId($args));
            case 'keep':
                return $this->keep($admin, self::emojiId($args));
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

    /** An emoji's name in a button's data: the first 8 hex of the md5 of its EmojiMap::key() (❤ and ❤️ one). */
    public static function token(string $emoji): string
    {
        return substr(md5(EmojiMap::key($emoji)), 0, 8);
    }

    /**
     * The status, the switch and a page of the emoji the texts carry.
     *
     * @param array<string, mixed> $data GET /bot/premium-texts's `premium_texts`, or a change's answer
     */
    private function view(Admin $admin, array $data): View
    {
        $rows = self::rows($data);
        $items = self::items($data);
        $status = Json::part($data, 'status');
        $ok = $status === [] ? null : Json::on($status, 'ok');
        $enabled = Json::on($data, 'enabled');
        $max = self::most($data);
        $here = PremiumEmojisScreen::here($admin);
        [$shown, $page, $pages] = View::slice($rows, (int) ($admin->state(self::ID)['page'] ?? 1), self::PER_PAGE);

        $view = View::make(self::TITLE, ...self::path())->card([
            'ربات' => match ($ok) {
                true => '✅ می‌تواند ایموجی پرمیوم بفرستد',
                false => '⚠️ نمی‌تواند ایموجی پرمیوم بفرستد',
                null => '❔ نامشخص، هنوز بررسی نشده',
            },
            'آخرین بررسی' => $ok === null ? null : Fmt::date(Json::text($status, 'checked_at'), true),
            self::NAME => Fmt::onOff($enabled),
            'ایموجی‌های متن‌ها' => Fmt::number(count(array_filter($rows, static fn(array $row): bool => $row['texts'] + $row['menu'] > 0))),
            'پرمیوم شده' => Fmt::number(count($items)) . ' از ' . Fmt::number($max),
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

        if ($rows === []) {
            $view->line()->line('<b>متن‌های ربات فعلا ایموجی‌ای ندارند.</b>');
        } else {
            $view->note('یکی را بزنید تا ایموجی پرمیوم آن را انتخاب کنید؛ ' . self::SWAPPED . ' یعنی ایموجی پرمیوم دارد.');
        }
        if (count($items) >= $max) {
            $view->note('فهرست پر است: حداکثر ' . Fmt::number($max) . ' ایموجی.');
        }

        $view->grid(self::PER_ROW, ...array_map(static fn(array $row): Button => Button::to($row['emoji'] . ($row['emoji_id'] !== null ? ' ' . self::SWAPPED : ''), self::ID, 'e', self::token($row['emoji']), $page), $shown))
            ->pager($page, $pages, self::ID, 'page')
            ->tiles(
                count($items) < $max ? Button::to(self::OTHER, self::ID, 'add') : null,
                self::switch($enabled, Json::on($data, 'can_enable')),
            )
            ->tiles($here ? Button::to(self::CHECK, self::ID, 'chk') : null, Button::to(PremiumEmojisScreen::TITLE, PremiumEmojisScreen::ID));

        return $view->help('ایموجی‌های معمولی متن‌های ربات، پیش‌فرض یا تغییر یافته، و دکمه‌های شیشه‌ای منوی شروع، پراستفاده‌ترها اول. به هر کدام یک ایموجی پرمیوم بدهید تا در همه متن‌ها با آن فرستاده شود؛ ایموجی اول یک دکمه آیکون آن دکمه می‌شود. متنی که خودش ایموجی پرمیوم دارد همان را نگه می‌دارد. برای ایموجی‌ای که در متن‌ها نیست، «' . self::OTHER . '» را بزنید. ' . self::WHY . ' ایموجی پرمیوم تازه را در «' . PremiumEmojisScreen::TITLE . '» اضافه کنید.')
            ->refresh(self::ID)
            ->back(TextsScreen::ID);
    }

    /**
     * An emoji's page: where it is, the premium emoji swapped in, and the ways to pick one, another or none — none to
     * pick while the map is full and it has none.
     *
     * @param array<string, mixed> $data
     * @param array{emoji: string, texts: int, menu: int, emoji_id: ?string} $row
     */
    private function emojiView(Admin $admin, array $data, array $row): View
    {
        $token = self::token($row['emoji']);
        $id = $row['emoji_id'];
        $full = $id === null && count(self::items($data)) >= self::most($data);

        $view = View::make('ایموجی ' . $row['emoji'], ...[...self::path(), Fmt::bare(self::TITLE)])->card([
            'استفاده' => self::where($row),
            'ایموجی پرمیوم' => $id !== null ? self::premium($id, $row['emoji']) : 'ندارد',
        ]);
        if ($full) {
            $view->note('فهرست پر است: حداکثر ' . Fmt::number(self::most($data)) . ' ایموجی. برای این یکی، ایموجی پرمیوم یکی دیگر را بردارید.');
        } elseif ($id === null) {
            $view->note('یک ایموجی پرمیوم از ایموجی‌های ربات انتخاب کنید تا در متن‌ها جای ' . Fmt::e($row['emoji']) . ' بنشیند.');
        }

        return $view->row(
            $full ? null : Button::to($id === null ? self::PICK : self::CHANGE, self::ID, 'ch', $token)->primary(),
            $id === null ? null : Button::to(self::UNSWAP, self::ID, 'un', $token)->danger(),
        )->back(self::ID, 'page', (int) ($admin->state(self::ID)['page'] ?? 1));
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
     * Emoji `$token`'s page; gone since (no text carries it, nothing swapped in), said, and the list.
     *
     * @throws ApiRefusal
     */
    private function opened(Admin $admin, string $token): View
    {
        $data = self::read($admin);
        $row = self::resolve($data, $token);
        if ($row === null) {
            $admin->notice(self::GONE);

            return $this->view($admin, $data);
        }

        return $this->emojiView($admin, $data, $row);
    }

    /**
     * The library to pick emoji `$token`'s premium emoji from, page `$page`; gone, said, and the list; one to add while
     * the map is full, said, and its page.
     *
     * @throws ApiRefusal
     */
    private function choose(Admin $admin, string $token, int $page): View
    {
        $data = self::read($admin);
        $row = self::resolve($data, $token);
        if ($row === null) {
            $admin->notice(self::GONE);

            return $this->view($admin, $data);
        }
        if ($row['emoji_id'] === null && count(self::items($data)) >= self::most($data)) {
            $admin->notice(self::full($data));

            return $this->emojiView($admin, $data, $row);
        }

        return $this->picker($admin, $data, $page, $row);
    }

    /**
     * Premium emoji `$id` swapped in for emoji `$token`: in its item's place, or a new item at the end of the whole list as
     * the panel has it now; then its page. Gone, unknown to the library since, standing for a plain emoji already, or
     * one more than the map takes: said.
     *
     * @throws ApiRefusal
     */
    private function swap(Admin $admin, string $token, string $id): View
    {
        $data = self::read($admin);
        $row = self::resolve($data, $token);
        if ($row === null) {
            $admin->notice(self::GONE);

            return $this->view($admin, $data);
        }
        if (self::known($admin, $id) === null) {
            $admin->notice(self::NOT_KNOWN);

            return $this->picker($admin, $data, (int) ($admin->state(self::ID)['pick'] ?? 1), $row);
        }
        $items = self::items($data);
        $taken = self::find($items, $id);
        if ($taken !== null) {
            $admin->notice(self::ALREADY);

            return EmojiMap::key($taken['emoji']) === EmojiMap::key($row['emoji'])
                ? $this->emojiView($admin, $data, $row)
                : $this->picker($admin, $data, (int) ($admin->state(self::ID)['pick'] ?? 1), $row);
        }
        $at = self::at($items, $row['emoji']);
        if ($at !== null) {
            $items[$at]['emoji_id'] = $id;
        } elseif (count($items) >= self::most($data)) {
            $admin->notice(self::full($data));

            return $this->emojiView($admin, $data, $row);
        } else {
            $items[] = ['emoji' => $row['emoji'], 'emoji_id' => $id];
        }
        $data = self::save($admin, $items);
        $admin->notice('✅ ایموجی پرمیوم ' . $row['emoji'] . ' ذخیره شد');

        return $this->landing($admin, $data, $row['emoji']);
    }

    /**
     * Asked before emoji `$token`'s premium emoji is taken off; gone, or none any more, said.
     *
     * @throws ApiRefusal
     */
    private function askUnswap(Admin $admin, string $token): View
    {
        $data = self::read($admin);
        $row = self::resolve($data, $token);
        if ($row === null) {
            $admin->notice(self::GONE);

            return $this->view($admin, $data);
        }
        $items = self::items($data);
        $at = self::at($items, $row['emoji']);
        if ($at === null) {
            $admin->notice(self::NOT_SWAPPED);

            return $this->emojiView($admin, $data, $row);
        }

        return $admin->confirm(
            'ایموجی پرمیوم این ایموجی برداشته شود؟',
            Button::to(Words::yes('برداشته شود', '🗑'), self::ID, 'und', $token),
            Button::to(Words::CANCEL, self::ID, 'e', $token),
            Fmt::e($row['emoji']) . ' ← ' . self::premium($items[$at]['emoji_id'], $row['emoji']) . ': از این به بعد متن‌ها همان ' . Fmt::e($row['emoji']) . ' معمولی را دارند. خود ایموجی پرمیوم در «' . PremiumEmojisScreen::TITLE . '» می‌ماند.',
        );
    }

    /**
     * Emoji `$token`'s premium emoji taken off: the whole list as the panel has it now, without its item; then its page,
     * or the list once nothing carries it. Gone, or none any more, said.
     *
     * @throws ApiRefusal
     */
    private function unswap(Admin $admin, string $token): View
    {
        $data = self::read($admin);
        $row = self::resolve($data, $token);
        if ($row === null) {
            $admin->notice(self::GONE);

            return $this->view($admin, $data);
        }
        $items = self::items($data);
        $at = self::at($items, $row['emoji']);
        if ($at === null) {
            $admin->notice(self::NOT_SWAPPED);

            return $this->emojiView($admin, $data, $row);
        }
        array_splice($items, $at, 1);
        $data = self::save($admin, $items);
        $admin->notice('🗑 ایموجی پرمیوم ' . $row['emoji'] . ' برداشته شد');

        return $this->landing($admin, $data, $row['emoji']);
    }

    /**
     * «➕ ایموجی دیگر»: the library's page `$page`; the map full, said.
     *
     * @throws ApiRefusal
     */
    private function other(Admin $admin, int $page): View
    {
        $data = self::read($admin);
        if (count(self::items($data)) >= self::most($data)) {
            $admin->notice(self::full($data));

            return $this->view($admin, $data);
        }

        return $this->picker($admin, $data, $page);
    }

    /**
     * The bot's premium emoji standing for no plain emoji yet, page `$page`, each drawn and numbered as its button is —
     * for emoji `$row` (its page's «انتخاب»), or for one the plain emoji of is asked after («➕ ایموجی دیگر»); none,
     * how to show the bot one (/emoji — «✨ افزودن ایموجی تازه» in this shop's own bot).
     *
     * @param array<string, mixed> $data
     * @param array{emoji: string, texts: int, menu: int, emoji_id: ?string}|null $row
     * @throws ApiRefusal
     */
    private function picker(Admin $admin, array $data, int $page, ?array $row = null): View
    {
        $mapped = array_column(self::items($data), 'emoji_id');
        $library = self::library($admin);
        $free = array_values(array_filter($library, static fn(array $emoji): bool => !in_array($emoji['id'], $mapped, true)));
        [$shown, $page, $pages] = View::slice($free, $page, self::PICK_PAGE);
        $admin->remember(self::ID, ['pick' => $page]);
        $first = ($page - 1) * self::PICK_PAGE + 1;
        $token = $row === null ? null : self::token($row['emoji']);
        $back = $token === null ? [self::ID, Nav::SHOW] : [self::ID, 'e', $token];

        $view = View::make($row === null ? self::OTHER : ($row['emoji_id'] === null ? self::PICK : self::CHANGE), ...[...self::path(), Fmt::bare(self::TITLE)]);
        if ($row !== null) {
            $view->card([
                'ایموجی' => Fmt::e($row['emoji']),
                'ایموجی پرمیوم فعلی' => $row['emoji_id'] === null ? null : self::premium($row['emoji_id'], $row['emoji']),
            ]);
        }
        if ($free === []) {
            $view->line($library === []
                ? '<b>ربات هنوز ایموجی پرمیومی نمی‌شناسد.</b> یک بار ' . Fmt::ltr('/emoji') . ' را در ربات بفرستید و بعد پیامی با ایموجی‌های پرمیوم؛ ربات آن‌ها را به خاطر می‌سپارد.'
                : '<b>همه ایموجی‌های پرمیومی که ربات می‌شناسد در فهرست هستند.</b> برای ایموجی تازه، پیامی با آن را بعد از ' . Fmt::ltr('/emoji') . ' به ربات بفرستید.');
            if (PremiumEmojisScreen::here($admin)) {
                $view->row(Button::to(PremiumEmojisScreen::ADD, PremiumEmojisScreen::ID, 'add')->success());
            } else {
                $view->line(PremiumEmojisScreen::OWN_BOT);
            }

            return $view->back(...$back);
        }

        $drawn = [];
        foreach ($shown as $n => $emoji) {
            $drawn[] = Fmt::number($first + $n) . ' ' . self::premium($emoji['id'], $emoji['emoji']);
        }
        $view->total(count($free), 'ایموجی پرمیوم')
            ->line()
            ->line(implode('   ', $drawn))
            ->note($row === null
                ? 'یکی را انتخاب کنید؛ بعد پرسیده می‌شود در متن‌ها جای کدام ایموجی معمولی بنشیند.'
                : 'یکی را انتخاب کنید تا در متن‌ها جای ' . Fmt::e($row['emoji']) . ' بنشیند.');
        $buttons = [];
        foreach ($shown as $n => $emoji) {
            $label = Fmt::plain(Fmt::number($first + $n)) . ' ' . $emoji['emoji'];
            $buttons[] = $token === null ? Button::to($label, self::ID, 'pk', $emoji['id']) : Button::to($label, self::ID, 'to', $token, $emoji['id']);
        }

        return $view->grid(4, ...$buttons)
            ->pager($page, $pages, self::ID, ...($token === null ? ['add'] : ['ch', $token]))
            ->back(...$back);
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

            return $this->other($admin, (int) ($admin->state(self::ID)['pick'] ?? 1));
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

            return $this->other($admin, (int) ($admin->state(self::ID)['pick'] ?? 1));
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
     * Premium emoji `$id` added for plain emoji `$emoji`: the whole list as the panel has it now, and it; then that
     * emoji's page. In the map already (a second answer), said; a plain emoji that has one already opens its page.
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
        $typed = trim($emoji);
        foreach (EmojiMap::isOne($typed) ? $items : [] as $item) {
            if (EmojiMap::key($item['emoji']) === EmojiMap::key($typed)) {
                $admin->notice(self::HAS_ONE);

                return $this->landing($admin, $data, $item['emoji']);
            }
        }
        $items[] = ['emoji' => $emoji, 'emoji_id' => $id];
        $data = self::save($admin, $items);
        $saved = self::find(self::items($data), $id)['emoji'] ?? $emoji;
        $admin->notice('✅ ایموجی پرمیوم ' . $saved . ' ذخیره شد');

        return $this->landing($admin, $data, $saved);
    }

    /**
     * After a change: plain emoji `$emoji`'s page, or the list once it is in it no more.
     *
     * @param array<string, mixed> $data The change's answer
     */
    private function landing(Admin $admin, array $data, string $emoji): View
    {
        $row = self::resolve($data, self::token($emoji));

        return $row === null ? $this->view($admin, $data) : $this->emojiView($admin, $data, $row);
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
     * @param list<array{emoji: string, emoji_id: string}> $items
     * @return array<string, mixed>
     * @throws ApiRefusal
     */
    private static function save(Admin $admin, array $items): array
    {
        $list = array_map(static fn(array $item): array => ['emoji' => $item['emoji'], 'emoji_id' => $item['emoji_id']], $items);

        return Json::part($admin->api->put('/bot/premium-texts', ['items' => $list]), 'premium_texts');
    }

    /**
     * The plain emoji the texts carry, in the API's order (`emojis`), each with the premium emoji swapped in.
     *
     * @param array<string, mixed> $data
     * @return list<array{emoji: string, texts: int, menu: int, emoji_id: ?string}>
     */
    private static function rows(array $data): array
    {
        $rows = [];
        foreach (Json::rows($data, 'emojis') as $row) {
            $id = (string) ($row['emoji_id'] ?? '');
            if (is_string($row['emoji'] ?? null) && $row['emoji'] !== '') {
                $rows[] = ['emoji' => $row['emoji'], 'texts' => (int) ($row['texts'] ?? 0), 'menu' => (int) ($row['menu'] ?? 0), 'emoji_id' => preg_match('/^\d{1,20}$/', $id) === 1 ? $id : null];
            }
        }

        return $rows;
    }

    /**
     * Emoji `$token` among the ones the texts carry — or the map's, carried by none —; null for none.
     *
     * @param array<string, mixed> $data
     * @return array{emoji: string, texts: int, menu: int, emoji_id: ?string}|null
     */
    private static function resolve(array $data, string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        foreach (self::rows($data) as $row) {
            if (self::token($row['emoji']) === $token) {
                return $row;
            }
        }
        foreach (self::items($data) as $item) {
            if (self::token($item['emoji']) === $token) {
                return ['emoji' => $item['emoji'], 'texts' => 0, 'menu' => 0, 'emoji_id' => $item['emoji_id']];
            }
        }

        return null;
    }

    /**
     * The map's items, in the shop's order.
     *
     * @param array<string, mixed> $data
     * @return list<array{emoji: string, emoji_id: string}>
     */
    private static function items(array $data): array
    {
        $items = [];
        foreach (Json::rows($data, 'items') as $item) {
            $id = (string) ($item['emoji_id'] ?? '');
            if (preg_match('/^\d{1,20}$/', $id) === 1 && is_string($item['emoji'] ?? null)) {
                $items[] = ['emoji' => $item['emoji'], 'emoji_id' => $id];
            }
        }

        return $items;
    }

    /**
     * The item of premium emoji `$id` among `$items`; null for none.
     *
     * @param list<array{emoji: string, emoji_id: string}> $items
     * @return array{emoji: string, emoji_id: string}|null
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
     * Where plain emoji `$emoji`'s item is among `$items` (❤ and ❤️ one); null for none.
     *
     * @param list<array{emoji: string, emoji_id: string}> $items
     */
    private static function at(array $items, string $emoji): ?int
    {
        foreach ($items as $n => $item) {
            if (EmojiMap::key($item['emoji']) === EmojiMap::key($emoji)) {
                return $n;
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
     * Said of one more emoji than the map takes.
     *
     * @param array<string, mixed> $data
     */
    private static function full(array $data): string
    {
        return 'فهرست پر است: حداکثر ' . Fmt::plain(Fmt::number(self::most($data))) . ' ایموجی';
    }

    /**
     * Where emoji `$row` is: «در ۲ متن و ۱ دکمه منو», «در ۲۹ متن», «در ۱ دکمه منو» or «فعلا در هیچ متنی نیست».
     *
     * @param array{emoji: string, texts: int, menu: int, emoji_id: ?string} $row
     */
    private static function where(array $row): string
    {
        $places = array_filter([
            $row['texts'] > 0 ? Fmt::number($row['texts']) . ' متن' : null,
            $row['menu'] > 0 ? Fmt::number($row['menu']) . ' دکمه منو' : null,
        ]);

        return $places === [] ? 'فعلا در هیچ متنی نیست' : 'در ' . implode(' و ', $places);
    }

    /** Premium emoji `$id` drawn as itself, `$emoji` where Telegram draws it plain. */
    private static function premium(string $id, string $emoji): string
    {
        return '<tg-emoji emoji-id="' . $id . '">' . Fmt::e($emoji) . '</tg-emoji>';
    }

    /**
     * The argument at `$index` as a premium emoji's id (digits); '' for none.
     *
     * @param list<string> $args
     */
    private static function emojiId(array $args, int $index = 0): string
    {
        return preg_match('/^\d{1,20}$/', $args[$index] ?? '') === 1 ? (string) $args[$index] : '';
    }

    /**
     * The argument at `$index` as an emoji's token (token()); '' for none.
     *
     * @param list<string> $args
     */
    private static function tokenAt(array $args, int $index = 0): string
    {
        return preg_match('/^[0-9a-f]{8}$/', $args[$index] ?? '') === 1 ? (string) $args[$index] : '';
    }

    /** @return list<string> «مدیریت › ربات › متن‌های ربات». */
    private static function path(): array
    {
        return [...BotScreens::PATH, Fmt::bare(TextsScreen::TITLE)];
    }
}
