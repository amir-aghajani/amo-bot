<?php

declare(strict_types=1);

namespace Tests\Unit\Imports\Faoxima;

use App\Modules\Imports\Migrators\Faoxima\FaoximaMigrator;
use App\Modules\Imports\Records\ImportedCard;
use App\Modules\Imports\Records\ImportedChannel;
use App\Modules\Imports\Records\ImportedCustomer;
use App\Modules\Imports\Records\ImportedDiscountCode;
use App\Modules\Imports\Records\ImportedGiftCode;
use App\Modules\Imports\Records\ImportedPanel;
use App\Modules\Imports\Records\ImportedPlan;
use App\Modules\Imports\Records\ImportedService;
use App\Modules\Imports\Staging\Schema;
use App\Modules\Imports\Staging\Table;
use App\Modules\Imports\Survey\LeftBehind;
use App\Modules\Imports\Survey\SurveyAdmin;
use App\Modules\Imports\Survey\SurveyGroup;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Providers\ProviderRegistry;
use Tests\Fakes\ArrayStaged;
use Tests\TestCase;

/**
 * The Faoxima migrator against a staged copy shaped as Faoxima's table.php leaves its database: what it stages, how it
 * tells Faoxima from MirzaBot, the survey (counts, panels with their connectors and connections — each one its
 * connector's form takes —, groups, admins, what stays behind, warnings), every generator's records and their edge
 * cases, and resuming after a seq.
 */
final class FaoximaMigratorTest extends TestCase
{
    private const PG_KEY = 'pg_key_6f1c2a3b-4d5e-4f60-8a1b-2c3d4e5f6a7b';

    public function testItsCardNamesTheBotAndAsksNothing(): void
    {
        $card = (new FaoximaMigrator())->describe()->toArray();

        self::assertSame(['faoxima', 'Faoxima', []], [$card['key'], $card['label'], $card['fields']]);
        self::assertEquals((object) ['mark' => 'FX', 'vendor' => 'https://github.com/Mmd-Amir/Faoxima'], $card['traits']);
        self::assertNotEmpty(array_filter($card['notes'], static fn(string $note): bool => str_contains($note, 'backup.zip')), 'where its backup is');
        self::assertDoesNotMatchRegularExpression('/[\x{064B}-\x{0652}\x{0654}]/u', $card['description'] . implode('', $card['notes']), 'no diacritics');
    }

    public function testItStagesWhatItReadsWithTheSecretsMarked(): void
    {
        $tables = (new FaoximaMigrator())->tables();

        self::assertSame(['password_panel', 'api_key', 'xui_api_token'], $tables['marzban_panel']->secrets);
        self::assertSame(['bottype'], $tables['user']->secrets, 'a reseller bot\'s token');
        self::assertSame(['bottype'], $tables['invoice']->secrets);
        self::assertSame(['bot_token'], $tables['botsaz']->secrets);
        self::assertSame(['id_admin', 'rule'], $tables['admin']->columns, 'never an admin\'s web password');
        self::assertSame(['id', 'payment_Status'], $tables['Payment_report']->columns, 'history is counted, not read: only whether a payment still awaits approval');
        self::assertArrayNotHasKey('textbot', $tables, 'its texts say nothing to the owner');
        self::assertArrayHasKey('DiscountSell', $tables, 'Faoxima\'s mixed-case names, as they are');
    }

    public function testItTakesFaoximasDatabaseAndNotMirzaBotsItsAncestor(): void
    {
        $migrator = new FaoximaMigrator();
        $core = [
            'user' => ['id', 'limit_usertest', 'username', 'number', 'Balance', 'User_Status', 'agent', 'affiliates'],
            'invoice' => ['id_invoice', 'id_user', 'username', 'Service_location', 'name_product', 'Status'],
            'marzban_panel' => ['id', 'name_panel', 'type', 'url_panel', 'username_panel', 'password_panel'],
        ];

        $faoxima = $migrator->recognize(new Schema($core + ['wallet_ledger' => ['id', 'id_user', 'amount']]));
        self::assertTrue($faoxima->ok);
        self::assertNull($faoxima->version, 'Faoxima keeps no version in its database');

        $marked = $core;
        $marked['marzban_panel'][] = 'xui_api_mode';
        self::assertTrue($migrator->recognize(new Schema($marked))->ok, 'its panels\' sign-in modes mark it too');

        $mirza = $migrator->recognize(new Schema($core + ['Payment_report' => ['id']]));
        self::assertFalse($mirza->ok);
        self::assertSame(['wallet_ledger'], $mirza->missing);

        $other = $migrator->recognize(new Schema(['user' => ['id', 'Balance'], 'wallet_ledger' => ['id']]));
        self::assertSame(['user.User_Status', 'user.agent', 'invoice', 'marzban_panel'], $other->missing);
    }

    public function testCustomersComeWithTheirWalletsLevelsReferrersAndResellerBots(): void
    {
        $customers = self::byKey((new FaoximaMigrator())->customers(self::staged()));

        self::assertSame(['111111111', '222222222', '333333333', '444444444', '555555555', 'abc', '777777777', '666666666', '999999999'], array_map(strval(...), array_keys($customers)));
        self::assertEquals(new ImportedCustomer(
            key: '111111111',
            telegramId: 111111111,
            username: 'ali_shop',
            phone: '+989121234567',
            balance: '50000.00',
            discountPercent: 10,
            joinedAt: new \DateTimeImmutable('@1700000000'),
            boughtBefore: true,
        ), $customers['111111111']);

        $agent = $customers['222222222'];
        self::assertSame([null, null, true, '-30000.00', 'نماینده پیشرفته', '111111111'], [$agent->username, $agent->phone, $agent->phoneExempt, $agent->balance, $agent->group, $agent->referrerKey], 'no @username, excused from a phone by an admin, in debt');
        self::assertFalse($customers['444444444']->phoneExempt, 'no phone is no excuse');

        $banned = $customers['333333333'];
        self::assertSame([true, 'banned_guy', '989351112233', 'نماینده عادی'], [$banned->banned, $banned->username, $banned->phone, $banned->group], 'an agency whose end is still to come');
        self::assertNull($customers['666666666']->group, 'an agency past its end: a customer');

        self::assertSame('@agent_shop_bot', $customers['444444444']->reseller, 'a reseller bot by its @username');
        self::assertSame('ربات نماینده حذف‌شده', $customers['555555555']->reseller, 'a token of a reseller bot Faoxima no longer has');
        self::assertSame(0, $customers['abc']->telegramId, 'no Telegram id: the core names it');

        $odd = $customers['777777777'];
        self::assertSame([null, null, 100, null], [$odd->referrerKey, $odd->joinedAt, $odd->discountPercent, $odd->reseller], 'brought by themselves, a legacy «none» join date, a percent past 100, «0» for the bot itself');

        self::assertTrue($customers['999999999']->admin, 'Faoxima\'s administrator');
        self::assertFalse($customers['333333333']->admin, 'its support staff: listed, never made an admin here');
        self::assertFalse($customers['111111111']->admin);

        self::assertSame(
            ['111111111', '222222222', '444444444', '777777777', '999999999'],
            array_map(strval(...), array_keys(array_filter($customers, static fn(ImportedCustomer $customer): bool => $customer->boughtBefore))),
            'a purchase delivered, paid for: not a test, not unpaid; a manual sale too',
        );
    }

    public function testPlansPairAgentsPricesIntoTheCustomersOnes(): void
    {
        $plans = self::byKey((new FaoximaMigrator())->plans(self::staged()));

        self::assertSame(['p1', 'p2', 'p3', 'p4'], array_keys($plans), 'an agents\' twin is its plan\'s agent price, no plan of its own');
        self::assertEquals(new ImportedPlan(
            key: 'p1',
            name: 'یک ماهه ۳۰ گیگ',
            category: 'ماهانه',
            price: '150000.00',
            agentPrice: '120000.00',
            trafficGb: '30',
            durationDays: 30,
            ipLimit: 2,
            note: 'بدون محدودیت کاربر',
            panelKeys: null,
        ), $plans['p1'], 'the plain agents\' price over the advanced ones\'');

        self::assertSame(['350000.00', ['آلمان ۱', 'هلند']], [$plans['p2']->agentPrice, $plans['p2']->panelKeys], 'twins on the same panels in another order; only an advanced agents\' price');
        self::assertSame(['1000000.00', '0', 0, [], null], [$plans['p3']->price, $plans['p3']->trafficGb, $plans['p3']->durationDays, $plans['p3']->panelKeys, $plans['p3']->agentPrice], 'unlimited, on a panel Faoxima no longer has');
        self::assertSame([true, '90000.00', null, ['PG']], [$plans['p4']->agentsOnly, $plans['p4']->price, $plans['p4']->agentPrice, $plans['p4']->panelKeys], 'an agents\' product with no twin');
    }

    public function testAProductCodeThatRepeatsKeysTheSecondByItsId(): void
    {
        $staged = new ArrayStaged(['product' => [
            ['id' => '8', 'code_product' => 'ab12', 'name_product' => 'یک', 'price_product' => '1000', 'Volume_constraint' => '1', 'Service_time' => '1', 'Location' => '/all', 'agent' => 'f'],
            ['id' => '9', 'code_product' => 'ab12', 'name_product' => 'دو', 'price_product' => '2000', 'Volume_constraint' => '2', 'Service_time' => '2', 'Location' => '/all', 'agent' => 'f'],
        ]]);

        self::assertSame(['ab12', '#9'], array_keys(self::byKey((new FaoximaMigrator())->plans($staged))));
    }

    public function testPlansComeInFaoximasOwnOrderKeyedByTheirPlaceInIt(): void
    {
        $product = static fn(string $id, string $position): array => ['id' => $id, 'code_product' => "c{$id}", 'name_product' => "پلن {$id}", 'price_product' => '1000', 'Volume_constraint' => $id, 'Service_time' => '30', 'Location' => '/all', 'agent' => 'f', 'position' => $position];
        $staged = new ArrayStaged(['product' => [$product('1', '0'), $product('2', '2'), $product('4', '1'), $product('3', '1')]]);
        $migrator = new FaoximaMigrator();

        $plans = iterator_to_array($migrator->plans($staged));
        self::assertSame([1, 2, 3, 4], array_keys($plans), 'its place, not its row\'s seq');
        self::assertSame(['c3', 'c4', 'c2', 'c1'], array_map(static fn(ImportedPlan $plan): string => $plan->key, array_values($plans)), 'by position, then id; none (0) last');
        self::assertSame(['c2', 'c1'], array_map(static fn(ImportedPlan $plan): string => $plan->key, array_values(iterator_to_array($migrator->plans($staged, 2)))));
    }

    public function testServicesAreTheLiveClientsOnItsPanels(): void
    {
        $services = self::byKey((new FaoximaMigrator())->services(self::staged()));

        self::assertSame(['inv1', 'inv2', 'inv3', 'inv8', 'inv9', 'inv11', 'inv13', 'inv14'], array_keys($services), 'not removed, unpaid, a test whose time is up, from stock, sold by hand or on a panel Faoxima no longer has');
        self::assertEquals(new ImportedService(key: 'inv1', customerKey: '111111111', panelKey: 'آلمان ۱', remoteName: 'ali_1', planKey: 'p1', label: 'گوشی'), $services['inv1']);
        self::assertSame('p1', $services['inv2']->planKey, 'bought under the agents\' twin: its plan');
        self::assertSame([true, null], [$services['inv3']->test, $services['inv3']->planKey]);
        self::assertSame(['@agent_shop_bot', 'p2'], [$services['inv8']->reseller, $services['inv8']->planKey]);
        self::assertSame('ربکا', $services['inv9']->panelKey, 'on a panel no connector speaks to: the core leaves it, by its panel');
        self::assertNull($services['inv11']->planKey, 'a custom service of no product');
        self::assertSame('p1', $services['inv13']->planKey, 'a renamed product: the one plan of its traffic and days on its panel (the agents\' twins are that plan)');
        self::assertNull($services['inv14']->planKey, 'no product of its traffic and days');
    }

    public function testDiscountCodesKeepTheirScopeAndSayWhatTheShopsCannot(): void
    {
        $codes = self::byKey((new FaoximaMigrator())->discountCodes(self::staged()));

        self::assertEquals(new ImportedDiscountCode(
            key: 'SUMMER20',
            code: 'SUMMER20',
            type: 'percent',
            value: '20',
            usesLeft: 70,
            perUser: 1,
            orderTypes: [OrderType::Purchase, OrderType::Renewal],
        ), $codes['SUMMER20'], 'by its code: another bot\'s row #1 is no other code');

        $fixed = $codes['FIX50'];
        self::assertSame(['fixed', '50000', null, null, ['p1'], ['آلمان ۱'], true], [$fixed->type, $fixed->value, $fixed->usesLeft, $fixed->orderTypes, $fixed->planKeys, $fixed->panelKeys, $fixed->enabled], 'a product (its plan) on a panel (by its code); every audience named is everyone');
        self::assertEquals(new \DateTimeImmutable('@2000000000'), $fixed->endsAt);

        $agents = $codes['AGENTS'];
        self::assertSame(['free', '0', null, ['هلند'], false, 'فقط برای نماینده‌ها بود.'], [$agents->type, $agents->value, $agents->planKeys, $agents->panelKeys, $agents->enabled, $agents->note], 'a panel code Faoxima no longer has is none');

        $topUp = $codes['TOPUP'];
        self::assertSame([null, false, 'فقط برای شارژ کیف پول بود.'], [$topUp->orderTypes, $topUp->enabled, $topUp->note]);

        $mixed = $codes['MIX'];
        self::assertSame([[OrderType::ExtraTraffic], ['p1'], null, false, 'فقط برای کاربر 111111111 بود. برای شارژ کیف پول هم بود.'], [$mixed->orderTypes, $mixed->planKeys, $mixed->panelKeys, $mixed->enabled, $mixed->note], 'a category\'s plans; expired; for one customer');

        $legacy = $codes['OLD'];
        self::assertSame([[OrderType::Renewal], true, 'percent'], [$legacy->orderTypes, $legacy->firstPurchaseOnly, $legacy->type], 'the old `type` column for its sections');

        self::assertSame([false, 'فقط برای کاربران عادی بود.'], [$codes['ONLYF']->enabled, $codes['ONLYF']->note], 'customers alone («فقط کاربر عادی»): here it would reach agents too');
        self::assertSame([false, 'فقط برای کاربران عادی و نماینده‌های عادی بود.'], [$codes['PLAIN']->enabled, $codes['PLAIN']->note]);
    }

    public function testGiftCodesComeWithTheUsesTheyHaveLeft(): void
    {
        $gifts = self::byKey((new FaoximaMigrator())->giftCodes(self::staged()));

        self::assertEquals(new ImportedGiftCode(key: 'GIFT1', code: 'GIFT1', amount: '25000.00', usesLeft: 1), $gifts['GIFT1'], 'by its code');
        self::assertSame(0, $gifts['GIFT2']->usesLeft);
        self::assertSame([null, true], [$gifts['GIFT3']->usesLeft, $gifts['GIFT3']->enabled]);
        self::assertEquals(new \DateTimeImmutable('@1700000000'), $gifts['GIFT3']->expiresAt);
        self::assertSame([false, null], [$gifts['GIFT4']->enabled, $gifts['GIFT4']->forCustomer], 'expired; a blank target is anyone');
        self::assertSame([true, '222222222'], [$gifts['GIFT5']->enabled, $gifts['GIFT5']->forCustomer], 'kept for one customer: named, for the core to report');
    }

    public function testChannelsAndCards(): void
    {
        $migrator = new FaoximaMigrator();

        self::assertEquals([
            new ImportedChannel('@my_channel', '@my_channel', 'کانال ما', 'https://t.me/my_channel'),
            new ImportedChannel('-1001234567890', '-1001234567890', 'گروه', 'https://t.me/+AbCdEf'),
            new ImportedChannel('@news_channel', '@news_channel'),
            new ImportedChannel('@vpn_news', '@vpn_news', 'اخبار', 'https://t.me/vpn_news'),
            new ImportedChannel('https://t.me/joinchat/AbCdEf123', 'https://t.me/joinchat/AbCdEf123', 'خصوصی', 'https://t.me/joinchat/AbCdEf123'),
        ], array_values(iterator_to_array($migrator->channels(self::staged()))), 'by the chat Faoxima checked: a bare username and a t.me link its @username; an invite link as it was');

        self::assertEquals([
            new ImportedCard('6037997700001119', '6037997700001119', 'علی رضایی'),
            new ImportedCard('6219861012345675', '6219861012345675', 'بانک ملی'),
            new ImportedCard('5022291012345678', '5022291012345678', 'رضا'),
        ], array_values(iterator_to_array($migrator->cards(self::staged()))), 'by their digits however typed; switched off, or hidden from customers');
    }

    public function testTheSurveyCountsWhatComesAndWhatStays(): void
    {
        $survey = (new FaoximaMigrator())->survey(self::staged());

        self::assertNull($survey->version);
        self::assertSame(
            ['customers' => 9, 'balances' => 3, 'services' => 7, 'tests' => 1, 'plans' => 4, 'discount_codes' => 8, 'gift_codes' => 5, 'channels' => 5, 'cards' => 3, 'resellers' => 2],
            $survey->counts,
        );
        self::assertSame('21000.00', $survey->balanceTotal, 'a debt taken off; reseller bots\' customers left out');
        self::assertEquals([new SurveyGroup('نماینده عادی', true, 1), new SurveyGroup('نماینده پیشرفته', true, 1)], $survey->groups);
        self::assertEquals(
            [new SurveyAdmin('999999999', 999999999, 'owner', 'administrator')],
            $survey->admins,
            'only the administrators, whom the owner\'s switch would make admins (support staff are a warning); an admin who never started the bot is no customer to make one',
        );
        self::assertEquals([
            new LeftBehind('تاریخچه پرداخت‌ها', 3),
            new LeftBehind('تیکت‌های پشتیبانی', 3),
            new LeftBehind('تاریخچه کیف پول', 2),
            new LeftBehind('تاریخچه تمدیدها و خریدهای اضافه سرویس‌ها', 1),
            new LeftBehind('ربات‌های نمایندگی', 1),
            new LeftBehind('کانفیگ‌های انبار و فروش دستی', 2),
            new LeftBehind('سرویس‌های پنل‌هایی که این فروشگاه کانکتورشان را ندارد', 1),
            new LeftBehind('سرویس‌های پنل‌هایی که در ربات قبلی حذف شده بودند', 1),
            new LeftBehind('قیمت‌های حجم و زمان اضافه و سرویس دلخواه پنل‌ها', 2),
            new LeftBehind('تنظیمات محصول‌ها (محدودیت دستگاه، خرید یک‌باره، ریست دوره‌ای حجم)', 2),
            new LeftBehind('تاریخچه استفاده از کدهای تخفیف و هدیه', 2),
        ], $survey->left);

        self::assertSame([
            '۱ مشتری در ربات قبلی بدهکار بودند (جمعا ۳۰٬۰۰۰ تومان)؛ کیف پولشان اینجا هم منفی می‌آید.',
            '۱ نفر از ادمین‌های ربات قبلی پشتیبان یا فروشنده بودند؛ در فهرست هستند ولی اینجا ادمین نمی‌شوند.',
            'سقف خرید اعتباری ۱ نماینده (خرید با موجودی منفی) منتقل نمی‌شود.',
            '۱ نماینده در ربات قبلی تاریخ پایان نمایندگی داشتند؛ این تاریخ منتقل نمی‌شود و اینجا نماینده می‌مانند تا خودتان عوضشان کنید.',
            '۲ مشتری تخفیف شخصی دارند؛ ربات قبلی کد تخفیف را از آن‌ها نمی‌پذیرفت، ولی اینجا کدهای تخفیف برایشان هم کار می‌کند.',
            'قیمت‌های جدای «نماینده پیشرفته» منتقل نمی‌شود: هر پلن اینجا یک قیمت نمایندگی دارد، همان قیمت «نماینده عادی» و اگر نبود، پیشرفته.',
            '۲ سرویس به هیچ پلنی وصل نشد (محصولش در ربات قبلی تغییر نام داده یا حذف شده بود)؛ بدون پلن می‌آید و با تمدید، پلن می‌گیرد.',
            '۲ پرداخت در ربات قبلی هنوز منتظر تایید است؛ اول آن‌ها را آنجا تایید کنید و بعد پشتیبان تازه بگیرید، وگرنه این مبلغ‌ها به کیف پول آن مشتری‌ها نمی‌رسد.',
        ], array_slice($survey->warnings, 0, 8), 'a still-running agency; personal discounts with codes coming; a custom service and a deleted product\'s; a payment waiting and one pending');
        self::assertCount(12, $survey->warnings);
        self::assertStringContainsString("«\u{2068}فرانسه\u{2069}»", $survey->warnings[8], 'links through the old bot, on a panel with services');
        self::assertStringContainsString("«\u{2068}فرانسه\u{2069}»", $survey->warnings[9], 'a 3x-ui panel on its admin\'s login: v3 or later to bring its services');
        self::assertStringNotContainsString('هلند', $survey->warnings[9], 'one on its API token is v3 already');
        self::assertStringContainsString('TLS', $survey->warnings[10]);
        self::assertSame('۲ کارت در ربات قبلی فقط به مشتری‌های انتخاب‌شده نشان داده می‌شد؛ اینجا هر کارت روشن به همه نشان داده می‌شود، پس پیش از روشن کردنشان دقت کنید.', $survey->warnings[11], 'cards 1 and 3; a whitelist row of a card gone is none');
        self::assertArrayNotHasKey('connection', $survey->toArray()['panels'][0], 'no secret leaves the server');
    }

    public function testEachPanelMapsToItsConnectorWithTheConnectionItsFormTakes(): void
    {
        $panels = [];
        foreach ((new FaoximaMigrator())->survey(self::staged())->panels as $panel) {
            $panels[$panel->key] = $panel;
        }

        self::assertSame(['آلمان ۱', 'هلند', 'فرانسه', 'PG', 'PG2', 'مرزبان جدید', 'ربکا', 'رمن', 'بی‌رمز'], array_keys($panels), 'no «فروش دستی» one');
        self::assertSame(
            ['marzban', '3x-ui', '3x-ui', 'pasarguard', 'pasarguard', 'pasarguard', null, null, 'marzban'],
            array_values(array_map(static fn(ImportedPanel $panel): ?string => $panel->driver, $panels)),
            'a Marzban panel on PasarGuard\'s API (its version 1) is PasarGuard',
        );
        self::assertSame([1, 3, 1, 1, 1, 0, 1, 0, 0], array_values(array_map(static fn(ImportedPanel $panel): int => $panel->services, $panels)));
        self::assertSame(['مرزبان جدید'], array_keys(array_filter($panels, static fn(ImportedPanel $panel): bool => !$panel->active)), 'closed to new sales in Faoxima');
        self::assertSame(['PG' => 200], array_filter(array_map(static fn(ImportedPanel $panel): ?int => $panel->capacity, $panels), static fn(?int $capacity): bool => $capacity !== null), 'its sales limit; «unlimted» is none');

        $options = ['verify_tls' => true, 'timeout' => 30, 'subscription_url' => '', 'proxy_enabled' => false];
        self::assertSame(['base_url' => 'https://de1.example.com:8000', 'auth_mode' => 'password', 'username' => 'admin', 'password' => 'p@ss word'] + $options, $panels['آلمان ۱']->connection);
        self::assertSame(
            ['base_url' => 'https://nl.example.com:2053/AbCd', 'auth_mode' => 'token', 'api_token' => 'xui-token-123', 'verify_tls' => true, 'timeout' => 30, 'subscription_url' => 'https://sub.nl.example.com:2096/sub', 'proxy_enabled' => false],
            $panels['هلند']->connection,
            'the links Faoxima gave, so a customer\'s stays',
        );
        self::assertSame(['auth_mode' => 'password', 'username' => 'root', 'password' => 'toor', 'subscription_url' => ''], array_intersect_key($panels['فرانسه']->connection, ['auth_mode' => 1, 'username' => 1, 'password' => 1, 'subscription_url' => 1]), 'legacy sign-in; links never set are the panel\'s own');
        self::assertSame(['auth_mode' => 'token', 'api_token' => self::PG_KEY], array_intersect_key($panels['PG']->connection, ['auth_mode' => 1, 'api_token' => 1]));
        self::assertSame(['auth_mode' => 'password', 'username' => 'root', 'password' => 'secret'], array_intersect_key($panels['PG2']->connection, ['auth_mode' => 1, 'username' => 1, 'password' => 1]), 'the old spelling, signed in with a login');
        self::assertSame('password', $panels['مرزبان جدید']->connection['auth_mode']);

        self::assertSame([[], 'این فروشگاه هنوز کانکتوری برای این نوع پنل ندارد.', false], [$panels['ربکا']->connection, $panels['ربکا']->unsupported, $panels['ربکا']->addable()]);
        self::assertSame([[], null, false], [$panels['بی‌رمز']->connection, $panels['بی‌رمز']->unsupported, $panels['بی‌رمز']->addable()], 'a connector, nothing to sign in with: mapped to a server, never added');

        // Every connection is what its connector's own form takes, as the servers screen would send it.
        $connectors = $this->service(ProviderRegistry::class);
        foreach (array_filter($panels, static fn(ImportedPanel $panel): bool => $panel->addable()) as $panel) {
            $form = $connectors->find((string) $panel->driver)?->describe()->form;
            self::assertNotNull($form, "{$panel->driver} is a connector of this shop");
            $kept = $form->check($panel->connection, static fn(): mixed => null);
            self::assertSame($panel->connection['base_url'], $kept['base_url'], $panel->key);
        }
    }

    public function testAPanelNamedByDigitsAloneIsStillAName(): void
    {
        $staged = new ArrayStaged([
            'marzban_panel' => [['name_panel' => '1', 'code_panel' => 'ab12', 'type' => 'x-ui_single', 'url_panel' => 'https://one.example.com', 'username_panel' => 'admin', 'password_panel' => 'pw', 'xui_api_mode' => 'legacy', 'subvip' => 'onsubvip']],
            'invoice' => [['id_invoice' => 'i1', 'id_user' => '111111111', 'username' => 'c1', 'Service_location' => '1', 'name_product' => 'x', 'Status' => 'active']],
        ]);
        $migrator = new FaoximaMigrator();

        $survey = $migrator->survey($staged);
        self::assertSame(['1', 1], [$survey->panels[0]->key, $survey->panels[0]->services]);
        self::assertCount(2, array_filter($survey->warnings, static fn(string $warning): bool => str_contains($warning, "«\u{2068}1\u{2069}»")), 'its links through the old bot, and its old 3x-ui');
        self::assertSame('1', array_values(iterator_to_array($migrator->services($staged)))[0]->panelKey);
    }

    public function testEveryGeneratorGoesOnAfterTheRowItStoppedAt(): void
    {
        $migrator = new FaoximaMigrator();
        $staged = self::staged();

        $generators = [
            'customers' => $migrator->customers(...),
            'plans' => $migrator->plans(...),
            'services' => $migrator->services(...),
            'discount codes' => $migrator->discountCodes(...),
            'gift codes' => $migrator->giftCodes(...),
            'channels' => $migrator->channels(...),
            'cards' => $migrator->cards(...),
        ];
        foreach ($generators as $kind => $generator) {
            $all = iterator_to_array($generator($staged));
            $seqs = array_keys($all);
            $ordered = array_values(array_unique($seqs));
            sort($ordered);
            self::assertSame($ordered, $seqs, "{$kind}: by seq, each once");

            self::assertEquals(array_slice($all, 2, null, true), iterator_to_array($generator($staged, $seqs[1])), "{$kind} after its second");
        }
    }

    /**
     * @template T of ImportedCustomer|ImportedPlan|ImportedService|ImportedDiscountCode|ImportedGiftCode
     * @param iterable<int, T> $records
     * @return array<int|string, T> By key (PHP keeps a key of digits as an int)
     */
    private static function byKey(iterable $records): array
    {
        $byKey = [];
        foreach ($records as $record) {
            $byKey[$record->key] = $record;
        }

        return $byKey;
    }

    /**
     * A small Faoxima shop: panels of every kind (one closed to sales, one with a sales limit), customers of every sort
     * (an agency ended, one still running), plans with agents' twins and options, services under renamed products,
     * codes, cards some customers alone saw, payments awaiting approval, history.
     */
    private static function staged(): ArrayStaged
    {
        return new ArrayStaged([
            'marzban_panel' => [
                ['name_panel' => 'آلمان ۱', 'code_panel' => 'a1b2', 'type' => 'marzban', 'url_panel' => 'https://de1.example.com:8000', 'username_panel' => 'admin', 'password_panel' => 'p@ss word', 'api_key' => null, 'pasarguard_auth_mode' => 'api_key', 'xui_api_token' => null, 'xui_api_mode' => 'legacy', 'linksubx' => 'https://de1.example.com:8000', 'version_panel' => '0', 'subvip' => 'offsubvip', 'status' => 'active', 'limit_panel' => 'unlimted', 'priceextravolume' => '{"f":"4000","n":"4000","n2":"4000"}'],
                ['name_panel' => 'هلند', 'code_panel' => 'c3d4', 'type' => 'x-ui_single', 'url_panel' => 'https://nl.example.com:2053/AbCd/', 'username_panel' => 'null', 'password_panel' => 'null', 'xui_api_token' => 'xui-token-123', 'xui_api_mode' => 'token', 'linksubx' => 'https://sub.nl.example.com:2096/sub/', 'version_panel' => '0', 'subvip' => 'offsubvip', 'pricecustomtime' => '{"f":"3000"}'],
                ['name_panel' => 'فرانسه', 'code_panel' => 'e5f6', 'type' => 'x-ui_single', 'url_panel' => 'https://fr.example.com:54321/xui', 'username_panel' => 'root', 'password_panel' => 'toor', 'xui_api_token' => '', 'xui_api_mode' => 'legacy', 'linksubx' => 'https://fr.example.com:54321/xui', 'version_panel' => '0', 'subvip' => 'onsubvip'],
                ['name_panel' => 'PG', 'code_panel' => 'g7h8', 'type' => 'pasarguard', 'url_panel' => 'https://pg.example.com', 'username_panel' => 'null', 'password_panel' => 'null', 'api_key' => self::PG_KEY, 'pasarguard_auth_mode' => 'api_key', 'version_panel' => '0', 'subvip' => 'offsubvip', 'limit_panel' => '200'],
                ['name_panel' => 'PG2', 'code_panel' => 'i9j0', 'type' => 'pasargard', 'url_panel' => 'http://10.0.0.5:8000', 'username_panel' => 'root', 'password_panel' => 'secret', 'api_key' => 'null', 'pasarguard_auth_mode' => 'password', 'version_panel' => '0'],
                ['name_panel' => 'مرزبان جدید', 'code_panel' => 'k1l2', 'type' => 'marzban', 'url_panel' => 'https://mz.example.com', 'username_panel' => 'sudo', 'password_panel' => 'pw', 'version_panel' => '1', 'status' => 'disable'],
                ['name_panel' => 'ربکا', 'code_panel' => 'm3n4', 'type' => 'rebecca', 'url_panel' => 'https://rb.example.com', 'username_panel' => 'null', 'password_panel' => 'null', 'api_key' => 'rb-token'],
                ['name_panel' => 'رمن', 'code_panel' => 'o5p6', 'type' => 'remnawave', 'url_panel' => 'https://rw.example.com'],
                ['name_panel' => 'دستی', 'code_panel' => 'q7r8', 'type' => 'Manualsale', 'url_panel' => 'null'],
                ['name_panel' => 'بی‌رمز', 'code_panel' => 's9t0', 'type' => 'marzban', 'url_panel' => 'https://nopass.example.com', 'username_panel' => 'null', 'password_panel' => 'null', 'version_panel' => '0'],
            ],
            'user' => [
                ['id' => '111111111', 'username' => 'ali_shop', 'number' => '+989121234567', 'Balance' => '50000', 'User_Status' => 'Active', 'agent' => 'f', 'affiliates' => '0', 'register' => '1700000000', 'pricediscount' => '10', 'bottype' => null, 'maxbuyagent' => '0'],
                ['id' => '222222222', 'username' => 'NOT_USERNAME', 'number' => 'confrim number by admin', 'Balance' => '-30000', 'User_Status' => 'Active', 'agent' => 'n2', 'affiliates' => '111111111', 'register' => '1700000100', 'pricediscount' => '0', 'bottype' => '0', 'maxbuyagent' => '100000'],
                ['id' => '333333333', 'username' => '@banned_guy', 'number' => '989351112233', 'Balance' => '0', 'User_Status' => 'Block', 'agent' => 'n', 'affiliates' => '0', 'register' => '1700000200', 'pricediscount' => '0', 'expire' => '4000000000'],
                ['id' => '444444444', 'username' => 'reseller_customer', 'number' => 'none', 'Balance' => '20000', 'User_Status' => 'Active', 'agent' => 'f', 'affiliates' => '0', 'register' => '1700000300', 'bottype' => 'AGENT_TOKEN_1'],
                ['id' => '555555555', 'username' => 'none', 'number' => 'none', 'Balance' => '5000', 'User_Status' => 'Active', 'agent' => 'f', 'affiliates' => '0', 'register' => '1700000400', 'bottype' => 'GONE_TOKEN'],
                ['id' => 'abc', 'username' => '', 'number' => 'none', 'Balance' => '0', 'User_Status' => 'Active', 'agent' => 'f', 'affiliates' => '0', 'register' => 'none'],
                ['id' => '777777777', 'username' => 'self_ref', 'number' => 'none', 'Balance' => '1000', 'User_Status' => 'Active', 'agent' => 'f', 'affiliates' => '777777777', 'register' => 'none', 'pricediscount' => '150', 'bottype' => '0'],
                ['id' => '666666666', 'username' => 'old_agent', 'number' => 'none', 'Balance' => '0', 'User_Status' => 'Active', 'agent' => 'n', 'affiliates' => '0', 'register' => '1690000500', 'expire' => '1600000000'],
                ['id' => '999999999', 'username' => 'owner', 'number' => 'none', 'Balance' => '0', 'User_Status' => 'Active', 'agent' => 'f', 'affiliates' => '0', 'register' => '1690000000'],
            ],
            'admin' => [
                ['id_admin' => '999999999', 'rule' => 'administrator'],
                ['id_admin' => '888888888', 'rule' => 'support'],
                ['id_admin' => '333333333', 'rule' => 'support'],
            ],
            'botsaz' => [
                ['id' => '1', 'id_user' => '444000000', 'username' => 'agent_shop_bot', 'bot_token' => 'AGENT_TOKEN_1'],
            ],
            'product' => [
                ['id' => '1', 'code_product' => 'p1', 'name_product' => 'یک ماهه ۳۰ گیگ', 'price_product' => '150000', 'Volume_constraint' => '30', 'Service_time' => '30', 'Location' => '/all', 'agent' => 'f', 'category' => 'ماهانه,ویژه', 'note' => 'بدون محدودیت کاربر', 'ip_limit' => '2', 'position' => '1'],
                ['id' => '2', 'code_product' => 'p1n2', 'name_product' => 'یک ماهه پیشرفته', 'price_product' => '110000', 'Volume_constraint' => '30', 'Service_time' => '30', 'Location' => '/all', 'agent' => 'n2', 'category' => 'ماهانه', 'note' => '', 'ip_limit' => '0', 'position' => '2'],
                ['id' => '3', 'code_product' => 'p1n', 'name_product' => 'یک ماهه ۳۰ گیگ نماینده', 'price_product' => '120000', 'Volume_constraint' => '30.0', 'Service_time' => '30', 'Location' => '/all', 'agent' => 'n', 'category' => 'ماهانه', 'note' => '', 'ip_limit' => '0', 'position' => '3'],
                ['id' => '4', 'code_product' => 'p2', 'name_product' => 'سه ماهه', 'price_product' => '400000', 'Volume_constraint' => '100', 'Service_time' => '90', 'Location' => 'آلمان ۱,هلند', 'agent' => 'all', 'category' => 'سه ماهه', 'position' => '4', 'data_limit_reset' => 'month'],
                ['id' => '5', 'code_product' => null, 'name_product' => 'سه ماهه نماینده', 'price_product' => '350000', 'Volume_constraint' => '100', 'Service_time' => '90', 'Location' => 'هلند,آلمان ۱', 'agent' => 'n2', 'category' => 'سه ماهه', 'position' => '5'],
                ['id' => '6', 'code_product' => 'p3', 'name_product' => 'نامحدود', 'price_product' => '1,000,000', 'Volume_constraint' => '0', 'Service_time' => '0', 'Location' => 'حذف‌شده', 'agent' => 'f', 'category' => '', 'position' => '6'],
                ['id' => '7', 'code_product' => 'p4', 'name_product' => 'ویژه نمایندگان', 'price_product' => '90000', 'Volume_constraint' => '50', 'Service_time' => '60', 'Location' => 'PG', 'agent' => 'n', 'category' => 'ویژه', 'position' => '7', 'hwid_limit' => '2', 'data_limit_reset' => 'no_reset'],
            ],
            'invoice' => [
                ['id_invoice' => 'inv1', 'id_user' => '111111111', 'username' => 'ali_1', 'Service_location' => 'آلمان ۱', 'name_product' => 'یک ماهه ۳۰ گیگ', 'price_product' => '150000', 'Status' => 'active', 'note' => 'گوشی', 'bottype' => null, 'source_panel_code' => null],
                ['id_invoice' => 'inv2', 'id_user' => '222222222', 'username' => 'agent_2', 'Service_location' => 'هلند', 'name_product' => 'یک ماهه ۳۰ گیگ نماینده', 'price_product' => '120000', 'Status' => 'end_of_volume', 'note' => null, 'source_panel_code' => ''],
                ['id_invoice' => 'inv3', 'id_user' => '333333333', 'username' => 'test_3', 'Service_location' => 'فرانسه', 'name_product' => 'سرویس تست', 'price_product' => '0', 'Status' => 'active'],
                ['id_invoice' => 'inv4', 'id_user' => '111111111', 'username' => 'ali_old', 'Service_location' => 'آلمان ۱', 'name_product' => 'یک ماهه ۳۰ گیگ', 'Status' => 'removebyuser'],
                ['id_invoice' => 'inv5', 'id_user' => '111111111', 'username' => 'ali_stock', 'Service_location' => 'آلمان ۱', 'name_product' => 'یک ماهه ۳۰ گیگ', 'Status' => 'active', 'source_panel_code' => 'a1b2'],
                ['id_invoice' => 'inv6', 'id_user' => '777777777', 'username' => 'manual_1', 'Service_location' => 'دستی', 'name_product' => 'دستی', 'price_product' => '50000', 'Status' => 'active'],
                ['id_invoice' => 'inv7', 'id_user' => '777777777', 'username' => 'gone_1', 'Service_location' => 'حذف‌شده', 'name_product' => 'نامحدود', 'Status' => 'active'],
                ['id_invoice' => 'inv8', 'id_user' => '444444444', 'username' => 'res_8', 'Service_location' => 'PG', 'name_product' => 'سه ماهه', 'price_product' => '400000', 'Status' => 'sendedwarn', 'bottype' => 'AGENT_TOKEN_1'],
                ['id_invoice' => 'inv9', 'id_user' => '111111111', 'username' => 'rb_9', 'Service_location' => 'ربکا', 'name_product' => 'سه ماهه', 'Status' => 'disabled'],
                ['id_invoice' => 'inv10', 'id_user' => '555555555', 'username' => 'unpaid_10', 'Service_location' => 'آلمان ۱', 'name_product' => 'سه ماهه', 'price_product' => '400000', 'Status' => 'Unpaid'],
                ['id_invoice' => 'inv11', 'id_user' => '999999999', 'username' => 'custom_11', 'Service_location' => 'PG2', 'name_product' => '⚙️ سرویس دلخواه', 'price_product' => '200000', 'Status' => 'disablebyadmin'],
                ['id_invoice' => 'inv12', 'id_user' => '666666666', 'username' => 'test_12', 'Service_location' => 'PG', 'name_product' => 'سرویس تست', 'price_product' => '0', 'Status' => 'disabled'],
                ['id_invoice' => 'inv13', 'id_user' => '111111111', 'username' => 'renamed_13', 'Service_location' => 'هلند', 'name_product' => 'یک ماهه قدیمی', 'Volume' => '30', 'Service_time' => '30', 'Status' => 'active'],
                ['id_invoice' => 'inv14', 'id_user' => '111111111', 'username' => 'gone_14', 'Service_location' => 'هلند', 'name_product' => 'محصول حذف‌شده', 'Volume' => '77', 'Service_time' => '7', 'Status' => 'active'],
            ],
            'DiscountSell' => [
                ['id' => '1', 'codeDiscount' => 'SUMMER20', 'price' => '20', 'limitDiscount' => '100', 'usedDiscount' => '30', 'agent' => 'allusers', 'usefirst' => '0', 'useuser' => '1', 'code_product' => 'all', 'code_panel' => '/all', 'time' => '0', 'type' => 'buy', 'section' => 'buy,extend', 'value_type' => 'percent', 'target_user' => null, 'status' => 'active', 'targeting_mode' => 'none', 'code_category' => 'all'],
                ['id' => '2', 'codeDiscount' => 'FIX50', 'price' => '50000', 'limitDiscount' => '0', 'usedDiscount' => '4', 'agent' => 'f,n,n2', 'usefirst' => '0', 'useuser' => '0', 'code_product' => 'p1n', 'code_panel' => 'a1b2', 'time' => '2000000000', 'section' => 'all', 'value_type' => 'amount', 'status' => '', 'targeting_mode' => 'none'],
                ['id' => '3', 'codeDiscount' => 'AGENTS', 'price' => '0', 'limitDiscount' => '10', 'usedDiscount' => '0', 'agent' => 'n,n2', 'usefirst' => '0', 'useuser' => '0', 'code_product' => 'all', 'code_panel' => 'c3d4,zzzz', 'time' => '0', 'section' => 'all', 'value_type' => 'free', 'status' => 'active', 'targeting_mode' => 'panel'],
                ['id' => '4', 'codeDiscount' => 'TOPUP', 'price' => '5', 'limitDiscount' => '0', 'usedDiscount' => '0', 'agent' => 'allusers', 'section' => 'charge', 'value_type' => null, 'status' => 'active'],
                ['id' => '5', 'codeDiscount' => 'MIX', 'price' => '15', 'limitDiscount' => '0', 'usedDiscount' => '0', 'agent' => 'allusers', 'section' => 'volume,charge', 'value_type' => 'percent', 'target_user' => '111111111', 'status' => 'expired', 'targeting_mode' => 'category', 'code_category' => 'ماهانه'],
                ['id' => '6', 'codeDiscount' => 'OLD', 'price' => '10', 'limitDiscount' => '0', 'usedDiscount' => '0', 'agent' => 'allusers', 'usefirst' => '1', 'useuser' => '0', 'code_product' => 'all', 'code_panel' => '/all', 'time' => '0', 'type' => 'extend', 'section' => null, 'value_type' => null],
                ['id' => '7', 'codeDiscount' => 'ONLYF', 'price' => '10', 'limitDiscount' => '0', 'usedDiscount' => '0', 'agent' => 'f', 'section' => 'all', 'status' => 'active'],
                ['id' => '8', 'codeDiscount' => 'PLAIN', 'price' => '10', 'limitDiscount' => '0', 'usedDiscount' => '0', 'agent' => 'n,f', 'section' => 'all', 'status' => 'active'],
            ],
            'Discount' => [
                ['id' => '1', 'code' => 'GIFT1', 'price' => '25000', 'limituse' => '1', 'limitused' => '0', 'expire_at' => null, 'status' => 'active', 'target_user' => null],
                ['id' => '2', 'code' => 'GIFT2', 'price' => '10000', 'limituse' => '5', 'limitused' => '5', 'status' => 'active'],
                ['id' => '3', 'code' => 'GIFT3', 'price' => '5000', 'limituse' => '0', 'limitused' => '12', 'expire_at' => '1700000000', 'status' => ''],
                ['id' => '4', 'code' => 'GIFT4', 'price' => '5000', 'limituse' => '1', 'limitused' => '0', 'status' => 'expired', 'target_user' => ''],
                ['id' => '5', 'code' => 'GIFT5', 'price' => '5000', 'limituse' => '1', 'limitused' => '0', 'status' => 'active', 'target_user' => '222222222'],
            ],
            'channels' => [
                ['id' => '1', 'link' => '@my_channel', 'remark' => 'کانال ما', 'linkjoin' => 'https://t.me/my_channel'],
                ['id' => '2', 'link' => '-1001234567890', 'remark' => 'گروه', 'linkjoin' => 'https://t.me/+AbCdEf'],
                ['id' => '3', 'link' => 'news_channel', 'remark' => '', 'linkjoin' => 'not a link'],
                ['id' => '4', 'link' => 'https://t.me/s/vpn_news', 'remark' => 'اخبار', 'linkjoin' => 'https://t.me/vpn_news'],
                ['id' => '5', 'link' => 'https://t.me/joinchat/AbCdEf123', 'remark' => 'خصوصی', 'linkjoin' => 'https://t.me/joinchat/AbCdEf123'],
            ],
            'card_number' => [
                ['id' => '1', 'cardnumber' => '6037997700001119', 'namecard' => 'علی رضایی', 'is_active' => '1', 'is_visible' => '1'],
                ['id' => '2', 'cardnumber' => '۶۲۱۹-۸۶۱۰ ۱۲۳۴ ۵۶۷۵', 'namecard' => 'بانک ملی', 'is_active' => '0', 'is_visible' => '1'],
                ['id' => '3', 'cardnumber' => '5022 2910 1234 5678', 'namecard' => 'رضا', 'is_active' => '1', 'is_visible' => '0'],
            ],
            'card_whitelist' => [['card_id' => '1'], ['card_id' => '1'], ['card_id' => '3'], ['card_id' => '99']],
            'Payment_report' => [['id' => '1', 'payment_Status' => 'paid'], ['id' => '2', 'payment_Status' => 'waiting'], ['id' => '3', 'payment_Status' => 'pending']],
            'support_message' => [['id' => '1', 'Tracking' => 't1'], ['id' => '2', 'Tracking' => 't1'], ['id' => '3', 'Tracking' => 't2'], ['id' => '4', 'Tracking' => null]],
            'wallet_ledger' => [['id' => '1'], ['id' => '2']],
            'service_other' => [['id' => '1']],
            'Giftcodeconsumed' => [['id' => '1'], ['id' => '2']],
        ]);
    }
}
