<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Bot\BotScreens;
use App\Modules\Telegram\Admin\Sections\Bot\SwitchesScreen;
use App\Modules\Telegram\Admin\Sections\Catalog\CatalogScreens;
use App\Modules\Telegram\Admin\Sections\Core\BotAdminsScreen;
use App\Modules\Telegram\Admin\Sections\Core\HomeScreen;
use App\Modules\Telegram\Admin\Sections\Core\OwnerAccountsScreen;
use App\Modules\Telegram\Admin\Sections\Core\PageScreen;
use App\Modules\Telegram\Admin\Sections\Core\SearchScreen;
use App\Modules\Telegram\Admin\Sections\Core\ShopScreen;
use App\Modules\Telegram\Admin\Sections\Customers\CustomerScreens;
use App\Modules\Telegram\Admin\Sections\Marketing\MarketingScreens;
use App\Modules\Telegram\Admin\Sections\Owner\OwnerScreens;
use App\Modules\Telegram\Admin\Sections\Panel\PanelScreens;
use App\Modules\Telegram\Admin\Sections\Sales\SalesScreens;
use App\Modules\Telegram\Admin\Sections\Support\SupportScreens;
use Psr\Container\ContainerInterface;

/**
 * Every management screen by its id (Nav), and the menus that lead to them — the panel's sidebar as the bot's home and
 * hubs. Each section's screens are its own list (Sections\*\*Screens::SCREENS); a menu entry whose screen is not there,
 * or that the seat may not open, is left out. Screens are made by the container when first opened.
 */
final class Screens
{
    public const HOME = 'h';

    /** The pages' middle button: says where it is, the window left as it is (Core\PageScreen). */
    public const PAGE = 'pgi';

    /** «🔎 جستجو» on the home: a customer, an order, a payment or a service found by one value (Core\SearchScreen). */
    public const SEARCH = 'sr';

    /**
     * The core's own screens: the home, the search, «مدیران ربات», the owner's linked accounts, the owner's shop, the
     * pages' middle button.
     */
    private const CORE = [
        self::HOME => HomeScreen::class,
        self::SEARCH => SearchScreen::class,
        BotAdminsScreen::ID => BotAdminsScreen::class,
        OwnerAccountsScreen::ID => OwnerAccountsScreen::class,
        'shop' => ShopScreen::class,
        self::PAGE => PageScreen::class,
    ];

    /** Each section's list. */
    private const SECTIONS = [
        SupportScreens::SCREENS,
        CustomerScreens::SCREENS,
        SalesScreens::SCREENS,
        CatalogScreens::SCREENS,
        MarketingScreens::SCREENS,
        BotScreens::SCREENS,
        OwnerScreens::SCREENS,
        PanelScreens::SCREENS,
    ];

    /**
     * @var array<string, Screen>
     */
    private array $made = [];

    public function __construct(private readonly ContainerInterface $container) {}

    /** The screen of `$id`; null for none (a button of a screen since removed). */
    public function get(string $id): ?Screen
    {
        if (isset($this->made[$id])) {
            return $this->made[$id];
        }
        $hub = $this->hub($id);
        if ($hub !== null) {
            return $this->made[$id] = $hub;
        }
        $class = self::all()[$id] ?? null;
        if ($class === null || !class_exists($class)) {
            return null;
        }
        $screen = $this->container->get($class);

        return $this->made[$id] = $screen instanceof Screen ? $screen : throw new \LogicException("The management screen {$id} is no Screen.");
    }

    /**
     * Every screen's class by its id: the core's, then each section's.
     *
     * @return array<string, class-string<Screen>>
     */
    private static function all(): array
    {
        return array_merge(self::CORE, ...self::SECTIONS);
    }

    public function home(): Screen
    {
        return $this->get(self::HOME) ?? throw new \LogicException('The management has no home screen.');
    }

    /**
     * How much waits on a human in the queues `$entries` count (Admin\Services\Queues), read once; none when nothing
     * there counts one, or the panel would not say.
     *
     * @param list<array{0: string, 1: string, 2?: string}> $entries
     * @return array<string, int>
     */
    public function queues(Admin $admin, array $entries): array
    {
        if (array_filter($entries, static fn(array $entry): bool => isset($entry[2])) === []) {
            return [];
        }

        try {
            $queues = $admin->api->get('/queues')['queues'] ?? [];
        } catch (ApiRefusal) {
            return [];
        }

        return is_array($queues) ? array_map(intval(...), array_filter($queues, is_numeric(...))) : [];
    }

    /**
     * A menu's entries as its buttons: the ones `$seat` may open — built ones —, each with the count of its queue
     * beside it (a badge).
     *
     * @param list<array{0: string, 1: string, 2?: string}> $entries
     * @param array<string, int> $queues
     * @return list<Button>
     */
    public function buttons(AdminSeat $seat, array $entries, array $queues = []): array
    {
        $buttons = [];
        foreach ($entries as $entry) {
            if ($this->get($entry[0])?->allows($seat) === true) {
                $buttons[] = Button::to($entry[1] . Fmt::badge($queues[$entry[2] ?? ''] ?? 0), $entry[0]);
            }
        }

        return $buttons;
    }

    /**
     * The home's menu: the panel's sidebar, each entry with the queue it holds (the home says what waits apart, as
     * buttons straight to it).
     *
     * @return list<array{0: string, 1: string, 2?: string}>
     */
    public static function homeEntries(): array
    {
        return [
            ['db', Icons::DASHBOARD . ' داشبورد'],
            ['acc', Icons::AGENT_ACCOUNT . ' حساب نمایندگی'],
            ['tk', Icons::SUPPORT . ' پشتیبانی', 'open_tickets'],
            ['rv', Icons::REVIEWS . ' نظرات', 'pending_reviews'],
            ['hl', Icons::SALES . ' فروش'],
            ['hs', Icons::SHOP . ' فروشگاه'],
            ['hm', Icons::REWARDS . ' تخفیف و جوایز'],
            ['hb', Icons::BOT . ' ربات'],
            ['sv', Icons::SERVERS . ' سرورها'],
            ['ag', Icons::AGENTS . ' نمایندگان'],
            ['hc', Icons::SETTINGS . ' تنظیمات'],
        ];
    }

    /**
     * The menu of `$id`; null when it is no menu. Each section is reached from one menu alone: a settings card of a
     * feature's (the discount codes', the test accounts', the custom services', the referrals') from that feature's own
     * screen, never from «🤖 تنظیمات ربات» too; the channels and the report group from «🤖 ربات»; «🛡 مدیران ربات» from
     * «⚙️ تنظیمات». «⚡️ کلیدهای اصلی» is the one reached from two: «⚙️ تنظیمات» and the home.
     */
    private function hub(string $id): ?HubScreen
    {
        return match ($id) {
            'hl' => new HubScreen($this, Icons::SALES . ' فروش', [Words::ROOT], [
                ['py', Icons::PAYMENTS . ' پرداخت‌ها', 'payments_to_review'],
                ['or', Icons::ORDERS . ' سفارش‌ها', 'stuck_orders'],
                ['us', Icons::CUSTOMERS . ' کاربران'],
                ['sb', Icons::SERVICES . ' اشتراک‌ها'],
                ['rf', Icons::REFERRALS . ' زیرمجموعه‌گیری'],
                ['dp', Icons::DEPOSITS . ' واریزهای پیامکی'],
            ], 'کارهای روزانه فروش: آنچه مشتری‌ها خریده و پرداخته‌اند.'),
            'hs' => new HubScreen($this, Icons::SHOP . ' فروشگاه', [Words::ROOT], [
                ['pl', Icons::PLANS . ' پلن‌ها'],
                ['ct', Icons::CATEGORIES . ' دسته‌بندی‌ها'],
                ['ug', Icons::GROUPS . ' گروه‌های کاربران'],
                ['ts', Icons::TESTS . ' اکانت تست'],
                ['cs', Icons::CUSTOM . ' سرویس دلخواه'],
                ['pm', Icons::METHODS . ' روش‌های پرداخت'],
            ], 'آنچه فروشگاه می‌فروشد، به چه کسانی و با چه راه پرداختی.'),
            'hm' => new HubScreen($this, Icons::REWARDS . ' تخفیف و جوایز', [Words::ROOT], [
                ['dc', Icons::DISCOUNTS . ' کدهای تخفیف'],
                ['sl', Icons::SPECIAL_SALES . ' فروش ویژه'],
                ['ly', Icons::LOYALTY . ' سطوح وفاداری'],
                ['cb', Icons::CASHBACK . ' کش‌بک'],
                ['gc', Icons::GIFT_CODES . ' کدهای هدیه'],
                ['wh', Icons::WHEEL . ' گردونه شانس'],
                ['lt', Icons::LOTTERY . ' قرعه‌کشی'],
            ], 'راه‌های جذب و نگه داشتن مشتری با تخفیف، هدیه و جایزه.'),
            'hb' => new HubScreen($this, Icons::BOT . ' ربات', [Words::ROOT], [
                ['br', Icons::BROADCAST . ' ارسال همگانی'],
                ['mg', Icons::MASS_GIFT . ' هدیه همگانی'],
                ['tx', Icons::TEXTS . ' متن‌های ربات'],
                ['kb', Icons::KEYBOARDS . ' کیبوردها'],
                ['ch', Icons::CHANNELS . ' کانال‌های اجباری'],
                ['rg', Icons::REPORT_GROUP . ' گروه گزارش‌ها'],
            ], 'آنچه مشتری در ربات می‌بیند و می‌گیرد، و گزارش‌هایی که به شما می‌رسد.'),
            'hc' => new HubScreen($this, Icons::SETTINGS . ' تنظیمات', [Words::ROOT], [
                ['ss', Icons::SEARCH . ' جستجوی تنظیمات'],
                [SwitchesScreen::ID, SwitchesScreen::TITLE],
                ['bs', Icons::BOT . ' تنظیمات ربات'],
                ['ba', Icons::BOT_ADMINS . ' مدیران ربات'],
                ['ws', Icons::WEBSITE . ' تنظیمات وب‌سایت'],
                ['ma', Icons::MINI_APP . ' مینی‌اپ تلگرام'],
                ['pp', Icons::PANEL . ' تنظیمات پنل'],
                ['ol', Icons::OWNER . ' حساب تلگرام مالک'],
            ], 'رفتار ربات، دسترسی مدیران و اتصال وب‌سایت، مینی‌اپ و پنل.'),
            default => null,
        };
    }
}
