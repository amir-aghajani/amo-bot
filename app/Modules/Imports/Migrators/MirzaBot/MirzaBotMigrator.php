<?php

declare(strict_types=1);

namespace App\Modules\Imports\Migrators\MirzaBot;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Form;
use App\Modules\Imports\Contracts\Migrator;
use App\Modules\Imports\Migrators\Faoxima\FaoximaMigrator;
use App\Modules\Imports\Records\ImportedDiscountCode;
use App\Modules\Imports\Records\ImportedGiftCode;
use App\Modules\Imports\Records\ImportedService;
use App\Modules\Imports\Staging\Schema;
use App\Modules\Imports\Staging\Staged;
use App\Modules\Imports\Staging\Table;
use App\Modules\Imports\Survey\LeftBehind;
use App\Modules\Imports\Survey\Recognition;
use App\Modules\Imports\Survey\Survey;
use App\Modules\Orders\Enums\OrderType;
use App\Support\Persian;

/**
 * MirzaBot (https://github.com/mahdiMGF2/mirzabot), a Telegram VPN shop of PHP and MySQL, read from its tables as its
 * schema leaves them — any version, from the old 5.x line to the 0.x one, whose tables lost their capitals in 0.9.3 (the
 * import reads a name whatever its case): its customers with their wallets and agent levels, their services on its
 * panels, its products as plans (its agents' prices paired in, Twins), its discount and gift codes, required channels and
 * bank cards. MirzaBot keeps no version in its database.
 */
final class MirzaBotMigrator implements Migrator
{
    private const KEY = 'mirzabot';

    /**
     * A service whose client is on its panel (`invoice.Status`); every other is unpaid, removed or gone from its panel —
     * `disabled` among them: a test MirzaBot's test cron took off the panel (cronbot/configtest.php, its one writer).
     */
    private const LIVE = ['active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold', 'disablebyadmin'];

    /** Where a live service stands (standing()): a client on its panel, a config sold by hand, or on a panel MirzaBot no longer has. */
    private const ON_PANEL = 'panel';
    private const STOCK = 'stock';
    private const GONE = 'gone';

    /** A purchase MirzaBot never delivered (`invoice.Status`, in any case): every other made its customer one who had bought. */
    private const UNDELIVERED = ['unpaid', 'unsuccessful'];

    /** A card payment awaiting an admin's approval (`payment_report.payment_Status`). */
    private const AWAITING = 'waiting';

    /** The product name MirzaBot gives a free test account, in each language its customers may pick. */
    private const TESTS = ['سرویس تست', 'Test service', 'Тестовый сервис', '测试服务'];

    /**
     * The product name of a service its customer built («سرویس دلخواه»: traffic and days at a price per unit), likewise —
     * and the «حجم دلخواه» names its renewals take for one too.
     */
    private const CUSTOM = [
        '⚙️ سرویس دلخواه', '⚙️ Custom service', '⚙️ Произвольный сервис', '⚙️ 自定义服务',
        '🛍 حجم دلخواه', '🛍 Custom volume', '🛍 Произвольный объём', '🛍 自定义流量',
    ];

    /** A customer excused from the required channels (`user.joinchannel`), and one shown the bank cards (`cardpayment`). */
    private const EXCUSED_FROM_CHANNELS = 'active';
    private const SEES_CARDS = '1';

    /** Who a discount code is for (`discountsell.agent`): everyone, or one audience — customers or an agents' level. */
    private const EVERYONE = 'allusers';
    private const AUDIENCES = ['f' => 'کاربران عادی', 'n' => 'نماینده‌های عادی', 'n2' => 'نماینده‌های پیشرفته'];

    /** What a discount code is taken for (`discountsell.type`): MirzaBot asks for one at a purchase and a renewal alone. */
    private const SECTIONS = ['all' => [OrderType::Purchase, OrderType::Renewal], 'buy' => [OrderType::Purchase], 'extend' => [OrderType::Renewal]];

    /** Every product or panel, as a discount code names them. */
    private const EVERY_PRODUCT = 'all';
    private const EVERY_PANEL = '/all';

    // What a discount code's note says of what the shop's codes cannot (the code comes switched off).
    private const FOR_SOME = 'فقط برای %s بود.';
    private const UNUSABLE = 'در ربات قبلی برای هیچ خریدی پذیرفته نمی‌شد.';

    private const CREDIT = '%s نماینده پیشرفته در ربات قبلی با موجودی منفی هم می‌خریدند (خرید اعتباری)؛ اینجا خرید با موجودی منفی نیست.';
    private const PLANLESS = '%s سرویس به هیچ پلنی وصل نشد (سرویس دلخواه بود، یا محصولش در ربات قبلی تغییر نام داده یا حذف شده بود)؛ بدون پلن می‌آید و با تمدید، پلن می‌گیرد.';
    private const SOLD_OUT = '%s پلن در ربات قبلی «ناموجود» بود؛ خاموش می‌آید تا خودتان روشنش کنید.';
    private const EXCUSED = '%s مشتری در ربات قبلی از عضویت در کانال‌های اجباری معاف بودند؛ اینجا معاف نیستند.';
    private const CARDS_HIDDEN = '%s مشتری در ربات قبلی کارت به کارت را نمی‌دیدند؛ اینجا هر کارت روشن به همه نشان داده می‌شود، پس پیش از روشن کردن کارت‌ها دقت کنید.';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: self::KEY,
            label: 'MirzaBot',
            description: 'ربات فروش VPN تلگرامی MirzaBot. مشتری‌ها با موجودی کیف پول و سطح نمایندگی، سرویس‌هایشان روی همان پنل‌ها، محصول‌ها به‌عنوان پلن با قیمت نمایندگی، کدهای تخفیف و هدیه، کانال‌های اجباری و کارت‌های بانکی منتقل می‌شوند.',
            form: new Form(self::KEY, []),
            notes: [
                "همه نسخه‌های MirzaBot خوانده می‌شود، از نسخه‌های قدیمی 5 تا نسخه‌های \u{2068}0.x\u{2069}؛ MirzaBot نسخه‌اش را در دیتابیس نگه نمی‌دارد.",
                "فایل پشتیبان همان \u{2068}backup_تاریخ.sql\u{2069} است که ربات هر چند ساعت به کانال گزارشش می‌فرستد (نه فایل‌های zip ربات‌های نماینده کنارش)؛ خروجی phpMyAdmin هم پذیرفته می‌شود. پشتیبان zip رمزدار نسخه‌های پیش از 0.1.6 را اول باز کنید و فایل \u{2068}.sql\u{2069} داخلش را بدهید.",
                "سرویس‌های پنل‌های \u{2068}Marzban\u{2069}، \u{2068}PasarGuard\u{2069}، \u{2068}3X-UI\u{2069} و \u{2068}Rebecca\u{2069} منتقل می‌شوند؛ \u{2068}Marzneshin\u{2069}، \u{2068}Hiddify\u{2069}، \u{2068}WGDashboard\u{2069} و بقیه هنوز نه.",
                'تاریخچه پرداخت‌ها، تیکت‌ها، ربات‌های نمایندگی و تنظیمات و متن‌های ربات منتقل نمی‌شوند.',
            ],
            traits: [self::MARK => 'MB', self::VENDOR => 'https://github.com/mahdiMGF2/mirzabot'],
        );
    }

    /**
     * Only what the import uses, under MirzaBot's names since 0.9.3; a reseller bot's token (`bottype`, `bot_token`) and a
     * panel's password or API token are secrets. The history tables are staged by their ids alone (a payment with its
     * state and bot), for the counts of what stays behind; `setting.text_edit` and `config` only say how new the bot was
     * (Panels::of()).
     */
    public function tables(): array
    {
        return [
            'user' => new Table(['id', 'username', 'number', 'Balance', 'User_Status', 'agent', 'affiliates', 'register', 'pricediscount', 'bottype', 'maxbuyagent', 'expire', 'joinchannel', 'cardpayment'], secrets: ['bottype']),
            'invoice' => new Table(['id_invoice', 'id_user', 'username', 'Service_location', 'name_product', 'price_product', 'Volume', 'Service_time', 'Status', 'note', 'bottype'], secrets: ['bottype']),
            'marzban_panel' => new Table(
                [
                    'id', 'name_panel', 'code_panel', 'type', 'status', 'url_panel', 'username_panel', 'password_panel', 'linksubx', 'subvip', 'limit_panel', 'agent',
                    'hide_user', 'display_order', 'version_panel', 'priceextravolume', 'priceextratime', 'pricecustomvolume', 'pricecustomtime',
                ],
                secrets: ['password_panel'],
            ),
            'product' => new Table(['id', 'code_product', 'name_product', 'price_product', 'Volume_constraint', 'Service_time', 'Location', 'agent', 'category', 'note', 'one_buy_status', 'data_limit_reset', 'status_product', 'hide_panel', 'display_order']),
            'admin' => new Table(['id_admin', 'rule']),
            'discountsell' => new Table(['id', 'codeDiscount', 'price', 'limitDiscount', 'usedDiscount', 'agent', 'usefirst', 'useuser', 'code_product', 'code_panel', 'time', 'type']),
            'discount' => new Table(['id', 'code', 'price', 'limituse', 'limitused']),
            'channels' => new Table(['link', 'remark', 'linkjoin']),
            'card_number' => new Table(['cardnumber', 'namecard']),
            'botsaz' => new Table(['id', 'id_user', 'username', 'bot_token'], secrets: ['bot_token']),
            'payment_report' => new Table(['id', 'payment_Status', 'bottype'], secrets: ['bottype']),
            'support_message' => new Table(['id', 'Tracking']),
            'service_other' => new Table(['id']),
            'giftcodeconsumed' => new Table(['id']),
            'setting' => new Table(['text_edit']),
            'config' => new Table(['scope']),
        ];
    }

    /**
     * The line's core tables (Line::missing()), without the marks of Faoxima, its descendant (FaoximaMigrator::marked()) —
     * whose database the core then names to the owner, Faoxima's own migrator taking it.
     */
    public function recognize(Schema $schema): Recognition
    {
        $missing = Line::missing($schema);
        if ($missing !== []) {
            return Recognition::no($missing);
        }

        return FaoximaMigrator::marked($schema) ? Recognition::no([]) : Recognition::yes();
    }

    /**
     * The counts as the generators yield them. A reseller bot's customers are counted apart (`resellers`) and left out
     * of the balances and groups: they come only when the owner says so.
     */
    public function survey(Staged $staged): Survey
    {
        $panels = Panels::of($staged);
        $catalog = Catalog::of($staged, $panels);
        $people = People::of($staged, self::bought(...));
        $census = $people->census();
        $counts = array_replace(array_fill_keys(Survey::KINDS, 0), ['customers' => $census->customers, 'resellers' => $census->resellers, 'balances' => $census->balances]);

        $onPanels = [];
        $stock = 0;
        $gone = 0;
        $stranded = 0;
        $planless = 0;
        foreach ($staged->rows('invoice') as $row) {
            $standing = self::standing($row, $panels);
            if ($standing === self::STOCK) {
                $stock++;
            } elseif ($standing === self::GONE) {
                $gone++;
            } elseif ($standing === self::ON_PANEL) {
                $service = self::service($row, $catalog, $people);
                $counts[$service->test ? 'tests' : 'services']++;
                $onPanels[$service->panelKey] = ($onPanels[$service->panelKey] ?? 0) + 1;
                $stranded += $panels->hasConnector($service->panelKey) ? 0 : 1;
                $planless += !$service->test && $service->planKey === null ? 1 : 0;
            }
        }
        // The bot's own payments awaiting approval: a reseller bot's (`bottype`) credit the wallets in its files.
        $awaiting = 0;
        foreach ($staged->rows('payment_report') as $row) {
            $awaiting += strtolower(trim((string) ($row['payment_Status'] ?? ''))) === self::AWAITING && $people->reseller($row['bottype'] ?? null) === null ? 1 : 0;
        }
        // What the bot excused its own customers from, or kept from them, per customer.
        $excused = 0;
        $cardsHidden = 0;
        foreach ($staged->rows('user') as $row) {
            if ($people->customer($row)->reseller === null) {
                $excused += strtolower(trim((string) ($row['joinchannel'] ?? ''))) === self::EXCUSED_FROM_CHANNELS ? 1 : 0;
                $cardsHidden += trim((string) ($row['cardpayment'] ?? self::SEES_CARDS)) !== self::SEES_CARDS ? 1 : 0;
            }
        }

        $counts['plans'] = $catalog->count();
        $counts['discount_codes'] = iterator_count($this->discountCodes($staged));
        $counts['gift_codes'] = iterator_count($this->giftCodes($staged));
        $counts['channels'] = iterator_count($this->channels($staged));
        $counts['cards'] = iterator_count($this->cards($staged));

        $imported = $panels->imported($onPanels);
        // Advanced agents buy on credit, below a zero balance — as far as `maxbuyagent`, or without end when it is 0.
        $warnings = [
            // A customer's own percent falls on top of a code in MirzaBot, as it does here: nothing to tell.
            ...$census->warnings($census->seniorAgents > 0 ? sprintf(self::CREDIT, Persian::number($census->seniorAgents)) : null, null),
            ...array_filter([
                $catalog->seniorPrices ? Notes::SENIOR_PRICES : null,
                $planless > 0 ? sprintf(self::PLANLESS, Persian::number($planless)) : null,
                $catalog->soldOut > 0 ? sprintf(self::SOLD_OUT, Persian::number($catalog->soldOut)) : null,
                Notes::awaiting($awaiting),
                $excused > 0 && $counts['channels'] > 0 ? sprintf(self::EXCUSED, Persian::number($excused)) : null,
                $cardsHidden > 0 && $counts['cards'] > 0 ? sprintf(self::CARDS_HIDDEN, Persian::number($cardsHidden)) : null,
            ]),
            ...Notes::panels($panels->linkedThroughBot(), $panels->oldThreeXui(), $imported),
            ...$panels->hiding(),
        ];

        return new Survey(
            version: null,
            counts: $counts,
            balanceTotal: $census->balanceTotal,
            panels: $imported,
            groups: $census->surveyGroups(),
            // Those the owner's «مدیرها» would make admins here — MirzaBot's administrators alone; its Seller and support
            // staff are only a warning.
            admins: $census->admins,
            left: array_values(array_filter([
                new LeftBehind('تاریخچه پرداخت‌ها', $staged->count('payment_report')),
                new LeftBehind('تیکت‌های پشتیبانی', Notes::threads($staged)),
                new LeftBehind('تاریخچه تمدیدها و خریدهای اضافه سرویس‌ها', $staged->count('service_other')),
                new LeftBehind('ربات‌های نمایندگی', $staged->count('botsaz')),
                new LeftBehind('کیف پول مشتری‌های ربات‌های نمایندگی (در فایل‌های ربات قبلی است، نه دیتابیسش)', $census->resellers),
                new LeftBehind('کانفیگ‌های فروش دستی', $stock),
                new LeftBehind('سرویس‌های پنل‌هایی که این فروشگاه کانکتورشان را ندارد', $stranded),
                new LeftBehind('سرویس‌های پنل‌هایی که در ربات قبلی حذف شده بودند', $gone),
                new LeftBehind('قیمت‌های حجم و زمان اضافه و سرویس دلخواه پنل‌ها', $panels->withExtraPrices()),
                new LeftBehind('تنظیمات محصول‌ها (فقط برای مشتری تازه، ریست دوره‌ای حجم)', $catalog->withOptions),
                new LeftBehind('تاریخچه استفاده از کدهای تخفیف و هدیه (سقف استفاده هر مشتری از نو شمرده می‌شود)', $staged->count('giftcodeconsumed')),
            ], static fn(LeftBehind $left): bool => $left->count > 0)),
            warnings: $warnings,
        );
    }

    public function customers(Staged $staged, int $after = 0): iterable
    {
        return People::of($staged, self::bought(...))->customers($after);
    }

    /** In MirzaBot's own order (Catalog::plans()). */
    public function plans(Staged $staged, int $after = 0): iterable
    {
        return Catalog::of($staged, Panels::of($staged))->plans($after);
    }

    /** Live services whose clients are on a panel — a config sold by hand is none. */
    public function services(Staged $staged, int $after = 0): iterable
    {
        $panels = Panels::of($staged);
        $catalog = Catalog::of($staged, $panels);
        $people = People::of($staged);
        foreach ($staged->rows('invoice', $after) as $seq => $row) {
            if (self::standing($row, $panels) === self::ON_PANEL) {
                yield $seq => self::service($row, $catalog, $people);
            }
        }
    }

    public function discountCodes(Staged $staged, int $after = 0): iterable
    {
        $panels = Panels::of($staged);
        $catalog = Catalog::of($staged, $panels);
        foreach ($staged->rows('discountsell', $after) as $seq => $row) {
            $code = Cell::text($row['codeDiscount'] ?? null);
            if ($code !== null) {
                yield $seq => self::discountCode($row, $code, $panels, $catalog);
            }
        }
    }

    /**
     * Each gift code with the redemptions it has left: MirzaBot holds every code to its limit (`limituse`), so one of 0
     * is used up, never one without a limit.
     */
    public function giftCodes(Staged $staged, int $after = 0): iterable
    {
        foreach ($staged->rows('discount', $after) as $seq => $row) {
            $code = Cell::text($row['code'] ?? null);
            if ($code !== null) {
                yield $seq => new ImportedGiftCode(
                    key: $code,
                    code: $code,
                    amount: Cell::amount($row['price'] ?? null),
                    usesLeft: max(0, Cell::whole($row['limituse'] ?? null) - Cell::whole($row['limitused'] ?? null)),
                );
            }
        }
    }

    /** Each by the chat MirzaBot checked membership in (Line::channels()). */
    public function channels(Staged $staged, int $after = 0): iterable
    {
        return Line::channels($staged, $after);
    }

    /** Each card by its digits (Line::cards()). */
    public function cards(Staged $staged, int $after = 0): iterable
    {
        return Line::cards($staged, $after);
    }

    /**
     * Whether an invoice made its customer one who had bought in MirzaBot, as it tells a newcomer (its first-purchase
     * codes, its one-buy products, a referral's first purchase): one that is no test, that it delivered (any state but
     * unpaid and unsuccessful) — at any price.
     *
     * @param array<string, ?string> $row
     */
    private static function bought(array $row): bool
    {
        $status = strtolower(trim((string) ($row['Status'] ?? '')));

        return $status !== '' && !in_array($status, self::UNDELIVERED, true) && !self::isTest($row);
    }

    /**
     * Where a live service stands: a client on a panel (ON_PANEL), a config sold by hand (STOCK), on a panel MirzaBot no
     * longer has (GONE); null for one not live, or with no client name to find it by.
     *
     * @param array<string, ?string> $row
     */
    private static function standing(array $row, Panels $panels): ?string
    {
        if (!in_array(trim((string) ($row['Status'] ?? '')), self::LIVE, true) || Cell::text($row['username'] ?? null) === null) {
            return null;
        }
        $panel = Cell::text($row['Service_location'] ?? null);

        return match (true) {
            $panels->isManual($panel) => self::STOCK,
            $panels->isPanel($panel) => self::ON_PANEL,
            default => self::GONE,
        };
    }

    /**
     * A live service on a panel (standing() ON_PANEL): its client's name there is the invoice's `username` (3x-ui's
     * client email), its plan the one it was sold under (Sold::planOf()), its label the customer's own note. A test, or a
     * service its customer built (renewed at its own prices), has no plan.
     *
     * @param array<string, ?string> $row
     */
    private static function service(array $row, Catalog $catalog, People $people): ImportedService
    {
        $product = Cell::text($row['name_product'] ?? null);
        $test = self::isTest($row);
        $panel = (string) Cell::text($row['Service_location'] ?? null);

        return new ImportedService(
            key: trim((string) ($row['id_invoice'] ?? '')),
            customerKey: trim((string) ($row['id_user'] ?? '')),
            panelKey: $panel,
            remoteName: (string) Cell::text($row['username'] ?? null),
            planKey: $test || in_array($product, self::CUSTOM, true) ? null : $catalog->sold->planOf($product, $row['Volume'] ?? null, $row['Service_time'] ?? null, $panel),
            label: Cell::text($row['note'] ?? null),
            test: $test,
            reseller: $people->reseller($row['bottype'] ?? null),
        );
    }

    /**
     * A discount code, a percent off (`price`) of a product (`code_product`, or «all») on a panel (`code_panel`, or «/all»),
     * held to its limit of uses (`limitDiscount`) and of uses each (`useuser`) — one of 0 MirzaBot never took. One for a
     * single audience (`agent`) comes switched off with its note, as it would reach more customers here than there; so
     * does one MirzaBot took for nothing — an audience, a kind of order, a product or a panel it does not know or none
     * (a column an old MirzaBot added later left empty, which its checks never match).
     *
     * @param array<string, ?string> $row
     */
    private static function discountCode(array $row, string $code, Panels $panels, Catalog $catalog): ImportedDiscountCode
    {
        $enabled = true;
        $notes = [];
        $for = strtolower(trim((string) ($row['agent'] ?? '')));
        if ($for !== self::EVERYONE) {
            $enabled = false;
            $notes[] = isset(self::AUDIENCES[$for]) ? sprintf(self::FOR_SOME, self::AUDIENCES[$for]) : self::UNUSABLE;
        }
        $orderTypes = self::SECTIONS[strtolower(trim((string) ($row['type'] ?? '')))] ?? null;
        // A product or panel MirzaBot no longer has holds the code to none.
        $product = Cell::text($row['code_product'] ?? null);
        $planKeys = $product === null || strtolower($product) === self::EVERY_PRODUCT ? null : array_values(array_filter([$catalog->sold->planOfProduct($product)]));
        $panel = Cell::text($row['code_panel'] ?? null);
        $panelKeys = $panel === null || $panel === self::EVERY_PANEL ? null : array_values(array_filter([$panels->nameOf($panel)]));
        if ($orderTypes === null || $product === null || $panel === null) {
            $enabled = false;
            $notes[] = self::UNUSABLE;
        }
        $perUser = Cell::whole($row['useuser'] ?? null);

        return new ImportedDiscountCode(
            key: $code,
            code: $code,
            type: 'percent',
            value: Cell::number($row['price'] ?? null) ?? '0',
            usesLeft: $perUser > 0 ? max(0, Cell::whole($row['limitDiscount'] ?? null) - Cell::whole($row['usedDiscount'] ?? null)) : 0,
            perUser: $perUser > 0 ? $perUser : null,
            firstPurchaseOnly: trim((string) ($row['usefirst'] ?? '')) === '1',
            endsAt: Cell::moment($row['time'] ?? null),
            // A kind of order it took the code for at no time: held, should the owner switch it on, to the kinds it took codes for.
            orderTypes: $orderTypes ?? self::SECTIONS['all'],
            planKeys: $planKeys,
            panelKeys: $panelKeys,
            enabled: $enabled,
            note: $notes === [] ? null : implode(' ', array_unique($notes)),
        );
    }

    /**
     * Whether an invoice is a free test account, by its product's name in any of MirzaBot's languages.
     *
     * @param array<string, ?string> $row
     */
    private static function isTest(array $row): bool
    {
        return in_array(Cell::text($row['name_product'] ?? null), self::TESTS, true);
    }
}
