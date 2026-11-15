<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Auth\StaffGrant;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Messages;

/**
 * One customer (the panel's customer page, `/users/{id}`): who they are — number, Telegram, email, phone, since when and
 * when last seen, standing and role —, their wallet, services, orders, own discount, groups, referral facts, loyalty
 * tier and an agent's agency; and the page's ways on: their services, payments, orders and tickets (each row opening its
 * own screen), wallet, groups and website sign-in, «➕ افزودن سفارش» and «✉️ ارسال پیام», their referrer's page, the
 * loyalty tiers, an agent's agency (the owner's) and their Telegram chat. Changed here: the phone rule's
 * excuse (at a tap), the ban (asked first; lifted at once), and — the owner and an agent alone, as the panels — the bot
 * admin role (asked first) and the own discount (typed). One of the shop's admins only looks at a bot admin's or an
 * agent's account (Customer::lock()).
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

    private const BAN_WORDS = 'ربات به هر پیامش فقط می‌گوید حسابش مسدود شده و هیچ کاری برایش انجام نمی‌دهد. سرویس‌های فعلی‌اش دست نمی‌خورد و هر وقت خواستید می‌توانید رفع مسدودی کنید.';

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
                $user = $this->changed($admin->api->put("/users/{$id}/phone-exempt", ['phone_exempt' => ($args[1] ?? '') === '1']));
                $admin->notice(Json::on($user, 'phone_exempt') ? '✅ ' . Customer::name($user) . ' از ارسال شماره موبایل معاف شد' : '✅ ' . Customer::name($user) . ' دیگر از ارسال شماره موبایل معاف نیست');

                return $this->show($admin, [(string) $id]);
            case 'disc':
                return $this->askDiscount($admin, Customer::row(Customer::read($admin, $id)));
            case 'disc0':
                return $this->discounted($admin, $id, '0');
            case 'role':
                return $this->askRole($admin, Customer::row(Customer::read($admin, $id)), ($args[1] ?? '') === 'admin' ? 'admin' : 'customer');
            case 'rs':
                $user = $this->changed($admin->api->put("/users/{$id}/role", ['role' => ($args[1] ?? '') === 'admin' ? 'admin' : 'customer']));
                $admin->notice(($user['role'] ?? null) === 'admin' ? '✅ ' . Customer::name($user) . ' مدیر ربات شد' : '✅ ' . Customer::name($user) . ' دیگر مدیر ربات نیست');

                return $this->show($admin, [(string) $id]);
            case 'ban':
                $user = Customer::row(Customer::read($admin, $id));
                $self = $id === $admin->seat->user->id ? "\n\n⚠️ این حساب خود شماست: مسدود که شود، از ربات مدیریت نمی‌کنید تا از پنل رفع مسدودی شود." : '';

                return $admin->confirm(
                    'این کاربر مسدود شود؟',
                    Button::to('⛔️ بله، مسدود شود', self::ID, 'bs', $id, 'banned'),
                    Button::to('✖️ انصراف', self::ID, 'show', $id),
                    Fmt::e(Customer::name($user) . ' مسدود می‌شود.' . "\n" . self::BAN_WORDS . $self),
                );
            case 'bs':
                $user = $this->changed($admin->api->patch("/users/{$id}", ['status' => ($args[1] ?? '') === 'banned' ? 'banned' : 'active']));
                $admin->notice(($user['status'] ?? null) === 'banned' ? '⛔️ ' . Customer::name($user) . ' مسدود شد' : '✅ ' . Customer::name($user) . ' دوباره فعال شد');

                return $this->show($admin, [(string) $id]);
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
     * The page as the panel answered it: who, then what they bought and hold, then the referral program, loyalty and
     * agency, then why it is locked — and the buttons the seat may use on it.
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

        $view = View::make('👤 ' . Customer::name($user), 'مدیریت', 'فروش', 'کاربران')
            ->line(implode(' · ', array_filter([$banned ? '⛔️ مسدود' : '🟢 فعال', $isAdmin ? '🛡 مدیر ربات' : null, $agency !== null ? '🤝 نماینده' : null])));
        $this->identity($view, $user);
        $this->holdings($view, $user, $agency);
        $this->standing($view, $detail);
        $warning = $this->phoneWarning($admin, $user);
        if ($warning !== null) {
            $view->line()->line('⚠️ ' . $warning);
        }
        if ($lock !== null) {
            $view->line()->line($lock);
        }

        $counts = Json::part($user, 'counts');
        $view->grid(
            2,
            Button::to('📡 سرویس‌ها' . Fmt::badge(Customer::int($counts, 'subscriptions')), CustomerServicesScreen::ID, 'show', $id),
            Button::to('💳 پرداخت‌ها', CustomerPaymentsScreen::ID, 'show', $id),
            Button::to('🛒 سفارش‌ها' . Fmt::badge(Customer::int($counts, 'orders')), CustomerOrdersScreen::ID, 'show', $id),
            Button::to('🎧 تیکت‌ها', CustomerTicketsScreen::ID, 'show', $id),
            Button::to('👛 کیف پول', CustomerWalletScreen::ID, 'show', $id),
            Button::to('👫 گروه‌ها', CustomerGroupsScreen::ID, 'show', $id),
        );
        $view->row(
            $admin->seat->holds(StaffGrant::ManualOrders) && !Customer::own($admin, $id) ? Button::to('➕ افزودن سفارش', ManualOrderScreen::ID, 'new', $id) : null,
            $lock === null ? Button::to('✉️ ارسال پیام', CustomerMessageScreen::ID, 'show', $id) : null,
        );
        $view->row(
            Button::to('🔐 ورود به وب‌سایت', CustomerAccountScreen::ID, 'show', $id),
            $admin->seat->configures() ? Button::to('🏷 تخفیف اختصاصی: ' . (Customer::int($user, 'discount_percent') > 0 ? Fmt::percent(Customer::int($user, 'discount_percent')) : 'ندارد'), self::ID, 'disc', $id) : null,
        );
        if ($lock === null && !$isAdmin) {
            $exempt = Json::on($user, 'phone_exempt');
            $view->row(Button::to('📱 معاف از ارسال شماره موبایل: ' . ($exempt ? '✅' : '⛔️'), self::ID, 'ph', $id, $exempt ? 0 : 1));
        }
        $view->row(
            $admin->seat->configures() ? ($isAdmin ? Button::to('🛡 برداشتن مدیریت ربات', self::ID, 'role', $id, 'customer') : Button::to('🛡 مدیر ربات کردن', self::ID, 'role', $id, 'admin')) : null,
            $lock !== null ? null : ($banned ? Button::to('✅ رفع مسدودی', self::ID, 'bs', $id, 'active') : Button::to('⛔️ مسدود کردن', self::ID, 'ban', $id)),
        );
        $referral = Json::part($detail, 'referral');
        $referrer = is_array($referral['referrer'] ?? null) ? $referral['referrer'] : null;
        $loyalty = Json::part($detail, 'loyalty');
        $view->row(
            $referrer === null ? null : Button::to('🔗 معرف: ' . Customer::name($referrer), self::ID, 'show', Customer::int($referrer, 'id')),
            // Their invitees, as the panel's card links them: the referrals' invitees narrowed to this referrer.
            Customer::int($referral, 'referrals') > 0 ? Button::to('🙋 زیرمجموعه‌ها', 'rfi', 'ref', $id) : null,
            // The tier's name links to the loyalty tiers, as on the panel's card: theirs who set the shop up.
            $admin->seat->configures() && (is_array($loyalty['tier'] ?? null) || is_array($loyalty['next'] ?? null)) ? Button::to('👑 سطوح وفاداری', 'ly') : null,
        );
        $handle = Customer::text($user, 'username');
        $view->row(
            $handle !== null ? Button::url('💬 گفتگو در تلگرام', 'https://t.me/' . rawurlencode($handle)) : null,
            $agency !== null && $admin->seat->isOwner() ? Button::to('🤝 مدیریت نماینده', 'agv', 'show', $id) : null,
            Button::to('🔄 تازه‌سازی', self::ID, 'show', $id),
        );

        return $view->back(UsersScreen::ID);
    }

    /**
     * Who they are, under the name: their number, Telegram, email, phone, since when and when last seen.
     *
     * @param array<string, mixed> $user
     */
    private function identity(View $view, array $user): void
    {
        $telegram = is_int($user['telegram_id'] ?? null) ? $user['telegram_id'] : null;
        $handle = Customer::text($user, 'username');
        $phone = Customer::text($user, 'phone');
        $phoneRule = match (true) {
            ($user['role'] ?? null) === 'admin' => ' · مدیر ربات است و هیچ‌وقت برای شماره موبایل نگه داشته نمی‌شود',
            Json::on($user, 'phone_exempt') => ' · معاف از ارسال شماره موبایل',
            default => '',
        };
        $seen = Customer::text($user, 'last_seen_at');
        $view->line()->facts([
            'شماره مشتری' => Fmt::code('#' . Customer::int($user, 'id')),
            'تلگرام' => $telegram === null ? 'بدون تلگرام' : ($handle !== null ? Fmt::ltr('@' . $handle) : 'بدون نام کاربری')
                . ' · شناسه ' . Fmt::code((string) $telegram) . ' · <a href="tg://user?id=' . $telegram . '">گفتگو</a>',
            'ایمیل' => Customer::text($user, 'email') === null ? null : Fmt::ltr(Customer::text($user, 'email')),
            'موبایل' => ($phone === null ? 'هنوز شماره‌ای تایید نکرده است' : Fmt::ltr($phone)) . $phoneRule,
            'عضویت' => Fmt::date(Customer::text($user, 'created_at')) . ' · آخرین بازدید: ' . ($seen === null ? 'هنوز نه' : Fmt::date($seen, true)),
        ]);
    }

    /**
     * What they hold: the wallet (an agent's credit beside it), services, orders, own discount and groups.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed>|null $agency
     */
    private function holdings(View $view, array $user, ?array $agency): void
    {
        $counts = Json::part($user, 'counts');
        $credit = $agency === null ? '0' : Customer::money($agency['credit_limit'] ?? null);
        $percent = Customer::int($user, 'discount_percent');
        $groups = array_filter(array_map(static fn(array $group): ?string => Customer::text($group, 'name'), Json::rows($user, 'groups')));
        $view->line()->facts([
            'کیف پول' => Fmt::e(Messages::balance(Customer::money($user['balance'] ?? null)))
                . ((float) $credit > 0 ? ' · اعتبار نمایندگی ' . Fmt::money($credit) . ': موجودی تا این اندازه زیر صفر می‌رود' : ''),
            'سرویس‌ها' => Customer::int($counts, 'subscriptions') > 0
                ? Fmt::number(Customer::int($counts, 'active_subscriptions')) . ' فعال از ' . Fmt::number(Customer::int($counts, 'subscriptions'))
                : 'هنوز سرویسی نخریده است',
            'سفارش‌ها' => Customer::int($counts, 'orders') > 0 ? Fmt::number(Customer::int($counts, 'orders')) . ' سفارش' : 'هنوز سفارشی ثبت نکرده است',
            'تخفیف اختصاصی' => $percent > 0 ? Fmt::percent($percent) . ' روی هر خرید، تمدید و حجم، زمان و کاربر اضافه' : 'ندارد',
            'گروه‌ها' => $groups === [] ? 'در هیچ گروهی نیست' : Fmt::e(implode('، ', $groups)),
        ]);
    }

    /**
     * What the referral program knows of them, their loyalty tier (while the shop has tiers) and an agent's agency.
     *
     * @param array<string, mixed> $detail
     */
    private function standing(View $view, array $detail): void
    {
        $referral = Json::part($detail, 'referral');
        $referrer = is_array($referral['referrer'] ?? null) ? $referral['referrer'] : null;
        $earned = Customer::money($referral['earned'] ?? null);
        $view->line()->facts([
            'معرف' => $referrer === null ? 'با لینک کسی نیامده است' : Fmt::e(Customer::name($referrer)) . ' ' . Fmt::id(Customer::int($referrer, 'id')),
            'زیرمجموعه‌ها' => Customer::int($referral, 'referrals') > 0 ? Fmt::number(Customer::int($referral, 'referrals')) . ' نفر' : 'هنوز کسی را نیاورده است',
            'پورسانت' => (float) $earned > 0 ? Fmt::money($earned) : null,
        ]);

        $loyalty = Json::part($detail, 'loyalty');
        $tier = is_array($loyalty['tier'] ?? null) ? $loyalty['tier'] : null;
        $next = is_array($loyalty['next'] ?? null) ? $loyalty['next'] : null;
        if ($tier !== null || $next !== null) {
            $perks = $tier === null ? [] : array_filter([
                Customer::int($tier, 'discount_percent') > 0 ? Fmt::percent(Customer::int($tier, 'discount_percent')) . ' تخفیف' : null,
                Customer::int($tier, 'cashback_percent') > 0 ? Fmt::percent(Customer::int($tier, 'cashback_percent')) . ' کش‌بک اضافه' : null,
            ]);
            $view->line()->facts([
                'سطح وفاداری' => $tier === null ? 'هنوز به اولین سطح نرسیده است' : Fmt::e(Customer::text($tier, 'name')) . ($perks === [] ? '' : ' · ' . implode('، ', $perks)),
                'جمع خریدها' => Fmt::money(Customer::money($loyalty['spent'] ?? null)),
                'سطح بعدی' => $next === null ? 'بالاترین سطح' : Fmt::e(Customer::text($next, 'name')) . ' (' . Fmt::money(Customer::money($next['missing'] ?? null)) . ' مانده)',
            ]);
        }

        $agency = is_array($detail['agency'] ?? null) ? $detail['agency'] : null;
        if ($agency !== null) {
            $level = Json::part($agency, 'level');
            $bot = is_array($agency['bot'] ?? null) ? $agency['bot'] : null;
            $counts = Json::part($agency, 'counts');
            $view->line()->line('<b>🤝 نمایندگی</b> · این مشتری نماینده است و ربات فروش خودش را دارد.')->facts([
                'سطح' => Fmt::e(Customer::text($level, 'name')) . ' · هر گیگابایت ' . Fmt::money(Customer::money($level['price_per_gb'] ?? null)),
                'ربات' => $bot === null ? Fmt::NONE : (Customer::text($bot, 'username') !== null ? Fmt::ltr('@' . Customer::text($bot, 'username')) : Fmt::e(Customer::text($bot, 'title') ?? Fmt::NONE)),
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
     * «تخفیف اختصاصی» asked: the percent, as it stands, «🗑 برداشتن تخفیف» for one set.
     *
     * @param array<string, mixed> $user
     */
    private function askDiscount(Admin $admin, array $user): View
    {
        $id = Customer::int($user, 'id');
        $percent = Customer::int($user, 'discount_percent');
        $prompt = new Prompt('درصد تخفیف اختصاصی ' . Customer::name($user), self::ID, 'disc', [$id], implode("\n", [
            'الان: ' . ($percent > 0 ? Fmt::percent($percent) : 'ندارد'),
            'یک عدد بین ۰ تا ۱۰۰ بنویسید؛ ۰ یعنی بدون تخفیف اختصاصی.',
            'روی هر خرید، تمدید و حجم، زمان و کاربر اضافه کم می‌شود — اگر فروش ویژه یا سطح وفاداری‌اش بیشتر باشد، همان بیشتر.',
        ]));
        if ($percent > 0) {
            $prompt->choices(Button::to('🗑 برداشتن تخفیف', self::ID, 'disc0', $id));
        }

        return $admin->ask($prompt);
    }

    /** The own discount saved as `$percent` (as typed: the panel reads it), and the page as it then stands. */
    private function discounted(Admin $admin, int $id, string $percent): View
    {
        $user = $this->changed($admin->api->put("/users/{$id}/discount", ['percent' => $percent]));
        $admin->notice(Customer::int($user, 'discount_percent') > 0 ? '✅ تخفیف اختصاصی ذخیره شد' : '✅ تخفیف اختصاصی برداشته شد');

        return $this->show($admin, [(string) $id]);
    }

    /**
     * The role's change asked, in the panel's words.
     *
     * @param array<string, mixed> $user
     */
    private function askRole(Admin $admin, array $user, string $role): View
    {
        $id = Customer::int($user, 'id');
        $name = Customer::name($user);
        $no = Button::to('✖️ انصراف', self::ID, 'show', $id);
        if ($role === 'admin') {
            return $admin->confirm('این کاربر مدیر ربات شود؟', Button::to('🛡 بله، مدیر ربات شود', self::ID, 'rs', $id, 'admin'), $no, Fmt::e($name . ' مدیر ربات می‌شود.') . "\n" . sprintf(self::BOT_ADMIN, Fmt::ltr('/broadcast')));
        }

        return $admin->confirm(
            'مدیریت ربات این کاربر برداشته شود؟',
            Button::to('🛡 بله، برداشته شود', self::ID, 'rs', $id, 'customer'),
            $no,
            Fmt::e($name . ' دیگر مدیر ربات نیست.' . "\n" . self::NO_LONGER_ADMIN . self::ownRole($admin, $id)),
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

    /**
     * The customer's row an operation answered.
     *
     * @param array<string, mixed> $answer
     * @return array<string, mixed>
     */
    private function changed(array $answer): array
    {
        return Customer::row($answer);
    }
}
