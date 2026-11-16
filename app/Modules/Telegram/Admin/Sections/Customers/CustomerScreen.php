<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Auth\StaffGrant;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Support\InviteesScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Modules\Telegram\Messages;

/**
 * One customer (the panel's customer page, `/users/{id}`): a summary card of who they are and what they hold — state,
 * role, handle, Telegram id (to copy), wallet, services, orders, groups, own discount —, then their account (number,
 * phone and its rule, email, since when, last seen), and, where there is any, the referral program's facts, their loyalty
 * tier and an agent's agency. Its buttons: the quick actions first — a message, the wallet, an order by hand, their
 * services —, then their other lists (orders, payments, tickets, groups), the website sign-in, the own discount and the
 * phone rule, the links on (their referrer, invitees, the loyalty tiers, an agent's agency, their Telegram chat), and last
 * the admin's changes: the bot admin role (asked first) and the ban (asked first, red; lifted at once). The own discount
 * and the role are the owner's and an agent's alone, as on the panels; one of the shop's admins only looks at a bot
 * admin's or an agent's account (Customer::lock()).
 *
 * Opened with the customer's id (none: the one last opened). Actions: `ph` (id, 1|0: excused or not), `disc` (id: asks
 * the percent), `disc0` (id: none), `role` (id, role: asks), `rs` (id, role: given), `ban` (id: asks), `bs` (id,
 * status: banned or active).
 */
final class CustomerScreen extends BaseScreen
{
    public const ID = 'usv';

    /** What a bot admin may do (the panel's mark and question), and what the bot adds to it. */
    private const BOT_ADMIN = 'مدیر ربات دستور %s را دارد، رسیدها را در گروه گزارش‌ها تایید و رد می‌کند و از شرط‌های ربات (خاموش بودن، شماره موبایل، کانال‌ها) معاف است. تا «مدیران ربات» روشن است، با «⚙️ مدیریت» کارهای روزانه فروشگاه را هم از همین ربات انجام می‌دهد.';

    /** What taking the role away does (the panel's words). */
    private const NO_LONGER_ADMIN = 'از این پس مثل بقیه مشتری‌هاست: دستورهای مدیر، دکمه‌های گروه گزارش‌ها و مدیریت فروشگاه از ربات برایش کار نمی‌کند.';

    private const BAN_WORDS = 'ربات به هر پیامش فقط می‌گوید حسابش مسدود شده و کاری برایش انجام نمی‌دهد. سرویس‌های فعلی‌اش دست نمی‌خورد و هر وقت بخواهید رفع مسدودی می‌کنید.';

    /** The phone rule's excuse, as the card says it. */
    private const EXEMPT = 'معاف از ارسال شماره موبایل';

    public function show(Admin $admin, array $args): View
    {
        $id = Customer::current($admin, $args);
        if ($id === 0) {
            return $admin->open(UsersScreen::ID);
        }

        return $this->view($admin, Customer::read($admin, $id));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        if ($id === 0) {
            return $this->show($admin, []);
        }
        switch ($action) {
            case 'ph':
                $user = Customer::row($admin->api->put("/users/{$id}/phone-exempt", ['phone_exempt' => ($args[1] ?? '') === '1']));
                $admin->notice(Json::on($user, 'phone_exempt') ? '✅ ' . Customer::name($user) . ' از ارسال شماره موبایل معاف شد' : '✅ ' . Customer::name($user) . ' دیگر از ارسال شماره موبایل معاف نیست');
                break;
            case 'disc':
                return $this->askDiscount($admin, Customer::row(Customer::read($admin, $id)));
            case 'disc0':
                return $this->discounted($admin, $id, '0');
            case 'role':
                return $this->askRole($admin, Customer::row(Customer::read($admin, $id)), ($args[1] ?? '') === 'admin' ? 'admin' : 'customer');
            case 'rs':
                $user = Customer::row($admin->api->put("/users/{$id}/role", ['role' => ($args[1] ?? '') === 'admin' ? 'admin' : 'customer']));
                $admin->notice(($user['role'] ?? null) === 'admin' ? '✅ ' . Customer::name($user) . ' مدیر ربات شد' : '✅ ' . Customer::name($user) . ' دیگر مدیر ربات نیست');
                break;
            case 'ban':
                $user = Customer::row(Customer::read($admin, $id));
                $self = $id === $admin->seat->user->id ? "\n\n⚠️ این حساب خود شماست: مسدود که شود، از ربات مدیریت نمی‌کنید تا از پنل رفع مسدودی شود." : '';

                return $admin->confirm(
                    'این کاربر مسدود شود؟',
                    Button::to(Words::yes('مسدود شود'), self::ID, 'bs', $id, 'banned'),
                    Button::to(Words::CANCEL, self::ID, Nav::SHOW, $id),
                    Fmt::e(Customer::name($user) . ' مسدود می‌شود. ' . self::BAN_WORDS . $self),
                    danger: true,
                );
            case 'bs':
                $user = Customer::row($admin->api->patch("/users/{$id}", ['status' => ($args[1] ?? '') === 'banned' ? 'banned' : 'active']));
                $admin->notice(($user['status'] ?? null) === 'banned' ? Customer::BANNED_MARK . ' ' . Customer::name($user) . ' مسدود شد' : '✅ ' . Customer::name($user) . ' دوباره فعال شد');
                break;
        }

        return $this->show($admin, [(string) $id]);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $id = self::id($args);
        if ($action === 'disc' && $id > 0) {
            return $this->discounted($admin, $id, $answer->latin());
        }

        return $this->show($admin, $args);
    }

    /**
     * The page as the panel answered it: a warning, the summary card, the account, then the referral program, loyalty and
     * agency where there is any, why it is locked — and the buttons the seat may use on it, grouped.
     *
     * @param array<string, mixed> $detail
     */
    private function view(Admin $admin, array $detail): View
    {
        $user = Customer::row($detail);
        $id = Customer::int($user, 'id');
        $agency = is_array($detail['agency'] ?? null) ? $detail['agency'] : null;
        $lock = Customer::lock($admin, $detail);
        $isAdmin = ($user['role'] ?? null) === 'admin';
        $banned = ($user['status'] ?? null) === 'banned';

        $view = View::make(Icons::CUSTOMER . ' ' . Customer::name($user), ...Customer::under());
        $warning = $this->phoneWarning($admin, $user);
        if ($warning !== null) {
            $view->line('⚠️ ' . $warning);
        }
        $this->summary($view, $user, $agency);
        $this->account($view, $user);
        $this->standing($view, $detail);
        if ($lock !== null) {
            $view->note(Fmt::e($lock));
        }

        // The quick actions: a message, the wallet, an order by hand, their services.
        $view->row(
            $lock === null ? Button::to(CustomerMessageScreen::TITLE, CustomerMessageScreen::ID, Nav::SHOW, $id)->primary() : null,
            Button::to(CustomerWalletScreen::TITLE, CustomerWalletScreen::ID, Nav::SHOW, $id),
        );
        $view->row(
            $admin->seat->holds(StaffGrant::ManualOrders) && !Customer::own($admin, $id) ? Button::to(Words::add('سفارش'), ManualOrderScreen::ID, 'new', $id) : null,
            Button::to(CustomerServicesScreen::TITLE, CustomerServicesScreen::ID, Nav::SHOW, $id),
        );
        // Their other lists (how many the card says).
        $view->grid(
            2,
            Button::to(CustomerOrdersScreen::TITLE, CustomerOrdersScreen::ID, Nav::SHOW, $id),
            Button::to(CustomerPaymentsScreen::TITLE, CustomerPaymentsScreen::ID, Nav::SHOW, $id),
            Button::to(CustomerTicketsScreen::TITLE, CustomerTicketsScreen::ID, Nav::SHOW, $id),
            Button::to(CustomerGroupsScreen::TITLE, CustomerGroupsScreen::ID, Nav::SHOW, $id),
        );
        // Their account's settings.
        $view->row(
            Button::to(CustomerAccountScreen::TITLE, CustomerAccountScreen::ID, Nav::SHOW, $id),
            $admin->seat->configures() ? Button::to(Words::edit('تخفیف اختصاصی'), self::ID, 'disc', $id) : null,
        );
        if ($lock === null && !$isAdmin) {
            $exempt = Json::on($user, 'phone_exempt');
            $view->row(Button::to(Words::turn(!$exempt, 'معافیت از شماره موبایل'), self::ID, 'ph', $id, $exempt ? 0 : 1));
        }
        // The ways on.
        $referral = Json::part($detail, 'referral');
        $referrer = is_array($referral['referrer'] ?? null) ? $referral['referrer'] : null;
        $loyalty = Json::part($detail, 'loyalty');
        $handle = Customer::text($user, 'username');
        $view->tiles(
            // Who it is, the referral card says: the button says where it goes.
            $referrer === null ? null : Button::to(Icons::CUSTOMER . ' صفحه معرف', self::ID, Nav::SHOW, Customer::int($referrer, 'id')),
            // Their invitees, as the panel's card links them: the referrals' invitees narrowed to this referrer.
            Customer::int($referral, 'referrals') > 0 ? Button::to(Icons::REFERRALS . ' زیرمجموعه‌ها', InviteesScreen::ID, 'ref', $id) : null,
            // The tier's name links to the loyalty tiers, as on the panel's card: theirs who set the shop up.
            $admin->seat->configures() && (is_array($loyalty['tier'] ?? null) || is_array($loyalty['next'] ?? null)) ? Button::to(Icons::LOYALTY . ' سطوح وفاداری', 'ly') : null,
            $agency !== null && $admin->seat->isOwner() ? Button::to(Icons::AGENTS . ' مدیریت نماینده', 'agv', Nav::SHOW, $id) : null,
            $handle !== null ? Button::url('💬 گفتگو در تلگرام', 'https://t.me/' . rawurlencode($handle)) : null,
        );
        // The admin's changes, what takes away last and red.
        $view->row(
            $admin->seat->configures() && !$isAdmin ? Button::to(Icons::BOT_ADMINS . ' مدیر ربات کردن', self::ID, 'role', $id, 'admin') : null,
            $lock === null && $banned ? Button::to('✅ رفع مسدودی', self::ID, 'bs', $id, 'active')->success() : null,
        );
        $view->row(
            $admin->seat->configures() && $isAdmin ? Button::to(Icons::BOT_ADMINS . ' برداشتن مدیریت ربات', self::ID, 'role', $id, 'customer')->danger() : null,
            $lock === null && !$banned ? Button::to(Customer::BANNED_MARK . ' مسدود کردن', self::ID, 'ban', $id)->danger() : null,
        );

        return $view->back(UsersScreen::ID)->refresh(self::ID, Nav::SHOW, $id);
    }

    /**
     * The summary card: state, role, handle, Telegram id (to copy; a link to their chat when they have no handle), wallet
     * (an agent's credit beside it), services, orders, groups and own discount.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed>|null $agency
     */
    private function summary(View $view, array $user, ?array $agency): void
    {
        $counts = Json::part($user, 'counts');
        $telegram = is_int($user['telegram_id'] ?? null) ? $user['telegram_id'] : null;
        $handle = Customer::text($user, 'username');
        $credit = $agency === null ? '0' : Customer::money($agency['credit_limit'] ?? null);
        $percent = Customer::int($user, 'discount_percent');
        $groups = array_filter(array_map(static fn(array $group): ?string => Customer::text($group, 'name'), Json::rows($user, 'groups')));
        $roles = array_filter([($user['role'] ?? null) === 'admin' ? Customer::BOT_ADMIN : null, $agency !== null ? Customer::AGENT : null]);
        $services = Customer::int($counts, 'subscriptions');

        $view->card([
            'وضعیت' => ($user['status'] ?? null) === 'banned' ? Customer::BANNED : Words::ACTIVE,
            'نقش' => $roles === [] ? 'مشتری' : Fmt::e(implode(' · ', $roles)),
            'نام کاربری' => $handle === null ? '' : Fmt::ltr('@' . $handle),
            'شناسه تلگرام' => $telegram === null ? 'بدون تلگرام' : Fmt::code((string) $telegram) . ($handle === null ? ' · <a href="tg://user?id=' . $telegram . '">گفتگو</a>' : ''),
            'کیف پول' => Fmt::e(Messages::balance(Customer::money($user['balance'] ?? null))) . ((float) $credit > 0 ? ' · اعتبار نمایندگی ' . Fmt::money($credit) : ''),
            'سرویس‌ها' => $services > 0 ? Fmt::number(Customer::int($counts, 'active_subscriptions')) . ' فعال از ' . Fmt::number($services) : Fmt::number(0),
            'سفارش‌ها' => Fmt::number(Customer::int($counts, 'orders')),
            'گروه‌ها' => Fmt::e(implode('، ', $groups)),
            'تخفیف اختصاصی' => $percent > 0 ? Fmt::percent($percent) : '',
        ]);
    }

    /**
     * Their account: number (to copy: a search finds it), phone and the phone rule, email, since when and when last seen.
     *
     * @param array<string, mixed> $user
     */
    private function account(View $view, array $user): void
    {
        $phone = Customer::text($user, 'phone');
        $rule = match (true) {
            ($user['role'] ?? null) === 'admin' => ' · مدیر ربات است و برای شماره نگه داشته نمی‌شود',
            Json::on($user, 'phone_exempt') => ' · ' . self::EXEMPT,
            default => '',
        };
        $email = Customer::text($user, 'email');
        $seen = Customer::text($user, 'last_seen_at');
        $view->section('📇 حساب')->card([
            'شماره مشتری' => Fmt::code('#' . Customer::int($user, 'id')),
            'موبایل' => ($phone === null ? 'تایید نشده' : Fmt::ltr($phone)) . $rule,
            'ایمیل' => $email === null ? null : Fmt::ltr($email),
            'عضویت' => Fmt::date(Customer::text($user, 'created_at')),
            'آخرین بازدید' => $seen === null ? 'هنوز نه' : Fmt::date($seen, true),
        ]);
    }

    /**
     * What the referral program knows of them (when it knows anything), their loyalty tier (while the shop has tiers) and
     * an agent's agency, each a section of its own.
     *
     * @param array<string, mixed> $detail
     */
    private function standing(View $view, array $detail): void
    {
        $referral = Json::part($detail, 'referral');
        $referrer = is_array($referral['referrer'] ?? null) ? $referral['referrer'] : null;
        $invited = Customer::int($referral, 'referrals');
        $earned = Customer::money($referral['earned'] ?? null);
        if ($referrer !== null || $invited > 0 || (float) $earned > 0) {
            $view->section(Icons::REFERRALS . ' زیرمجموعه‌گیری')->card([
                'معرف' => $referrer === null ? '' : Fmt::e(Customer::name($referrer)) . ' ' . Fmt::id(Customer::int($referrer, 'id')),
                'زیرمجموعه‌ها' => Fmt::number($invited) . ' نفر',
                'پورسانت' => (float) $earned > 0 ? Fmt::money($earned) : null,
            ]);
        }

        $loyalty = Json::part($detail, 'loyalty');
        $tier = is_array($loyalty['tier'] ?? null) ? $loyalty['tier'] : null;
        $next = is_array($loyalty['next'] ?? null) ? $loyalty['next'] : null;
        if ($tier !== null || $next !== null) {
            $perks = $tier === null ? [] : array_filter([
                Customer::int($tier, 'discount_percent') > 0 ? Fmt::percent(Customer::int($tier, 'discount_percent')) . ' تخفیف' : null,
                Customer::int($tier, 'cashback_percent') > 0 ? Fmt::percent(Customer::int($tier, 'cashback_percent')) . ' کش‌بک اضافه' : null,
            ]);
            $view->section(Icons::LOYALTY . ' وفاداری')->card([
                'سطح' => $tier === null ? 'هنوز به اولین سطح نرسیده' : Fmt::e(Customer::text($tier, 'name')) . ($perks === [] ? '' : ' · ' . implode('، ', $perks)),
                'جمع خریدها' => Fmt::money(Customer::money($loyalty['spent'] ?? null)),
                'سطح بعدی' => $next === null ? 'بالاترین سطح' : Fmt::e(Customer::text($next, 'name')) . ' · ' . Fmt::money(Customer::money($next['missing'] ?? null)) . ' مانده',
            ]);
        }

        $agency = is_array($detail['agency'] ?? null) ? $detail['agency'] : null;
        if ($agency !== null) {
            $level = Json::part($agency, 'level');
            $bot = is_array($agency['bot'] ?? null) ? $agency['bot'] : null;
            $counts = Json::part($agency, 'counts');
            $view->section(Icons::AGENTS . ' نمایندگی')->card([
                'سطح' => Fmt::e(Customer::text($level, 'name')) . ' · هر گیگابایت ' . Fmt::money(Customer::money($level['price_per_gb'] ?? null)),
                'ربات' => $bot === null ? '' : (Customer::text($bot, 'username') !== null ? Fmt::ltr('@' . Customer::text($bot, 'username')) : Fmt::e(Customer::text($bot, 'title'))),
                'حجم ربات' => $bot === null ? null : Fmt::bytes(Customer::int($bot, 'traffic_balance')),
                'فروش ربات' => Fmt::number(Customer::int($counts, 'sold')) . ' سرویس · ' . Fmt::number(Customer::int($counts, 'active')) . ' فعال · ' . Fmt::number(Customer::int($counts, 'customers')) . ' مشتری',
            ]);
        }
    }

    /**
     * The panel's phone card's warning: another country's number while the bot takes Iranian ones alone — they are asked
     * again, unless excused. Read from the bot's settings, which only the owner and an agent read.
     *
     * @param array<string, mixed> $user
     */
    private function phoneWarning(Admin $admin, array $user): ?string
    {
        $phone = Customer::text($user, 'phone');
        if ($phone === null || str_starts_with($phone, '+98') || ($user['role'] ?? null) === 'admin' || Json::on($user, 'phone_exempt') || !$admin->seat->configures()) {
            return null;
        }
        try {
            $settings = BotGroupSource::settings($admin);
        } catch (ApiRefusal) {
            return null;
        }

        return Json::on($settings, 'phone_required') && Json::on($settings, 'phone_iran_only')
            ? 'شماره‌اش ایرانی نیست و ربات دوباره شماره ایرانی از او می‌خواهد؛ اگر ندارد، او را از ارسال شماره موبایل معاف کنید.'
            : null;
    }

    /**
     * «تخفیف اختصاصی» asked: the percent, as it stands, and «🗑 خالی کردن» for one set.
     *
     * @param array<string, mixed> $user
     */
    private function askDiscount(Admin $admin, array $user): View
    {
        $id = Customer::int($user, 'id');
        $percent = Customer::int($user, 'discount_percent');
        $prompt = (new Prompt('تخفیف اختصاصی (درصد)', self::ID, 'disc', [$id], 'از هر خرید، تمدید و حجم، زمان و کاربر اضافه‌اش کم می‌شود؛ اگر فروش ویژه یا سطح وفاداری‌اش بیشتر باشد، همان بیشتر.'))
            ->current($percent > 0 ? Fmt::plain(Fmt::percent($percent)) : null)
            ->example('۱۰')
            ->limits('از ۰ تا ۱۰۰؛ ۰ یعنی بدون تخفیف اختصاصی');
        if ($percent > 0) {
            $prompt->clearable(self::ID, 'disc0', $id);
        }

        return $admin->ask($prompt);
    }

    /** The own discount saved as `$percent` (as typed: the panel reads it), and the page as it then stands. */
    private function discounted(Admin $admin, int $id, string $percent): View
    {
        $user = Customer::row($admin->api->put("/users/{$id}/discount", ['percent' => $percent]));
        $admin->notice(Customer::int($user, 'discount_percent') > 0 ? '✅ تخفیف اختصاصی ذخیره شد' : '✅ تخفیف اختصاصی برداشته شد');

        return $this->show($admin, [(string) $id]);
    }

    /**
     * The role's change asked, in the panel's words — its taking away red.
     *
     * @param array<string, mixed> $user
     */
    private function askRole(Admin $admin, array $user, string $role): View
    {
        $id = Customer::int($user, 'id');
        $name = Customer::name($user);
        $no = Button::to(Words::CANCEL, self::ID, Nav::SHOW, $id);
        if ($role === 'admin') {
            return $admin->confirm('این کاربر مدیر ربات شود؟', Button::to(Words::yes('مدیر ربات شود'), self::ID, 'rs', $id, 'admin'), $no, Fmt::e($name . ' مدیر ربات می‌شود.') . "\n" . sprintf(self::BOT_ADMIN, Fmt::ltr('/broadcast')));
        }

        return $admin->confirm(
            'مدیریت ربات این کاربر برداشته شود؟',
            Button::to(Words::yes('برداشته شود'), self::ID, 'rs', $id, 'customer'),
            $no,
            Fmt::e($name . ' دیگر مدیر ربات نیست.' . "\n" . self::NO_LONGER_ADMIN . self::ownRole($admin, $id)),
            danger: true,
        );
    }

    /**
     * The warning when the role to take away is the seat's own: the owner and an agent keep the management and still pass
     * the bot's conditions (AdminSeats::exempt()), but the report group's buttons answer admins alone; anyone else loses
     * the management. '' for another's account.
     */
    private static function ownRole(Admin $admin, int $id): string
    {
        if ($id !== $admin->seat->user->id) {
            return '';
        }

        return "\n\n⚠️ این حساب خود شماست: " . ($admin->seat->configures()
            ? '«⚙️ مدیریت» برای شما می‌ماند، چون ' . ($admin->seat->isOwner() ? 'مالک فروشگاه' : 'نماینده این ربات') . ' هستید، و خاموش بودن ربات، شماره موبایل و کانال‌ها هم شما را نگه نمی‌دارند؛ فقط دکمه‌های زیر گزارش‌ها در گروه گزارش‌ها دیگر به شما جواب نمی‌دهند.'
            : 'مدیر ربات که نباشید، مدیریت فروشگاه از ربات برای این حساب بسته می‌شود.');
    }
}
