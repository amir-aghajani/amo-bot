<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanCategory;
use App\Modules\Discounts\Models\DiscountCode;
use App\Modules\Imports\Enums\ImportPhase;
use App\Modules\Imports\Imports;
use App\Modules\Imports\Models\Import;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Payments\Models\PaymentCard;
use App\Modules\Providers\Models\Server;
use App\Modules\Rewards\Models\GiftCode;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Models\BotChannel;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\HttpTestCase;
use Tests\Support\FakeTelegram;

/**
 * A Faoxima shop brought over end to end through the owner's panel, by the real pieces — the sources reading its backup,
 * the Faoxima migrator, the core writing — from tests/Fixtures/imports/faoxima: Faoxima's own backup (backup.zip, its
 * backup.sql beside its vpnbot/ folder) and a mysqldump export of the same data read to the same survey; its panels
 * mapped to the shop's Marzban, 3x-ui, PasarGuard and Rebecca servers, whose panels only list (and Rebecca read) their
 * clients; everything it brings in the shop as Faoxima had it, quietly, nothing written to a panel; the same import
 * again bringing nothing twice; and the same shop when every step stops a few rows in.
 */
final class FaoximaEndToEndTest extends HttpTestCase
{
    use ImportSteps;

    private const FIXTURES = __DIR__ . '/../../Fixtures/imports/faoxima';

    /**
     * What the panels may be asked, in a run's order: Marzban's sign-in, its admin (a sudo one, who sees every user) and
     * users, 3x-ui's clients and settings, PasarGuard's users, Rebecca's admin (a sudo one), users and — its listing
     * leaving the device limit unsaid — the one user the import adopts there.
     */
    private const READS = [
        'POST de.vpnshop.example/api/admin/token',
        'GET de.vpnshop.example/api/admin',
        'GET de.vpnshop.example/api/users',
        'GET nl.vpnshop.example/Xk9pQ/panel/api/clients/list',
        'POST nl.vpnshop.example/Xk9pQ/panel/api/setting/all',
        'GET fi.vpnshop.example/api/users',
        'GET uk.vpnshop.example/api/admin',
        'GET uk.vpnshop.example/api/users',
        'GET uk.vpnshop.example/api/user/rz_uk',
    ];

    /** rz_uk's subscription token on Rebecca: base64url("rz_uk,1730500000") and its 10-character signature. */
    private const REBECCA_TOKEN = 'cnpfdWssMTczMDUwMDAwMA' . 'Xq7Lm2Pz9K';

    /** The required channel as Telegram answers for it. */
    private const CHANNEL = ['id' => -1001234500001, 'type' => 'channel', 'title' => 'کانال اطلاع‌رسانی', 'username' => 'vpnshop_news'];

    /** @var list<string> Every request the panels got, "METHOD host/path" */
    private array $panelCalls = [];

    private Server $germany;
    private Server $netherlands;
    private Server $finland;
    private Server $england;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
        // The shop's servers on Faoxima's panels: Marzban with its admin's login, 3x-ui, PasarGuard and Rebecca with a token.
        $this->germany = Server::query()->create(['name' => 'آلمان', 'driver' => 'marzban', 'base_url' => 'https://de.vpnshop.example:8000', 'username' => 'admin', 'password' => 'secret', 'is_active' => true, 'serves_subscriptions' => true]);
        $this->netherlands = Server::query()->create(['name' => 'هلند', 'driver' => '3x-ui', 'base_url' => 'https://nl.vpnshop.example:2053/Xk9pQ', 'api_token' => 'xui-token', 'is_active' => true, 'serves_subscriptions' => true, 'meta' => ['subscription_url' => 'https://nl.vpnshop.example:2096/sub']]);
        $this->finland = Server::query()->create(['name' => 'فنلاند', 'driver' => 'pasarguard', 'base_url' => 'https://fi.vpnshop.example', 'api_token' => 'pg-key', 'is_active' => true, 'serves_subscriptions' => true]);
        $this->england = Server::query()->create(['name' => 'انگلیس', 'driver' => 'rebecca', 'base_url' => 'https://uk.vpnshop.example', 'api_token' => 'rk_ShopsOwnRebeccaKeyForTestsOnly_0123456789ab', 'is_active' => true, 'serves_subscriptions' => true]);

        // The panels answer by address, however many times and in whatever order a run asks (the fake panel's own queue
        // answers in order); put back offline once the test is over, as the fake panel is.
        $this->panelHttp();
        $transport = $this->app()->container()->get('http.transport');
        self::assertInstanceOf(HandlerStack::class, $transport);
        $transport->setHandler($this->panels(...));

        $this->telegram()->on('getChat', static fn(array $params): mixed => $params['chat_id'] === '@vpnshop_news' ? self::CHANNEL : FakeTelegram::error(400, 'Bad Request: chat not found'));
        $this->telegram()->on('getChatMember', static fn(): array => ['status' => 'administrator', 'user' => ['id' => FakeTelegram::BOT_ID]]);
    }

    public function testFaoximasOwnBackupAndAMysqldumpExportAreReadToTheSameSurvey(): void
    {
        $zip = $this->read('backup.zip', $this->faoximaBackup());
        self::assertSame(['review', 'backup.zip', null], [$zip['phase'], $zip['file_name'], $zip['version']]);

        $survey = $zip['survey'];
        self::assertSame(['customers' => 8, 'balances' => 5, 'services' => 6, 'tests' => 1, 'plans' => 4, 'discount_codes' => 4, 'gift_codes' => 4, 'channels' => 1, 'cards' => 2, 'resellers' => 1], $survey['counts'], "the customer who last opened Sara's reseller bot, with a wallet and a purchase here, the bot's own");
        self::assertSame('437000.00', $survey['balance_total'], "85,000 + 420,000 − 125,000 + 12,000 + 45,000: the reseller bot's own customer (no wallet here) left out");
        self::assertSame([
            ['آلمان-مرزبان', 'marzban', 'marzban', true, 2, $this->germany->id, null],
            ['هلند-ثنایی', 'x-ui_single', '3x-ui', true, 2, $this->netherlands->id, null],
            ['PasarGuard-FI', 'pasarguard', 'pasarguard', true, 2, $this->finland->id, null],
            ['Rebecca-UK', 'rebecca', 'rebecca', true, 1, $this->england->id, null],
        ], array_map(static fn(array $panel): array => [$panel['key'], $panel['type'], $panel['driver'], $panel['addable'], $panel['services'], $panel['suggested_server_id'], $panel['unsupported']], $survey['panels']), 'no panel of manual sales; Rebecca on its connector, by the rk_ key Faoxima kept; each suggested on the shop\'s server at its address');
        self::assertSame([['name' => 'نماینده عادی', 'agent_pricing' => true, 'customers' => 1], ['name' => 'نماینده پیشرفته', 'agent_pricing' => true, 'customers' => 1]], $survey['groups']);
        self::assertSame([
            ['key' => '1000000001', 'telegram_id' => 1000000001, 'username' => 'shop_owner', 'role' => 'administrator'],
        ], $survey['admins']);
        self::assertSame([
            'تاریخچه پرداخت‌ها' => 3,
            'تیکت‌های پشتیبانی' => 2,
            'تاریخچه کیف پول' => 3,
            'تاریخچه تمدیدها و خریدهای اضافه سرویس‌ها' => 2,
            'ربات‌های نمایندگی' => 1,
            'کیف پول مشتری‌های ربات‌های نمایندگی (در فایل‌های ربات قبلی است، نه دیتابیسش)' => 1,
            'کانفیگ‌های انبار و فروش دستی' => 2,
            'قیمت‌های حجم و زمان اضافه و سرویس دلخواه پنل‌ها' => 4,
            'تاریخچه استفاده از کدهای تخفیف و هدیه (سقف استفاده هر مشتری از نو شمرده می‌شود)' => 2,
        ], array_column($survey['left'], 'count', 'what'), 'three messages in two tickets; the reseller bot\'s own customer\'s wallet, in its files; a stock config and a manual sale; each panel\'s prices for extras; a gift code used twice — every panel\'s services come');
        self::assertCount(7, $survey['warnings']);
        self::assertSame('۱ مشتری در ربات قبلی بدهکار بودند (جمعا ۱۲۵٬۰۰۰ تومان)؛ کیف پولشان اینجا هم منفی می‌آید.', $survey['warnings'][0]);
        self::assertSame('۱ نفر از ادمین‌های ربات قبلی پشتیبان یا فروشنده بودند؛ در فهرست هستند ولی اینجا ادمین نمی‌شوند.', $survey['warnings'][1]);
        self::assertSame('۱ مشتری تخفیف شخصی دارند؛ ربات قبلی کد تخفیف را از آن‌ها نمی‌پذیرفت، ولی اینجا کدهای تخفیف برایشان هم کار می‌کند.', $survey['warnings'][3], 'mina_ref\'s 15%, with codes coming');
        self::assertStringContainsString("«\u{2068}هلند-ثنایی\u{2069}»", $survey['warnings'][5], 'its links went through the old bot (subvip)');
        self::assertSame(['آلمان-مرزبان' => 'server', 'هلند-ثنایی' => 'server', 'PasarGuard-FI' => 'server', 'Rebecca-UK' => 'server'], array_map(static fn(array $choice): string => $choice['action'], $zip['proposed']['panels']));
        self::assertSame([], $this->panelCalls, 'reading asks no panel');

        self::assertSame(204, $this->deleteJson("/api/admin/imports/{$zip['id']}")->getStatusCode());
        $dump = $this->read('backup-mysqldump.sql', (string) file_get_contents(self::FIXTURES . '/backup-mysqldump.sql'));
        self::assertSame($survey, $dump['survey'], 'the same data, whichever tool wrote it');
    }

    public function testTheWholeShopComesOverQuietlyAndThePanelsAreOnlyRead(): void
    {
        $import = $this->read('backup.zip', $this->faoximaBackup());
        $saved = $this->decode($this->putJson("/api/admin/imports/{$import['id']}/choices", ['choices' => $this->choices()]))['import'];
        self::assertSame([true, true, false, false], [$saved['choices']['admins'], $saved['choices']['channels'], $saved['choices']['resellers'], $saved['choices']['plans_on']]);
        self::assertSame('importing', $this->decode($this->postJson("/api/admin/imports/{$import['id']}/start"))['import']['phase']);

        $done = $this->continueWhile($import['id'], 'importing');

        self::assertSame(['done', null], [$done['phase'], $done['error']]);
        $this->assertTheShopCameOver($done);
        self::assertSame(self::READS, $this->panelCalls, 'each panel listed once, nothing written to any');
        self::assertSame(['getChat', 'getChatMember'], $this->telegram()->calls(), 'quiet: the channel looked up, nothing sent to a customer or the report group');
        self::assertSame(0, ImportRow::query()->count(), 'the staged copy gone once it ended');

        $problems = $this->decode($this->get("/api/admin/imports/{$import['id']}/problems"));
        self::assertSame([
            ['plans', 'pagt', 'agents_only_plan', 'پلن ویژه همکاران'],
            ['gift_codes', 'GIFT-YALDA', 'multi_use_gift', "\u{2068}GIFTYALDA\u{2069} — ۳۶ بار مانده"],
            ['gift_codes', 'GIFT-SARA', 'one_customer', "\u{2068}GIFTSARA\u{2069} — ۵۰٬۰۰۰ تومان — برای مشتری با شناسه تلگرام \u{2068}5012345672\u{2069}"],
            ['cards', '6219861012345675', 'card_invalid', "\u{2068}6219861012345675\u{2069}"],
        ], array_map(static fn(array $problem): array => [$problem['kind'], $problem['source_key'], $problem['reason'], $problem['detail']], $problems['problems']), 'a row for each to look into; the rest only counted');
    }

    public function testTheSameImportAgainBringsNothingTwice(): void
    {
        $first = $this->importAll();
        self::assertSame('customer', User::query()->where('telegram_id', 1000000001)->sole()->role->value, 'admins only when chosen: by default nobody is made one');
        self::assertSame(0, BotChannel::query()->count(), 'channels only when chosen');
        $shop = $this->shop();
        $this->panelCalls = [];

        $again = $this->importAll();

        self::assertNotSame($first['id'], $again['id']);
        self::assertSame($shop, $this->shop(), 'not a customer, a balance, a plan, a service, a code or a card brought twice');
        self::assertSame(5, WalletTransaction::query()->count(), 'each balance credited once');
        self::assertSame(['customers' => 7, 'groups' => 2, 'plans' => 4, 'services' => 6, 'discount_codes' => 3, 'gift_codes' => 1, 'cards' => 1], array_filter(array_map(static fn(array $count): int => $count['linked'], $again['counts'])), 'what the first brought, recognised');
        self::assertSame(0, array_sum(array_column($again['counts'], 'created')));
        self::assertSame([], $this->panelCalls, 'every service recognised by what it became: no panel asked again');
    }

    public function testEveryStepStoppingAFewRowsInGivesTheSameShop(): void
    {
        $imports = $this->service(Imports::class);
        $bytes = $this->faoximaBackup();
        $import = $imports->fromBackup('faoxima', new UploadedFile((new StreamFactory())->createStream($bytes), 'backup.zip', null, strlen($bytes)), 'root');

        $reading = $this->stepWhile($import, ImportPhase::Reading, 3);
        self::assertGreaterThan(5, $reading, 'read a few statements a step');
        self::assertSame(ImportPhase::Review, $import->phase);
        $imports->choose($import, $this->choices());
        $imports->start($import);

        // A step writes two records; where each stopped, as the next one goes on from it.
        $stops = [];
        $this->stepWhile($import, ImportPhase::Importing, 2, function (Import $import) use (&$stops): void {
            $stops[] = [$import->stage?->value, $import->cursor];
        });

        self::assertSame(ImportPhase::Done, $import->phase, (string) $import->error);
        $midway = array_unique(array_column(array_filter($stops, static fn(array $stop): bool => $stop[0] !== null && $stop[1] > 0), 0));
        self::assertSame([], array_values(array_diff(['customers', 'plans', 'services', 'discount_codes', 'gift_codes'], $midway)), 'stopped in the middle of each long stage, and went on from there');
        $this->assertTheShopCameOver($imports->present($import));
        self::assertSame([], array_values(array_diff(array_unique($this->panelCalls), self::READS)), 'every step only read the panels');
        self::assertSame(['getChat', 'getChatMember'], $this->telegram()->calls());
    }

    /**
     * Everything the import brought, in the shop as Faoxima had it, and its report: what each kind came to and the
     * problems by reason.
     *
     * @param array<string, mixed> $import The import as it ended (ImportState)
     */
    private function assertTheShopCameOver(array $import): void
    {
        $shop = $this->shop();

        self::assertSame([
            // [username, phone, status, role, discount %, brought by, groups, balance]
            1000000001 => ['shop_owner', null, 'active', 'admin', 0, null, [], '0.00'],
            5012345671 => ['reza_mohammadi', '+989121112233', 'active', 'customer', 0, null, [], '85000.00'],
            5012345672 => ['sara_agent', '+989351234567', 'active', 'customer', 0, null, ['نماینده عادی'], '420000.00'],
            5012345673 => [null, null, 'active', 'customer', 0, null, ['نماینده پیشرفته'], '-125000.00'],
            5012345674 => ['blocked_user', null, 'banned', 'customer', 0, null, [], '0.00'],
            5012345675 => ['mina_ref', null, 'active', 'customer', 15, 5012345671, [], '12000.00'],
            5012345677 => ['loyal_visitor', null, 'active', 'customer', 0, null, [], '45000.00'],
        ], $shop['customers'], "Faoxima's administrator an admin (chosen), its support staff not; the agents in their groups; one who last opened Sara's reseller bot (`bottype`) but has a wallet and a purchase here the bot's own; the reseller bot's own customer left out");
        self::assertSame(1730000000, User::query()->where('telegram_id', 5012345671)->sole()->created_at?->getTimestamp(), 'a customer since they joined Faoxima');

        self::assertSame([
            [5012345671, 'credit', '85000.00', '85000.00'],
            [5012345672, 'credit', '420000.00', '420000.00'],
            [5012345673, 'debit', '125000.00', '-125000.00'],
            [5012345675, 'credit', '12000.00', '12000.00'],
            [5012345677, 'credit', '45000.00', '45000.00'],
        ], $shop['wallet'], 'each balance a wallet line, the debt a debit below zero');
        self::assertSame(['موجودی منتقل‌شده از Faoxima'], WalletTransaction::query()->distinct()->pluck('description')->all());
        self::assertSame(['نماینده عادی' => true, 'نماینده پیشرفته' => true], $shop['groups'], 'paying agent prices');
        self::assertSame(['ماهانه' => false, 'سه ماهه' => false], $shop['categories'], 'off with their plans');

        self::assertSame([
            // [category, price, agent price, hours, GB, devices, note, on, servers]
            'یک ماهه ۵۰ گیگ' => ['ماهانه', '180000.00', '140000.00', 720, 50.0, 2, 'مناسب استفاده روزمره', false, ['آلمان', 'هلند', 'فنلاند', 'انگلیس']],
            'سه ماهه ۱۵۰ گیگ' => ['سه ماهه', '450000.00', null, 2160, 150.0, 0, null, false, ['آلمان', 'هلند']],
            'نامحدود یک ماهه' => ['ماهانه', '350000.00', null, 720, 0.0, 1, "بدون محدودیت حجم\nفقط یک کاربر \"همزمان\" - مناسب 'خانواده'", false, ['فنلاند']],
            'پلن ویژه همکاران' => ['ماهانه', '90000.00', null, 720, 40.0, 0, null, false, ['فنلاند']],
        ], $shop['plans'], 'off for the owner to check; the plain agents\' twin its plan\'s agent price, the advanced one\'s left; sold on the servers their panels map to');

        self::assertSame([
            // [customer, server, plan, test, label, link, traffic limit, end, claimed]
            '5012345671_rz1' => [5012345671, 'آلمان', 'یک ماهه ۵۰ گیگ', false, 'گوشی خودم', 'https://de.vpnshop.example:8000/sub/cnoxMQ', 53687091200, '2030-01-01 00:00:00', false],
            'sara_x1' => [5012345672, 'هلند', 'یک ماهه ۵۰ گیگ', false, null, 'https://nl.vpnshop.example:2096/sub/sarax1sub', 53687091200, '2030-02-01 00:00:00', false],
            'mina_test' => [5012345675, 'فنلاند', null, true, null, 'https://fi.vpnshop.example/sub/bWluYQ', 104857600, '2030-03-01 00:00:00', true],
            'agent2_9' => [5012345673, 'فنلاند', 'نامحدود یک ماهه', false, 'کار "دفتر"', 'https://fi.vpnshop.example/sub/YWdlbnQy', 0, '2030-04-01 00:00:00', false],
            'rz_uk' => [5012345671, 'انگلیس', 'یک ماهه ۵۰ گیگ', false, null, 'https://uk.vpnshop.example/sub/' . self::REBECCA_TOKEN, 53687091200, '2030-05-01 00:00:00', false],
            'loyal_1' => [5012345677, 'آلمان', 'یک ماهه ۵۰ گیگ', false, null, 'https://de.vpnshop.example:8000/sub/bG95YWw', 53687091200, '2030-01-01 00:00:00', false],
        ], $shop['services'], 'adopted as the panels have them; bought under the agents\' twin, its plan; the test with none; Rebecca\'s by its token link, made whole on the panel\'s address; not the stock config, the removed, the unpaid, the manual sale or the one bought in the reseller bot');
        self::assertSame(2, Subscription::query()->where('remote_name', 'rz_uk')->value('ip_limit'), "its device limit, which Rebecca's listing leaves out: the user read on its own");

        self::assertSame([
            // [type, value, uses, per customer, orders, plans, servers, first purchase, ends, on, note]
            'NOROOZ1405' => ['percent', '20.00', 163, 1, ['purchase', 'renewal'], null, null, false, null, true, null],
            'FIRST50K' => ['fixed', '50000.00', null, 1, ['purchase'], ['یک ماهه ۵۰ گیگ'], ['آلمان'], true, '2027-01-01 00:00:00', true, null],
            'WALLET10' => ['percent', '10.00', null, null, null, null, null, false, null, false, 'فقط برای شارژ کیف پول بود.'],
        ], $shop['discount_codes'], 'its uses left; a product on a panel, its plan on its server; a wallet top-up\'s, off with why');
        self::assertSame(['GIFTWELCOME' => '20000.00'], $shop['gift_codes'], 'the one single-use code still good, kept as the shop keeps a code');
        self::assertSame([['کارت به کارت', false, '6037997700001119', 'رضا محمدی - بانک ملی', false]], $shop['cards'], 'on a card-to-card method made switched off, the card off too, for the owner to check and switch on');
        self::assertSame([[self::CHANNEL['id'], 'کانال اطلاع‌رسانی', 'vpnshop_news']], $shop['channels']);

        self::assertSame([
            'customers' => ['created' => 7, 'linked' => 0, 'skipped' => 1],
            'balances' => ['created' => 5, 'linked' => 0, 'skipped' => 0],
            'groups' => ['created' => 2, 'linked' => 0, 'skipped' => 0],
            'servers' => ['created' => 0, 'linked' => 0, 'skipped' => 0],
            'plans' => ['created' => 4, 'linked' => 0, 'skipped' => 0],
            'categories' => ['created' => 2, 'linked' => 0, 'skipped' => 0],
            'services' => ['created' => 6, 'linked' => 0, 'skipped' => 1],
            'discount_codes' => ['created' => 3, 'linked' => 0, 'skipped' => 1],
            'gift_codes' => ['created' => 1, 'linked' => 0, 'skipped' => 3],
            'channels' => ['created' => 1, 'linked' => 0, 'skipped' => 0],
            'cards' => ['created' => 1, 'linked' => 0, 'skipped' => 1],
        ], $import['counts']);
        self::assertSame([
            'reseller' => 2,
            'used_up' => 2,
            'agents_only_plan' => 1,
            'multi_use_gift' => 1,
            'card_invalid' => 1,
            'one_customer' => 1,
        ], array_column($import['problems']['by_reason'], 'count', 'reason'), "the reseller bot's customer and service; a discount and a gift code used up");
    }

    /**
     * The shop as an import leaves it, read back: customers, wallet lines, groups, plans, services, codes, cards and
     * channels — each as the owner reads it, without ids or times of the run.
     *
     * @return array<string, array<array-key, mixed>>
     */
    private function shop(): array
    {
        $servers = Server::query()->pluck('name', 'id')->all();
        $plans = Plan::query()->pluck('name', 'id')->all();
        $telegram = User::query()->pluck('telegram_id', 'id')->all();

        $customers = [];
        foreach (User::query()->with('groups')->orderBy('telegram_id')->get() as $user) {
            $customers[$user->telegram_id] = [$user->username, $user->phone, $user->status->value, $user->role->value, $user->discount_percent, $user->referred_by === null ? null : $telegram[$user->referred_by], $user->groups->pluck('name')->all(), $user->balance()];
        }
        $wallet = WalletTransaction::query()->orderBy('id')->get()->map(static fn(WalletTransaction $line): array => [$telegram[$line->user_id], $line->type->value, $line->amount, $line->balance_after])->all();
        $planServers = [];
        foreach ($this->db()->table('plan_servers')->orderBy('sort')->get() as $row) {
            $planServers[$row->plan_id][] = $servers[$row->server_id];
        }

        return [
            'customers' => $customers,
            'wallet' => $wallet,
            'groups' => array_map(boolval(...), $this->db()->table('customer_groups')->orderBy('sort')->pluck('agent_pricing', 'name')->all()),
            'categories' => array_map(boolval(...), PlanCategory::query()->orderBy('sort')->pluck('is_active', 'name')->all()),
            'plans' => Plan::query()->with('category')->orderBy('sort')->get()->mapWithKeys(static fn(Plan $plan): array => [$plan->name => [$plan->category?->name, $plan->price, $plan->agent_price, $plan->duration_hours, $plan->traffic_gb, $plan->ip_limit, $plan->checkout_note, $plan->is_active, $planServers[$plan->id] ?? []]])->all(),
            'services' => Subscription::query()->orderBy('id')->get()->mapWithKeys(static fn(Subscription $service): array => [$service->remote_name => [$telegram[$service->user_id], $servers[$service->server_id], $service->plan_id === null ? null : $plans[$service->plan_id], $service->is_test, $service->label, $service->subscription_url, $service->traffic_limit_bytes, $service->expires_at?->format('Y-m-d H:i:s'), $service->claimed_at !== null]])->all(),
            'discount_codes' => DiscountCode::query()->orderBy('id')->get()->mapWithKeys(static fn(DiscountCode $code): array => [$code->code => [
                $code->type->value,
                $code->value,
                $code->max_uses,
                $code->per_user,
                $code->order_types,
                $code->plan_ids === null ? null : array_map(static fn(int $id): string => $plans[$id], $code->plan_ids),
                $code->server_ids === null ? null : array_map(static fn(int $id): string => $servers[$id], $code->server_ids),
                $code->first_purchase_only,
                $code->ends_at?->format('Y-m-d H:i:s'),
                $code->enabled,
                $code->note,
            ]])->all(),
            'gift_codes' => GiftCode::query()->orderBy('id')->pluck('amount', 'code')->all(),
            'cards' => PaymentCard::query()->with('method')->orderBy('id')->get()->map(static fn(PaymentCard $card): array => [$card->method?->label, $card->method?->enabled, $card->number, $card->holder, $card->enabled])->all(),
            'channels' => BotChannel::query()->orderBy('id')->get()->map(static fn(BotChannel $channel): array => [$channel->chat_id, $channel->title, $channel->username])->all(),
        ];
    }

    /**
     * The owner's choices: Faoxima's four panels on the shop's servers; its admins and channel too.
     *
     * @return array<string, mixed>
     */
    private function choices(): array
    {
        return [
            'panels' => [
                'آلمان-مرزبان' => ['action' => 'server', 'server_id' => $this->germany->id],
                'هلند-ثنایی' => ['action' => 'server', 'server_id' => $this->netherlands->id],
                'PasarGuard-FI' => ['action' => 'server', 'server_id' => $this->finland->id],
                'Rebecca-UK' => ['action' => 'server', 'server_id' => $this->england->id],
            ],
            'admins' => true,
            'channels' => true,
        ];
    }

    /**
     * Faoxima's own backup as its report channel gets it: its vpnbot/ folder, and backup.sql (Faoxima's PHP writer's)
     * beside it.
     */
    private function faoximaBackup(): string
    {
        $path = $this->scratchDir() . '/backup.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE));
        $zip->addFromString('vpnbot/config.php', "<?php\n\$APIKEY = 'not-a-token';\n");
        $zip->addFromString('vpnbot/text.json', '{"text_start":"سلام"}');
        $zip->addFile(self::FIXTURES . '/backup-php.sql', 'backup.sql');
        $zip->close();

        return (string) file_get_contents($path);
    }

    /**
     * A backup uploaded and read through to its review, the page calling continue as it does.
     *
     * @return array<string, mixed> The import in review
     */
    private function read(string $name, string $bytes): array
    {
        $uploaded = $this->upload('/api/admin/imports', 'file', $name, $bytes, ['migrator' => 'faoxima']);
        self::assertSame(201, $uploaded->getStatusCode(), (string) $uploaded->getBody());

        return $this->continueWhile($this->decode($uploaded)['import']['id'], 'reading');
    }

    /**
     * Faoxima's backup brought in whole on the review's proposed choices.
     *
     * @return array<string, mixed> The import, done
     */
    private function importAll(): array
    {
        $import = $this->read('backup.zip', $this->faoximaBackup());
        self::assertSame('importing', $this->decode($this->postJson("/api/admin/imports/{$import['id']}/start"))['import']['phase']);
        $done = $this->continueWhile($import['id'], 'importing');
        self::assertSame(['done', null], [$done['phase'], $done['error']]);

        return $done;
    }

    /**
     * Faoxima's panels, answering by address: Marzban signs its admin in (a sudo admin) and lists its users, 3x-ui its
     * settings and clients, PasarGuard its users, Rebecca its admin (sudo), a page of users and one user's detail, as its
     * Go handlers answer — each with the clients Faoxima's live services are, and one that is no service of its;
     * anything else is a 404, which no import should ask.
     *
     * @param array<string, mixed> $options
     */
    private function panels(RequestInterface $request, array $options): PromiseInterface
    {
        $uri = $request->getUri();
        $call = "{$request->getMethod()} {$uri->getHost()}{$uri->getPath()}";
        $this->panelCalls[] = $call;
        $json = static fn(array $body): Response => new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($body));
        $marzban = static fn(string $name, int $limit, int $expire, string $token): array => ['username' => $name, 'status' => 'active', 'used_traffic' => 1073741824, 'data_limit' => $limit, 'expire' => $expire, 'subscription_url' => "/sub/{$token}", 'online_at' => null];
        $pasarguard = static fn(string $name, int $limit, string $expire, string $token): array => ['username' => $name, 'status' => 'active', 'used_traffic' => 0, 'data_limit' => $limit, 'expire' => $expire, 'subscription_url' => "/sub/{$token}", 'online_at' => null];
        // A Rebecca user as its listing has it (no device limit, no waiting term); its detail adds them.
        $rebecca = static fn(int $id, string $name, int $limit, int $expire, string $token, string $key): array => [
            'id' => $id, 'username' => $name, 'status' => 'active', 'used_traffic' => 2147483648, 'lifetime_used_traffic' => 2147483648, 'created_at' => '2024-11-02T10:00:00Z',
            'expire' => $expire, 'data_limit' => $limit, 'data_limit_reset_strategy' => 'no_reset', 'online_at' => '2026-11-14T08:30:00Z', 'is_online' => false,
            'service_id' => 1, 'service_name' => 'UK', 'admin_id' => 1, 'admin_username' => 'admin', 'links' => [], 'subscription_url' => "/sub/{$token}",
            'subscription_urls' => ['primary' => "/sub/{$token}", 'username-key' => "/sub/{$name}/{$key}", 'key' => "/sub/{$key}", 'token' => "/sub/{$token}"], 'group' => null, 'tags' => [],
        ];
        $rzUk = $rebecca(7, 'rz_uk', 53687091200, 1903824000, self::REBECCA_TOKEN, '3f1c9a7e5b2d4c6a8e0f1b3d5c7a9e2f');

        return new FulfilledPromise(match ($call) {
            'POST de.vpnshop.example/api/admin/token' => $json(['access_token' => 'jwt', 'token_type' => 'bearer']),
            'GET de.vpnshop.example/api/admin' => $json(['username' => 'admin', 'is_sudo' => true, 'telegram_id' => null, 'discord_webhook' => null, 'users_usage' => 0]),
            'GET de.vpnshop.example/api/users' => $json(['users' => [
                $marzban('5012345671_rz1', 53687091200, 1893456000, 'cnoxMQ'),
                $marzban('loyal_1', 53687091200, 1893456000, 'bG95YWw'),
                $marzban('not_from_faoxima', 0, 1893456000, 'b3RoZXI'),
            ], 'total' => 3]),
            'POST nl.vpnshop.example/Xk9pQ/panel/api/setting/all' => $json(['success' => true, 'msg' => '', 'obj' => ['subEnable' => true, 'subPort' => 2096, 'subPath' => '/sub/']]),
            'GET nl.vpnshop.example/Xk9pQ/panel/api/clients/list' => $json(['success' => true, 'msg' => '', 'obj' => [
                ['email' => 'sara_x1', 'subId' => 'sarax1sub', 'enable' => true, 'totalGB' => 53687091200, 'expiryTime' => 1896134400000, 'traffic' => ['up' => 1, 'down' => 2, 'lastOnline' => 0]],
                ['email' => 'res_1', 'subId' => 'res1sub', 'enable' => true, 'totalGB' => 0, 'expiryTime' => 0, 'traffic' => ['up' => 0, 'down' => 0, 'lastOnline' => 0]],
            ]]),
            'GET fi.vpnshop.example/api/users' => $json(['users' => [
                $pasarguard('mina_test', 104857600, '2030-03-01T00:00:00', 'bWluYQ'),
                $pasarguard('agent2_9', 0, '2030-04-01T00:00:00', 'YWdlbnQy'),
            ], 'total' => 2]),
            'GET uk.vpnshop.example/api/admin' => $json(['username' => 'admin', 'role' => 'sudo', 'permissions' => [], 'telegram_id' => null, 'users_usage' => 0]),
            'GET uk.vpnshop.example/api/users' => $json(['users' => [
                $rebecca(3, 'not_from_faoxima', 0, 0, 'bm90X2Zyb21fZmFveGltYSwxNzMwMDAwMDAwYbc4Kp2Lm0', '0a1b2c3d4e5f60718293a4b5c6d7e8f9'),
                $rzUk,
            ], 'link_templates' => [], 'total' => 2, 'status_breakdown' => ['active' => 2]]),
            'GET uk.vpnshop.example/api/user/rz_uk' => $json($rzUk + [
                'credential_key' => '3f1c9a7e5b2d4c6a8e0f1b3d5c7a9e2f', 'flow' => null, 'note' => null, 'telegram_id' => null, 'contact_number' => null, 'sub_updated_at' => null,
                'sub_last_user_agent' => null, 'on_hold_expire_duration' => null, 'on_hold_timeout' => null, 'ip_limit' => 2, 'speed_limit_kbps' => 0, 'auto_delete_in_days' => null,
                'proxies' => [], 'excluded_inbounds' => [], 'inbounds' => [], 'next_plans' => [], 'service_host_orders' => [],
            ]),
            default => new Response(404, ['Content-Type' => 'application/json'], '{"detail":"Not Found"}'),
        });
    }
}
