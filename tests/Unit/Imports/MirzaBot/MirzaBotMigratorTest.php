<?php

declare(strict_types=1);

namespace Tests\Unit\Imports\MirzaBot;

use App\Core\Exceptions\ValidationException;
use App\Modules\Imports\Migrators\Faoxima\FaoximaMigrator;
use App\Modules\Imports\Migrators\MirzaBot\MirzaBotMigrator;
use App\Modules\Imports\Records\ImportedCard;
use App\Modules\Imports\Records\ImportedChannel;
use App\Modules\Imports\Records\ImportedCustomer;
use App\Modules\Imports\Records\ImportedDiscountCode;
use App\Modules\Imports\Records\ImportedGiftCode;
use App\Modules\Imports\Records\ImportedPanel;
use App\Modules\Imports\Records\ImportedPlan;
use App\Modules\Imports\Records\ImportedService;
use App\Modules\Imports\Staging\Schema;
use App\Modules\Imports\Survey\LeftBehind;
use App\Modules\Imports\Survey\SurveyAdmin;
use App\Modules\Imports\Survey\SurveyGroup;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Providers\ProviderRegistry;
use Tests\Fakes\ArrayStaged;
use Tests\TestCase;

/**
 * The MirzaBot migrator against a staged copy shaped as MirzaBot 0.9.3 leaves its database (its db/tables, lower-case
 * names): what it stages, how it tells MirzaBot from Faoxima, its descendant, the survey (counts, panels with their
 * connectors and connections — each one its connector's form takes —, groups, admins, what stays behind, warnings), every
 * generator's records and their edge cases, how it tells a reseller bot's customer from its own, how it tells a 3x-ui
 * panel's token from an older MirzaBot's login, and resuming after a seq.
 */
final class MirzaBotMigratorTest extends TestCase
{
    /** What MirzaBot gives a panel's extras (`priceextravolume` …) for each audience, and the same switched off. */
    private const PRICED = '{"f":"4000","n":"4000","n2":"4000"}';
    private const UNPRICED = '{"f":"0","n":"0","n2":"0"}';

    /** A Rebecca admin's API key as the panel makes it: `rk_` and 43 base64url characters. */
    private const REBECCA_KEY = 'rk_AbCdEfGhIjKlMnOpQrStUvWxYz0123456789-_AbCdE';

    private const UNUSABLE = 'در ربات قبلی برای هیچ خریدی پذیرفته نمی‌شد.';
    private const OLD_THREE_XUI = "پنل %s با روش قدیمی (نام کاربری و رمز) به ربات قبلی وصل بود؛ کانکتور \u{2068}3X-UI\u{2069} این فروشگاه نسخه 3 به بعد را می‌خواهد و سرویس‌های پنل قدیمی‌تر منتقل نمی‌شوند.";
    private const UNCHECKED_TLS = 'ربات قبلی گواهی TLS پنل‌ها را بررسی نمی‌کرد، ولی این فروشگاه بررسی می‌کند: پنلی با گواهی خودامضا یا منقضی به‌عنوان سرور تازه اضافه نمی‌شود؛ آن را در «سرورها» با «بررسی گواهی TLS» خاموش اضافه کنید و همان را برایش انتخاب کنید.';
    private const RESELLER_WALLETS = 'کیف پول مشتری‌های ربات‌های نمایندگی (در فایل‌های ربات قبلی است، نه دیتابیسش)';

    public function testItsCardNamesTheBotAndAsksNothing(): void
    {
        $migrator = new MirzaBotMigrator();
        $card = $migrator->describe()->toArray();

        self::assertSame(['mirzabot', 'mirzabot', 'MirzaBot', []], [$migrator->key(), $card['key'], $card['label'], $card['fields']]);
        self::assertEquals((object) ['mark' => 'MB', 'vendor' => 'https://github.com/mahdiMGF2/mirzabot'], $card['traits']);
        self::assertNotEmpty(array_filter($card['notes'], static fn(string $note): bool => str_contains($note, 'backup_') && str_contains($note, '.sql')), 'where its backup is');
        $panels = array_values(array_filter($card['notes'], static fn(string $note): bool => str_contains($note, 'منتقل می‌شوند؛')));
        self::assertCount(1, $panels, 'which panels\' services come');
        [$come, $notYet] = explode('؛', $panels[0], 2);
        foreach (['Marzban', 'PasarGuard', '3X-UI', 'Rebecca'] as $kind) {
            self::assertStringContainsString("\u{2068}{$kind}\u{2069}", $come, "{$kind}'s come");
        }
        self::assertStringContainsString("\u{2068}Hiddify\u{2069}", $notYet, 'Hiddify\'s not yet');
        self::assertStringNotContainsString('Rebecca', $notYet);
        self::assertDoesNotMatchRegularExpression('/[\x{064B}-\x{0652}\x{0654}]/u', $card['description'] . implode('', $card['notes']), 'no diacritics');
    }

    public function testItStagesWhatItReadsWithTheSecretsMarked(): void
    {
        $tables = (new MirzaBotMigrator())->tables();

        self::assertSame(['password_panel'], $tables['marzban_panel']->secrets, 'a 3x-ui panel\'s API token is kept there too');
        self::assertSame(
            ['id', 'name_panel', 'code_panel', 'type', 'status', 'url_panel', 'username_panel', 'password_panel', 'linksubx', 'subvip', 'limit_panel', 'agent', 'hide_user', 'display_order', 'version_panel', 'priceextravolume', 'priceextratime', 'pricecustomvolume', 'pricecustomtime'],
            $tables['marzban_panel']->columns,
            'who a panel was shown to, and hidden from',
        );
        self::assertSame(['bottype'], $tables['user']->secrets, 'a reseller bot\'s token');
        self::assertSame([], array_diff(['joinchannel', 'cardpayment'], (array) $tables['user']->columns), 'who was excused from the channels, who saw the cards');
        self::assertSame(['bottype'], $tables['invoice']->secrets);
        self::assertSame(['bot_token'], $tables['botsaz']->secrets);
        self::assertSame(['id_admin', 'rule'], $tables['admin']->columns, 'never an admin\'s web password');
        self::assertSame(['id', 'payment_Status', 'bottype'], $tables['payment_report']->columns, 'history is counted, not read: only whether a payment of the bot itself still awaits approval');
        self::assertSame(['bottype'], $tables['payment_report']->secrets);
        self::assertSame([['text_edit'], ['scope']], [$tables['setting']->columns, $tables['config']->columns], 'only how new the bot was');
        self::assertSame([], array_diff(['hide_panel', 'status_product', 'display_order'], (array) $tables['product']->columns));
        self::assertSame(['discountsell', 'discount', 'giftcodeconsumed'], array_values(array_intersect(array_keys($tables), ['discountsell', 'discount', 'giftcodeconsumed'])), 'its names since 0.9.3, lower-case');
        self::assertArrayNotHasKey('textbot', $tables, 'its texts say nothing to the owner');
        self::assertArrayNotHasKey('wallet_ledger', $tables, 'Faoxima\'s');
    }

    public function testItTakesMirzaBotsDatabaseAndNotFaoximasItsDescendant(): void
    {
        $migrator = new MirzaBotMigrator();
        $core = [
            'user' => ['id', 'limit_usertest', 'username', 'number', 'Balance', 'User_Status', 'agent', 'affiliates'],
            'invoice' => ['id_invoice', 'id_user', 'username', 'Service_location', 'name_product', 'Status'],
            'marzban_panel' => ['id', 'code_panel', 'name_panel', 'status', 'url_panel', 'username_panel', 'password_panel', 'type', 'version_panel'],
        ];

        $mirza = $migrator->recognize(new Schema($core + ['payment_report' => ['id', 'payment_Status']]));
        self::assertTrue($mirza->ok);
        self::assertNull($mirza->version, 'MirzaBot keeps no version in its database');

        $other = $migrator->recognize(new Schema(['user' => ['id', 'Balance']]));
        self::assertFalse($other->ok);
        self::assertSame(['user.User_Status', 'user.agent', 'invoice', 'marzban_panel'], $other->missing);

        $faoxima = $migrator->recognize(new Schema($core + ['wallet_ledger' => ['id', 'id_user', 'amount']]));
        self::assertSame([false, []], [$faoxima->ok, $faoxima->missing], 'Faoxima\'s own table: nothing missing, someone else\'s data');
        foreach (['pasarguard_auth_mode', 'xui_api_mode'] as $mark) {
            $marked = $core;
            $marked['marzban_panel'][] = $mark;
            $refused = $migrator->recognize(new Schema($marked));
            self::assertSame([false, []], [$refused->ok, $refused->missing], "Faoxima's panels' {$mark}");
        }

        $byFaoxima = (new FaoximaMigrator())->recognize(new Schema($core));
        self::assertSame([false, ['wallet_ledger']], [$byFaoxima->ok, $byFaoxima->missing], 'and Faoxima\'s migrator leaves MirzaBot\'s to this one');
    }

    public function testCustomersComeWithTheirWalletsLevelsReferrersAndResellerBots(): void
    {
        $customers = self::byKey((new MirzaBotMigrator())->customers(self::staged()));

        self::assertSame(['111111111', '222222222', '333333333', '444444444', '555555555', '666666666', '777777777', '888888888', '999999999'], array_map(strval(...), array_keys($customers)));
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

        self::assertSame(['@agent_shop_bot', '0.00'], [$customers['444444444']->reseller, $customers['444444444']->balance], 'a reseller bot\'s customer, by its @username: nothing ties them to the bot itself');
        self::assertSame('ربات نماینده حذف‌شده', $customers['555555555']->reseller, 'a token of a reseller bot MirzaBot no longer has');

        $odd = $customers['777777777'];
        self::assertSame([null, null, 100, null, 'نماینده پیشرفته'], [$odd->referrerKey, $odd->joinedAt, $odd->discountPercent, $odd->reseller, $odd->group], 'brought by themselves, a «none» join date, a percent past 100, «0» for the bot itself');

        self::assertTrue($customers['999999999']->admin, 'MirzaBot\'s administrator');
        self::assertFalse($customers['333333333']->admin, 'its support staff: listed, never made an admin here');
        self::assertFalse($customers['222222222']->admin, 'nor its Seller');
        self::assertFalse($customers['111111111']->admin);

        self::assertSame(
            ['111111111', '222222222', '444444444', '777777777', '888888888', '999999999'],
            array_map(strval(...), array_keys(array_filter($customers, static fn(ImportedCustomer $customer): bool => $customer->boughtBefore))),
            'a purchase delivered (removed since, disabled, sold by hand, on a panel gone, free too): not a test, not unpaid or unsuccessful',
        );
    }

    public function testEachTieToTheBotItselfMakesAResellerBotsCustomerItsOwn(): void
    {
        $staged = self::ties();
        $migrator = new MirzaBotMigrator();

        $resellers = array_map(static fn(ImportedCustomer $customer): ?string => $customer->reseller, self::byKey($migrator->customers($staged)));
        self::assertSame([
            500000001 => '@agent_shop_bot',
            500000002 => null,
            500000003 => null,
            500000004 => null,
            500000005 => null,
            500000006 => null,
            500000007 => null,
            500000008 => null,
            500000009 => null,
            500000010 => null,
            500000011 => 'ربات نماینده حذف‌شده',
            500000012 => null,
        ], $resellers, 'only the one nothing ties to the bot itself (a reseller bot\'s purchase is no tie); a wallet, in credit or debt, either agents\' level, a reseller bot of their own, an admin\'s role, a purchase in the bot itself — none, «0» or blank, unpaid too —; «0» on the customer is the bot itself');

        $survey = $migrator->survey($staged);
        self::assertSame([12, 2, 2], [$survey->counts['customers'], $survey->counts['resellers'], $survey->counts['balances']]);
        self::assertSame('500.00', $survey->balanceTotal, 'the wallets tied ones keep are the bot\'s own');
        self::assertContainsEquals(new LeftBehind(self::RESELLER_WALLETS, 2), $survey->left, 'their wallets stay in the reseller bot\'s files');

        $services = self::byKey($migrator->services($staged));
        self::assertSame(['@agent_shop_bot', '@agent_shop_bot', null], [$services['i1']->reseller, $services['i2']->reseller, $services['i3']->reseller], 'a service by its own invoice: bought in the reseller bot by a customer of the bot itself too');
    }

    public function testBoughtBeforeIsAnyPurchaseMirzaBotDelivered(): void
    {
        $invoice = static fn(string $user, string $product, string $price, string $status): array => ['id_invoice' => "i{$user}", 'id_user' => $user, 'username' => "c{$user}", 'Service_location' => 'آلمان', 'name_product' => $product, 'price_product' => $price, 'Status' => $status];
        $staged = new ArrayStaged([
            'user' => array_map(static fn(string $id): array => ['id' => $id, 'Balance' => '0', 'User_Status' => 'Active', 'agent' => 'f'], ['1001', '1002', '1003', '1004', '1005', '1006', '1007', '1008', '1009']),
            'invoice' => [
                $invoice('1001', 'یک ماهه', '150000', 'active'),
                $invoice('1002', 'یک ماهه', '150000', 'Unpaid'),
                $invoice('1003', 'یک ماهه', '150000', 'unpaid'),
                $invoice('1004', 'یک ماهه', '150000', 'Unsuccessful'),
                $invoice('1005', 'Test service', '5000', 'active'),
                $invoice('1006', 'یک ماهه', '0', 'active'),
                $invoice('1007', 'یک ماهه', '150000', 'removevolume'),
                $invoice('1008', 'یک ماهه', '150000', ''),
                $invoice('1009', '⚙️ Custom service', '', 'disabled'),
            ],
        ]);

        $bought = array_map(static fn(ImportedCustomer $customer): bool => $customer->boughtBefore, self::byKey((new MirzaBotMigrator())->customers($staged)));
        self::assertSame([1001 => true, 1002 => false, 1003 => false, 1004 => false, 1005 => false, 1006 => true, 1007 => true, 1008 => false, 1009 => true], $bought, 'a test is none, even priced; at any price, as MirzaBot tells a newcomer; one removed or disabled since is one');
    }

    public function testPlansPairAgentsPricesIntoTheCustomersOnes(): void
    {
        $plans = self::byKey((new MirzaBotMigrator())->plans(self::staged()));

        self::assertSame(['p3', 'p2', 'p1', 'p2b', 'p4', 'p5', '#10', 'p6'], array_keys($plans), 'an agents\' twin is its plan\'s agent price, no plan of its own');
        self::assertEquals(new ImportedPlan(
            key: 'p1',
            name: 'یک ماهه ۳۰ گیگ',
            category: 'ماهانه',
            price: '150000.00',
            agentPrice: '120000.00',
            trafficGb: '30',
            durationDays: 30,
            note: 'بدون محدودیت کاربر',
            panelKeys: null,
        ), $plans['p1'], 'the plain agents\' price over the advanced ones\'');

        self::assertSame([null, ['هلند']], [$plans['p2']->agentPrice, $plans['p2']->panelKeys], 'on its one panel; its twin went to another of the same category');
        self::assertSame(['350000.00', 'ویژه'], [$plans['p2b']->agentPrice, $plans['p2b']->category], 'the advanced agents\' twin of its own category');
        self::assertSame(['1000000.00', '0', 0, [], null, null, false], [$plans['p3']->price, $plans['p3']->trafficGb, $plans['p3']->durationDays, $plans['p3']->panelKeys, $plans['p3']->agentPrice, $plans['p3']->category, $plans['p3']->selling], 'unlimited, on a panel MirzaBot no longer has, sold out');
        self::assertSame([true, '90000.00', null, ['PG'], true], [$plans['p4']->agentsOnly, $plans['p4']->price, $plans['p4']->agentPrice, $plans['p4']->panelKeys, $plans['p4']->selling], 'a plain agents\' product with no twin');
        self::assertSame([true, '70000.00', null, ['آلمان ۱']], [$plans['p6']->agentsOnly, $plans['p6']->price, $plans['p6']->agentPrice, $plans['p6']->panelKeys], 'an advanced agents\' one with no twin');
        self::assertSame(['مرزنشین', 'آلمان ۱', 'فرانسه', 'PG', 'ربکا', 'هیدیفای', 'بی‌رمز'], $plans['p5']->panelKeys, 'every panel but the one it is hidden from, in MirzaBot\'s order; no «فروش دستی» one');
        self::assertSame(['یک ماهه دوم', null], [$plans['#10']->name, $plans['#10']->agentPrice], 'a code that repeats: by its id');
        self::assertSame([], array_keys(array_filter($plans, static fn(ImportedPlan $plan): bool => $plan->ipLimit !== 0)), 'MirzaBot has no device limit');
    }

    public function testTheSameCategoryWinsAnAgentsTwinAndEachLevelTakesItsOwn(): void
    {
        $product = static fn(string $id, string $agent, string $category, string $price): array => ['id' => $id, 'code_product' => "c{$id}", 'name_product' => "پلن {$id}", 'price_product' => $price, 'Volume_constraint' => '10', 'Service_time' => '30', 'Location' => '/all', 'agent' => $agent, 'category' => $category, 'hide_panel' => '{}', 'display_order' => $id];
        $staged = new ArrayStaged(['product' => [
            $product('1', 'f', 'الف', '100000'),
            $product('2', 'f', 'ب', '110000'),
            $product('3', 'n2', 'ب', '80000'),
            $product('4', 'n', 'ب', '90000'),
            $product('5', 'n', 'ج', '95000'),
            $product('6', 'n', 'ب', '99000'),
        ]]);

        $plans = self::byKey((new MirzaBotMigrator())->plans($staged));
        self::assertSame(['c1', 'c2', 'c6'], array_keys($plans), 'a third plain agents\' product finds every twin taken by its level');
        self::assertSame(['95000.00', '90000.00'], [$plans['c1']->agentPrice, $plans['c2']->agentPrice], 'its category\'s twin first, else the first one left; the plain agents\' price over the advanced one\'s');
        self::assertSame([true, null], [$plans['c6']->agentsOnly, $plans['c6']->agentPrice]);
    }

    public function testAgentsProductsPairInMirzaBotsOwnOrder(): void
    {
        $product = static fn(string $id, string $agent, string $order, string $price): array => ['id' => $id, 'code_product' => "c{$id}", 'name_product' => "پلن {$id}", 'price_product' => $price, 'Volume_constraint' => '10', 'Service_time' => '30', 'Location' => '/all', 'agent' => $agent, 'category' => 'ماهانه', 'hide_panel' => '{}', 'display_order' => $order];
        $staged = new ArrayStaged(['product' => [
            $product('1', 'f', '5', '100000'),
            $product('2', 'f', '1', '110000'),
            $product('3', 'n', '9', '90000'),
            $product('4', 'n', '2', '80000'),
        ]]);

        $plans = iterator_to_array((new MirzaBotMigrator())->plans($staged));
        self::assertSame(
            [1 => ['c2', '80000.00'], 2 => ['c1', '90000.00']],
            array_map(static fn(ImportedPlan $plan): array => [$plan->key, $plan->agentPrice], $plans),
            'the agents\' product MirzaBot shows first takes the twin it shows first (display order, not the rows\' order); each plan keeps its place',
        );
    }

    public function testAProductCodeThatRepeatsKeysTheSecondByItsId(): void
    {
        $staged = new ArrayStaged(['product' => [
            ['id' => '8', 'code_product' => 'ab12', 'name_product' => 'یک', 'price_product' => '1000', 'Volume_constraint' => '1', 'Service_time' => '1', 'Location' => '/all', 'agent' => 'f', 'hide_panel' => '{}', 'display_order' => '8'],
            ['id' => '9', 'code_product' => 'ab12', 'name_product' => 'دو', 'price_product' => '2000', 'Volume_constraint' => '2', 'Service_time' => '2', 'Location' => '/all', 'agent' => 'f', 'hide_panel' => '{}', 'display_order' => '9'],
        ]]);

        self::assertSame(['ab12', '#9'], array_keys(self::byKey((new MirzaBotMigrator())->plans($staged))));
    }

    public function testPlansComeInMirzaBotsOwnOrderKeyedByTheirPlaceInIt(): void
    {
        $product = static fn(string $id, string $order): array => ['id' => $id, 'code_product' => "c{$id}", 'name_product' => "پلن {$id}", 'price_product' => '1000', 'Volume_constraint' => $id, 'Service_time' => '30', 'Location' => '/all', 'agent' => 'f', 'hide_panel' => '{}', 'display_order' => $order];
        $staged = new ArrayStaged(['product' => [$product('1', '2'), $product('2', '0'), $product('4', '1'), $product('3', '1')]]);
        $migrator = new MirzaBotMigrator();

        $plans = iterator_to_array($migrator->plans($staged));
        self::assertSame([1, 2, 3, 4], array_keys($plans), 'its place, not its row\'s seq');
        self::assertSame(['c2', 'c3', 'c4', 'c1'], array_map(static fn(ImportedPlan $plan): string => $plan->key, array_values($plans)), 'by display order, 0 first (ORDER BY display_order, id), then id');
        self::assertSame(['c4', 'c1'], array_map(static fn(ImportedPlan $plan): string => $plan->key, array_values(iterator_to_array($migrator->plans($staged, 2)))));
    }

    public function testServicesAreTheLiveClientsOnItsPanels(): void
    {
        $services = self::byKey((new MirzaBotMigrator())->services(self::staged()));

        self::assertSame(
            ['inv1', 'inv2', 'inv3', 'inv4', 'inv5', 'inv10', 'inv13', 'inv14', 'inv15', 'inv16', 'inv17', 'inv18', 'inv23', 'inv24', 'inv25'],
            array_keys($services),
            'not disabled (a test or paid alike), removed, sold by hand, on a panel MirzaBot no longer has, unpaid, unsuccessful, gone from its panel, or without a client name',
        );
        self::assertEquals(new ImportedService(key: 'inv1', customerKey: '111111111', panelKey: 'آلمان ۱', remoteName: 'ali_1', planKey: 'p1', label: 'گوشی'), $services['inv1']);
        self::assertSame('p1', $services['inv2']->planKey, 'bought under the agents\' twin: its plan');
        self::assertSame(
            [[true, null], [true, null], [true, null]],
            [[$services['inv3']->test, $services['inv3']->planKey], [$services['inv4']->test, $services['inv4']->planKey], [$services['inv5']->test, $services['inv5']->planKey]],
            'a test in Persian, English and Russian (the Chinese one is disabled: its client is gone)',
        );
        self::assertSame(['@agent_shop_bot', 'p5'], [$services['inv10']->reseller, $services['inv10']->planKey]);
        self::assertSame([null, null], [$services['inv13']->planKey, $services['inv24']->planKey], 'a service its customer built has no plan, though one of its traffic and days is sold on its panel');
        self::assertSame('p1', $services['inv14']->planKey, 'a renamed product: the one plan of its traffic and days on its panel (the agents\' twins are that plan)');
        self::assertNull($services['inv15']->planKey, 'two plans of its traffic and days on its panel: none told');
        self::assertSame(['p5', null], [$services['inv16']->planKey, $services['inv17']->planKey], 'a plan hidden from a panel is not one sold there');
        self::assertSame(['p1', null], [$services['inv18']->planKey, $services['inv18']->label], 'a free one given still names its plan');
        self::assertSame(['ربکا', 'p1', false], [$services['inv23']->panelKey, $services['inv23']->planKey, $services['inv23']->test], 'on Rebecca, like any other');
        self::assertSame(['مرزنشین', 'p5'], [$services['inv25']->panelKey, $services['inv25']->planKey], 'on a panel no connector speaks to: the core leaves it, by its panel');
    }

    public function testAServicesPlanIsTheProductOfItsNameSoldOnItsPanel(): void
    {
        $panel = static fn(string $id, string $name): array => ['id' => $id, 'name_panel' => $name, 'type' => 'marzban', 'url_panel' => "https://{$id}.example.com", 'username_panel' => 'admin', 'password_panel' => 'pw', 'version_panel' => '0', 'display_order' => $id];
        $product = static fn(string $code, string $location, string $price, string $volume = '30', string $hidden = '{}'): array => ['id' => $code, 'code_product' => $code, 'name_product' => 'یک ماهه', 'price_product' => $price, 'Volume_constraint' => $volume, 'Service_time' => '30', 'Location' => $location, 'agent' => 'f', 'hide_panel' => $hidden, 'display_order' => $code];
        $invoice = static fn(string $key, string $panel, string $volume = '30'): array => ['id_invoice' => $key, 'id_user' => '111111111', 'username' => "c{$key}", 'Service_location' => $panel, 'name_product' => 'یک ماهه', 'price_product' => '1000', 'Volume' => $volume, 'Service_time' => '30', 'Status' => 'active'];
        $staged = new ArrayStaged([
            'marzban_panel' => [$panel('1', 'آلمان'), $panel('2', 'هلند'), $panel('3', 'فرانسه'), $panel('4', 'ترکیه')],
            'product' => [
                $product('1', 'آلمان', '100000'),
                $product('2', 'هلند', '120000'),
                $product('3', '/all', '150000', '40', (string) json_encode(['آلمان', 'هلند', 'ترکیه'])),
            ],
            'invoice' => [$invoice('de', 'آلمان'), $invoice('nl', 'هلند'), $invoice('fr', 'فرانسه', '40'), $invoice('tr', 'ترکیه', '40')],
        ]);

        $plans = array_map(static fn(ImportedService $service): ?string => $service->planKey, self::byKey((new MirzaBotMigrator())->services($staged)));
        self::assertSame(
            ['de' => '1', 'nl' => '2', 'fr' => '3', 'tr' => null],
            $plans,
            'one name, a product for each panel: the one sold on the service\'s own panel, as MirzaBot renews it — never another panel\'s, at its price; none sold there of its name, traffic and days: no plan',
        );
    }

    public function testATestOrACustomServiceIsToldInAnyLanguageAndDisabledIsNeverLive(): void
    {
        $tests = ['سرویس تست', 'Test service', 'Тестовый сервис', '测试服务'];
        $custom = ['⚙️ سرویس دلخواه', '⚙️ Custom service', '⚙️ Произвольный сервис', '⚙️ 自定义服务'];
        $invoices = [];
        foreach ([...$tests, ...$custom, 'یک ماهه'] as $at => $name) {
            foreach (['active', 'disabled'] as $status) {
                $invoices[] = ['id_invoice' => "{$status}{$at}", 'id_user' => '111111111', 'username' => "c{$status}{$at}", 'Service_location' => 'آلمان', 'name_product' => $name, 'price_product' => '0', 'Volume' => '10', 'Service_time' => '30', 'Status' => $status];
            }
        }
        $staged = new ArrayStaged([
            'marzban_panel' => [['id' => '1', 'name_panel' => 'آلمان', 'type' => 'marzban', 'url_panel' => 'https://de.example.com', 'username_panel' => 'admin', 'password_panel' => 'pw', 'version_panel' => '0']],
            'product' => [['id' => '1', 'code_product' => 'p1', 'name_product' => 'یک ماهه', 'price_product' => '1000', 'Volume_constraint' => '10', 'Service_time' => '30', 'Location' => '/all', 'agent' => 'f', 'hide_panel' => '{}']],
            'invoice' => $invoices,
        ]);

        $services = self::byKey((new MirzaBotMigrator())->services($staged));
        self::assertSame(['active0', 'active1', 'active2', 'active3', 'active4', 'active5', 'active6', 'active7', 'active8'], array_keys($services), 'disabled: MirzaBot\'s test cron took the client off its panel, whatever it was');
        self::assertSame(
            [[true, null], [true, null], [true, null], [true, null], [false, null], [false, null], [false, null], [false, null], [false, 'p1']],
            array_values(array_map(static fn(ImportedService $service): array => [$service->test, $service->planKey], $services)),
            'a test, a custom build: no plan, though one of its traffic and days is sold',
        );
    }

    public function testDiscountCodesKeepTheirScopeAndSayWhatTheShopsCannot(): void
    {
        $codes = self::byKey((new MirzaBotMigrator())->discountCodes(self::staged()));

        self::assertSame(['SUMMER20', 'BUY10', 'USEDUP', 'NOLIMIT', 'EXT', 'AGENTN', 'AGENTN2', 'ONLYF', 'WEIRD', 'TYPEX', 'NOPRODUCT', 'NOPANEL', 'NOAGENT', 'EXPIRED'], array_keys($codes), 'one with no code is none');
        self::assertEquals(new ImportedDiscountCode(
            key: 'SUMMER20',
            code: 'SUMMER20',
            type: 'percent',
            value: '20',
            usesLeft: 70,
            perUser: 1,
            orderTypes: [OrderType::Purchase, OrderType::Renewal],
        ), $codes['SUMMER20'], 'by its code; «all» is what MirzaBot asks a code for: a purchase and a renewal');

        $buy = $codes['BUY10'];
        self::assertSame([45, 2, true, [OrderType::Purchase], ['p2'], ['هلند'], true, null, false], [$buy->usesLeft, $buy->perUser, $buy->firstPurchaseOnly, $buy->orderTypes, $buy->planKeys, $buy->panelKeys, $buy->enabled, $buy->note, $buy->usedUp()], 'a product (its plan) on a panel (by its code)');
        self::assertEquals(new \DateTimeImmutable('@2000000000'), $buy->endsAt);

        self::assertSame([0, 0, true, true], [$codes['USEDUP']->usesLeft, $codes['NOLIMIT']->usesLeft, $codes['USEDUP']->usedUp(), $codes['NOLIMIT']->usedUp()], 'used past its limit; a limit of 0, which MirzaBot never took: neither comes');
        self::assertSame([true, false], [$codes['EXPIRED']->ended(), $buy->ended()], 'one past its end does not come');

        $extend = $codes['EXT'];
        self::assertSame(['12.5', 0, true, null, [OrderType::Renewal], ['p1'], [], false, self::UNUSABLE], [$extend->value, $extend->usesLeft, $extend->usedUp(), $extend->perUser, $extend->orderTypes, $extend->planKeys, $extend->panelKeys, $extend->enabled, $extend->note], 'none a customer may use (useuser 0) comes used up; an agents\' twin is its plan; a panel code MirzaBot no longer has is none; a blank audience is no one MirzaBot knew');

        $agents = $codes['AGENTN'];
        self::assertSame([[OrderType::Purchase, OrderType::Renewal], [], null, false, 'فقط برای نماینده‌های عادی بود. ' . self::UNUSABLE], [$agents->orderTypes, $agents->planKeys, $agents->panelKeys, $agents->enabled, $agents->note], 'a blank type: no kind of order MirzaBot took it for (held to those it asks a code at); a product MirzaBot no longer has is none');
        self::assertSame([false, 'فقط برای نماینده‌های پیشرفته بود.'], [$codes['AGENTN2']->enabled, $codes['AGENTN2']->note]);
        self::assertSame([false, 'فقط برای کاربران عادی بود.'], [$codes['ONLYF']->enabled, $codes['ONLYF']->note], 'customers alone: here it would reach agents too');

        foreach (['WEIRD', 'TYPEX', 'NOAGENT'] as $code) {
            self::assertSame([[OrderType::Purchase, OrderType::Renewal], false, self::UNUSABLE], [$codes[$code]->orderTypes, $codes[$code]->enabled, $codes[$code]->note], "{$code}: an audience or a type MirzaBot does not know, or none — said once");
        }
        self::assertSame([null, false, self::UNUSABLE], [$codes['NOPRODUCT']->planKeys, $codes['NOPRODUCT']->enabled, $codes['NOPRODUCT']->note], 'no product: MirzaBot\'s query never matched it');
        self::assertSame([null, false, self::UNUSABLE], [$codes['NOPANEL']->panelKeys, $codes['NOPANEL']->enabled, $codes['NOPANEL']->note], 'no panel, likewise');
        self::assertSame(['percent'], array_values(array_unique(array_map(static fn(ImportedDiscountCode $code): string => $code->type, $codes))), 'MirzaBot\'s codes are a percent off');
    }

    public function testGiftCodesComeWithTheUsesTheyHaveLeft(): void
    {
        $gifts = self::byKey((new MirzaBotMigrator())->giftCodes(self::staged()));

        self::assertSame(['GIFT1', 'GIFT2', 'GIFT3', 'GIFT4'], array_keys($gifts), 'one with no code is none');
        self::assertEquals(new ImportedGiftCode(key: 'GIFT1', code: 'GIFT1', amount: '25000.00', usesLeft: 1), $gifts['GIFT1'], 'by its code');
        self::assertSame([0, 0, 2], [$gifts['GIFT2']->usesLeft, $gifts['GIFT3']->usesLeft, $gifts['GIFT4']->usesLeft], 'used up; a limit of 0 is used up, never unlimited');
        self::assertSame([[null, true, null]], array_values(array_unique(array_map(static fn(ImportedGiftCode $gift): array => [$gift->expiresAt, $gift->enabled, $gift->forCustomer], $gifts), SORT_REGULAR)), 'MirzaBot\'s gift codes have no end, switch or customer');
    }

    public function testChannelsAndCards(): void
    {
        $migrator = new MirzaBotMigrator();

        self::assertEquals([
            new ImportedChannel('@my_channel', '@my_channel', 'کانال ما', 'https://t.me/my_channel'),
            new ImportedChannel('-1001234567890', '-1001234567890', 'گروه', 'https://t.me/+AbCdEf'),
            new ImportedChannel('@news_channel', '@news_channel'),
            new ImportedChannel('@vpn_news', '@vpn_news', 'اخبار', 'http://t.me/vpn_news'),
            new ImportedChannel('https://t.me/joinchat/AbCdEf123', 'https://t.me/joinchat/AbCdEf123', 'خصوصی', 'https://t.me/joinchat/AbCdEf123'),
        ], array_values(iterator_to_array($migrator->channels(self::staged()))), 'by the chat MirzaBot checked: a bare username and a t.me link its @username; an invite link as it was; a join link only when it is one');

        self::assertEquals([
            new ImportedCard('6037997700001119', '6037997700001119', 'علی رضایی'),
            new ImportedCard('6219861012345675', '6219861012345675', 'بانک ملی'),
            new ImportedCard('5022291012345678', '5022291012345678', ''),
        ], array_values(iterator_to_array($migrator->cards(self::staged()))), 'by their digits however typed; no holder kept is none');
    }

    public function testTheSurveyCountsWhatComesAndWhatStays(): void
    {
        $survey = (new MirzaBotMigrator())->survey(self::staged());

        self::assertNull($survey->version);
        self::assertSame(
            ['customers' => 9, 'balances' => 3, 'services' => 12, 'tests' => 3, 'plans' => 8, 'discount_codes' => 14, 'gift_codes' => 4, 'channels' => 5, 'cards' => 3, 'resellers' => 2],
            $survey->counts,
        );
        self::assertSame('21000.00', $survey->balanceTotal, 'a debt taken off; reseller bots\' customers left out');
        self::assertEquals([new SurveyGroup('نماینده عادی', true, 1), new SurveyGroup('نماینده پیشرفته', true, 2)], $survey->groups, 'an agency past its end joins none');
        self::assertEquals(
            [new SurveyAdmin('999999999', 999999999, 'owner', 'administrator')],
            $survey->admins,
            'only the administrators, whom the owner\'s switch would make admins (Seller and support staff are a warning); an admin who never started the bot is no customer to make one',
        );
        self::assertEquals([
            new LeftBehind('تاریخچه پرداخت‌ها', 6),
            new LeftBehind('تیکت‌های پشتیبانی', 3),
            new LeftBehind('تاریخچه تمدیدها و خریدهای اضافه سرویس‌ها', 1),
            new LeftBehind('ربات‌های نمایندگی', 1),
            new LeftBehind(self::RESELLER_WALLETS, 2),
            new LeftBehind('کانفیگ‌های فروش دستی', 1),
            new LeftBehind('سرویس‌های پنل‌هایی که این فروشگاه کانکتورشان را ندارد', 1),
            new LeftBehind('سرویس‌های پنل‌هایی که در ربات قبلی حذف شده بودند', 1),
            new LeftBehind('قیمت‌های حجم و زمان اضافه و سرویس دلخواه پنل‌ها', 2),
            new LeftBehind('تنظیمات محصول‌ها (فقط برای مشتری تازه، ریست دوره‌ای حجم)', 2),
            new LeftBehind('تاریخچه استفاده از کدهای تخفیف و هدیه (سقف استفاده هر مشتری از نو شمرده می‌شود)', 2),
        ], $survey->left, 'the reseller bots\' customers\' wallets, in its files; a hand-sold config no longer live is none; Marzneshin\'s service (Rebecca\'s comes); the extras\' prices of panels that sold any');

        self::assertSame([
            '۱ مشتری در ربات قبلی بدهکار بودند (جمعا ۳۰٬۰۰۰ تومان)؛ کیف پولشان اینجا هم منفی می‌آید.',
            '۲ نفر از ادمین‌های ربات قبلی پشتیبان یا فروشنده بودند؛ در فهرست هستند ولی اینجا ادمین نمی‌شوند.',
            '۲ نماینده پیشرفته در ربات قبلی با موجودی منفی هم می‌خریدند (خرید اعتباری)؛ اینجا خرید با موجودی منفی نیست.',
            '۱ نماینده در ربات قبلی تاریخ پایان نمایندگی داشتند؛ این تاریخ منتقل نمی‌شود و اینجا نماینده می‌مانند تا خودتان عوضشان کنید.',
            'قیمت‌های جدای «نماینده پیشرفته» منتقل نمی‌شود: هر پلن اینجا یک قیمت نمایندگی دارد، همان قیمت «نماینده عادی» و اگر نبود، پیشرفته.',
            '۴ سرویس به هیچ پلنی وصل نشد (سرویس دلخواه بود، یا محصولش در ربات قبلی تغییر نام داده یا حذف شده بود)؛ بدون پلن می‌آید و با تمدید، پلن می‌گیرد.',
            '۱ پلن در ربات قبلی «ناموجود» بود؛ خاموش می‌آید تا خودتان روشنش کنید.',
            '۲ پرداخت در ربات قبلی هنوز منتظر تایید است؛ اول آن‌ها را آنجا تایید کنید و بعد پشتیبان تازه بگیرید، وگرنه این مبلغ‌ها به کیف پول آن مشتری‌ها نمی‌رسد.',
            '۲ مشتری در ربات قبلی از عضویت در کانال‌های اجباری معاف بودند؛ اینجا معاف نیستند.',
            '۲ مشتری در ربات قبلی کارت به کارت را نمی‌دیدند؛ اینجا هر کارت روشن به همه نشان داده می‌شود، پس پیش از روشن کردن کارت‌ها دقت کنید.',
            "لینک اشتراک مشتری‌های پنل «\u{2068}فرانسه\u{2069}» از دامنه ربات قبلی می‌گذشت؛ با خاموش شدن آن ربات این لینک‌ها کار نمی‌کنند و مشتری‌ها باید لینک تازه را از این ربات بگیرند.",
            self::UNCHECKED_TLS,
            "پنل «\u{2068}PG\u{2069}»، «\u{2068}هیدیفای\u{2069}» در ربات قبلی فقط به بخشی از مشتری‌ها (کاربران عادی یا یکی از سطح‌های نمایندگی) نشان داده می‌شد؛ اینجا پلن‌های روی آن به همه فروخته می‌شوند، پس پیش از روشن کردنشان بررسی کنید.",
            "پنل «\u{2068}آلمان ۱\u{2069}» در ربات قبلی از چند مشتری انتخاب‌شده پنهان بود؛ اینجا از کسی پنهان نمی‌ماند.",
        ], $survey->warnings, 'every advanced agent buys on credit, with no cap too; a still-running agency; no word on personal discounts (MirzaBot adds them on top of a code, as this shop does); a custom build, an unknown product\'s and one of two plans; a sold-out plan; the bot\'s own payments waiting (not a reseller bot\'s, nor a rejected one); its own customers excused from channels or kept from cards (not a reseller bot\'s); links through the bot of a panel with services alone; no old 3x-ui in a later MirzaBot; panels for one audience, or hidden from some (a «فروش دستی» one, an emptied list, «all» or none are no word)');
        self::assertDoesNotMatchRegularExpression('/[\x{064B}-\x{0652}\x{0654}]/u', implode('', $survey->warnings) . implode('', array_map(static fn(LeftBehind $left): string => $left->what, $survey->left)), 'no diacritics');
        self::assertArrayNotHasKey('connection', $survey->toArray()['panels'][0], 'no secret leaves the server');
    }

    public function testExcusedCustomersAndHiddenCardsAreAWordOnlyWhenChannelsOrCardsCome(): void
    {
        $tables = [
            'user' => [
                ['id' => '1001', 'Balance' => '0', 'User_Status' => 'Active', 'agent' => 'f', 'joinchannel' => 'active', 'cardpayment' => '0'],
                ['id' => '1002', 'Balance' => '0', 'User_Status' => 'Active', 'agent' => 'f', 'joinchannel' => '0', 'cardpayment' => '1'],
                ['id' => '1003', 'Balance' => '0', 'User_Status' => 'Active', 'agent' => 'f', 'joinchannel' => null, 'cardpayment' => null],
            ],
            'channels' => [['link' => '', 'remark' => 'خالی', 'linkjoin' => '']],
            'card_number' => [['cardnumber' => ' - ', 'namecard' => 'بی‌شماره']],
        ];
        $migrator = new MirzaBotMigrator();
        $about = static fn(array $warnings): array => array_values(array_filter($warnings, static fn(string $warning): bool => str_contains($warning, 'معاف') || str_contains($warning, 'کارت به کارت')));

        self::assertSame([], $about($migrator->survey(new ArrayStaged($tables))->warnings), 'no channel and no card that come');

        $tables['channels'][] = ['link' => '@my_channel', 'remark' => 'کانال', 'linkjoin' => ''];
        $tables['card_number'][] = ['cardnumber' => '6037997700001119', 'namecard' => 'علی'];
        self::assertSame([
            '۱ مشتری در ربات قبلی از عضویت در کانال‌های اجباری معاف بودند؛ اینجا معاف نیستند.',
            '۱ مشتری در ربات قبلی کارت به کارت را نمی‌دیدند؛ اینجا هر کارت روشن به همه نشان داده می‌شود، پس پیش از روشن کردن کارت‌ها دقت کنید.',
        ], $about($migrator->survey(new ArrayStaged($tables))->warnings), 'a MirzaBot that kept no switch (an older one) hid no card');
    }

    public function testMirzaBotHasNoWordOnPersonalDiscounts(): void
    {
        $tables = [
            'user' => [['id' => '111111111', 'username' => 'ali', 'number' => 'none', 'Balance' => '0', 'User_Status' => 'Active', 'agent' => 'f', 'pricediscount' => '10']],
            'discountsell' => [['id' => '1', 'codeDiscount' => 'A', 'price' => '10', 'limitDiscount' => '5', 'usedDiscount' => '0', 'agent' => 'allusers', 'usefirst' => '0', 'useuser' => '1', 'code_product' => 'all', 'code_panel' => '/all', 'time' => '0', 'type' => 'all']],
        ];

        self::assertSame([], (new MirzaBotMigrator())->survey(new ArrayStaged($tables))->warnings, 'its own percent falls on top of a code in MirzaBot, as here: a code that comes changes nothing for them');
    }

    public function testEachPanelMapsToItsConnectorWithTheConnectionItsFormTakes(): void
    {
        $panels = [];
        foreach ((new MirzaBotMigrator())->survey(self::staged())->panels as $panel) {
            $panels[$panel->key] = $panel;
        }

        self::assertSame(['مرزنشین', 'هلند', 'آلمان ۱', 'فرانسه', 'PG', 'ربکا', 'هیدیفای', 'بی‌رمز'], array_keys($panels), 'by display order (0 first), then id; no «فروش دستی» one');
        self::assertSame(
            [null, '3x-ui', 'marzban', '3x-ui', 'pasarguard', 'rebecca', null, 'marzban'],
            array_values(array_map(static fn(ImportedPanel $panel): ?string => $panel->driver, $panels)),
            'a Marzban panel whose version is 1 is PasarGuard',
        );
        self::assertSame(['marzneshin', 'x-ui_single', 'marzban', 'x-ui_single', 'marzban', 'rebecca', 'hiddify', 'marzban'], array_values(array_map(static fn(ImportedPanel $panel): string => $panel->type, $panels)), 'MirzaBot\'s word for its kind, as it is');
        self::assertSame([1, 7, 3, 1, 2, 1, 0, 0], array_values(array_map(static fn(ImportedPanel $panel): int => $panel->services, $panels)), 'tests among them');
        self::assertSame(['PG'], array_keys(array_filter($panels, static fn(ImportedPanel $panel): bool => !$panel->active)), 'closed to new sales in MirzaBot');
        self::assertSame(['هلند' => 150], array_filter(array_map(static fn(ImportedPanel $panel): ?int => $panel->capacity, $panels), static fn(?int $capacity): bool => $capacity !== null), 'its sales limit; «unlimited» and «unlimted» are none');

        $options = ['verify_tls' => true, 'timeout' => 30, 'subscription_url' => '', 'proxy_enabled' => false];
        self::assertSame(['base_url' => 'https://de1.example.com:8000', 'auth_mode' => 'password', 'username' => 'admin', 'password' => 'p@ss word'] + $options, $panels['آلمان ۱']->connection);
        self::assertSame(
            ['base_url' => 'https://nl.example.com:2053/AbCd', 'auth_mode' => 'token', 'api_token' => 'xui-token-123', 'verify_tls' => true, 'timeout' => 30, 'subscription_url' => 'https://sub.nl.example.com:2096/sub', 'proxy_enabled' => false],
            $panels['هلند']->connection,
            'its API token where MirzaBot keeps it (its username «null»); the links MirzaBot gave, so a customer\'s stays',
        );
        self::assertSame(['auth_mode' => 'token', 'api_token' => 'fr-token-456', 'subscription_url' => ''], array_intersect_key($panels['فرانسه']->connection, ['auth_mode' => 1, 'api_token' => 1, 'subscription_url' => 1]), 'a later MirzaBot (its settings in `config`) signs in with the token though the panel kept its username; links never set apart from the panel\'s address are the panel\'s own');
        self::assertSame(['base_url' => 'http://10.0.0.5:8000', 'auth_mode' => 'password', 'username' => 'root', 'password' => 'secret'] + $options, $panels['PG']->connection, 'PasarGuard on its admin\'s login');

        self::assertSame(['base_url' => 'https://rb.example.com', 'auth_mode' => 'token', 'api_token' => self::REBECCA_KEY] + $options, $panels['ربکا']->connection, 'Rebecca on the `rk_…` API key MirzaBot kept as its password');

        foreach (['مرزنشین', 'هیدیفای'] as $name) {
            self::assertSame([[], 'این فروشگاه هنوز کانکتوری برای این نوع پنل ندارد.', false], [$panels[$name]->connection, $panels[$name]->unsupported, $panels[$name]->addable()], $name);
        }
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

    public function testAMirzaBotBefore019SignedInTo3xUiWithALoginUnlessThePanelHadNoUsername(): void
    {
        // Before 0.1.9 MirzaBot used 3x-ui's older login API — though every panel had its «نسخه پنل» (version_panel) since
        // 0.1 —, kept no edited texts in its settings nor a `config` table, gave a panel no display order, and an extra's
        // price was a number.
        $staged = new ArrayStaged([
            'marzban_panel' => [
                ['id' => '1', 'name_panel' => 'قدیمی', 'code_panel' => 'ab12', 'type' => 'x-ui_single', 'status' => 'active', 'url_panel' => 'https://old.example.com:54321/', 'username_panel' => 'admin', 'password_panel' => 'pw', 'version_panel' => '0', 'linksubx' => 'https://old.example.com:2096/sub', 'subvip' => 'offsubvip', 'limit_panel' => 'unlimted', 'priceextravolume' => '4000'],
                ['id' => '2', 'name_panel' => 'مرز', 'code_panel' => 'cd34', 'type' => 'marzban', 'status' => 'active', 'url_panel' => 'http://mz.example.com:8000', 'username_panel' => 'root', 'password_panel' => 'toor', 'version_panel' => '0', 'subvip' => 'offsubvip', 'priceextravolume' => '0'],
                ['id' => '3', 'name_panel' => 'بی‌سرویس', 'code_panel' => 'ef56', 'type' => 'x-ui_single', 'status' => 'active', 'url_panel' => 'https://x2.example.com', 'username_panel' => 'a', 'password_panel' => 'b', 'version_panel' => '0', 'subvip' => 'offsubvip'],
                ['id' => '4', 'name_panel' => 'توکن', 'code_panel' => 'gh78', 'type' => 'x-ui_single', 'status' => 'active', 'url_panel' => 'https://x3.example.com', 'username_panel' => 'null', 'password_panel' => 'x3-token', 'version_panel' => '0', 'subvip' => 'offsubvip'],
                ['id' => '5', 'name_panel' => 'پاسارگارد', 'code_panel' => 'ij90', 'type' => 'marzban', 'status' => 'active', 'url_panel' => 'http://pg.example.com', 'username_panel' => 'root', 'password_panel' => 'pg', 'version_panel' => '1', 'subvip' => 'offsubvip'],
            ],
            'setting' => [['text_edit' => null]],
            'user' => [['id' => '111111111', 'username' => 'ali', 'number' => 'none', 'Balance' => '0', 'User_Status' => 'Active', 'agent' => 'f']],
            'product' => [['id' => '1', 'code_product' => 'aa11', 'name_product' => 'یک ماهه', 'price_product' => '1000', 'Volume_constraint' => '10', 'Service_time' => '30', 'Location' => '/all', 'agent' => 'f']],
            'invoice' => [['id_invoice' => 'i1', 'id_user' => '111111111', 'username' => 'c1', 'Service_location' => 'قدیمی', 'name_product' => 'یک ماهه', 'price_product' => '1000', 'Status' => 'active']],
        ]);
        $survey = (new MirzaBotMigrator())->survey($staged);

        $panels = [];
        foreach ($survey->panels as $panel) {
            $panels[$panel->key] = $panel;
        }
        self::assertSame(['قدیمی' => '3x-ui', 'مرز' => 'marzban', 'بی‌سرویس' => '3x-ui', 'توکن' => '3x-ui', 'پاسارگارد' => 'pasarguard'], array_map(static fn(ImportedPanel $panel): ?string => $panel->driver, $panels), 'by id; a version of 0 is Marzban, of 1 PasarGuard');
        self::assertSame(
            ['base_url' => 'https://old.example.com:54321', 'auth_mode' => 'password', 'username' => 'admin', 'password' => 'pw', 'verify_tls' => true, 'timeout' => 30, 'subscription_url' => 'https://old.example.com:2096/sub', 'proxy_enabled' => false],
            $panels['قدیمی']->connection,
            'its admin\'s login, as MirzaBot signed in — its «نسخه پنل» says nothing of that',
        );
        self::assertSame(['auth_mode' => 'token', 'api_token' => 'x3-token'], array_intersect_key($panels['توکن']->connection, ['auth_mode' => 1, 'api_token' => 1, 'username' => 1]), 'one with no username was added as MirzaBot adds 3x-ui panels since 0.1.9: on its token');
        self::assertSame(['auth_mode' => 'password', 'username' => 'root', 'password' => 'toor'], array_intersect_key($panels['مرز']->connection, ['auth_mode' => 1, 'username' => 1, 'password' => 1]));
        self::assertSame([
            sprintf(self::OLD_THREE_XUI, "«\u{2068}قدیمی\u{2069}»، «\u{2068}بی‌سرویس\u{2069}»"),
            self::UNCHECKED_TLS,
        ], $survey->warnings, 'each 3x-ui panel on its admin\'s login, with services or not; a settings row with no edited texts is no later MirzaBot');
        self::assertEquals([new LeftBehind('قیمت‌های حجم و زمان اضافه و سرویس دلخواه پنل‌ها', 1)], $survey->left, 'an extra priced as a bare number');
    }

    public function testA3xUiPanelThatKeptItsUsernameIsOnItsTokenInAnyMirzaBotLaterThan019(): void
    {
        $panel = ['id' => '1', 'name_panel' => 'هلند', 'type' => 'x-ui_single', 'url_panel' => 'https://nl.example.com', 'username_panel' => 'admin', 'password_panel' => 'nl-token', 'version_panel' => '0'];
        $migrator = new MirzaBotMigrator();
        $signIn = static function (array $tables) use ($migrator): array {
            $survey = $migrator->survey(new ArrayStaged($tables));

            return [$survey->panels[0]->connection['auth_mode'], $survey->panels[0]->connection['api_token'] ?? $survey->panels[0]->connection['password'], count(array_filter($survey->warnings, static fn(string $warning): bool => str_contains($warning, 'روش قدیمی')))];
        };

        self::assertSame(['token', 'nl-token', 0], $signIn(['marzban_panel' => [$panel], 'setting' => [['text_edit' => '{"start":"سلام"}']]]), '0.2.x: its settings kept edited texts');
        self::assertSame(['token', 'nl-token', 0], $signIn(['marzban_panel' => [$panel], 'config' => [['scope' => 'setting'], ['scope' => 'pay']]]), '0.9.3: its settings in `config`');
        self::assertSame(['password', 'nl-token', 1], $signIn(['marzban_panel' => [$panel], 'setting' => [['text_edit' => null]]]), 'before: its admin\'s login, and a word on it');
        foreach (['', 'none', 'null'] as $none) {
            self::assertSame(['token', 'nl-token', 0], $signIn(['marzban_panel' => [['username_panel' => $none] + $panel]]), "no username («{$none}»): as MirzaBot adds 3x-ui panels since 0.1.9");
        }
    }

    public function testARebeccaPanelIsAddedOnTheKeyMirzaBotKeptAndTheFormJudgesThatKey(): void
    {
        $rebecca = static fn(string $name, string $id, ?string $username, ?string $password): array => ['id' => $id, 'name_panel' => $name, 'type' => 'rebecca', 'url_panel' => 'https://rb.example.com', 'username_panel' => $username, 'password_panel' => $password, 'version_panel' => '0'];
        $survey = (new MirzaBotMigrator())->survey(new ArrayStaged([
            'marzban_panel' => [
                $rebecca('کلید', '1', 'admin', self::REBECCA_KEY),
                $rebecca('رمز', '2', 'admin', 'an old password'),
                $rebecca('خالی', '3', 'admin', 'null'),
            ],
        ]));
        $panels = [];
        foreach ($survey->panels as $panel) {
            $panels[$panel->key] = $panel;
        }

        self::assertSame(['کلید' => true, 'رمز' => true, 'خالی' => false], array_map(static fn(ImportedPanel $panel): bool => $panel->addable(), $panels), 'whatever MirzaBot kept as its password is the key; none is nothing to sign in with');
        self::assertSame([null, null, null], array_values(array_map(static fn(ImportedPanel $panel): ?string => $panel->unsupported, $panels)));
        self::assertSame(['token', 'an old password'], [$panels['رمز']->connection['auth_mode'], $panels['رمز']->connection['api_token']], 'never a login: Rebecca asks bots in by key alone');
        self::assertContains(self::UNCHECKED_TLS, $survey->warnings, 'a Rebecca panel added over https');

        $form = $this->service(ProviderRegistry::class)->find('rebecca')?->describe()->form;
        self::assertNotNull($form);
        self::assertSame(self::REBECCA_KEY, $form->check($panels['کلید']->connection, static fn(): mixed => null)['api_token']);
        try {
            $form->check($panels['رمز']->connection, static fn(): mixed => null);
            self::fail('a key that is no `rk_…` key is refused');
        } catch (ValidationException $e) {
            self::assertSame(['api_token'], array_keys($e->errors()), 'the server it would add is refused on its key (the core reports it not added)');
        }
    }

    public function testNoTlsWordWithoutAPanelAddedOverHttps(): void
    {
        $staged = new ArrayStaged([
            'marzban_panel' => [
                ['id' => '1', 'name_panel' => 'محلی', 'type' => 'marzban', 'url_panel' => 'http://10.0.0.9:8000', 'username_panel' => 'root', 'password_panel' => 'pw', 'version_panel' => '0', 'subvip' => 'onsubvip', 'display_order' => '1'],
                ['id' => '2', 'name_panel' => 'هیدیفای', 'type' => 'hiddify', 'url_panel' => 'https://hd.example.com', 'version_panel' => '0', 'display_order' => '2'],
            ],
        ]);

        self::assertSame([], (new MirzaBotMigrator())->survey($staged)->warnings, 'http alone, and https on a panel never added; links through the bot of a panel without services');
    }

    public function testEveryGeneratorGoesOnAfterTheRowItStoppedAt(): void
    {
        $migrator = new MirzaBotMigrator();
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
     * Customers who last opened a reseller bot (`bottype` its token), each but the first tied to the bot itself by one
     * thing: a wallet in credit or in debt, an agents' level, a reseller bot of their own, an admin's role, a purchase in
     * the bot itself (`invoice.bottype` blank, «0» or none — unpaid too).
     */
    private static function ties(): ArrayStaged
    {
        $user = static fn(string $id, array $row = []): array => $row + ['id' => $id, 'username' => "u{$id}", 'number' => 'none', 'Balance' => '0', 'User_Status' => 'Active', 'agent' => 'f', 'bottype' => 'AGENT_TOKEN_1'];
        $invoice = static fn(string $key, string $user, ?string $bot, string $status = 'active'): array => ['id_invoice' => $key, 'id_user' => $user, 'username' => "c{$key}", 'Service_location' => 'آلمان', 'name_product' => 'یک ماهه', 'price_product' => '1000', 'Status' => $status, 'bottype' => $bot];

        return new ArrayStaged([
            'marzban_panel' => [['id' => '1', 'name_panel' => 'آلمان', 'type' => 'marzban', 'url_panel' => 'https://de.example.com', 'username_panel' => 'admin', 'password_panel' => 'pw', 'version_panel' => '0']],
            'botsaz' => [['id' => '1', 'id_user' => '500000006', 'username' => 'agent_shop_bot', 'bot_token' => 'AGENT_TOKEN_1']],
            'admin' => [['id_admin' => '500000007', 'rule' => 'support']],
            'user' => [
                $user('500000001'),
                $user('500000002', ['Balance' => '1000']),
                $user('500000003', ['Balance' => '-500']),
                $user('500000004', ['agent' => 'n']),
                $user('500000005', ['agent' => 'n2']),
                $user('500000006'),
                $user('500000007'),
                $user('500000008'),
                $user('500000009'),
                $user('500000010'),
                $user('500000011', ['bottype' => 'GONE_TOKEN']),
                $user('500000012', ['bottype' => '0']),
            ],
            'invoice' => [
                $invoice('i1', '500000001', 'AGENT_TOKEN_1'),
                $invoice('i2', '500000002', 'AGENT_TOKEN_1'),
                $invoice('i3', '500000008', null),
                $invoice('i4', '500000009', ''),
                $invoice('i5', '500000010', '0', 'unpaid'),
                $invoice('i6', '500000011', 'GONE_TOKEN', 'unpaid'),
            ],
        ]);
    }

    /**
     * A small MirzaBot 0.9.3 shop: panels of every kind in an order of their own (one closed to sales, one with a sales
     * limit, one selling by hand, some for one audience or hidden from some customers), customers of every sort (an agency
     * ended, one still running, advanced agents with and without a cap to their credit, reseller bots' customers, some
     * excused from the channels or kept from the cards), products with agents' twins, one hidden from a panel and one
     * sold out, services under renamed products and custom builds, tests in every language, codes, cards, payments
     * awaiting approval in the bot and in a reseller bot, history.
     */
    private static function staged(): ArrayStaged
    {
        $panel = static fn(array $row): array => $row + ['status' => 'active', 'limit_panel' => 'unlimted', 'subvip' => 'offsubvip', 'version_panel' => '0', 'agent' => 'all', 'hide_user' => null, 'priceextravolume' => self::UNPRICED, 'priceextratime' => self::UNPRICED, 'pricecustomvolume' => self::UNPRICED, 'pricecustomtime' => self::UNPRICED];
        $product = static fn(array $row): array => $row + ['note' => '', 'one_buy_status' => '0', 'data_limit_reset' => 'no_reset', 'status_product' => 'active', 'hide_panel' => '{}'];
        $user = static fn(array $row): array => $row + ['username' => 'none', 'number' => 'none', 'Balance' => '0', 'User_Status' => 'Active', 'agent' => 'f', 'affiliates' => '0', 'register' => 'none', 'pricediscount' => '0', 'bottype' => '0', 'maxbuyagent' => '0', 'expire' => null, 'joinchannel' => '0', 'cardpayment' => '1'];
        $code = static fn(array $row): array => $row + ['usedDiscount' => '0', 'agent' => 'allusers', 'usefirst' => '0', 'useuser' => '1', 'code_product' => 'all', 'code_panel' => '/all', 'time' => '0', 'type' => 'all'];

        return new ArrayStaged([
            'marzban_panel' => [
                $panel(['id' => '1', 'name_panel' => 'آلمان ۱', 'code_panel' => 'a1b2', 'type' => 'marzban', 'url_panel' => 'https://de1.example.com:8000', 'username_panel' => 'admin', 'password_panel' => 'p@ss word', 'linksubx' => 'https://de1.example.com:8000', 'display_order' => '2', 'priceextravolume' => self::PRICED, 'hide_user' => '["123456789"]']),
                $panel(['id' => '2', 'name_panel' => 'هلند', 'code_panel' => 'c3d4', 'type' => 'x-ui_single', 'url_panel' => 'https://nl.example.com:2053/AbCd/', 'username_panel' => 'null', 'password_panel' => 'xui-token-123', 'linksubx' => 'https://sub.nl.example.com:2096/sub/', 'display_order' => '1', 'limit_panel' => '150', 'pricecustomtime' => '{"f":"3000","n":"0","n2":"0"}', 'hide_user' => '[]']),
                $panel(['id' => '3', 'name_panel' => 'فرانسه', 'code_panel' => 'e5f6', 'type' => 'x-ui_single', 'url_panel' => 'https://fr.example.com:54321/xui', 'username_panel' => 'admin', 'password_panel' => 'fr-token-456', 'linksubx' => 'https://fr.example.com:54321/xui', 'subvip' => 'onsubvip', 'display_order' => '3', 'limit_panel' => 'unlimited']),
                $panel(['id' => '4', 'name_panel' => 'PG', 'code_panel' => 'g7h8', 'type' => 'marzban', 'url_panel' => 'http://10.0.0.5:8000', 'username_panel' => 'root', 'password_panel' => 'secret', 'version_panel' => '1', 'status' => 'disable', 'display_order' => '3', 'agent' => 'n2']),
                $panel(['id' => '5', 'name_panel' => 'ربکا', 'code_panel' => 'm3n4', 'type' => 'rebecca', 'url_panel' => 'https://rb.example.com/', 'username_panel' => 'null', 'password_panel' => self::REBECCA_KEY, 'display_order' => '5']),
                $panel(['id' => '6', 'name_panel' => 'هیدیفای', 'code_panel' => 'o5p6', 'type' => 'hiddify', 'url_panel' => 'https://hd.example.com', 'username_panel' => 'null', 'password_panel' => 'hd-key', 'subvip' => 'onsubvip', 'display_order' => '6', 'agent' => 'f']),
                $panel(['id' => '7', 'name_panel' => 'دستی', 'code_panel' => 'q7r8', 'type' => 'Manualsale', 'url_panel' => 'null', 'username_panel' => 'null', 'password_panel' => 'null', 'display_order' => '0', 'priceextravolume' => self::PRICED, 'agent' => 'n', 'hide_user' => '["1"]']),
                $panel(['id' => '8', 'name_panel' => 'مرزنشین', 'code_panel' => 's9t0', 'type' => 'marzneshin', 'url_panel' => 'https://mn.example.com', 'username_panel' => 'admin', 'password_panel' => 'mn', 'display_order' => '0', 'agent' => '']),
                $panel(['id' => '9', 'name_panel' => 'بی‌رمز', 'code_panel' => 'u1v2', 'type' => 'marzban', 'url_panel' => 'https://nopass.example.com', 'username_panel' => 'null', 'password_panel' => 'null', 'display_order' => '9']),
            ],
            'user' => [
                $user(['id' => '111111111', 'username' => 'ali_shop', 'number' => '+989121234567', 'Balance' => '50000', 'register' => '1700000000', 'pricediscount' => '10', 'joinchannel' => 'active']),
                $user(['id' => '222222222', 'number' => 'confrim number by admin', 'Balance' => '-30000', 'agent' => 'n2', 'affiliates' => '111111111', 'register' => '1700000100', 'maxbuyagent' => '100000', 'cardpayment' => '0']),
                $user(['id' => '333333333', 'username' => 'banned_guy', 'number' => '989351112233', 'User_Status' => 'block', 'agent' => 'n', 'register' => '1700000200', 'expire' => '4000000000', 'joinchannel' => 'active']),
                $user(['id' => '444444444', 'username' => 'reseller_customer', 'register' => '1700000300', 'bottype' => 'AGENT_TOKEN_1', 'joinchannel' => 'active']),
                $user(['id' => '555555555', 'register' => '1700000400', 'bottype' => 'GONE_TOKEN', 'pricediscount' => '5', 'cardpayment' => '0']),
                $user(['id' => '666666666', 'username' => 'old_agent', 'agent' => 'n', 'register' => '1690000500', 'expire' => '1600000000']),
                $user(['id' => '777777777', 'username' => 'self_ref', 'Balance' => '1000', 'agent' => 'n2', 'affiliates' => '777777777', 'pricediscount' => '150', 'cardpayment' => '0']),
                $user(['id' => '888888888', 'username' => 'freebie', 'register' => '1700000800']),
                $user(['id' => '999999999', 'username' => 'owner', 'register' => '1690000000']),
            ],
            'admin' => [
                ['id_admin' => '999999999', 'rule' => 'administrator'],
                ['id_admin' => '333333333', 'rule' => 'support'],
                ['id_admin' => '222222222', 'rule' => 'Seller'],
                ['id_admin' => '121212121', 'rule' => 'administrator'],
            ],
            'botsaz' => [
                ['id' => '1', 'id_user' => '444000000', 'username' => 'agent_shop_bot', 'bot_token' => 'AGENT_TOKEN_1'],
            ],
            'product' => [
                $product(['id' => '1', 'code_product' => 'p1', 'name_product' => 'یک ماهه ۳۰ گیگ', 'price_product' => '150000', 'Volume_constraint' => '30', 'Service_time' => '30', 'Location' => '/all', 'agent' => 'f', 'category' => 'ماهانه', 'note' => 'بدون محدودیت کاربر', 'display_order' => '2']),
                $product(['id' => '2', 'code_product' => 'p1n2', 'name_product' => 'یک ماهه پیشرفته', 'price_product' => '110000', 'Volume_constraint' => '30', 'Service_time' => '30', 'Location' => '/all', 'agent' => 'n2', 'category' => 'ماهانه', 'display_order' => '3']),
                $product(['id' => '3', 'code_product' => 'p1n', 'name_product' => 'یک ماهه نماینده', 'price_product' => '120000', 'Volume_constraint' => '30.0', 'Service_time' => '30', 'Location' => '/all', 'agent' => 'n', 'category' => 'ماهانه', 'display_order' => '4']),
                $product(['id' => '4', 'code_product' => 'p2', 'name_product' => 'سه ماهه', 'price_product' => '400000', 'Volume_constraint' => '100', 'Service_time' => '90', 'Location' => 'هلند', 'agent' => 'f', 'category' => 'سه ماهه', 'display_order' => '1']),
                $product(['id' => '5', 'code_product' => 'p2b', 'name_product' => 'سه ماهه ویژه', 'price_product' => '420000', 'Volume_constraint' => '100', 'Service_time' => '90', 'Location' => 'هلند', 'agent' => 'f', 'category' => 'ویژه', 'display_order' => '5']),
                $product(['id' => '6', 'code_product' => 'p2n2', 'name_product' => 'سه ماهه نماینده', 'price_product' => '350000', 'Volume_constraint' => '100', 'Service_time' => '90', 'Location' => 'هلند', 'agent' => 'n2', 'category' => 'ویژه', 'display_order' => '6']),
                $product(['id' => '7', 'code_product' => 'p3', 'name_product' => 'نامحدود', 'price_product' => '1,000,000', 'Volume_constraint' => '0', 'Service_time' => '0', 'Location' => 'حذف‌شده', 'agent' => 'f', 'category' => '', 'one_buy_status' => '1', 'status_product' => 'disable', 'display_order' => '0']),
                $product(['id' => '8', 'code_product' => 'p4', 'name_product' => 'ویژه نمایندگان', 'price_product' => '90000', 'Volume_constraint' => '50', 'Service_time' => '60', 'Location' => 'PG', 'agent' => 'n', 'category' => 'ویژه', 'data_limit_reset' => 'month', 'display_order' => '7']),
                $product(['id' => '9', 'code_product' => 'p5', 'name_product' => 'دو ماهه', 'price_product' => '250000', 'Volume_constraint' => '60', 'Service_time' => '60', 'Location' => '/all', 'agent' => 'f', 'category' => 'دو ماهه', 'hide_panel' => (string) json_encode(['هلند']), 'display_order' => '8']),
                $product(['id' => '10', 'code_product' => 'p1', 'name_product' => 'یک ماهه دوم', 'price_product' => '160000', 'Volume_constraint' => '31', 'Service_time' => '30', 'Location' => '/all', 'agent' => 'f', 'category' => 'ماهانه', 'display_order' => '9']),
                $product(['id' => '11', 'code_product' => 'p6', 'name_product' => 'پیشرفته ویژه', 'price_product' => '70000', 'Volume_constraint' => '20', 'Service_time' => '15', 'Location' => 'آلمان ۱', 'agent' => 'n2', 'category' => 'ویژه', 'display_order' => '10']),
            ],
            'invoice' => [
                ['id_invoice' => 'inv1', 'id_user' => '111111111', 'username' => 'ali_1', 'Service_location' => 'آلمان ۱', 'name_product' => 'یک ماهه ۳۰ گیگ', 'price_product' => '150000', 'Status' => 'active', 'note' => 'گوشی', 'bottype' => null],
                ['id_invoice' => 'inv2', 'id_user' => '222222222', 'username' => 'agent_2', 'Service_location' => 'هلند', 'name_product' => 'یک ماهه نماینده', 'price_product' => '120000', 'Status' => 'end_of_volume', 'note' => null],
                ['id_invoice' => 'inv3', 'id_user' => '333333333', 'username' => 'test_3', 'Service_location' => 'فرانسه', 'name_product' => 'سرویس تست', 'price_product' => '0', 'Status' => 'active'],
                ['id_invoice' => 'inv4', 'id_user' => '111111111', 'username' => 'test_en', 'Service_location' => 'آلمان ۱', 'name_product' => 'Test service', 'price_product' => '0', 'Status' => 'active'],
                ['id_invoice' => 'inv5', 'id_user' => '111111111', 'username' => 'test_ru', 'Service_location' => 'هلند', 'name_product' => 'Тестовый сервис', 'price_product' => '0', 'Status' => 'send_on_hold'],
                ['id_invoice' => 'inv6', 'id_user' => '666666666', 'username' => 'test_zh', 'Service_location' => 'PG', 'name_product' => '测试服务', 'price_product' => '0', 'Status' => 'disabled'],
                ['id_invoice' => 'inv7', 'id_user' => '111111111', 'username' => 'ali_old', 'Service_location' => 'آلمان ۱', 'name_product' => 'یک ماهه ۳۰ گیگ', 'price_product' => '150000', 'Status' => 'removebyuser'],
                ['id_invoice' => 'inv8', 'id_user' => '777777777', 'username' => 'manual_8', 'Service_location' => 'دستی', 'name_product' => 'دستی', 'price_product' => '50000', 'Status' => 'active'],
                ['id_invoice' => 'inv9', 'id_user' => '777777777', 'username' => 'gone_9', 'Service_location' => 'حذف‌شده', 'name_product' => 'نامحدود', 'price_product' => '1000000', 'Status' => 'active'],
                ['id_invoice' => 'inv10', 'id_user' => '444444444', 'username' => 'res_10', 'Service_location' => 'PG', 'name_product' => 'دو ماهه', 'price_product' => '400000', 'Status' => 'sendedwarn', 'bottype' => 'AGENT_TOKEN_1'],
                ['id_invoice' => 'inv11', 'id_user' => '111111111', 'username' => 'rb_11', 'Service_location' => 'ربکا', 'name_product' => 'سه ماهه', 'price_product' => '400000', 'Status' => 'disabled'],
                ['id_invoice' => 'inv12', 'id_user' => '555555555', 'username' => 'unpaid_12', 'Service_location' => 'آلمان ۱', 'name_product' => 'سه ماهه', 'price_product' => '400000', 'Status' => 'unpaid', 'bottype' => 'GONE_TOKEN'],
                ['id_invoice' => 'inv13', 'id_user' => '999999999', 'username' => 'custom_13', 'Service_location' => 'PG', 'name_product' => '⚙️ سرویس دلخواه', 'price_product' => '200000', 'Volume' => '50', 'Service_time' => '60', 'Status' => 'disablebyadmin'],
                ['id_invoice' => 'inv14', 'id_user' => '111111111', 'username' => 'renamed_14', 'Service_location' => 'هلند', 'name_product' => 'یک ماهه قدیمی', 'Volume' => '30', 'Service_time' => '30', 'Status' => 'active'],
                ['id_invoice' => 'inv15', 'id_user' => '111111111', 'username' => 'twofold_15', 'Service_location' => 'هلند', 'name_product' => 'سه ماهه قدیمی', 'Volume' => '100', 'Service_time' => '90', 'Status' => 'active'],
                ['id_invoice' => 'inv16', 'id_user' => '222222222', 'username' => 'hidden_16', 'Service_location' => 'آلمان ۱', 'name_product' => 'دو ماهه قدیمی', 'Volume' => '60', 'Service_time' => '60', 'Status' => 'end_of_time'],
                ['id_invoice' => 'inv17', 'id_user' => '222222222', 'username' => 'hidden_17', 'Service_location' => 'هلند', 'name_product' => 'دو ماهه قدیمی', 'Volume' => '60', 'Service_time' => '60', 'Status' => 'active'],
                ['id_invoice' => 'inv18', 'id_user' => '888888888', 'username' => 'gift_18', 'Service_location' => 'هلند', 'name_product' => 'یک ماهه ۳۰ گیگ', 'price_product' => '0', 'Status' => 'active', 'note' => ''],
                ['id_invoice' => 'inv19', 'id_user' => '888888888', 'username' => 'fail_19', 'Service_location' => 'آلمان ۱', 'name_product' => 'یک ماهه ۳۰ گیگ', 'price_product' => '150000', 'Status' => 'Unsuccessful'],
                ['id_invoice' => 'inv20', 'id_user' => '999999999', 'username' => 'gone_20', 'Service_location' => 'آلمان ۱', 'name_product' => 'یک ماهه ۳۰ گیگ', 'price_product' => '150000', 'Status' => 'disabledn'],
                ['id_invoice' => 'inv21', 'id_user' => '111111111', 'username' => null, 'Service_location' => 'آلمان ۱', 'name_product' => 'یک ماهه ۳۰ گیگ', 'price_product' => '150000', 'Status' => 'active'],
                ['id_invoice' => 'inv22', 'id_user' => '777777777', 'username' => 'manual_22', 'Service_location' => 'دستی', 'name_product' => 'دستی', 'price_product' => '50000', 'Status' => 'removebyadmin'],
                ['id_invoice' => 'inv23', 'id_user' => '111111111', 'username' => 'rb_23', 'Service_location' => 'ربکا', 'name_product' => 'یک ماهه ۳۰ گیگ', 'price_product' => '400000', 'Status' => 'end_of_time'],
                ['id_invoice' => 'inv24', 'id_user' => '111111111', 'username' => 'custom_24', 'Service_location' => 'هلند', 'name_product' => '⚙️ Custom service', 'price_product' => '90000', 'Volume' => '30', 'Service_time' => '30', 'Status' => 'active'],
                ['id_invoice' => 'inv25', 'id_user' => '111111111', 'username' => 'mn_25', 'Service_location' => 'مرزنشین', 'name_product' => 'دو ماهه', 'price_product' => '400000', 'Status' => 'active'],
            ],
            'discountsell' => [
                $code(['id' => '1', 'codeDiscount' => 'SUMMER20', 'price' => '20', 'limitDiscount' => '100', 'usedDiscount' => '30']),
                $code(['id' => '2', 'codeDiscount' => 'BUY10', 'price' => '10', 'limitDiscount' => '50', 'usedDiscount' => '5', 'usefirst' => '1', 'useuser' => '2', 'code_product' => 'p2', 'code_panel' => 'c3d4', 'time' => '2000000000', 'type' => 'buy']),
                $code(['id' => '3', 'codeDiscount' => 'USEDUP', 'price' => '10', 'limitDiscount' => '5', 'usedDiscount' => '7']),
                $code(['id' => '4', 'codeDiscount' => 'NOLIMIT', 'price' => '10', 'limitDiscount' => '0']),
                $code(['id' => '5', 'codeDiscount' => 'EXT', 'price' => '12.5', 'limitDiscount' => '10', 'usedDiscount' => '3', 'agent' => '', 'useuser' => '0', 'code_product' => 'p1n', 'code_panel' => 'zzzz', 'type' => 'extend']),
                $code(['id' => '6', 'codeDiscount' => 'AGENTN', 'price' => '5', 'limitDiscount' => '10', 'agent' => 'n', 'code_product' => 'gone', 'type' => '']),
                $code(['id' => '7', 'codeDiscount' => 'AGENTN2', 'price' => '5', 'limitDiscount' => '10', 'agent' => 'n2']),
                $code(['id' => '8', 'codeDiscount' => 'ONLYF', 'price' => '5', 'limitDiscount' => '10', 'agent' => 'f']),
                $code(['id' => '9', 'codeDiscount' => 'WEIRD', 'price' => '5', 'limitDiscount' => '10', 'agent' => 'vip', 'type' => 'gift']),
                $code(['id' => '10', 'codeDiscount' => 'TYPEX', 'price' => '5', 'limitDiscount' => '10', 'type' => 'charge']),
                $code(['id' => '11', 'codeDiscount' => '', 'price' => '5', 'limitDiscount' => '10']),
                $code(['id' => '12', 'codeDiscount' => 'NOPRODUCT', 'price' => '5', 'limitDiscount' => '10', 'code_product' => null]),
                $code(['id' => '13', 'codeDiscount' => 'NOPANEL', 'price' => '5', 'limitDiscount' => '10', 'code_panel' => '']),
                $code(['id' => '14', 'codeDiscount' => 'NOAGENT', 'price' => '5', 'limitDiscount' => '10', 'agent' => null, 'type' => null]),
                $code(['id' => '15', 'codeDiscount' => 'EXPIRED', 'price' => '5', 'limitDiscount' => '10', 'time' => '1600000000']),
            ],
            'discount' => [
                ['id' => '1', 'code' => 'GIFT1', 'price' => '25000', 'limituse' => '1', 'limitused' => '0'],
                ['id' => '2', 'code' => 'GIFT2', 'price' => '10000', 'limituse' => '5', 'limitused' => '5'],
                ['id' => '3', 'code' => 'GIFT3', 'price' => '5000', 'limituse' => '0', 'limitused' => '12'],
                ['id' => '4', 'code' => 'GIFT4', 'price' => '5000', 'limituse' => '3', 'limitused' => '1'],
                ['id' => '5', 'code' => ' ', 'price' => '5000', 'limituse' => '3', 'limitused' => '0'],
            ],
            'channels' => [
                ['link' => '@my_channel', 'remark' => 'کانال ما', 'linkjoin' => 'https://t.me/my_channel'],
                ['link' => '-1001234567890', 'remark' => 'گروه', 'linkjoin' => 'https://t.me/+AbCdEf'],
                ['link' => 'news_channel', 'remark' => '', 'linkjoin' => 'not a link'],
                ['link' => 'https://t.me/vpn_news', 'remark' => 'اخبار', 'linkjoin' => 'http://t.me/vpn_news'],
                ['link' => 'https://t.me/joinchat/AbCdEf123', 'remark' => 'خصوصی', 'linkjoin' => 'https://t.me/joinchat/AbCdEf123'],
                ['link' => '', 'remark' => 'خالی', 'linkjoin' => ''],
            ],
            'card_number' => [
                ['cardnumber' => '6037997700001119', 'namecard' => 'علی رضایی'],
                ['cardnumber' => '۶۲۱۹-۸۶۱۰ ۱۲۳۴ ۵۶۷۵', 'namecard' => 'بانک ملی'],
                ['cardnumber' => '5022 2910 1234 5678', 'namecard' => 'none'],
                ['cardnumber' => ' - ', 'namecard' => 'بی‌شماره'],
            ],
            'payment_report' => [
                ['id' => '1', 'payment_Status' => 'paid', 'bottype' => null],
                ['id' => '2', 'payment_Status' => 'waiting', 'bottype' => null],
                ['id' => '3', 'payment_Status' => 'reject', 'bottype' => null],
                ['id' => '4', 'payment_Status' => 'waiting', 'bottype' => '0'],
                ['id' => '5', 'payment_Status' => 'waiting', 'bottype' => 'AGENT_TOKEN_1'],
                ['id' => '6', 'payment_Status' => 'waiting', 'bottype' => 'GONE_TOKEN'],
            ],
            'support_message' => [['id' => '1', 'Tracking' => 't1'], ['id' => '2', 'Tracking' => 't1'], ['id' => '3', 'Tracking' => 't2'], ['id' => '4', 'Tracking' => null]],
            'service_other' => [['id' => '1']],
            'giftcodeconsumed' => [['id' => '1'], ['id' => '2']],
            'config' => [['scope' => 'setting'], ['scope' => 'pay'], ['scope' => 'shop'], ['scope' => 'topic']],
        ]);
    }
}
