<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Ui;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Bot\BotScreens;
use App\Modules\Telegram\Admin\Sections\Catalog\CatalogScreens;
use App\Modules\Telegram\Admin\Sections\Core\BotAdminsScreen;
use App\Modules\Telegram\Admin\Sections\Core\HomeScreen;
use App\Modules\Telegram\Admin\Sections\Core\OwnerAccountsScreen;
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

    /** The core's own screens: the home, «مدیران ربات», the owner's linked accounts, the owner's shop. */
    private const CORE = [
        self::HOME => HomeScreen::class,
        'ba' => BotAdminsScreen::class,
        'ol' => OwnerAccountsScreen::class,
        'shop' => ShopScreen::class,
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

        $queues = is_array($queues) ? array_map(intval(...), array_filter($queues, is_numeric(...))) : [];
        // The sales menu's badge: the receipts to review and the orders stuck, as its line on the home says.
        $queues['sales'] = ($queues['payments_to_review'] ?? 0) + ($queues['stuck_orders'] ?? 0);

        return $queues;
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
     * The home's entries: what waits first, then the panel's sidebar.
     *
     * @return list<array{0: string, 1: string, 2?: string}>
     */
    public static function homeEntries(): array
    {
        return [
            ['db', '📊 داشبورد'],
            ['acc', '💼 حساب نمایندگی'],
            ['tk', '🎧 پشتیبانی', 'open_tickets'],
            ['rv', '⭐️ نظرات', 'pending_reviews'],
            ['hl', '🛍 فروش', 'sales'],
            ['hs', '📦 فروشگاه'],
            ['hm', '🎉 تخفیف و جوایز'],
            ['hb', '🤖 ربات'],
            ['sv', '🖥 سرورها'],
            ['ag', '🤝 نمایندگان'],
            ['hc', '⚙️ تنظیمات'],
        ];
    }

    /** The menu of `$id`; null when it is no menu. */
    private function hub(string $id): ?HubScreen
    {
        return match ($id) {
            'hl' => new HubScreen($this, '🛍 فروش', ['مدیریت'], [
                ['py', '💳 پرداخت‌ها', 'payments_to_review'],
                ['or', '🛒 سفارش‌ها', 'stuck_orders'],
                ['us', '👥 کاربران'],
                ['sb', '📡 اشتراک‌ها'],
                ['rf', '🔗 زیرمجموعه‌گیری'],
                ['dp', '📩 واریزهای پیامکی'],
            ]),
            'hs' => new HubScreen($this, '📦 فروشگاه', ['مدیریت'], [
                ['pl', '📦 پلن‌ها'],
                ['ct', '🗂 دسته‌بندی‌ها'],
                ['ug', '👫 گروه‌های کاربران'],
                ['ts', '🧪 اکانت تست'],
                ['cs', '🛠 سرویس دلخواه'],
                ['pm', '🏦 روش‌های پرداخت'],
            ]),
            'hm' => new HubScreen($this, '🎉 تخفیف و جوایز', ['مدیریت'], [
                ['dc', '🏷 کدهای تخفیف'],
                ['sl', '🔥 فروش ویژه'],
                ['ly', '🏅 سطوح وفاداری'],
                ['cb', '💸 کش‌بک'],
                ['gc', '🎟 کدهای هدیه'],
                ['wh', '🎡 گردونه شانس'],
                ['lt', '🎲 قرعه‌کشی'],
            ]),
            'hb' => new HubScreen($this, '🤖 ربات', ['مدیریت'], [
                ['br', '📣 ارسال همگانی'],
                ['mg', '🎁 هدیه همگانی'],
                ['tx', '📝 متن‌های ربات'],
                ['kb', '⌨️ کیبوردها'],
                ['ch', '📢 کانال‌های اجباری'],
                ['rg', '📋 گروه گزارش‌ها'],
            ]),
            'hc' => new HubScreen($this, '⚙️ تنظیمات', ['مدیریت'], [
                ['bs', '🤖 تنظیمات ربات'],
                ['ba', '🛡 مدیران ربات'],
                ['ws', '🌐 تنظیمات وب‌سایت'],
                ['ma', '📱 مینی‌اپ تلگرام'],
                ['pp', '🧰 تنظیمات پنل'],
                ['ol', '👑 حساب تلگرام مالک'],
            ]),
            default => null,
        };
    }
}
