<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Core;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Customers\Customer;
use App\Modules\Telegram\Admin\Sections\Customers\CustomerScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «🛡 مدیران ربات» (reached from «⚙️ تنظیمات»): the shop's admins — its customers whose role is admin (GET /users with
 * role=admin) —, searched and paged as the users list is, each row opening the customer's page (CustomerScreen); over
 * them, what an admin has now (powers(): in the main shop the owner's whole seat, in an agent's shop the agent's).
 * «➕ افزودن مدیر ربات» asks for one customer — «#number», a Telegram id or a @username — and looks them up through the
 * users list's search, an exact match first (looked for past the search's first page: lookup()): one who is no admin yet
 * is asked about first, in red with who exactly and what is handed over (handsOver()), then made one (PUT
 * /users/{id}/role); one already an admin is said so; several are offered as buttons to their pages; none is said over
 * the question, asked again. The panel refuses what it refuses, said as every screen says it.
 *
 * Actions besides ListScreen's: `add` (asks), `set` (id: made an admin, once asked).
 */
final class BotAdminsScreen extends ListScreen
{
    public const ID = 'ba';

    public const TITLE = Icons::BOT_ADMINS . ' مدیران ربات';

    /** «➕ افزودن مدیر ربات». */
    public const ADD = '➕ افزودن مدیر ربات';

    /** What an admin's decisions keep, and what they never decide (another admin does it for them). */
    public const OWN = 'کارهایش به نام خودش ثبت می‌شود، ولی درباره حساب خودش کاری نمی‌کند: کیف پول، تخفیف اختصاصی، گروه «همکار»، سفارش و رسید خودش را مدیر دیگری انجام می‌دهد.';

    /** What else the role has besides the management (`%s` the broadcast command): the panel's mark and question. */
    public const ALSO = 'دستور %s و دکمه‌های گروه گزارش‌ها را هم دارد و از شرط‌های ربات (خاموش بودن، شماره موبایل، کانال‌ها) معاف است.';

    /** The most customers one answer offers as buttons when it finds several. */
    private const FOUND_MOST = 10;

    /**
     * The most pages of the users list's search (the panel's 25 a page) read for the one typed exactly: the search finds
     * by a part, newest first, so a whole @username may be past its first page.
     */
    private const LOOKUP_PAGES = 4;

    /**
     * What a bot admin has now (plain text): in the main shop, the whole management with the owner's access — but the
     * panel login, the owner's linked Telegram accounts (their role and ban too), moving the Bot API address and the main
     * bot's webhook secret, the owner's alone —; in an agent's shop, everything the agent has in their bot.
     */
    public static function powers(bool $main): string
    {
        return $main
            ? 'مدیر ربات با «⚙️ مدیریت» در ربات، همه مدیریت فروشگاه را با دسترسی مالک دارد: همه بخش‌ها و تنظیمات، روش‌های پرداخت، سرورها، نماینده‌ها، تنظیمات پنل (توکن ربات و دیتابیس هم)، پشتیبان‌گیری و بازگردانی، به‌روزرسانی و فروشگاه هر نماینده؛ جز ورود به پنل، حساب‌های تلگرام مالک، آدرس Bot API و رمز Webhook که با خود مالک می‌ماند.'
            : 'مدیر این ربات با «⚙️ مدیریت» در همین ربات، به هر چه نماینده در ربات خودش دارد دسترسی دارد: همه بخش‌ها و تنظیمات فروشگاه او.';
    }

    /** The warning before someone is made an admin (plain text): whom to make one, and what they reach. */
    public static function warning(bool $main): string
    {
        return 'فقط کسی را مدیر کنید که کاملا به او اعتماد دارید: ' . ($main
            ? 'به پول‌ها، سرورها، توکن ربات و دیتابیس فروشگاه دسترسی پیدا می‌کند.'
            : 'به همه فروشگاه این ربات دسترسی پیدا می‌کند.');
    }

    /**
     * What the question before `$user` is made a bot admin says (HTML): who exactly — their name, number, @username and
     * Telegram id, so the one asked about is the one meant —, what they reach (powers()) and what else the role has
     * (ALSO), what their decisions keep (OWN) and, last, whom to make one (warning()). The list's «➕ افزودن مدیر ربات»
     * and the customer's page ask in these words.
     *
     * @param array<string, mixed> $user The users list's row
     */
    public static function handsOver(array $user, bool $main): string
    {
        $handle = Customer::text($user, 'username');
        $named = Customer::text($user, 'name') !== null;
        $who = array_filter([
            $named && $handle !== null ? Fmt::ltr('@' . $handle) : null,
            ($named || $handle !== null) && is_int($user['telegram_id'] ?? null) ? Fmt::code((string) $user['telegram_id']) : null,
        ]);

        return Fmt::e(Customer::name($user)) . ' ' . Fmt::id(Customer::int($user, 'id')) . ($who === [] ? '' : ' (' . implode(' · ', $who) . ')') . ' مدیر ربات می‌شود.' . "\n"
            . Fmt::e(self::powers($main)) . ' ' . sprintf(self::ALSO, Fmt::ltr('/broadcast')) . "\n"
            . Fmt::e(self::OWN) . "\n\n⚠️ " . Fmt::e(self::warning($main));
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return self::TITLE;
    }

    protected function path(): array
    {
        return [Words::ROOT, Fmt::bare(Words::SETTINGS)];
    }

    protected function parent(): string
    {
        return 'hc';
    }

    protected function endpoint(): string
    {
        return '/users';
    }

    protected function key(): string
    {
        return 'users';
    }

    /** Only the shop's admins, in the users list's own order. */
    protected function fixed(): array
    {
        return ['role' => 'admin'];
    }

    protected function noun(): string
    {
        return 'مدیر ربات';
    }

    protected function searchHint(): string
    {
        return 'نام، نام کاربری یا شناسه تلگرام مدیر.';
    }

    protected function empty(): string
    {
        $searched = (string) ($this->here?->state(self::ID)['search'] ?? '') !== '';

        return $searched ? 'مدیری با این جستجو پیدا نشد.' : 'هنوز مدیری ندارد؛ با «' . self::ADD . '» یکی را اضافه کنید.';
    }

    /** What an admin has now, said over the list. */
    protected function summary(array $meta): array
    {
        return [Fmt::e(self::powers($this->main())), '<i>' . Fmt::e(self::OWN) . '</i>'];
    }

    /** An admin as the users list has them, with when they were last seen. */
    protected function item(array $row): Button
    {
        return Button::to(Customer::label($row, 'last_seen'), CustomerScreen::ID, Nav::SHOW, (int) ($row['id'] ?? 0));
    }

    /** «➕ افزودن مدیر ربات» under the rows, and where else the role is given, folded away. */
    protected function extra(View $view, Admin $admin): void
    {
        $view->row(Button::to(self::ADD, self::ID, 'add')->primary())
            ->help('مدیر ربات را از صفحه هر کاربر در «' . Fmt::e(Icons::CUSTOMERS . ' کاربران') . '» هم می‌گذارید یا برمی‌دارید. مدیر ربات دستور ' . Fmt::ltr('/broadcast') . ' و دکمه‌های گروه گزارش‌ها را هم دارد و خاموش بودن ربات، شماره موبایل و کانال‌های اجباری جلویش را نمی‌گیرد.');
    }

    protected function action(Admin $admin, string $action, array $args): View
    {
        if ($action === 'add') {
            return $admin->ask($this->question());
        }
        $id = self::id($args);
        if ($action === 'set' && $id > 0) {
            $user = Customer::row($admin->api->put("/users/{$id}/role", ['role' => 'admin']));
            $admin->notice('✅ ' . Customer::name($user) . ' مدیر ربات شد');
        }

        return $this->show($admin, []);
    }

    /**
     * The customer typed at «➕ افزودن مدیر ربات», looked up: one who is no admin yet asked about, one already an admin
     * said so, several offered, none refused (the question again).
     *
     * @throws ApiRefusal
     */
    protected function typed(Admin $admin, string $action, array $args, Answer $answer): View
    {
        if ($action !== 'add') {
            return $this->show($admin, []);
        }
        $term = mb_substr(trim($answer->latin()), 0, 100);
        [$exact, $rows, $total] = $this->lookup($admin, $term);
        if ($rows === []) {
            throw ApiRefusal::typed('user', 'کاربری با «' . $term . '» در این فروشگاه پیدا نشد؛ شماره را با # (مثل #۱۲)، شناسه عددی تلگرام یا نام کاربری را با @ بنویسید.');
        }
        // What is exactly what was typed, else whatever the search found by a part of it.
        [$matches, $count] = $exact !== [] ? [$exact, count($exact)] : [$rows, $total];
        if ($count > 1 || count($matches) > 1) {
            return $this->several($term, $matches, max($count, count($matches)));
        }
        $user = $matches[0];
        if (($user['role'] ?? null) === 'admin') {
            $admin->notice('ℹ️ ' . Customer::name($user) . ' همین حالا مدیر ربات است.');

            return $this->show($admin, []);
        }

        return $admin->confirm(
            'این کاربر مدیر ربات شود؟',
            Button::to(Words::yes('مدیر ربات شود'), self::ID, 'set', Customer::int($user, 'id')),
            Button::to(Words::CANCEL, self::ID),
            self::handsOver($user, $this->main()),
            danger: true,
        );
    }

    /**
     * What `$term` finds among the shop's customers (GET /users' search): the rows exactly what was typed (exact()) —
     * looked for page after page, up to LOOKUP_PAGES, as the search finds by a part, newest first —, the first page's
     * rows and how many the search found.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: int}
     * @throws ApiRefusal
     */
    private function lookup(Admin $admin, string $term): array
    {
        $first = [];
        $total = 0;
        for ($page = 1; $page <= self::LOOKUP_PAGES; $page++) {
            $found = $admin->api->get('/users', ['search' => $term, 'page' => $page]);
            $rows = Json::rows($found, 'users');
            $meta = Json::part($found, 'meta');
            if ($page === 1) {
                [$first, $total] = [$rows, (int) ($meta['total'] ?? count($rows))];
            }
            $exact = self::exact($rows, $term);
            if ($exact !== [] || $page >= (int) ($meta['last_page'] ?? 1)) {
                return [$exact, $first, $total];
            }
        }

        return [[], $first, $total];
    }

    /** «➕ افزودن مدیر ربات»'s question. */
    private function question(): Prompt
    {
        return (new Prompt(
            'افزودن مدیر ربات',
            self::ID,
            'add',
            hint: 'شماره مشتری با # (مثل #۱۲)، شناسه عددی تلگرام یا نام کاربری با @ کسی که مدیر ربات می‌شود. باید پیش از این مشتری همین فروشگاه شده باشد.',
            cancel: Button::to(Words::CANCEL, self::ID),
        ))->example('@ali');
    }

    /**
     * The customers one answer found when it is not one alone: each a button to their page (where «🛡 مدیر ربات کردن»
     * is), FOUND_MOST of them — `$total` how many there are —, and the question again.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function several(string $term, array $rows, int $total): View
    {
        $view = View::make(Icons::SEARCH . ' کدام کاربر؟', ...[...$this->path(), Fmt::bare(self::TITLE)])
            ->line(Fmt::number($total) . ' کاربر با «' . Fmt::ltr($term) . '» پیدا شد. صفحه کسی را که می‌خواهید باز کنید و «' . Fmt::e(CustomerScreen::MAKE_ADMIN) . '» را بزنید.');
        if ($total > self::FOUND_MOST || count($rows) > self::FOUND_MOST) {
            // A whole @username typed is no help again: none of them has it exactly.
            $view->note(str_starts_with($term, '@')
                ? 'همه پیداشده‌ها این‌جا نیستند؛ شماره مشتری را با # یا شناسه عددی تلگرامش را بنویسید تا همان یک نفر پیدا شود.'
                : 'همه پیداشده‌ها این‌جا نیستند؛ دقیق‌تر بنویسید: شماره مشتری با #، شناسه عددی تلگرام یا نام کاربری کامل با @.');
        }
        $buttons = array_map(static fn(array $row): Button => Button::to(Customer::label($row, 'last_seen'), CustomerScreen::ID, Nav::SHOW, Customer::int($row, 'id')), array_slice($rows, 0, self::FOUND_MOST));

        return $view->grid(1, ...$buttons)
            ->row(Button::to(Icons::SEARCH . ' جستجوی دیگر', self::ID, 'add'))
            ->back(self::ID);
    }

    /**
     * The rows that are exactly what was typed — «#number» as a customer's number alone, a bare number as a customer's
     * number or Telegram id, a handle as the whole username —, of the ones the search found by a part of them.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function exact(array $rows, string $term): array
    {
        if (str_starts_with($term, '#')) {
            // The search's «#12» is row 12 alone; a «#» before anything else found what it found by a part.
            $digits = substr($term, 1);

            return ctype_digit($digits) ? array_values(array_filter($rows, static fn(array $row): bool => ($row['id'] ?? null) === (int) $digits)) : [];
        }
        $number = ctype_digit($term) ? (int) $term : null;
        $handle = mb_strtolower(ltrim($term, '@'));

        return array_values(array_filter($rows, static fn(array $row): bool => ($number !== null && (($row['id'] ?? null) === $number || ($row['telegram_id'] ?? null) === $number))
            || ($handle !== '' && is_string($row['username'] ?? null) && mb_strtolower($row['username']) === $handle)));
    }

    /** Whether the shop worked is the main one: its admins have the owner's seat, an agent's shop's the agent's. */
    private function main(): bool
    {
        return $this->here?->seat->shop()->isMain() ?? true;
    }
}
