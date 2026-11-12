<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Auth\Actor;
use App\Modules\Bots\Services\Bots;
use App\Modules\Orders\Models\Order;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Reports\Customers\CardPress;
use App\Modules\Telegram\Reports\Customers\CustomerAccount;
use App\Modules\Telegram\Reports\Customers\CustomerCards;
use App\Modules\Telegram\Reports\Customers\CustomerDiscount;
use App\Modules\Telegram\Reports\Customers\CustomerHistory;
use App\Modules\Telegram\Reports\Customers\CustomerOrder;
use App\Modules\Telegram\Reports\Customers\CustomerServices;
use App\Modules\Telegram\Reports\Customers\CustomerWallet;
use App\Modules\Telegram\Texts\TelegramHtml;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Telegram\Update\GroupHandler;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\CustomerGroup;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\CustomerGroups;
use App\Modules\Users\Services\UserActions;
use App\Support\Persian;

/**
 * A customer's card in the report group, for its bot admins to manage them right there — its front: «⚙️ مدیریت کاربر»
 * under a newcomer's report posts one under it, and `/manage <who>` anywhere in the group — a Telegram id, an @username
 * or the customer's number in the shop («#12») — answers with one.
 *
 * The front says who they are: their phone, since when and when last seen, their wallet, services and paid orders,
 * their own discount, role, standing, groups and referrer. Its buttons open the card's sections, each rewriting the
 * card in place with «⬅️ بازگشت» back here — «🛍 سرویس‌ها» (CustomerServices), «👛 کیف پول» (CustomerWallet), «➕ سرویس
 * دستی» (CustomerOrder), «🏷 تخفیف» (CustomerDiscount), «🧾 سوابق» (CustomerHistory), «🔐 ورود به سایت»
 * (CustomerAccount) — and do the rest here: «👥 گروه‌ها» (a switch per group, naming the state it asks for: pressed
 * twice, or by two admins at once, it stands), «🎖 مدیر ربات شود» / «🎖 برداشتن نقش مدیر» and «⛔️ مسدود شود» / «✅ رفع
 * مسدودی» (each asked again first), «🔄 تازه‌سازی».
 *
 * What every section shares — the checks, the look, the questions — is CustomerCards'. Decisions go through UserActions
 * under the presser's name (Actor::groupAdmin()), by its rules for the report group: never the admin's own account nor
 * an agent's (the card offers neither: only what reads), never an admin banned nor put in a «همکار» group, never an
 * admin made of someone with their own discount or in a «همکار» group (the panel's). Only in the shop's connected report
 * group, and only for its bot admins; anyone else is told who may.
 */
final class UserManagement implements GroupHandler
{
    /** What the buttons' callback data starts with: `um:<action>:<customer id>[:<value>]`. */
    public const PREFIX = 'um:';

    /** The command that opens a customer's card: `/manage <who>`. */
    public const COMMAND = 'manage';

    /** The front again, as the account now stands: «🔄 تازه‌سازی» and every section's «⬅️ بازگشت». */
    public const OPEN = 'o';

    /** A new card, under the report the button is under. */
    private const NEW = 'n';
    /** The role's change asked; then made, to the role its button names (a stale card never flips it the other way). */
    private const ROLE = 'r';
    private const ROLE_SET = 'rs';
    /** The ban or its lifting asked; then made, to the standing its button names. */
    private const BAN = 'b';
    private const BAN_SET = 'bs';
    /** The groups' switches; one group switched for the customer, to the state its button names: `:<group>:<1 in|0 out>`. */
    private const GROUPS = 'g';
    private const GROUP_SWITCH = 'gs';

    /** The most groups the card offers a switch for: a keyboard holds 100 buttons. */
    private const GROUPS_MOST = 40;

    /**
     * The most of the front's «👥 گروه‌ها» line, as Telegram counts it: the groups that fit, then how many more — so the
     * front, with a question under it, stays within a message however many groups the customer is in.
     */
    private const GROUPS_ROOM = 300;

    private const USAGE = "برای مدیریت یک کاربر بنویسید /manage و بعد شناسه تلگرام، نام کاربری یا شماره او در فروشگاه.\nمثلا: /manage 123456789 یا /manage @ali یا /manage #12";
    private const NOT_FOUND = 'کاربری با «%s» در این فروشگاه پیدا نشد.';
    private const NO_GROUPS = 'هنوز گروهی ساخته نشده است؛ در پنل، صفحه «کاربران»، بخش «گروه‌ها» یکی بسازید.';
    private const GROUP_GONE = 'این گروه دیگر وجود ندارد.';

    private const MADE_ADMIN = '🎖 مدیر ربات شد.';
    private const MADE_CUSTOMER = '🎖 نقش مدیر برداشته شد.';
    private const BANNED = '⛔️ مسدود شد.';
    private const UNBANNED = '✅ مسدودی برداشته شد.';
    private const JOINED = '✅ به «%s» اضافه شد.';
    private const LEFT = '▫️ از «%s» برداشته شد.';

    /** A name the words show. */
    private const NAME_MAX = 64;

    public function __construct(
        private readonly GroupButtons $groupButtons,
        private readonly CustomerCards $cards,
        private readonly ReportGroupState $state,
        private readonly UserActions $actions,
        private readonly CustomerGroups $groups,
        private readonly Bots $bots,
    ) {}

    /**
     * The one button under a newcomer's report.
     *
     * @return list<list<array<string, mixed>>>
     */
    public static function buttons(int $userId): array
    {
        return InlineKeyboard::make()->row(InlineKeyboard::callback('⚙️ مدیریت کاربر', CallbackData::build(self::PREFIX, self::NEW, $userId)))->build()['inline_keyboard'];
    }

    public function handle(Update $update): void
    {
        if ($update->isCallback()) {
            $this->press($update);
        } else {
            $this->command($update);
        }
    }

    /**
     * The front's words: who, their phone, since when and when last seen, their wallet, services and paid orders, their
     * discount, role, standing, groups and referrer — and why it is locked.
     */
    public static function card(User $user, User $admin): string
    {
        $paid = Order::query()->where('user_id', $user->id)->whereIn('status', Order::KEPT)->count();
        $standing = ($user->isBanned() ? '⛔️ مسدود' : 'فعال') . ($user->bot_blocked ? ' · ربات را بلاک کرده' : '');
        $lines = [
            CustomerCards::header($user),
            '📱 موبایل: ' . ($user->phone === null ? 'ثبت نشده' : "\u{200E}" . htmlspecialchars($user->phone)),
            '📅 عضویت: ' . Persian::date($user->created_at) . ' · آخرین بازدید: ' . ($user->last_seen_at === null ? '—' : Persian::date($user->last_seen_at)),
            '👛 موجودی: ' . Messages::balance($user->balance()),
            sprintf(
                '🛍 سرویس‌ها: %s فعال از %s · سفارش پرداخت‌شده: %s',
                Persian::number(Subscription::active()->where('user_id', $user->id)->count()),
                Persian::number(Subscription::query()->where('user_id', $user->id)->count()),
                Persian::number($paid),
            ),
            '🏷 تخفیف اختصاصی: ' . ($user->discount_percent > 0 ? Persian::digits($user->discount_percent) . '٪' : 'ندارد'),
            '🎖 نقش: ' . ($user->isAdmin() ? 'مدیر ربات' : 'مشتری') . ($user->isAgent() ? ' · نماینده' : ''),
            '🚦 وضعیت: ' . $standing,
            '👥 گروه‌ها: ' . self::groups($user),
        ];
        if ($user->referrer !== null) {
            $lines[] = '🤝 معرف: ' . ShopReports::customer($user->referrer);
        }
        $locked = CustomerCards::lockOf($user, $admin);

        return implode("\n", $locked === null ? $lines : [...$lines, '', $locked]);
    }

    /**
     * The front's buttons as the account stands — a Persian reader's right first in each row: the sections, the groups,
     * the role and the ban (none for an admin: their role goes first), «🔄 تازه‌سازی». Their own account or an agent's
     * gets only what reads (CustomerCards::lockOf()).
     *
     * @return list<list<array<string, mixed>>>
     */
    public static function main(User $user, User $admin): array
    {
        $id = $user->id;
        $open = static fn(string $label, string $prefix): array => CustomerCards::button($label, CustomerCards::data($prefix, 'o', $id));
        $refresh = CustomerCards::button('🔄 تازه‌سازی', CustomerCards::data(self::PREFIX, self::OPEN, $id));
        if (CustomerCards::lockOf($user, $admin) !== null) {
            return InlineKeyboard::make()
                ->grid([$open('🛍 سرویس‌ها', CustomerServices::PREFIX), $open('👛 کیف پول', CustomerWallet::PREFIX), $open('🧾 سوابق', CustomerHistory::PREFIX), $open('🔐 ورود به سایت', CustomerAccount::PREFIX)], 2)
                ->row($refresh)
                ->build()['inline_keyboard'];
        }

        $role = $user->isAdmin()
            ? CustomerCards::button('🎖 برداشتن نقش مدیر', CustomerCards::data(self::PREFIX, self::ROLE, $id), 'danger')
            : CustomerCards::button('🎖 مدیر ربات شود', CustomerCards::data(self::PREFIX, self::ROLE, $id));
        $ban = match (true) {
            $user->isBanned() => [CustomerCards::button('✅ رفع مسدودی', CustomerCards::data(self::PREFIX, self::BAN, $id), 'success')],
            $user->isAdmin() => [],
            default => [CustomerCards::button('⛔️ مسدود شود', CustomerCards::data(self::PREFIX, self::BAN, $id), 'danger')],
        };

        return InlineKeyboard::make()
            ->grid([
                $open('🛍 سرویس‌ها', CustomerServices::PREFIX),
                $open('👛 کیف پول', CustomerWallet::PREFIX),
                $open('➕ سرویس دستی', CustomerOrder::PREFIX),
                $open('🏷 تخفیف', CustomerDiscount::PREFIX),
                CustomerCards::button('👥 گروه‌ها', CustomerCards::data(self::PREFIX, self::GROUPS, $id)),
                $open('🧾 سوابق', CustomerHistory::PREFIX),
                $role,
                $open('🔐 ورود به سایت', CustomerAccount::PREFIX),
            ], 2)
            ->row($refresh, ...$ban)
            ->build()['inline_keyboard'];
    }

    /** `/manage <who>`: the card, under the command; a word when nobody answers to `<who>`, or nothing was named. */
    private function command(Update $update): void
    {
        $message = $update->message();
        $chatId = (int) $update->chatId();
        if ($message === null || !$this->addressedToUs($update) || $chatId !== $this->state->chatId()) {
            return;
        }
        $admin = $this->groupButtons->writer($chatId, $message, GroupButtons::onlyAdmins(CustomerCards::MAY));
        if ($admin === null) {
            return;
        }

        $who = trim((string) $update->commandArgument());
        if ($who === '') {
            $this->groupButtons->sayUnder($chatId, $message, self::USAGE);

            return;
        }
        $user = self::find($who);
        if ($user === null) {
            $this->groupButtons->sayUnder($chatId, $message, sprintf(self::NOT_FOUND, mb_substr($who, 0, self::NAME_MAX)));

            return;
        }

        $this->post($chatId, $message, $user, $admin);
    }

    /** A press on one of the front's buttons. */
    private function press(Update $update): void
    {
        $press = $this->cards->press($update, self::PREFIX);
        if ($press === null) {
            return;
        }
        $shaped = match ($press->action) {
            self::NEW, self::OPEN, self::ROLE, self::BAN, self::GROUPS => $press->args === [],
            self::ROLE_SET, self::BAN_SET => count($press->args) === 1,
            self::GROUP_SWITCH => count($press->args) === 2 && $press->int(0) !== null && in_array($press->args[1], ['0', '1'], true),
            default => false,
        };
        if (!$shaped) {
            $this->cards->answer($press);

            return;
        }

        if ($press->action === self::NEW) {
            $this->post((int) $update->chatId(), (array) $update->message(), $press->user, $press->admin);
            $this->cards->answer($press);

            return;
        }

        try {
            match ($press->action) {
                self::ROLE => $this->ask($press, self::ROLE_SET, $press->user->isAdmin() ? UserRole::Customer->value : UserRole::Admin->value),
                self::BAN => $this->ask($press, self::BAN_SET, $press->user->isBanned() ? UserStatus::Active->value : UserStatus::Banned->value),
                self::ROLE_SET => $this->setRole($press, $press->args[0]),
                self::BAN_SET => $this->setStatus($press, $press->args[0]),
                self::GROUPS => $this->showGroups($press),
                self::GROUP_SWITCH => $this->switchGroup($press, (int) $press->int(0), $press->args[1] === '1'),
                default => $this->show($press),
            };
        } catch (DomainRuleException $e) {
            // A press the card did not offer (a forged one, or a card older than the account): refused as UserActions
            // refuses it, the card as the account now stands.
            $this->show($press, CustomerCards::words($e), alert: true);
        }
    }

    /**
     * A new card as a reply to `$message`, in its topic.
     *
     * @param array<string, mixed> $message
     */
    private function post(int $chatId, array $message, User $user, User $admin): void
    {
        $this->groupButtons->say($chatId, (int) ($message['message_id'] ?? 0), GroupButtons::threadOf($message), self::card($user, $admin), ['inline_keyboard' => self::main($user, $admin)], html: true);
    }

    /** The front rewritten as the account now stands, with its buttons; the press answered with `$word`. */
    private function show(CardPress $press, ?string $word = null, bool $alert = false): void
    {
        $press->user->refresh();
        $this->cards->show($press, self::card($press->user, $press->admin), self::main($press->user, $press->admin), $word, $alert);
    }

    /** A change asked again: the front with its question, «✔️ بله» (making it, to `$value`) and «⬅️ انصراف». */
    private function ask(CardPress $press, string $set, string $value): void
    {
        CustomerCards::mayChange($press->user, $press->admin);
        [$question, $style] = match ($value) {
            UserRole::Admin->value => ['❓ <b>مدیر ربات شود؟</b> مدیرها دکمه‌های این گروه را دارند — رسیدها، تیکت‌ها، نمایندگی و همین کارت — و دستورهای مدیریتی ربات را.', 'success'],
            UserRole::Customer->value => ['❓ <b>نقش مدیر از او برداشته شود؟</b>', 'danger'],
            UserStatus::Banned->value => ['❓ <b>مسدود شود؟</b> ربات و وب‌سایت دیگر جوابش را نمی‌دهند.', 'danger'],
            default => ['❓ <b>مسدودی‌اش برداشته شود؟</b>', 'success'],
        };
        $user = $press->user;
        $rows = InlineKeyboard::make()->row(
            CustomerCards::button('⬅️ انصراف', CustomerCards::data(self::PREFIX, self::OPEN, $user->id)),
            CustomerCards::button('✔️ بله', CustomerCards::data(self::PREFIX, $set, $user->id, $value), $style),
        )->build()['inline_keyboard'];

        $this->cards->show($press, self::card($user, $press->admin) . "\n\n" . $question, $rows);
    }

    /** @throws DomainRuleException */
    private function setRole(CardPress $press, string $role): void
    {
        $this->actions->setRole($press->user, Actor::groupAdmin($press->admin), ['role' => $role]);
        $this->show($press, $press->user->isAdmin() ? self::MADE_ADMIN : self::MADE_CUSTOMER);
    }

    /** @throws DomainRuleException */
    private function setStatus(CardPress $press, string $status): void
    {
        $this->actions->setStatus($press->user, Actor::groupAdmin($press->admin), ['status' => $status]);
        $this->show($press, $press->user->isBanned() ? self::BANNED : self::UNBANNED);
    }

    /**
     * «👥 گروه‌ها»: the front with a switch per group — ✅ the customer is in it —, each naming the state it asks for (out
     * for a group they are in, in for one they are not), and «⬅️ بازگشت».
     *
     * @throws DomainRuleException
     */
    private function showGroups(CardPress $press, ?string $word = null): void
    {
        $user = $press->user;
        CustomerCards::mayChange($user, $press->admin);
        $groups = $this->groups->ordered()->take(self::GROUPS_MOST);
        if ($groups->isEmpty()) {
            $this->cards->answer($press, self::NO_GROUPS, alert: true);

            return;
        }

        $in = $user->groups()->get()->modelKeys();
        $switches = $groups->map(static fn(CustomerGroup $group): array => CustomerCards::button(
            // A button's label is plain text: the name as it is.
            (in_array($group->id, $in, true) ? '✅ ' : '▫️ ') . mb_substr(trim($group->name), 0, self::NAME_MAX) . ($group->agent_pricing ? ' · همکار' : ''),
            CustomerCards::data(self::PREFIX, self::GROUP_SWITCH, $user->id, $group->id, in_array($group->id, $in, true) ? 0 : 1),
        ))->values()->all();
        $rows = InlineKeyboard::make()->grid($switches, 2)->row(CustomerCards::back($user->id))->build()['inline_keyboard'];

        $this->cards->show($press, self::card($user, $press->admin), $rows, $word);
    }

    /**
     * The customer put in the group (`$in`) or taken out of it — the state the button names, never the other way from
     * what the card showed —, their other groups as they are; the switches stay, for the next.
     *
     * @throws DomainRuleException
     */
    private function switchGroup(CardPress $press, int $groupId, bool $in): void
    {
        $group = CustomerGroup::query()->find($groupId);
        if ($group === null) {
            $this->showGroups($press, self::GROUP_GONE);

            return;
        }

        $this->actions->switchGroup($press->user, Actor::groupAdmin($press->admin), $group->id, $in);
        $this->showGroups($press, sprintf($in ? self::JOINED : self::LEFT, mb_substr($group->name, 0, self::NAME_MAX)));
    }

    /** The front's groups, in the admin's order: as many as fit GROUPS_ROOM, then «و ۱۲ گروه دیگر»; «ندارد» for none. */
    private static function groups(User $user): string
    {
        $groups = $user->groups()->orderBy('sort')->orderBy('id')->get();
        if ($groups->isEmpty()) {
            return 'ندارد';
        }

        $more = static fn(int $left): string => ' و ' . Persian::number($left) . ' گروه دیگر';
        $room = self::GROUPS_ROOM - TelegramHtml::visibleLength($more($groups->count()));
        $names = [];
        foreach ($groups as $group) {
            $name = self::name($group->name);
            $room -= TelegramHtml::visibleLength($name) + ($names === [] ? 0 : 2);
            if ($room < 0) {
                break;
            }
            $names[] = $name;
        }
        $left = $groups->count() - count($names);

        return implode('، ', $names) . ($left > 0 ? $more($left) : '');
    }

    /**
     * The shop's customer `$who` names: «#12» their number in the shop, digits their Telegram id (else their number), a
     * username with or without its «@» — whatever digits it was typed in.
     */
    private static function find(string $who): ?User
    {
        $who = Persian::latinDigits($who);
        if (preg_match('/^#(\d{1,18})$/', $who, $match) === 1) {
            return User::query()->find((int) $match[1]);
        }
        if (preg_match('/^\d{1,18}$/', $who) === 1) {
            return User::query()->where('telegram_id', (int) $who)->first() ?? User::query()->find((int) $who);
        }
        if (preg_match('/^@?([A-Za-z0-9_]{3,32})$/', $who, $match) === 1) {
            return User::query()->whereRaw('LOWER(username) = ?', [strtolower($match[1])])->first();
        }

        return null;
    }

    /** `/manage` with no "@bot", or "@" this bot: in a group with several bots, another's command is not ours. */
    private function addressedToUs(Update $update): bool
    {
        $to = $update->commandTarget();
        $bot = $this->bots->username();

        return $to === null || $bot === '' || strcasecmp($to, $bot) === 0;
    }

    private static function name(string $name): string
    {
        return htmlspecialchars(mb_substr(trim($name), 0, self::NAME_MAX));
    }
}
