<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot;

use App\Modules\Settings\Services\BotSettingsScreen;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Bot\Channels\ChannelsScreen;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\ExpiredServicesCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\ExtrasCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\GeneralCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\ReferralCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\RemindersCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\ServiceClaimsCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\ServiceTransfersCard;
use App\Modules\Telegram\Admin\Sections\Bot\Settings\WalletTransfersCard;
use App\Modules\Telegram\Admin\Sections\Catalog\CustomServicesScreen;
use App\Modules\Telegram\Admin\Sections\Catalog\TestSettingsScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Cashback\CashbackSettingsScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\GiftCodes\GiftCodeSettingsScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Lottery\LotterySettingsScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Wheel\WheelSettingsScreen;
use App\Modules\Telegram\Admin\Sections\Panel\MiniApp\MiniAppConnectionScreen;
use App\Modules\Telegram\Admin\Sections\Panel\MiniApp\MiniAppSource;
use App\Modules\Telegram\Admin\Sections\Panel\Website\ConnectionScreen;
use App\Modules\Telegram\Admin\Sections\Panel\Website\WebsiteSource;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\Settings\Origin;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «⚡️ کلیدهای اصلی» (reached from the home and from «⚙️ تنظیمات»): the shop's main feature switches in one place,
 * grouped as the owner looks for them — the bot itself, the sales, the rewards, the reminders, the website and the mini
 * app. Each is a button saying its state («🟢 …» / «⏸ …») and carrying the one a tap sets; it is saved through its own
 * card's API as that card saves it — a bot settings group whole with the one field changed (BotGroupSource), the website
 * and the mini app a field at a time (WebsiteSource, MiniAppSource) —, the panel's refusal said as the card says it, and
 * the screen comes back, saying what was set. A switch that holds customers back is asked first as its card asks it
 * (asks(): the bot off, the phone rule or the channels' rule on). A switch is offered while the shop has it (its field
 * among GET /bot/settings') and its card opens to the seat — the link claims and the custom services the main shop's
 * alone —; the website and the mini app while their pages answer. Opened from the home, its «⬅️ بازگشت» goes back there
 * (Origin).
 *
 * Actions: `t` (key, 1|0: the switch set; `y` once asked), `v` (the screen again, its origin kept).
 */
final class SwitchesScreen extends BaseScreen
{
    public const ID = 'sw';

    public const TITLE = '⚡️ کلیدهای اصلی';

    /** The screen again where it was left, its origin kept: a question's «✖️ انصراف», «🔄 تازه‌سازی». */
    public const VIEW = 'v';

    /** The website's and the mini app's switches (their `enabled`), apart from the bot settings' by their key. */
    private const WEBSITE = 'web';
    private const MINI_APP = 'app';

    /** A switch's third argument once its question was answered yes. */
    private const ASKED = 'y';

    /**
     * The switches by section: its heading, what it holds, and each switch by its key — [label, its bot settings group
     * (WEBSITE, MINI_APP: their own), the card it lives on]. A bot settings switch's key is its field.
     */
    private const SECTIONS = [
        [Icons::BOT . ' ربات و مشتری', 'روشن بودن ربات و شرط‌هایی که مشتری پیش از منو می‌گذراند.', [
            'enabled' => ['ربات', 'general', GeneralCard::ID],
            'phone_required' => ['تایید شماره موبایل', 'general', GeneralCard::ID],
            'join_required' => ['عضویت اجباری', 'channels', ChannelsScreen::ID],
        ]],
        [Icons::SALES . ' فروش و سرویس‌ها', 'آنچه مشتری می‌خرد و با سرویس و کیف پولش می‌کند.', [
            'test_enabled' => ['اکانت تست', 'test_accounts', TestSettingsScreen::ID],
            'extras_enabled' => ['حجم، زمان و کاربر اضافه', 'extras', ExtrasCard::ID],
            'custom_enabled' => ['سرویس دلخواه', 'custom_services', CustomServicesScreen::ID],
            'claim_enabled' => ['افزودن سرویس با لینک', 'service_claims', ServiceClaimsCard::ID],
            'transfer_enabled' => ['انتقال سرویس', 'service_transfers', ServiceTransfersCard::ID],
            'wallet_transfer_enabled' => ['انتقال موجودی', 'wallet_transfers', WalletTransfersCard::ID],
        ]],
        [Icons::REWARDS . ' تخفیف و جوایز', 'برنامه‌های جذب و نگه داشتن مشتری.', [
            'referral_enabled' => ['زیرمجموعه‌گیری', 'referral', ReferralCard::ID],
            'gift_codes_enabled' => ['کد هدیه', 'gift_codes', GiftCodeSettingsScreen::ID],
            'wheel_enabled' => ['گردونه شانس', 'wheel', WheelSettingsScreen::ID],
            'cashback_enabled' => ['کش‌بک', 'cashback', CashbackSettingsScreen::ID],
            'lottery_enabled' => ['قرعه‌کشی شبانه', 'lottery', LotterySettingsScreen::ID],
        ]],
        ['⏰ یادآوری و حذف خودکار', 'پیام پیش از تمام شدن سرویس، و پاک شدن سرویس‌های منقضی‌شده.', [
            'expiry_reminder' => ['یادآوری پایان زمان', 'reminders', RemindersCard::ID],
            'traffic_reminder' => ['یادآوری پایان حجم', 'reminders', RemindersCard::ID],
            'expired_cleanup' => ['حذف سرویس منقضی', 'expired_services', ExpiredServicesCard::ID],
        ]],
        [Icons::WEBSITE . ' وب‌سایت و مینی‌اپ', 'درهای دیگر فروشگاه به روی مشتری.', [
            self::WEBSITE => ["\u{2068}API\u{2069} وب‌سایت", self::WEBSITE, ConnectionScreen::ID],
            self::MINI_APP => ['مینی‌اپ', self::MINI_APP, MiniAppConnectionScreen::ID],
        ]],
    ];

    public function __construct(private readonly BotSettingsScreen $settings) {}

    /**
     * The question before switch `$key` is set to `$on`, when that holds customers back — the bot off, the phone rule
     * on (GeneralCard's words), the channels' rule on (ChannelsScreen's) —: its question, its yes and what it does
     * (HTML); null for a switch set at a tap.
     *
     * @return array{question: string, yes: string, detail: string}|null
     * @throws ApiRefusal
     */
    public static function asks(Admin $admin, string $key, bool $on): ?array
    {
        return match (true) {
            $key === 'enabled' && !$on => [
                'question' => 'ربات خاموش شود؟',
                'yes' => Words::yes('خاموش شود', '⏸'),
                'detail' => 'مشتری‌ها به جای منو فقط پیام «ربات فعلا غیرفعال است» می‌بینند و وب‌سایت فروشگاه سفارش نمی‌گیرد، تا دوباره روشنش کنید. مدیریت فروشگاه (مالک، نماینده و مدیران ربات) همچنان از ربات استفاده می‌کند.',
            ],
            $key === 'phone_required' && $on => [
                'question' => 'تایید شماره موبایل روشن شود؟',
                'yes' => Words::yes('روشن شود', '✅'),
                'detail' => 'مشتری‌ای که شماره‌اش را تایید نکرده، تا شماره را با دکمه «ارسال شماره موبایل» نفرستد، در ربات جلو نمی‌رود (یا فقط سر کارهایی که انتخاب می‌کنید). کجا پرسیده شود را بعد از روشن شدن انتخاب کنید.',
            ],
            $key === 'join_required' && $on => [
                'question' => 'عضویت اجباری روشن شود؟',
                'yes' => Words::yes('روشن شود'),
                'detail' => 'از این به بعد مشتری‌ای که عضو همه کانال‌های لیست نیست، به جای منو لینک کانال‌ها و دکمه «عضو شدم» را می‌بیند (بعد از تایید شماره). مدیریت فروشگاه معاف است.'
                    . (ChannelsScreen::channels($admin) === [] ? ' لیست هنوز خالی است؛ تا کانالی اضافه نشود، از کسی چیزی خواسته نمی‌شود.' : ''),
            ],
            default => null,
        };
    }

    public function show(Admin $admin, array $args): View
    {
        Origin::opened($admin, self::ID, $args);

        return $this->view($admin, $this->load($admin));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $values = $this->load($admin);
        $key = $args[0] ?? '';
        if ($action !== 't' || !in_array($args[1] ?? null, ['0', '1'], true) || self::state($admin, $key, $values) === null) {
            return $this->view($admin, $values);
        }
        $on = $args[1] === '1';
        $label = self::label($key);
        if (self::state($admin, $key, $values) === $on) {
            $admin->notice('«' . $label . '» همین حالا ' . ($on ? 'روشن' : 'خاموش') . ' است');

            return $this->view($admin, $values);
        }
        $ask = ($args[2] ?? '') === self::ASKED ? null : self::asks($admin, $key, $on);
        if ($ask !== null) {
            // Turning on is green, turning off — customers held back — red.
            return $admin->confirm($ask['question'], Button::to($ask['yes'], self::ID, 't', $key, (int) $on, self::ASKED), Button::to(Words::CANCEL, self::ID, self::VIEW), $ask['detail'], danger: !$on);
        }

        $values = $this->save($admin, $key, $values, $on);
        $admin->notice(self::state($admin, $key, $values) === true ? '✅ «' . $label . '» روشن شد' : '⏸ «' . $label . '» خاموش شد');

        return $this->view($admin, $values);
    }

    /**
     * The switches offered, a section at a time: its heading, what it holds, and its switches two a row where the labels
     * are short; the bot switched off said first; the way to every bot settings card; how it works folded away.
     *
     * @param array{0: array<string, mixed>, 1: array<string, mixed>|null, 2: array<string, mixed>|null} $values
     */
    private function view(Admin $admin, array $values): View
    {
        $view = View::make(self::TITLE, Words::ROOT, Fmt::bare(Words::SETTINGS))
            ->line('امکان‌هایی که بیشتر روشن و خاموش می‌شوند، یک‌جا: هر دکمه وضعیتش را نشان می‌دهد و با یک ضربه عوضش می‌کند.');
        if (self::state($admin, 'enabled', $values) === false) {
            $view->line()->line('⚠️ <b>ربات خاموش است.</b> مشتری‌ها به جای منو فقط پیام «ربات فعلا غیرفعال است» می‌بینند.');
        }
        foreach (self::SECTIONS as [$heading, $about, $switches]) {
            $buttons = [];
            foreach (array_keys($switches) as $key) {
                $on = self::state($admin, $key, $values);
                if ($on !== null) {
                    $buttons[] = Button::to(($on ? '🟢 ' : '⏸ ') . self::label($key), self::ID, 't', $key, $on ? 0 : 1);
                }
            }
            if ($buttons !== []) {
                $view->section($heading)->line(Fmt::e($about))->tiles(...$buttons);
            }
        }

        return $view->row($admin->opens(BotSettingsMenu::ID) ? Button::to(Icons::SETTINGS . ' تنظیمات کامل ربات', BotSettingsMenu::ID) : null)
            ->help('🟢 روشن · ⏸ خاموش؛ هر دکمه با یک ضربه وضعیت را عوض می‌کند و همان لحظه روی ربات اعمال می‌شود. خاموش کردن ربات و روشن کردن تایید شماره موبایل یا عضویت اجباری اول پرسیده می‌شود، چون جلوی مشتری‌ها را می‌گیرد.'
                . self::where($admin, $values))
            ->back(Origin::back($admin, self::ID, 'hc'))
            ->refresh(self::ID, self::VIEW);
    }

    /**
     * Where each switch offered has its details (HTML, a line of its own): the bot settings, and the pages of the shop,
     * the rewards, the referral program, the channels, the website and the mini app — each named only for the switches
     * the shop has and the seat opens (state()), so an agent's shop is not sent to a page it has not; '' for none.
     *
     * @param array{0: array<string, mixed>, 1: array<string, mixed>|null, 2: array<string, mixed>|null} $values
     */
    private static function where(Admin $admin, array $values): string
    {
        // [the page's name, its switches by key with the word for each].
        $pages = [
            [Icons::SHOP . ' فروشگاه', ['test_enabled' => 'اکانت تست', 'custom_enabled' => 'سرویس دلخواه']],
            [Icons::REWARDS . ' تخفیف و جوایز', ['gift_codes_enabled' => 'کد هدیه', 'wheel_enabled' => 'گردونه', 'cashback_enabled' => 'کش‌بک', 'lottery_enabled' => 'قرعه‌کشی']],
            [Icons::REFERRALS . ' زیرمجموعه‌گیری', ['referral_enabled' => 'قوانین زیرمجموعه‌گیری']],
            [Icons::BOT . ' ربات', ['join_required' => 'کانال‌ها']],
            [Words::SETTINGS, [self::WEBSITE => 'وب‌سایت', self::MINI_APP => 'مینی‌اپ']],
        ];
        $clauses = $admin->opens(BotSettingsMenu::ID) ? ['«' . Fmt::e(Icons::BOT . ' ' . BotSettingsMenu::TITLE) . '»'] : [];
        foreach ($pages as [$page, $switches]) {
            $named = [];
            foreach ($switches as $key => $word) {
                if (self::state($admin, $key, $values) !== null) {
                    $named[] = $word;
                }
            }
            if ($named !== []) {
                $clauses[] = Fmt::e(self::series($named)) . ' در «' . Fmt::e($page) . '»';
            }
        }

        return $clauses === [] ? '' : "\n" . 'جزئیات هر کدام در صفحه خودش است: ' . self::series($clauses) . '.';
    }

    /**
     * Words in a Persian series: «الف»، «الف و ب»، «الف، ب و ج».
     *
     * @param list<string> $words
     */
    private static function series(array $words): string
    {
        $last = array_pop($words);

        return $words === [] ? (string) $last : implode('، ', $words) . ' و ' . $last;
    }

    /**
     * What the switches are read from: every bot settings group's fields (GET /bot/settings), the website's and the mini
     * app's — each of these two null while its page is not the seat's or the panel refuses it.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null, 2: array<string, mixed>|null}
     * @throws ApiRefusal
     */
    private function load(Admin $admin): array
    {
        return [
            BotGroupSource::settings($admin),
            self::read($admin, ConnectionScreen::ID, static fn(): array => (new WebsiteSource())->load($admin)),
            self::read($admin, MiniAppConnectionScreen::ID, static fn(): array => (new MiniAppSource())->load($admin)),
        ];
    }

    /**
     * A page's values (the website's, the mini app's), or null while `$card` is not the seat's or the panel refuses it.
     *
     * @param \Closure(): array<string, mixed> $load
     * @return array<string, mixed>|null
     */
    private static function read(Admin $admin, string $card, \Closure $load): ?array
    {
        if (!$admin->opens($card)) {
            return null;
        }
        try {
            return $load();
        } catch (ApiRefusal) {
            return null;
        }
    }

    /**
     * Switch `$key`'s state as `$values` hold it; null when it is not offered: no switch of that key, its card not the
     * seat's, or the shop without it (no field of that name in its settings, its page unread).
     *
     * @param array{0: array<string, mixed>, 1: array<string, mixed>|null, 2: array<string, mixed>|null} $values
     */
    private static function state(Admin $admin, string $key, array $values): ?bool
    {
        $switch = self::entry($key);
        if ($switch === null || !$admin->opens($switch[2])) {
            return null;
        }
        $from = match ($switch[1]) {
            self::WEBSITE => $values[1],
            self::MINI_APP => $values[2],
            default => $values[0],
        };
        $field = in_array($switch[1], [self::WEBSITE, self::MINI_APP], true) ? 'enabled' : $key;

        return $from !== null && array_key_exists($field, $from) ? Json::on($from, $field) : null;
    }

    /**
     * Switch `$key` set to `$on` through its own card's way of saving it, and the values as the panel then has them.
     *
     * @param array{0: array<string, mixed>, 1: array<string, mixed>|null, 2: array<string, mixed>|null} $values
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null, 2: array<string, mixed>|null}
     * @throws ApiRefusal
     */
    private function save(Admin $admin, string $key, array $values, bool $on): array
    {
        $group = self::entry($key)[1] ?? '';

        return match ($group) {
            self::WEBSITE => [$values[0], (new WebsiteSource())->save($admin, $values[1] ?? [], 'enabled', $on), $values[2]],
            self::MINI_APP => [$values[0], $values[1], (new MiniAppSource())->save($admin, $values[2] ?? [], 'enabled', $on)],
            default => [(new BotGroupSource($group, $this->settings))->save($admin, $values[0], $key, $on), $values[1], $values[2]],
        };
    }

    /**
     * Switch `$key`'s [label, group, card]; null for none.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    private static function entry(string $key): ?array
    {
        foreach (self::SECTIONS as [, , $switches]) {
            if (isset($switches[$key])) {
                return $switches[$key];
            }
        }

        return null;
    }

    private static function label(string $key): string
    {
        return self::entry($key)[0] ?? $key;
    }
}
