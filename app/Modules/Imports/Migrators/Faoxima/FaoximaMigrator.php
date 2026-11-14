<?php

declare(strict_types=1);

namespace App\Modules\Imports\Migrators\Faoxima;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Form;
use App\Modules\Imports\Contracts\Migrator;
use App\Modules\Imports\Records\ImportedCard;
use App\Modules\Imports\Records\ImportedChannel;
use App\Modules\Imports\Records\ImportedCustomer;
use App\Modules\Imports\Records\ImportedDiscountCode;
use App\Modules\Imports\Records\ImportedGiftCode;
use App\Modules\Imports\Records\ImportedService;
use App\Modules\Imports\Staging\Schema;
use App\Modules\Imports\Staging\Staged;
use App\Modules\Imports\Staging\Table;
use App\Modules\Imports\Survey\LeftBehind;
use App\Modules\Imports\Survey\Recognition;
use App\Modules\Imports\Survey\Survey;
use App\Modules\Imports\Survey\SurveyAdmin;
use App\Modules\Imports\Survey\SurveyGroup;
use App\Modules\Orders\Enums\OrderType;
use App\Support\BankCard;
use App\Support\Input;
use App\Support\Money;
use App\Support\Persian;
use Illuminate\Support\Carbon;

/**
 * Faoxima (https://github.com/Mmd-Amir/Faoxima), a Telegram VPN shop of PHP and MySQL descended from MirzaBot, read from
 * its tables as its table.php leaves them: its customers with their wallets and agent levels, their services on its
 * panels, its products as plans (its agents' prices paired in, Catalog), its discount and gift codes, required channels
 * and bank cards. Faoxima keeps no version in its database.
 */
final class FaoximaMigrator implements Migrator
{
    private const KEY = 'faoxima';

    /** The tables and columns every Faoxima database has — and MirzaBot's, its ancestor's, too. */
    private const CORE = [
        'user' => ['id', 'Balance', 'User_Status', 'agent'],
        'invoice' => ['id_invoice', 'id_user', 'username', 'Service_location', 'Status'],
        'marzban_panel' => ['name_panel', 'type', 'url_panel'],
    ];

    /** A table only Faoxima has: what tells it from MirzaBot (as do its panels' sign-in modes). */
    private const ITS_OWN = 'wallet_ledger';

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

    /** What Faoxima writes for a customer its admin excused from giving a phone (its spelling, and the right one). */
    private const PHONE_EXCUSED = ['confrim number by admin', 'confirm number by admin'];

    /** What Faoxima writes for a phone it has not got. */
    private const NO_PHONE = ['none', ...self::PHONE_EXCUSED];

    /** What Faoxima writes for a Telegram account without a @username. */
    private const NO_USERNAME = ['none', 'null', 'not_username'];

    /** Faoxima's full admins (`admin.rule`); its Seller and support staff are listed, never made admins here. */
    private const ADMINISTRATOR = 'administrator';

    /** A customer Faoxima's admins blocked (`User_Status`). */
    private const BLOCKED = 'block';

    /** Faoxima's agent levels (`user.agent`) and the customer groups, paying agent prices, they join. */
    private const GROUPS = ['n' => 'نماینده عادی', 'n2' => 'نماینده پیشرفته'];

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

    /** A reseller bot a customer belongs to that Faoxima no longer has. */
    private const GONE_RESELLER = 'ربات نماینده حذف‌شده';

    private const DEBTORS = '%s مشتری در ربات قبلی بدهکار بودند (جمعا %s)؛ کیف پولشان اینجا هم منفی می‌آید.';
    private const STAFF = '%s نفر از ادمین‌های ربات قبلی پشتیبان یا فروشنده بودند؛ در فهرست هستند ولی اینجا ادمین نمی‌شوند.';
    private const CREDIT = 'سقف خرید اعتباری %s نماینده (خرید با موجودی منفی) منتقل نمی‌شود.';
    private const AGENCY_ENDS = '%s نماینده در ربات قبلی تاریخ پایان نمایندگی داشتند؛ این تاریخ منتقل نمی‌شود و اینجا نماینده می‌مانند تا خودتان عوضشان کنید.';
    private const PERSONAL = '%s مشتری تخفیف شخصی دارند؛ ربات قبلی کد تخفیف را از آن‌ها نمی‌پذیرفت، ولی اینجا کدهای تخفیف برایشان هم کار می‌کند.';
    private const PLANLESS = '%s سرویس به هیچ پلنی وصل نشد (محصولش در ربات قبلی تغییر نام داده یا حذف شده بود)؛ بدون پلن می‌آید و با تمدید، پلن می‌گیرد.';
    private const AWAITING_PAYMENTS = '%s پرداخت در ربات قبلی هنوز منتظر تایید است؛ اول آن‌ها را آنجا تایید کنید و بعد پشتیبان تازه بگیرید، وگرنه این مبلغ‌ها به کیف پول آن مشتری‌ها نمی‌رسد.';
    private const LIMITED_CARDS = '%s کارت در ربات قبلی فقط به مشتری‌های انتخاب‌شده نشان داده می‌شد؛ اینجا هر کارت روشن به همه نشان داده می‌شود، پس پیش از روشن کردنشان دقت کنید.';
    private const OLD_THREE_XUI = "پنل %s با روش قدیمی (نام کاربری و رمز) به ربات قبلی وصل بود؛ کانکتور \u{2068}3X-UI\u{2069} این فروشگاه نسخه 3 به بعد را می‌خواهد و سرویس‌های پنل قدیمی‌تر منتقل نمی‌شوند.";
    private const SENIOR_PRICES = 'قیمت‌های جدای «نماینده پیشرفته» منتقل نمی‌شود: هر پلن اینجا یک قیمت نمایندگی دارد، همان قیمت «نماینده عادی» و اگر نبود، پیشرفته.';
    private const LINKS_THROUGH_BOT = 'لینک اشتراک مشتری‌های پنل %s از دامنه ربات قبلی می‌گذشت؛ با خاموش شدن آن ربات این لینک‌ها کار نمی‌کنند و مشتری‌ها باید لینک تازه را از این ربات بگیرند.';
    private const UNCHECKED_TLS = 'ربات قبلی گواهی TLS پنل‌ها را بررسی نمی‌کرد، ولی این فروشگاه بررسی می‌کند: پنلی با گواهی خودامضا یا منقضی به‌عنوان سرور تازه اضافه نمی‌شود؛ آن را در «سرورها» با «بررسی گواهی TLS» خاموش اضافه کنید و همان را برایش انتخاب کنید.';

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
                "سرویس‌های پنل‌های \u{2068}Marzban\u{2069}، \u{2068}PasarGuard\u{2069} و \u{2068}3X-UI\u{2069} منتقل می‌شوند؛ \u{2068}Rebecca\u{2069}، \u{2068}Remnawave\u{2069}، \u{2068}Guard\u{2069} و \u{2068}WGDashboard\u{2069} هنوز نه.",
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
                    'linksubx', 'version_panel', 'subvip', 'limit_panel', 'priceextravolume', 'priceextratime', 'pricecustomvolume', 'pricecustomtime',
                ],
                secrets: ['password_panel', 'api_key', 'xui_api_token'],
            ),
            'product' => new Table(['id', 'code_product', 'name_product', 'price_product', 'Volume_constraint', 'Service_time', 'Location', 'agent', 'category', 'note', 'ip_limit', 'position', 'hwid_limit', 'one_buy_status', 'data_limit_reset']),
            'admin' => new Table(['id_admin', 'rule']),
            'DiscountSell' => new Table(['id', 'codeDiscount', 'price', 'limitDiscount', 'usedDiscount', 'agent', 'usefirst', 'useuser', 'code_product', 'code_panel', 'time', 'type', 'section', 'value_type', 'target_user', 'status', 'targeting_mode', 'code_category']),
            'Discount' => new Table(['id', 'code', 'price', 'limituse', 'limitused', 'expire_at', 'status', 'target_user']),
            'channels' => new Table(['id', 'link', 'remark', 'linkjoin']),
            'card_number' => new Table(['id', 'cardnumber', 'namecard']),
            'card_whitelist' => new Table(['card_id']),
            'botsaz' => new Table(['id', 'id_user', 'username', 'bot_token'], secrets: ['bot_token']),
            'Payment_report' => new Table(['id', 'payment_Status']),
            'support_message' => new Table(['id', 'Tracking']),
            'wallet_ledger' => new Table(['id']),
            'service_other' => new Table(['id']),
            'Giftcodeconsumed' => new Table(['id']),
        ];
    }

    /** Faoxima's core tables, and a mark of Faoxima's own: a MirzaBot database has the first and not the second. */
    public function recognize(Schema $schema): Recognition
    {
        $missing = [];
        foreach (self::CORE as $table => $columns) {
            if (!$schema->has($table)) {
                $missing[] = $table;

                continue;
            }
            foreach ($columns as $column) {
                if (!$schema->hasColumns($table, $column)) {
                    $missing[] = "{$table}.{$column}";
                }
            }
        }
        if (!$schema->has(self::ITS_OWN) && !$schema->hasColumns('marzban_panel', 'pasarguard_auth_mode') && !$schema->hasColumns('marzban_panel', 'xui_api_mode')) {
            $missing[] = self::ITS_OWN;
        }

        return $missing === [] ? Recognition::yes() : Recognition::no($missing);
    }

    /**
     * The counts as the generators yield them. A reseller bot's customers are counted apart (`resellers`) and left out
     * of the balances and groups: they come only when the owner says so.
     */
    public function survey(Staged $staged): Survey
    {
        $panels = Panels::of($staged);
        $catalog = Catalog::of($staged, $panels);
        $admins = self::admins($staged);
        $resellers = self::resellers($staged);
        $buyers = self::buyers($staged);
        $counts = array_fill_keys(Survey::KINDS, 0);

        $balanceTotal = '0';
        $debtors = 0;
        $debt = '0';
        $groups = array_fill_keys(self::GROUPS, 0);
        $credit = 0;
        $agencyEnds = 0;
        $personal = 0;
        /** @var array<string, ImportedCustomer> $staff Faoxima's admins of every role who are customers too */
        $staff = [];
        foreach ($staged->rows('user') as $row) {
            $customer = self::customer($row, $admins, $resellers, $buyers);
            $counts['customers']++;
            if (array_key_exists($customer->key, $admins)) {
                $staff[$customer->key] = $customer;
            }
            if ($customer->reseller !== null) {
                $counts['resellers']++;

                continue;
            }
            if (Money::compare($customer->balance, 0) !== 0) {
                $counts['balances']++;
                $balanceTotal = Money::add($balanceTotal, $customer->balance);
            }
            if (Money::compare($customer->balance, 0) < 0) {
                $debtors++;
                $debt = Money::subtract($debt, $customer->balance);
            }
            if ($customer->group !== null) {
                $groups[$customer->group]++;
                // An agency whose end has not come yet (one past it is a customer's: no group).
                $agencyEnds += Cell::moment($row['expire'] ?? null) !== null ? 1 : 0;
            }
            // Only advanced agents buy on credit, down to `maxbuyagent` below zero.
            $credit += $customer->group === self::GROUPS['n2'] && Cell::whole($row['maxbuyagent'] ?? null) > 0 ? 1 : 0;
            $personal += $customer->discountPercent > 0 ? 1 : 0;
        }

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
                $service = self::service($row, $catalog, $resellers);
                $counts[$service->test ? 'tests' : 'services']++;
                $onPanels[$service->panelKey] = ($onPanels[$service->panelKey] ?? 0) + 1;
                $stranded += $panels->hasConnector($service->panelKey) ? 0 : 1;
                $planless += !$service->test && $service->planKey === null ? 1 : 0;
            }
        }
        $awaiting = 0;
        foreach ($staged->rows('Payment_report') as $row) {
            $awaiting += in_array(strtolower(trim((string) ($row['payment_Status'] ?? ''))), self::AWAITING, true) ? 1 : 0;
        }

        $counts['plans'] = $catalog->count();
        $counts['discount_codes'] = iterator_count($this->discountCodes($staged));
        $counts['gift_codes'] = iterator_count($this->giftCodes($staged));
        $counts['channels'] = iterator_count($this->channels($staged));
        $counts['cards'] = iterator_count($this->cards($staged));

        $imported = $panels->imported($onPanels);
        $warnings = [];
        if ($debtors > 0) {
            $warnings[] = sprintf(self::DEBTORS, Persian::number($debtors), Money::format($debt));
        }
        $helpers = count(array_filter($staff, static fn(ImportedCustomer $customer): bool => !$customer->admin));
        if ($helpers > 0) {
            $warnings[] = sprintf(self::STAFF, Persian::number($helpers));
        }
        if ($credit > 0) {
            $warnings[] = sprintf(self::CREDIT, Persian::number($credit));
        }
        if ($agencyEnds > 0) {
            $warnings[] = sprintf(self::AGENCY_ENDS, Persian::number($agencyEnds));
        }
        // Faoxima took no discount code from a customer with a percent of their own (DiscountSupport::hasEligible()).
        if ($personal > 0 && $counts['discount_codes'] > 0) {
            $warnings[] = sprintf(self::PERSONAL, Persian::number($personal));
        }
        if ($catalog->seniorPrices) {
            $warnings[] = self::SENIOR_PRICES;
        }
        if ($planless > 0) {
            $warnings[] = sprintf(self::PLANLESS, Persian::number($planless));
        }
        if ($awaiting > 0) {
            $warnings[] = sprintf(self::AWAITING_PAYMENTS, Persian::number($awaiting));
        }
        $throughBot = array_filter($panels->linkedThroughBot(), static fn(string $name): bool => isset($onPanels[$name]));
        if ($throughBot !== []) {
            $warnings[] = sprintf(self::LINKS_THROUGH_BOT, self::names($throughBot));
        }
        $oldThreeXui = $panels->oldThreeXui();
        if ($oldThreeXui !== []) {
            $warnings[] = sprintf(self::OLD_THREE_XUI, self::names($oldThreeXui));
        }
        foreach ($imported as $panel) {
            if ($panel->addable() && str_starts_with(strtolower((string) $panel->url), 'https://')) {
                $warnings[] = self::UNCHECKED_TLS;

                break;
            }
        }
        $limited = self::limitedCards($staged);
        if ($limited > 0) {
            $warnings[] = sprintf(self::LIMITED_CARDS, Persian::number($limited));
        }

        return new Survey(
            version: null,
            counts: $counts,
            balanceTotal: $balanceTotal,
            panels: $imported,
            groups: array_values(array_map(static fn(string $name, int $customers): SurveyGroup => new SurveyGroup($name, true, $customers), array_keys(array_filter($groups)), array_filter($groups))),
            // Those the owner's «مدیرها» would make admins here — Faoxima's administrators alone; its Seller and support
            // staff are only a warning.
            admins: array_values(array_map(
                static fn(ImportedCustomer $customer): SurveyAdmin => new SurveyAdmin($customer->key, $customer->telegramId, $customer->username, $admins[$customer->key]),
                array_filter($staff, static fn(ImportedCustomer $customer): bool => $customer->admin),
            )),
            left: array_values(array_filter([
                new LeftBehind('تاریخچه پرداخت‌ها', $staged->count('Payment_report')),
                new LeftBehind('تیکت‌های پشتیبانی', self::threads($staged)),
                new LeftBehind('تاریخچه کیف پول', $staged->count('wallet_ledger')),
                new LeftBehind('تاریخچه تمدیدها و خریدهای اضافه سرویس‌ها', $staged->count('service_other')),
                new LeftBehind('ربات‌های نمایندگی', $staged->count('botsaz')),
                new LeftBehind('کانفیگ‌های انبار و فروش دستی', $stock),
                new LeftBehind('سرویس‌های پنل‌هایی که این فروشگاه کانکتورشان را ندارد', $stranded),
                new LeftBehind('سرویس‌های پنل‌هایی که در ربات قبلی حذف شده بودند', $gone),
                new LeftBehind('قیمت‌های حجم و زمان اضافه و سرویس دلخواه پنل‌ها', $panels->withExtraPrices()),
                new LeftBehind('تنظیمات محصول‌ها (محدودیت دستگاه، خرید یک‌باره، ریست دوره‌ای حجم)', $catalog->withOptions),
                new LeftBehind('تاریخچه استفاده از کدهای تخفیف و هدیه', $staged->count('Giftcodeconsumed')),
            ], static fn(LeftBehind $left): bool => $left->count > 0)),
            warnings: $warnings,
        );
    }

    public function customers(Staged $staged, int $after = 0): iterable
    {
        $admins = self::admins($staged);
        $resellers = self::resellers($staged);
        $buyers = self::buyers($staged);
        foreach ($staged->rows('user', $after) as $seq => $row) {
            yield $seq => self::customer($row, $admins, $resellers, $buyers);
        }
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
        $resellers = self::resellers($staged);
        foreach ($staged->rows('invoice', $after) as $seq => $row) {
            if (self::standing($row, $panels) === self::ON_PANEL) {
                yield $seq => self::service($row, $catalog, $resellers);
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

    /** Each by the chat Faoxima checked membership in (chat()): one it could not tell comes as it was kept. */
    public function channels(Staged $staged, int $after = 0): iterable
    {
        foreach ($staged->rows('channels', $after) as $seq => $row) {
            $link = Cell::text($row['link'] ?? null);
            if ($link === null) {
                continue;
            }
            $link = self::chat($link) ?? $link;
            $invite = Cell::text($row['linkjoin'] ?? null);
            yield $seq => new ImportedChannel(
                key: $link,
                link: $link,
                title: Cell::text($row['remark'] ?? null),
                inviteLink: $invite !== null && preg_match('#^https?://#i', $invite) === 1 ? $invite : null,
            );
        }
    }

    /** Each card by its digits — every one comes switched off here, whatever Faoxima showed (ImportedCard). */
    public function cards(Staged $staged, int $after = 0): iterable
    {
        foreach ($staged->rows('card_number', $after) as $seq => $row) {
            $number = BankCard::normalize((string) ($row['cardnumber'] ?? ''));
            if ($number === '') {
                continue;
            }
            yield $seq => new ImportedCard(
                key: $number,
                number: $number,
                holder: Cell::text($row['namecard'] ?? null) ?? '',
            );
        }
    }

    /**
     * A customer: their Telegram id is their key (text that is no id comes with id 0, for the core to name), the agent
     * level their group, `affiliates` who brought them, `bottype` the reseller bot they came by. An admin only when
     * Faoxima's full admin (`administrator`): its Seller and support staff are no admins here. An agency whose end
     * (`expire`) has come is a customer's.
     *
     * @param array<string, ?string> $row
     * @param array<string, ?string> $admins
     * @param array<string, string> $resellers
     * @param array<string, true> $buyers buyers()
     */
    private static function customer(array $row, array $admins, array $resellers, array $buyers): ImportedCustomer
    {
        $key = trim((string) ($row['id'] ?? ''));
        $referrer = Cell::text($row['affiliates'] ?? null);
        $username = ltrim(trim((string) ($row['username'] ?? '')), '@');
        $phone = trim((string) ($row['number'] ?? ''));
        $agencyEnds = Cell::moment($row['expire'] ?? null);

        return new ImportedCustomer(
            key: $key,
            telegramId: Input::telegramIdOf($key) ?? 0,
            username: preg_match('/^\w{1,64}$/', $username) === 1 && !in_array(strtolower($username), self::NO_USERNAME, true) ? $username : null,
            phone: preg_match('/\d/', $phone) === 1 && !in_array(strtolower($phone), self::NO_PHONE, true) ? $phone : null,
            banned: strtolower(trim((string) ($row['User_Status'] ?? ''))) === self::BLOCKED,
            balance: Cell::amount($row['Balance'] ?? null),
            discountPercent: min(100, max(0, Cell::whole($row['pricediscount'] ?? null))),
            referrerKey: $referrer !== null && $referrer !== '0' && $referrer !== $key ? $referrer : null,
            group: $agencyEnds !== null && $agencyEnds <= Carbon::now() ? null : self::GROUPS[strtolower(trim((string) ($row['agent'] ?? '')))] ?? null,
            admin: strtolower((string) ($admins[$key] ?? '')) === self::ADMINISTRATOR,
            joinedAt: Cell::moment($row['register'] ?? null),
            reseller: self::resellerOf($row['bottype'] ?? null, $resellers),
            boughtBefore: isset($buyers[$key]),
            phoneExempt: in_array(strtolower($phone), self::PHONE_EXCUSED, true),
        );
    }

    /**
     * The customers who bought in Faoxima, by key: an invoice that is no test, of a price above 0, that Faoxima
     * delivered (any state but unpaid and unsuccessful).
     *
     * @return array<string, true>
     */
    private static function buyers(Staged $staged): array
    {
        $buyers = [];
        foreach ($staged->rows('invoice') as $row) {
            $status = strtolower(trim((string) ($row['Status'] ?? '')));
            if ($status !== '' && !in_array($status, self::UNDELIVERED, true) && Cell::text($row['name_product'] ?? null) !== self::TEST && Money::isPositive(Cell::amount($row['price_product'] ?? null))) {
                $buyers[trim((string) ($row['id_user'] ?? ''))] = true;
            }
        }

        return $buyers;
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
     * client email), its plan the product of its name — or, under a name Faoxima no longer has, the one plan of its
     * traffic and days on its panel (Catalog::planLike()) —, its label the customer's own note.
     *
     * @param array<string, ?string> $row
     * @param array<string, string> $resellers
     */
    private static function service(array $row, Catalog $catalog, array $resellers): ImportedService
    {
        $product = Cell::text($row['name_product'] ?? null);
        $test = $product === self::TEST;
        $panel = (string) Cell::text($row['Service_location'] ?? null);

        return new ImportedService(
            key: trim((string) ($row['id_invoice'] ?? '')),
            customerKey: trim((string) ($row['id_user'] ?? '')),
            panelKey: $panel,
            remoteName: (string) Cell::text($row['username'] ?? null),
            planKey: $test ? null : $catalog->planNamed($product) ?? $catalog->planLike($row['Volume'] ?? null, $row['Service_time'] ?? null, $panel),
            label: Cell::text($row['note'] ?? null),
            test: $test,
            reseller: self::resellerOf($row['bottype'] ?? null, $resellers),
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
        $plan = $catalog->planOfProduct($code);

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

    /**
     * The reseller bot a token (`bottype`) is of, by its name; null for the bot itself (no token, or «0»).
     *
     * @param array<string, string> $resellers
     */
    private static function resellerOf(?string $token, array $resellers): ?string
    {
        $token = Cell::text($token);

        return $token === null || $token === '0' ? null : $resellers[$token] ?? self::GONE_RESELLER;
    }

    /**
     * Faoxima's reseller bots (`botsaz`) by their token: each named by its @username, else its owner's id.
     *
     * @return array<string, string>
     */
    private static function resellers(Staged $staged): array
    {
        $names = [];
        foreach ($staged->all('botsaz') as $row) {
            $token = Cell::text($row['bot_token'] ?? null);
            if ($token !== null) {
                $username = Cell::text($row['username'] ?? null);
                $names[$token] = $username !== null ? '@' . ltrim($username, '@') : trim('ربات نماینده ' . Cell::text($row['id_user'] ?? null));
            }
        }

        return $names;
    }

    /**
     * Faoxima's admins by Telegram id (`id_admin`), each with their role (`rule`: administrator, Seller, support).
     *
     * @return array<string, ?string>
     */
    private static function admins(Staged $staged): array
    {
        $admins = [];
        foreach ($staged->all('admin') as $row) {
            $key = trim((string) ($row['id_admin'] ?? ''));
            if ($key !== '') {
                $admins[$key] = Cell::text($row['rule'] ?? null);
            }
        }

        return $admins;
    }

    /**
     * A channel as Faoxima checks membership in it (rx_normalize_channel_chat_id()): a -100… chat id, or an @username —
     * kept with its @, bare, or in a t.me link; null for none of those (an invite link).
     */
    private static function chat(string $link): ?string
    {
        return match (true) {
            preg_match('/^-\d{5,}$/', $link) === 1, preg_match('/^@[A-Za-z][A-Za-z0-9_]{3,31}$/', $link) === 1 => $link,
            preg_match('~^(?:https?://)?(?:www\.)?(?:t\.me|telegram\.me|telegram\.dog)/(?:s/)?([A-Za-z][A-Za-z0-9_]{3,31})/?(?:\?.*)?$~i', $link, $match) === 1 && strtolower($match[1]) !== 'joinchat' => '@' . $match[1],
            preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}$/', $link) === 1 => '@' . $link,
            default => null,
        };
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

    /**
     * Panel names for a warning, each set apart: «آلمان»، «Rebecca».
     *
     * @param list<string> $names
     */
    private static function names(array $names): string
    {
        return implode('، ', array_map(static fn(string $name): string => "«\u{2068}{$name}\u{2069}»", $names));
    }

    /** Faoxima's tickets: its support messages, one thread a tracking code. */
    private static function threads(Staged $staged): int
    {
        $threads = [];
        foreach ($staged->rows('support_message') as $seq => $row) {
            $threads[Cell::text($row['Tracking'] ?? null) ?? "#{$seq}"] = true;
        }

        return count($threads);
    }
}
