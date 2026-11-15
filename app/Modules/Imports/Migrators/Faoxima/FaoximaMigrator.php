<?php

declare(strict_types=1);

namespace App\Modules\Imports\Migrators\Faoxima;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Form;
use App\Modules\Imports\Contracts\Migrator;
use App\Modules\Imports\Migrators\MirzaBot\Cell;
use App\Modules\Imports\Migrators\MirzaBot\Line;
use App\Modules\Imports\Migrators\MirzaBot\Notes;
use App\Modules\Imports\Migrators\MirzaBot\People;
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
use App\Support\Money;
use App\Support\Persian;

/**
 * Faoxima (https://github.com/Mmd-Amir/Faoxima), a Telegram VPN shop of PHP and MySQL descended from MirzaBot, read from
 * its tables as its table.php leaves them: its customers with their wallets and agent levels, their services on its
 * panels, its products as plans (its agents' prices paired in, Catalog), its discount and gift codes, required channels
 * and bank cards. Faoxima keeps no version in its database.
 */
final class FaoximaMigrator implements Migrator
{
    private const KEY = 'faoxima';

    /** What only Faoxima has, never MirzaBot, its ancestor: a table of its own, and its panels' sign-in modes. */
    private const ITS_OWN = 'wallet_ledger';
    private const SIGN_IN_MODES = ['pasarguard_auth_mode', 'xui_api_mode'];

    /** A service whose client is on its panel (`invoice.Status`); every other is unpaid, removed or gone from its panel. */
    private const LIVE = ['active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold', 'disabled', 'disablebyadmin'];

    /** Where a live service stands (standing()): a client on its panel, a config from stock or sold by hand, or on a panel Faoxima no longer has. */
    private const ON_PANEL = 'panel';
    private const STOCK = 'stock';
    private const GONE = 'gone';

    /** A purchase Faoxima never delivered (`invoice.Status`): every other made its customer one who had bought. */
    private const UNDELIVERED = ['unpaid', 'unsuccessful'];

    /** A test account whose time is up: Faoxima's test cron took its client off the panel (cronbot/configtest.php). */
    private const ENDED_TEST = 'disabled';

    /** A card payment awaiting an admin's approval (`Payment_report.payment_Status`). */
    private const AWAITING = ['waiting', 'pending'];

    /** The product name Faoxima gives a free test account. */
    private const TEST = 'سرویس تست';

    /** Who a discount code is for (`DiscountSell.agent`): everyone, by these words or by naming every audience. */
    private const EVERYONE = ['allusers', 'all'];

    /** A discount code's audiences: customers (`f`) and the two agent levels. */
    private const AUDIENCES = ['f' => 'کاربران عادی', 'n' => 'نماینده‌های عادی', 'n2' => 'نماینده‌های پیشرفته'];

    /** What a discount code is taken for (`section`, formerly `type`); `charge`, a wallet top-up, no code here is. */
    private const SECTIONS = ['buy' => OrderType::Purchase, 'extend' => OrderType::Renewal, 'volume' => OrderType::ExtraTraffic, 'time' => OrderType::ExtraTime];

    /** A code or a switch Faoxima keeps on (`status`): blank, or this. */
    private const ON = 'active';

    // What a discount code's note says of what the shop's codes cannot (the code comes switched off for the first four).
    private const FOR_AGENTS = 'فقط برای نماینده‌ها بود.';
    private const FOR_SOME = 'فقط برای %s بود.';
    private const FOR_ONE = 'فقط برای کاربر %s بود.';
    private const TOP_UP_ONLY = 'فقط برای شارژ کیف پول بود.';
    private const TOP_UP_TOO = 'برای شارژ کیف پول هم بود.';

    private const CREDIT = 'سقف خرید اعتباری %s نماینده (خرید با موجودی منفی) منتقل نمی‌شود.';
    private const PERSONAL = '%s مشتری تخفیف شخصی دارند؛ ربات قبلی کد تخفیف را از آن‌ها نمی‌پذیرفت، ولی اینجا کدهای تخفیف برایشان هم کار می‌کند.';
    private const PLANLESS = '%s سرویس به هیچ پلنی وصل نشد (محصولش در ربات قبلی تغییر نام داده یا حذف شده بود)؛ بدون پلن می‌آید و با تمدید، پلن می‌گیرد.';
    private const LIMITED_CARDS = '%s کارت در ربات قبلی فقط به مشتری‌های انتخاب‌شده نشان داده می‌شد؛ اینجا هر کارت روشن به همه نشان داده می‌شود، پس پیش از روشن کردنشان دقت کنید.';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: self::KEY,
            label: 'Faoxima',
            description: 'ربات فروش VPN تلگرامی از نسل MirzaBot. مشتری‌ها با موجودی کیف پول و سطح نمایندگی، سرویس‌هایشان روی همان پنل‌ها، پلن‌ها با قیمت نمایندگی، کدهای تخفیف و هدیه، کانال‌های اجباری و کارت‌های بانکی منتقل می‌شوند.',
            form: new Form(self::KEY, []),
            notes: [
                'نسخه 1 به بعد Faoxima خوانده می‌شود؛ Faoxima نسخه‌اش را در دیتابیس نگه نمی‌دارد.',
                'فایل پشتیبان همان backup.zip است که ربات قبلی هر چند ساعت به کانال گزارشش می‌فرستد؛ خروجی phpMyAdmin هم پذیرفته می‌شود.',
                "سرویس‌های پنل‌های \u{2068}Marzban\u{2069}، \u{2068}PasarGuard\u{2069}، \u{2068}3X-UI\u{2069}، \u{2068}Rebecca\u{2069} و \u{2068}Remnawave\u{2069} منتقل می‌شوند؛ \u{2068}Guard\u{2069} و \u{2068}WGDashboard\u{2069} هنوز نه.",
                'تاریخچه پرداخت‌ها و کیف پول، تیکت‌ها، ربات‌های نمایندگی و تنظیمات و متن‌های ربات منتقل نمی‌شوند.',
            ],
            traits: [self::MARK => 'FX', self::VENDOR => 'https://github.com/Mmd-Amir/Faoxima'],
        );
    }

    /**
     * Only what the import uses; a reseller bot's token (`bottype`, `bot_token`) and a panel's credentials are secrets.
     * The history tables are staged by their ids alone (a payment with its state), for the counts of what stays behind.
     */
    public function tables(): array
    {
        return [
            'user' => new Table(['id', 'username', 'number', 'Balance', 'User_Status', 'agent', 'affiliates', 'register', 'pricediscount', 'bottype', 'maxbuyagent', 'expire'], secrets: ['bottype']),
            'invoice' => new Table(['id_invoice', 'id_user', 'username', 'Service_location', 'name_product', 'price_product', 'Volume', 'Service_time', 'Status', 'note', 'bottype', 'source_panel_code'], secrets: ['bottype']),
            'marzban_panel' => new Table(
                [
                    'name_panel', 'code_panel', 'type', 'status', 'url_panel', 'username_panel', 'password_panel', 'api_key', 'pasarguard_auth_mode', 'xui_api_token', 'xui_api_mode',
                    'remna_api_token', 'linksubx', 'version_panel', 'subvip', 'limit_panel', 'priceextravolume', 'priceextratime', 'pricecustomvolume', 'pricecustomtime',
                ],
                secrets: ['password_panel', 'api_key', 'xui_api_token', 'remna_api_token'],
            ),
            'product' => new Table(['id', 'code_product', 'name_product', 'price_product', 'Volume_constraint', 'Service_time', 'Location', 'agent', 'category', 'note', 'ip_limit', 'position', 'hwid_limit', 'one_buy_status', 'data_limit_reset']),
            'admin' => new Table(['id_admin', 'rule']),
            'DiscountSell' => new Table(['id', 'codeDiscount', 'price', 'limitDiscount', 'usedDiscount', 'agent', 'usefirst', 'useuser', 'code_product', 'code_panel', 'time', 'type', 'section', 'value_type', 'target_user', 'status', 'targeting_mode', 'code_category']),
            'Discount' => new Table(['id', 'code', 'price', 'limituse', 'limitused', 'expire_at', 'status', 'target_user']),
            'channels' => new Table(['id', 'link', 'remark', 'linkjoin']),
            'card_number' => new Table(['id', 'cardnumber', 'namecard']),
            'card_whitelist' => new Table(['card_id']),
            'botsaz' => new Table(['id', 'id_user', 'username', 'bot_token'], secrets: ['bot_token']),
            'Payment_report' => new Table(['id', 'payment_Status', 'bottype'], secrets: ['bottype']),
            'support_message' => new Table(['id', 'Tracking']),
            'wallet_ledger' => new Table(['id']),
            'service_other' => new Table(['id']),
            'Giftcodeconsumed' => new Table(['id']),
        ];
    }

    /** The line's core tables (Line::missing()), and Faoxima's marks: a MirzaBot database has the first and not the second. */
    public function recognize(Schema $schema): Recognition
    {
        $missing = Line::missing($schema);
        if (!self::marked($schema)) {
            $missing[] = self::ITS_OWN;
        }

        return $missing === [] ? Recognition::yes() : Recognition::no($missing);
    }

    /**
     * Whether a database bears a mark of Faoxima's own — its own table, or its panels' sign-in modes —, which tells it
     * from MirzaBot's (whose recognize() asks this too).
     */
    public static function marked(Schema $schema): bool
    {
        return $schema->has(self::ITS_OWN) || array_filter(self::SIGN_IN_MODES, static fn(string $column): bool => $schema->hasColumns('marzban_panel', $column)) !== [];
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
        foreach ($staged->rows('Payment_report') as $row) {
            $awaiting += in_array(strtolower(trim((string) ($row['payment_Status'] ?? ''))), self::AWAITING, true) && $people->reseller($row['bottype'] ?? null) === null ? 1 : 0;
        }

        $counts['plans'] = $catalog->count();
        $codes = iterator_to_array($this->discountCodes($staged), false);
        $counts['discount_codes'] = count($codes);
        $counts['gift_codes'] = iterator_count($this->giftCodes($staged));
        $counts['channels'] = iterator_count($this->channels($staged));
        $counts['cards'] = iterator_count($this->cards($staged));

        $imported = $panels->imported($onPanels);
        // Only advanced agents buy on credit, down to `maxbuyagent` below zero; a limit is what does not come.
        // Faoxima took no discount code from a customer with a percent of their own (DiscountSupport::hasEligible()): told
        // when a code that comes would work for them here.
        $coming = array_filter($codes, static fn(ImportedDiscountCode $code): bool => !$code->usedUp() && !$code->ended());
        $limited = self::limitedCards($staged);
        $warnings = [
            ...$census->warnings(
                $census->creditCaps > 0 ? sprintf(self::CREDIT, Persian::number($census->creditCaps)) : null,
                $census->personal > 0 && $coming !== [] ? sprintf(self::PERSONAL, Persian::number($census->personal)) : null,
            ),
            ...array_filter([
                $catalog->seniorPrices ? Notes::SENIOR_PRICES : null,
                $planless > 0 ? sprintf(self::PLANLESS, Persian::number($planless)) : null,
                Notes::awaiting($awaiting),
            ]),
            ...Notes::panels($panels->linkedThroughBot(), $panels->oldThreeXui(), $imported),
            ...($limited > 0 ? [sprintf(self::LIMITED_CARDS, Persian::number($limited))] : []),
        ];

        return new Survey(
            version: null,
            counts: $counts,
            balanceTotal: $census->balanceTotal,
            panels: $imported,
            groups: $census->surveyGroups(),
            // Those the owner's «مدیرها» would make admins here — Faoxima's administrators alone; its Seller and support
            // staff are only a warning.
            admins: $census->admins,
            left: array_values(array_filter([
                new LeftBehind('تاریخچه پرداخت‌ها', $staged->count('Payment_report')),
                new LeftBehind('تیکت‌های پشتیبانی', Notes::threads($staged)),
                new LeftBehind('تاریخچه کیف پول', $staged->count('wallet_ledger')),
                new LeftBehind('تاریخچه تمدیدها و خریدهای اضافه سرویس‌ها', $staged->count('service_other')),
                new LeftBehind('ربات‌های نمایندگی', $staged->count('botsaz')),
                new LeftBehind('کیف پول مشتری‌های ربات‌های نمایندگی (در فایل‌های ربات قبلی است، نه دیتابیسش)', $census->resellers),
                new LeftBehind('کانفیگ‌های انبار و فروش دستی', $stock),
                new LeftBehind('سرویس‌های پنل‌هایی که این فروشگاه کانکتورشان را ندارد', $stranded),
                new LeftBehind('سرویس‌های پنل‌هایی که در ربات قبلی حذف شده بودند', $gone),
                new LeftBehind('قیمت‌های حجم و زمان اضافه و سرویس دلخواه پنل‌ها', $panels->withExtraPrices()),
                new LeftBehind('تنظیمات محصول‌ها (محدودیت دستگاه، خرید یک‌باره، ریست دوره‌ای حجم)', $catalog->withOptions),
                new LeftBehind('تاریخچه استفاده از کدهای تخفیف و هدیه (سقف استفاده هر مشتری از نو شمرده می‌شود)', $staged->count('Giftcodeconsumed')),
            ], static fn(LeftBehind $left): bool => $left->count > 0)),
            warnings: $warnings,
        );
    }

    public function customers(Staged $staged, int $after = 0): iterable
    {
        return People::of($staged, self::bought(...))->customers($after);
    }

    /** In Faoxima's own order (Catalog::plans()). */
    public function plans(Staged $staged, int $after = 0): iterable
    {
        return Catalog::of($staged, Panels::of($staged))->plans($after);
    }

    /** Live services whose clients are on a panel — a config from stock or sold by hand is none. */
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
        foreach ($staged->rows('DiscountSell', $after) as $seq => $row) {
            $code = Cell::text($row['codeDiscount'] ?? null);
            if ($code !== null) {
                yield $seq => self::discountCode($row, $code, $panels, $catalog);
            }
        }
    }

    /** A gift code Faoxima kept for one customer (`target_user`) names them: the shop's gift codes are anyone's. */
    public function giftCodes(Staged $staged, int $after = 0): iterable
    {
        foreach ($staged->rows('Discount', $after) as $seq => $row) {
            $code = Cell::text($row['code'] ?? null);
            if ($code === null) {
                continue;
            }
            $uses = Cell::whole($row['limituse'] ?? null);
            yield $seq => new ImportedGiftCode(
                key: $code,
                code: $code,
                amount: Cell::amount($row['price'] ?? null),
                usesLeft: $uses > 0 ? max(0, $uses - Cell::whole($row['limitused'] ?? null)) : null,
                expiresAt: Cell::moment($row['expire_at'] ?? null),
                enabled: self::on($row['status'] ?? null),
                forCustomer: self::forOne($row['target_user'] ?? null),
            );
        }
    }

    /** Each by the chat Faoxima checked membership in (Line::channels()). */
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
     * Whether an invoice made its customer one who had bought in Faoxima: one that is no test, of a price above 0, that
     * Faoxima delivered (any state but unpaid and unsuccessful).
     *
     * @param array<string, ?string> $row
     */
    private static function bought(array $row): bool
    {
        $status = strtolower(trim((string) ($row['Status'] ?? '')));

        return $status !== '' && !in_array($status, self::UNDELIVERED, true) && Cell::text($row['name_product'] ?? null) !== self::TEST && Money::isPositive(Cell::amount($row['price_product'] ?? null));
    }

    /**
     * Where a live service stands: a client on a panel (ON_PANEL), a config from stock (`source_panel_code`, Faoxima's
     * «شبکه ملی» stock) or sold by hand (STOCK), on a panel Faoxima no longer has (GONE); null for one not live — a test
     * whose time is up among them —, or with no client name to find it by.
     *
     * @param array<string, ?string> $row
     */
    private static function standing(array $row, Panels $panels): ?string
    {
        $status = trim((string) ($row['Status'] ?? ''));
        if (!in_array($status, self::LIVE, true) || Cell::text($row['username'] ?? null) === null) {
            return null;
        }
        if ($status === self::ENDED_TEST && Cell::text($row['name_product'] ?? null) === self::TEST) {
            return null;
        }
        $panel = Cell::text($row['Service_location'] ?? null);

        return match (true) {
            Cell::text($row['source_panel_code'] ?? null) !== null, $panels->isManual($panel) => self::STOCK,
            $panels->isPanel($panel) => self::ON_PANEL,
            default => self::GONE,
        };
    }

    /**
     * A live service on a panel (standing() ON_PANEL): its client's name there is the invoice's `username` (3x-ui's
     * client email), its plan the one it was sold under (Sold::planOf()) — none for a test —, its label the customer's own
     * note.
     *
     * @param array<string, ?string> $row
     */
    private static function service(array $row, Catalog $catalog, People $people): ImportedService
    {
        $product = Cell::text($row['name_product'] ?? null);
        $test = $product === self::TEST;
        $panel = (string) Cell::text($row['Service_location'] ?? null);

        return new ImportedService(
            key: trim((string) ($row['id_invoice'] ?? '')),
            customerKey: trim((string) ($row['id_user'] ?? '')),
            panelKey: $panel,
            remoteName: (string) Cell::text($row['username'] ?? null),
            planKey: $test ? null : $catalog->sold->planOf($product, $row['Volume'] ?? null, $row['Service_time'] ?? null, $panel),
            label: Cell::text($row['note'] ?? null),
            test: $test,
            reseller: $people->reseller($row['bottype'] ?? null),
        );
    }

    /**
     * A purchase code. Faoxima scopes it by product and panel (targeting `none`), by panels, or by categories (the
     * plans in them); what this shop's codes cannot say comes in its note, the code switched off when it would reach
     * more customers here than there (one for some audiences — customers alone, agents alone —, or for one customer).
     *
     * @param array<string, ?string> $row
     */
    private static function discountCode(array $row, string $code, Panels $panels, Catalog $catalog): ImportedDiscountCode
    {
        $enabled = self::on($row['status'] ?? null);
        $notes = [];
        $for = array_map(strtolower(...), Cell::list($row['agent'] ?? null));
        if ($for !== [] && array_intersect($for, self::EVERYONE) === [] && array_diff(array_keys(self::AUDIENCES), $for) !== []) {
            $enabled = false;
            $notes[] = in_array('f', $for, true) ? sprintf(self::FOR_SOME, implode(' و ', array_intersect_key(self::AUDIENCES, array_flip($for)))) : self::FOR_AGENTS;
        }
        $one = self::forOne($row['target_user'] ?? null);
        if ($one !== null) {
            $enabled = false;
            $notes[] = sprintf(self::FOR_ONE, $one);
        }

        $orderTypes = null;
        $sections = array_map(strtolower(...), Cell::list(Cell::text($row['section'] ?? null) ?? $row['type'] ?? null));
        if ($sections !== [] && !in_array('all', $sections, true)) {
            $orderTypes = array_values(array_filter(array_map(static fn(string $section): ?OrderType => self::SECTIONS[$section] ?? null, $sections)));
            if (in_array('charge', $sections, true)) {
                $notes[] = $orderTypes === [] ? self::TOP_UP_ONLY : self::TOP_UP_TOO;
            }
            if ($orderTypes === []) {
                $orderTypes = null;
                $enabled = false;
            }
        }

        $type = match (strtolower(trim((string) ($row['value_type'] ?? '')))) {
            'amount' => 'fixed',
            'free' => 'free',
            default => 'percent',
        };
        [$planKeys, $panelKeys] = match (strtolower(trim((string) ($row['targeting_mode'] ?? '')))) {
            'panel' => [null, self::panelsOf($row['code_panel'] ?? null, $panels)],
            'category' => [self::categoryPlans($row['code_category'] ?? null, $catalog), null],
            default => [self::productPlans($row['code_product'] ?? null, $catalog), self::panelsOf($row['code_panel'] ?? null, $panels)],
        };
        $uses = Cell::whole($row['limitDiscount'] ?? null);
        $perUser = Cell::whole($row['useuser'] ?? null);

        return new ImportedDiscountCode(
            key: $code,
            code: $code,
            type: $type,
            value: $type === 'free' ? '0' : Cell::number($row['price'] ?? null) ?? '0',
            usesLeft: $uses > 0 ? max(0, $uses - Cell::whole($row['usedDiscount'] ?? null)) : null,
            perUser: $perUser > 0 ? $perUser : null,
            firstPurchaseOnly: trim((string) ($row['usefirst'] ?? '')) === '1',
            endsAt: Cell::moment($row['time'] ?? null),
            orderTypes: $orderTypes,
            planKeys: $planKeys,
            panelKeys: $panelKeys,
            enabled: $enabled,
            note: $notes === [] ? null : implode(' ', $notes),
        );
    }

    /**
     * The panels a discount code names (`code_panel`: their codes, or «/all»); null for every one. A code Faoxima no
     * longer has a panel of is none.
     *
     * @return list<string>|null
     */
    private static function panelsOf(?string $value, Panels $panels): ?array
    {
        $codes = Cell::list($value);
        if ($codes === [] || in_array('/all', $codes, true)) {
            return null;
        }

        return array_values(array_unique(array_filter(array_map(static fn(string $code): ?string => $panels->nameOf($code) ?? ($panels->isPanel($code) ? $code : null), $codes))));
    }

    /**
     * The plans in the categories a discount code names (`code_category`, or «all»); null for every one.
     *
     * @return list<string>|null
     */
    private static function categoryPlans(?string $value, Catalog $catalog): ?array
    {
        $categories = Cell::list($value);

        return $categories === [] || in_array('all', $categories, true) ? null : $catalog->plansIn($categories);
    }

    /**
     * The plan of the product a discount code names (`code_product`, or «all»); null for every one.
     *
     * @return list<string>|null
     */
    private static function productPlans(?string $value, Catalog $catalog): ?array
    {
        $code = Cell::text($value);
        if ($code === null || in_array(strtolower($code), ['all', '0'], true)) {
            return null;
        }
        $plan = $catalog->sold->planOfProduct($code);

        return $plan === null ? [] : [$plan];
    }

    /** Whether Faoxima keeps a code on (`status` blank or active; «expired» and the like are off). */
    private static function on(?string $status): bool
    {
        return in_array(strtolower(trim((string) $status)), ['', self::ON], true);
    }

    /** The customer a code is only for (`target_user`, a Telegram id); null for anyone. */
    private static function forOne(?string $target): ?string
    {
        $target = Cell::text($target);

        return $target === null || $target === '0' ? null : $target;
    }

    /** How many of Faoxima's cards it showed only to the customers listed for them (`card_whitelist`). */
    private static function limitedCards(Staged $staged): int
    {
        $limited = [];
        foreach ($staged->rows('card_whitelist') as $row) {
            $limited[(string) Cell::text($row['card_id'] ?? null)] = true;
        }

        return count(array_filter($staged->all('card_number'), static fn(array $row): bool => isset($limited[(string) Cell::text($row['id'] ?? null)])));
    }
}
