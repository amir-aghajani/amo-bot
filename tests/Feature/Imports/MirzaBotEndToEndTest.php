<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Core\Security\Encrypter;
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
 * A MirzaBot shop brought over end to end through the owner's panel, by the real pieces — the sources reading its backup,
 * the MirzaBot migrator, the core writing — from tests/Fixtures/imports/mirzabot: the backup_<date>.sql MirzaBot 0.9.3's
 * own PDO writer sends (lowercase tables, `config`), and a mysqldump of an old 5.x MirzaBot of the same shop (mixed-case
 * tables, no «نسخه پنل», 3x-ui on its admin's login), each read to its survey; its panels mapped to the shop's Marzban,
 * 3x-ui, PasarGuard and Rebecca servers, which only list (and Rebecca read) their clients (the HTTP the real connectors
 * send, answered by address: the review's choices take a server only of the panel's own connector, so no fake one stands
 * in); everything it brings in the shop as MirzaBot had it, quietly, nothing written to a panel; the same import again
 * bringing nothing twice; and the same shop when every step stops a few rows in.
 */
final class MirzaBotEndToEndTest extends HttpTestCase
{
    use ImportSteps;

    private const FIXTURES = __DIR__ . '/../../Fixtures/imports/mirzabot';

    /**
     * What the panels may be asked, in a run's order: Marzban's sign-in, its admin (a sudo one, who sees every user) and
     * users, 3x-ui's clients and settings, PasarGuard's users, Rebecca's admin (a sudo one), users and — its listing
     * leaving the device limit unsaid — the one user the import adopts there. Sweden's 3x-ui never: its one service was
     * bought in the reseller bot, which the owner does not bring.
     */
    private const READS = [
        'POST de.mirzashop.example.com/api/admin/token',
        'GET de.mirzashop.example.com/api/admin',
        'GET de.mirzashop.example.com/api/users',
        'GET nl.mirzashop.example.com/Xk9pQ/panel/api/clients/list',
        'POST nl.mirzashop.example.com/Xk9pQ/panel/api/setting/all',
        'GET fi.mirzashop.example.com/api/users',
        'GET tr.mirzashop.example.com/api/admin',
        'GET tr.mirzashop.example.com/api/users',
        'GET tr.mirzashop.example.com/api/user/rz_tr',
    ];

    /** rz_tr's subscription token on Rebecca: base64url("rz_tr,1731030000") and its 10-character signature. */
    private const REBECCA_TOKEN = 'cnpfdHIsMTczMTAzMDAwMA' . 'Hd4Kq8Wn2B';

    /** The Rebecca panel's rk_ API key, MirzaBot's `password_panel`. */
    private const REBECCA_KEY = 'rk_MirzaFixtureRebeccaKeyTR_0123456789abcdefgh';

    /**
     * The required channels as Telegram answers for them: a public one by its @username, a private one by its id (with
     * the invite link Telegram shows its admins).
     */
    private const NEWS = ['id' => -1001234500001, 'type' => 'channel', 'title' => 'کانال اطلاع‌رسانی', 'username' => 'mirzashop_news'];
    private const PRIVATE = ['id' => -1001234500002, 'type' => 'channel', 'title' => 'کانال خصوصی', 'invite_link' => 'https://t.me/+AbCdEfGhIjKlMnOp'];

    /** What stays behind in either backup, by what. */
    private const LEFT = [
        'تاریخچه پرداخت‌ها' => 4,
        'تیکت‌های پشتیبانی' => 2,
        'تاریخچه تمدیدها و خریدهای اضافه سرویس‌ها' => 2,
        'ربات‌های نمایندگی' => 1,
        'کیف پول مشتری‌های ربات‌های نمایندگی (در فایل‌های ربات قبلی است، نه دیتابیسش)' => 1,
        'کانفیگ‌های فروش دستی' => 1,
        'سرویس‌های پنل‌هایی که این فروشگاه کانکتورشان را ندارد' => 1,
        'سرویس‌های پنل‌هایی که در ربات قبلی حذف شده بودند' => 1,
        'قیمت‌های حجم و زمان اضافه و سرویس دلخواه پنل‌ها' => 4,
        'تنظیمات محصول‌ها (فقط برای مشتری تازه، ریست دوره‌ای حجم)' => 2,
        'تاریخچه استفاده از کدهای تخفیف و هدیه (سقف استفاده هر مشتری از نو شمرده می‌شود)' => 2,
    ];

    /**
     * What either backup tells the owner, in MirzaBot's order, up to its sold-out plans (which only 0.9.3 marks): its
     * customers (no word on a customer's own percent, which MirzaBot adds on top of a code as this shop does), its
     * agents' prices, the custom build that comes without a plan.
     */
    private const CUSTOMERS_AND_PLANS = [
        '۱ مشتری در ربات قبلی بدهکار بودند (جمعا ۱۲۵٬۰۰۰ تومان)؛ کیف پولشان اینجا هم منفی می‌آید.',
        '۱ نفر از ادمین‌های ربات قبلی پشتیبان یا فروشنده بودند؛ در فهرست هستند ولی اینجا ادمین نمی‌شوند.',
        '۱ نماینده پیشرفته در ربات قبلی با موجودی منفی هم می‌خریدند (خرید اعتباری)؛ اینجا خرید با موجودی منفی نیست.',
        '۱ نماینده در ربات قبلی تاریخ پایان نمایندگی داشتند؛ این تاریخ منتقل نمی‌شود و اینجا نماینده می‌مانند تا خودتان عوضشان کنید.',
        'قیمت‌های جدای «نماینده پیشرفته» منتقل نمی‌شود: هر پلن اینجا یک قیمت نمایندگی دارد، همان قیمت «نماینده عادی» و اگر نبود، پیشرفته.',
        '۱ سرویس به هیچ پلنی وصل نشد (سرویس دلخواه بود، یا محصولش در ربات قبلی تغییر نام داده یا حذف شده بود)؛ بدون پلن می‌آید و با تمدید، پلن می‌گیرد.',
    ];

    private const SOLD_OUT = '۱ پلن در ربات قبلی «ناموجود» بود؛ خاموش می‌آید تا خودتان روشنش کنید.';

    /**
     * Then: the bot's own payment awaiting approval (not the reseller bot's), the customers excused from the channels and
     * kept from the cards (the bot's own, not the reseller bot's), the links through the bot.
     */
    private const PAYMENTS_AND_LINKS = [
        '۱ پرداخت در ربات قبلی هنوز منتظر تایید است؛ اول آن‌ها را آنجا تایید کنید و بعد پشتیبان تازه بگیرید، وگرنه این مبلغ‌ها به کیف پول آن مشتری‌ها نمی‌رسد.',
        '۱ مشتری در ربات قبلی از عضویت در کانال‌های اجباری معاف بودند؛ اینجا معاف نیستند.',
        '۱ مشتری در ربات قبلی کارت به کارت را نمی‌دیدند؛ اینجا هر کارت روشن به همه نشان داده می‌شود، پس پیش از روشن کردن کارت‌ها دقت کنید.',
        "لینک اشتراک مشتری‌های پنل «\u{2068}هلند-ثنایی\u{2069}» از دامنه ربات قبلی می‌گذشت؛ با خاموش شدن آن ربات این لینک‌ها کار نمی‌کنند و مشتری‌ها باید لینک تازه را از این ربات بگیرند.",
    ];

    private const OLD_THREE_XUI = "پنل «\u{2068}هلند-ثنایی\u{2069}» با روش قدیمی (نام کاربری و رمز) به ربات قبلی وصل بود؛ کانکتور \u{2068}3X-UI\u{2069} این فروشگاه نسخه 3 به بعد را می‌خواهد و سرویس‌های پنل قدیمی‌تر منتقل نمی‌شوند.";
    private const UNCHECKED_TLS = 'ربات قبلی گواهی TLS پنل‌ها را بررسی نمی‌کرد، ولی این فروشگاه بررسی می‌کند: پنلی با گواهی خودامضا یا منقضی به‌عنوان سرور تازه اضافه نمی‌شود؛ آن را در «سرورها» با «بررسی گواهی TLS» خاموش اضافه کنید و همان را برایش انتخاب کنید.';

    /** Last: a panel shown to one audience (Hiddify's, to plain agents), one hidden from a customer (the Netherlands'). */
    private const HIDING = [
        "پنل «\u{2068}Hiddify-UK\u{2069}» در ربات قبلی فقط به بخشی از مشتری‌ها (کاربران عادی یا یکی از سطح‌های نمایندگی) نشان داده می‌شد؛ اینجا پلن‌های روی آن به همه فروخته می‌شوند، پس پیش از روشن کردنشان بررسی کنید.",
        "پنل «\u{2068}هلند-ثنایی\u{2069}» در ربات قبلی از چند مشتری انتخاب‌شده پنهان بود؛ اینجا از کسی پنهان نمی‌ماند.",
    ];

    /** @var list<string> Every request the panels got, "METHOD host/path" */
    private array $panelCalls = [];

    private Server $germany;
    private Server $netherlands;
    private Server $finland;
    private Server $sweden;
    private Server $turkey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();
        // The shop's servers on MirzaBot's panels: Marzban with its admin's login, two 3x-ui, a PasarGuard and a Rebecca with a token.
        $this->germany = Server::query()->create(['name' => 'آلمان', 'driver' => 'marzban', 'base_url' => 'https://de.mirzashop.example.com:8000', 'username' => 'admin', 'password' => 'secret', 'is_active' => true, 'serves_subscriptions' => true]);
        $this->netherlands = Server::query()->create(['name' => 'هلند', 'driver' => '3x-ui', 'base_url' => 'https://nl.mirzashop.example.com:2053/Xk9pQ', 'api_token' => 'xui-token', 'is_active' => true, 'serves_subscriptions' => true, 'meta' => ['subscription_url' => 'https://nl.mirzashop.example.com:2096/sub']]);
        $this->finland = Server::query()->create(['name' => 'فنلاند', 'driver' => 'pasarguard', 'base_url' => 'https://fi.mirzashop.example.com', 'api_token' => 'pg-key', 'is_active' => true, 'serves_subscriptions' => true]);
        $this->sweden = Server::query()->create(['name' => 'سوئد', 'driver' => '3x-ui', 'base_url' => 'https://se.mirzashop.example.com:2053/Lm3Nb', 'api_token' => 'xui-se-token', 'is_active' => true, 'serves_subscriptions' => true]);
        $this->turkey = Server::query()->create(['name' => 'ترکیه', 'driver' => 'rebecca', 'base_url' => 'https://tr.mirzashop.example.com', 'api_token' => self::REBECCA_KEY, 'is_active' => true, 'serves_subscriptions' => true]);

        // The panels answer by address, however many times and in whatever order a run asks; put back offline once the
        // test is over, as the fake panel is.
        $this->panelHttp();
        $transport = $this->app()->container()->get('http.transport');
        self::assertInstanceOf(HandlerStack::class, $transport);
        $transport->setHandler($this->panels(...));

        $this->telegram()->on('getChat', static fn(array $params): mixed => match ((string) $params['chat_id']) {
            '@mirzashop_news' => self::NEWS,
            '-1001234500002' => self::PRIVATE,
            default => FakeTelegram::error(400, 'Bad Request: chat not found'),
        });
        $this->telegram()->on('getChatMember', static fn(): array => ['status' => 'administrator', 'user' => ['id' => FakeTelegram::BOT_ID]]);
    }

    public function testMirzaBotsOwnBackupIsReadToItsSurvey(): void
    {
        $import = $this->read('backup_2026-11-14.sql', $this->backup('backup-php.sql'));
        self::assertSame(['review', 'backup_2026-11-14.sql', null], [$import['phase'], $import['file_name'], $import['version']], 'MirzaBot keeps no version');

        $survey = $import['survey'];
        self::assertSame(['customers' => 8, 'balances' => 5, 'services' => 8, 'tests' => 1, 'plans' => 4, 'discount_codes' => 4, 'gift_codes' => 3, 'channels' => 2, 'cards' => 2, 'resellers' => 1], $survey['counts'], "the customer who last opened the reseller bot but has a wallet here the bot's own; the one with nothing here the reseller bot's");
        self::assertSame('422000.00', $survey['balance_total'], '85,000 + 420,000 − 125,000 + 12,000 + 30,000: every wallet of the bot itself');
        self::assertSame([
            ['آلمان-مرزبان', 'marzban', 'marzban', true, 2, $this->germany->id, null],
            ['هلند-ثنایی', 'x-ui_single', '3x-ui', true, 2, $this->netherlands->id, null],
            ['PasarGuard-FI', 'marzban', 'pasarguard', true, 2, $this->finland->id, null],
            ['Hiddify-UK', 'hiddify', null, false, 1, null, 'این فروشگاه هنوز کانکتوری برای این نوع پنل ندارد.'],
            ['سوئد-ثنایی', 'x-ui_single', '3x-ui', true, 1, $this->sweden->id, null],
            ['Rebecca-TR', 'rebecca', 'rebecca', true, 1, $this->turkey->id, null],
        ], self::panelRows($survey), 'no panel of manual sales; a Marzban one of «نسخه پنل» 1 is PasarGuard; Rebecca on its connector; each suggested on the shop\'s server at its address');
        self::assertSame([['name' => 'نماینده عادی', 'agent_pricing' => true, 'customers' => 1], ['name' => 'نماینده پیشرفته', 'agent_pricing' => true, 'customers' => 1]], $survey['groups']);
        self::assertSame([['key' => '1000000001', 'telegram_id' => 1000000001, 'username' => 'shop_owner', 'role' => 'administrator']], $survey['admins'], 'its administrator; its support and Seller staff not');
        self::assertSame(self::LEFT, array_column($survey['left'], 'count', 'what'));
        self::assertSame(
            [...self::CUSTOMERS_AND_PLANS, self::SOLD_OUT, ...self::PAYMENTS_AND_LINKS, self::UNCHECKED_TLS, ...self::HIDING],
            $survey['warnings'],
            'every advanced agent named for their credit (a cap of 0 is none); no word on own percents; the sold-out plan; a database of 0.9.3 (`config`) signed in to 3x-ui with its token, a username or not: no old-login warning',
        );
        self::assertSame(['آلمان-مرزبان' => 'server', 'هلند-ثنایی' => 'server', 'PasarGuard-FI' => 'server', 'Hiddify-UK' => 'skip', 'سوئد-ثنایی' => 'server', 'Rebecca-TR' => 'server'], array_map(static fn(array $choice): string => $choice['action'], $import['proposed']['panels']));
        self::assertSame(['https://tr.mirzashop.example.com', 'token', self::REBECCA_KEY, ''], $this->kept($import['id'], 'Rebecca-TR', ['base_url', 'auth_mode', 'api_token', 'subscription_url']), 'Rebecca by the rk_ key MirzaBot kept as its password');
        self::assertSame(['token', 'xui-api-token-0123456789abcdef', null, 'https://nl.mirzashop.example.com:2096/sub', true, 150], $this->kept($import['id'], 'هلند-ثنایی', ['auth_mode', 'api_token', 'username', 'subscription_url', 'is_active', 'capacity']), "added with its admin's login, but a MirzaBot later than 0.1.9 signs in with the API token it keeps as the password; its links' prefix; its sales limit");
        self::assertSame(['token', 'xui-api-token-se-4567', ''], $this->kept($import['id'], 'سوئد-ثنایی', ['auth_mode', 'api_token', 'subscription_url']), "added with no username: its token; links on the panel's own (MirzaBot's default prefix is its address)");
        self::assertSame(['password', 'pgadmin', 'pg-pass-2025'], $this->kept($import['id'], 'PasarGuard-FI', ['auth_mode', 'username', 'password']));
        self::assertSame([], $this->panelCalls, 'reading asks no panel');
    }

    public function testAnOldMirzaBotsMysqldumpIsReadToTheSameShopWithItsOldLoginNamed(): void
    {
        $new = $this->read('backup_2026-11-14.sql', $this->backup('backup-php.sql'));
        self::assertSame(204, $this->deleteJson("/api/admin/imports/{$new['id']}")->getStatusCode());

        $import = $this->read('mirzabot_2025-04-18.sql', $this->backup('backup-mysqldump.sql'));

        $survey = $import['survey'];
        self::assertSame(array_replace($new['survey']['counts'], ['services' => 7, 'discount_codes' => 5]), $survey['counts'], 'the same customers; no Rebecca panel yet, so its service neither; one code more, made before codes had an audience');
        foreach (['balance_total', 'groups', 'admins', 'left'] as $part) {
            self::assertSame($new['survey'][$part], $survey[$part], "{$part}: the same, whichever MirzaBot and tool wrote them");
        }
        self::assertSame([
            ['آلمان-مرزبان', 'marzban', 'marzban', true, 2, $this->germany->id, null],
            ['هلند-ثنایی', 'x-ui_single', '3x-ui', true, 2, $this->netherlands->id, null],
            ['PasarGuard-FI', 'marzban', 'marzban', true, 2, null, null],
            ['Hiddify-UK', 'hiddify', null, false, 1, null, 'این فروشگاه هنوز کانکتوری برای این نوع پنل ندارد.'],
            ['سوئد-ثنایی', 'x-ui_single', '3x-ui', true, 1, $this->sweden->id, null],
        ], self::panelRows($survey), 'before «نسخه پنل» a PasarGuard panel was a Marzban one: no PasarGuard server suggested for it');
        self::assertSame(
            [...self::CUSTOMERS_AND_PLANS, ...self::PAYMENTS_AND_LINKS, self::OLD_THREE_XUI, self::UNCHECKED_TLS, ...self::HIDING],
            $survey['warnings'],
            'nothing was sold out before MirzaBot could mark it so; no `config` nor `setting.text_edit`: the 3x-ui panel added with a username is on the old login (OLD_THREE_XUI), the one without on its token',
        );
        self::assertSame(['password', 'admin', "xui'pass\\2024"], $this->kept($import['id'], 'هلند-ثنایی', ['auth_mode', 'username', 'password']), "the admin's login MirzaBot kept, its escapes read");
        self::assertSame(['token', 'xui-api-token-se-4567', null], $this->kept($import['id'], 'سوئد-ثنایی', ['auth_mode', 'api_token', 'username']), 'no username: its API token, in an old MirzaBot too');
        self::assertSame(['آلمان-مرزبان' => 'server', 'هلند-ثنایی' => 'server', 'PasarGuard-FI' => 'add', 'Hiddify-UK' => 'skip', 'سوئد-ثنایی' => 'server'], array_map(static fn(array $choice): string => $choice['action'], $import['proposed']['panels']));
        self::assertSame([], $this->panelCalls);
    }

    public function testTheWholeShopComesOverQuietlyAndThePanelsAreOnlyRead(): void
    {
        $import = $this->read('backup_2026-11-14.sql', $this->backup('backup-php.sql'));
        $saved = $this->decode($this->putJson("/api/admin/imports/{$import['id']}/choices", ['choices' => $this->choices()]))['import'];
        self::assertSame([true, true, false, true], [$saved['choices']['admins'], $saved['choices']['channels'], $saved['choices']['resellers'], $saved['choices']['plans_on']]);
        self::assertSame('importing', $this->decode($this->postJson("/api/admin/imports/{$import['id']}/start"))['import']['phase']);

        $done = $this->continueWhile($import['id'], 'importing');

        self::assertSame(['done', null], [$done['phase'], $done['error']]);
        $this->assertTheShopCameOver($done);
        self::assertSame(self::READS, $this->panelCalls, 'each panel listed once, nothing written to any');
        self::assertSame(['getChat', 'getChatMember', 'getChat', 'getChatMember'], $this->telegram()->calls(), 'quiet: the channels looked up, nothing sent to a customer or the report group');
        self::assertSame(0, ImportRow::query()->count(), 'the staged copy gone once it ended');

        $problems = $this->decode($this->get("/api/admin/imports/{$import['id']}/problems"));
        self::assertSame([
            ['plans', 'g7h8', 'agents_only_plan', 'پلن ویژه همکاران'],
            ['gift_codes', 'GIFTMANY', 'multi_use_gift', "\u{2068}GIFTMANY\u{2069} — ۳۶ بار مانده"],
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
        $bytes = $this->backup('backup-php.sql');
        $import = $imports->fromBackup('mirzabot', new UploadedFile((new StreamFactory())->createStream($bytes), 'backup_2026-11-14.sql', null, strlen($bytes)), 'root');

        $reading = $this->stepWhile($import, ImportPhase::Reading, 3);
        self::assertGreaterThan(5, $reading, 'read a few statements a step');
        self::assertSame(ImportPhase::Review, $import->phase, (string) $import->error);
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
        self::assertSame(['getChat', 'getChatMember', 'getChat', 'getChatMember'], $this->telegram()->calls());
    }

    /**
     * Everything the import brought, in the shop as MirzaBot had it, and its report: what each kind came to and the
     * problems by reason.
     *
     * @param array<string, mixed> $import The import as it ended (ImportState)
     */
    private function assertTheShopCameOver(array $import): void
    {
        $shop = $this->shop();

        self::assertSame([
            // [username, phone, status, role, discount %, brought by, groups, balance, phone excused, bought before]
            1000000001 => ['shop_owner', null, 'active', 'admin', 0, null, [], '0.00', false, true],
            5012345671 => ['reza_karimi', '+989121112233', 'active', 'customer', 0, null, [], '85000.00', false, true],
            5012345672 => ['sara_agent', '+989351234567', 'active', 'customer', 0, null, ['نماینده عادی'], '420000.00', false, true],
            5012345673 => [null, null, 'active', 'customer', 0, null, ['نماینده پیشرفته'], '-125000.00', false, true],
            5012345674 => ['blocked_user', null, 'banned', 'customer', 0, null, [], '0.00', false, false],
            5012345675 => ['mina_ref', null, 'active', 'customer', 15, 5012345671, [], '12000.00', true, false],
            5012345676 => ['res_customer', null, 'active', 'customer', 0, null, [], '30000.00', false, true],
        ], $shop['customers'], "MirzaBot's administrator an admin (chosen), its support staff not; the agents in their groups; «confrim number by admin» excused; one who had a service of the bot, at any price, one who had bought; one who last opened the reseller bot but has a wallet here the bot's own; the reseller bot's own customer left out");
        self::assertSame(1730000000, User::query()->where('telegram_id', 5012345671)->sole()->created_at?->getTimestamp(), 'a customer since they joined MirzaBot');

        self::assertSame([
            [5012345671, 'credit', '85000.00', '85000.00'],
            [5012345672, 'credit', '420000.00', '420000.00'],
            [5012345673, 'debit', '125000.00', '-125000.00'],
            [5012345675, 'credit', '12000.00', '12000.00'],
            [5012345676, 'credit', '30000.00', '30000.00'],
        ], $shop['wallet'], 'each balance a wallet line, the debt a debit below zero');
        self::assertSame(['موجودی منتقل‌شده از MirzaBot'], WalletTransaction::query()->distinct()->pluck('description')->all());
        self::assertSame(['نماینده عادی' => true, 'نماینده پیشرفته' => true], $shop['groups'], 'paying agent prices');
        self::assertSame(['ماهانه' => true, 'سه ماهه' => false], $shop['categories'], 'on with a plan switched on; the sold-out plan\'s off with it');

        self::assertSame([
            // [category, price, agent price, hours, GB, devices, note, on, servers]
            'یک ماهه ۵۰ گیگ' => ['ماهانه', '180000.00', '140000.00', 720, 50.0, 0, 'مناسب استفاده روزمره', true, ['آلمان', 'هلند', 'فنلاند', 'سوئد', 'ترکیه']],
            'نامحدود یک ماهه' => ['ماهانه', '350000.00', null, 720, 0.0, 0, "بدون محدودیت حجم\nفقط یک کاربر \"همزمان\" - مناسب 'خانواده'", true, ['فنلاند', 'سوئد', 'ترکیه']],
            'پلن ویژه همکاران' => ['ماهانه', '90000.00', null, 720, 40.0, 0, null, false, ['فنلاند']],
            'سه ماهه ۱۵۰ گیگ' => ['سه ماهه', '450000.00', null, 2160, 150.0, 0, null, false, ['آلمان']],
        ], $shop['plans'], "in MirzaBot's order (display_order); switched on as chosen but the advanced agents' own and the sold-out one; the plain agents' twin its plan's agent price; sold on the servers their panels map to, hidden ones left out");

        self::assertSame([
            // [customer, server, plan, test, label, link, traffic limit, end, claimed]
            '5012345671_rz1' => [5012345671, 'آلمان', 'یک ماهه ۵۰ گیگ', false, 'گوشی خودم', 'https://de.mirzashop.example.com:8000/sub/cnoxMQ', 53687091200, '2030-01-01 00:00:00', false],
            'sara_x1' => [5012345672, 'هلند', 'یک ماهه ۵۰ گیگ', false, null, 'https://nl.mirzashop.example.com:2096/sub/sarax1sub', 53687091200, '2030-02-01 00:00:00', false],
            'mina_test' => [5012345675, 'فنلاند', null, true, null, 'https://fi.mirzashop.example.com/sub/bWluYQ', 104857600, '2030-03-01 00:00:00', true],
            'agent2_9' => [5012345673, 'فنلاند', 'پلن ویژه همکاران', false, 'کار "دفتر"', 'https://fi.mirzashop.example.com/sub/YWdlbnQy', 0, '2030-04-01 00:00:00', false],
            'rz_custom' => [5012345671, 'آلمان', null, false, "لپ\u{200C}تاپ", 'https://de.mirzashop.example.com:8000/sub/cnpjdXN0', 53687091200, '2030-01-16 00:00:00', true],
            'rz_tr' => [5012345671, 'ترکیه', 'یک ماهه ۵۰ گیگ', false, 'تبلت', 'https://tr.mirzashop.example.com/sub/' . self::REBECCA_TOKEN, 53687091200, '2030-06-01 00:00:00', false],
        ], $shop['services'], "adopted as the panels have them (Rebecca's by its token link, made whole on the panel's address); one under a renamed product by its traffic, days and panel; the English test a test; the custom build planless though a product of its traffic and days is sold there; not the stock config, the removed, unpaid, disabled (paid or a test), gone, Hiddify's or the two bought in the reseller bot");
        self::assertSame(3, Subscription::query()->where('remote_name', 'rz_tr')->value('ip_limit'), "its device limit, which Rebecca's listing leaves out: the user read on its own");

        self::assertSame([
            // [type, value, uses, per customer, orders, plans, servers, first purchase, ends, on, note]
            'NOROOZ1405' => ['percent', '20.00', 163, 1, ['purchase', 'renewal'], null, null, false, null, true, null],
            'FIRST10' => ['percent', '10.00', 100, 1, ['purchase'], ['یک ماهه ۵۰ گیگ'], ['آلمان'], true, '2027-01-01 00:00:00', false, 'فقط برای کاربران عادی بود.'],
            'AGENT15' => ['percent', '15.00', 47, 2, ['renewal'], null, null, false, null, false, 'فقط برای نماینده‌های عادی بود.'],
        ], $shop['discount_codes'], "a percent off, its uses left; «all» at a purchase and a renewal; a product on a panel, its plan on its server; one audience's off with why; the code of a limit of 0 used up");
        self::assertSame(['GIFTONE' => '50000.00'], $shop['gift_codes'], 'the one single-use code still good; one of a limit of 0 used up');
        self::assertSame([['کارت به کارت', false, '6037997700001119', 'رضا کریمی - بانک ملی', false]], $shop['cards'], 'on a card-to-card method made switched off, the card off too');
        self::assertSame([[self::NEWS['id'], 'کانال اطلاع‌رسانی', 'mirzashop_news', null], [self::PRIVATE['id'], 'کانال خصوصی', null, 'https://t.me/+AbCdEfGhIjKlMnOp']], $shop['channels'], 'by the @username or the id MirzaBot checked membership in');

        self::assertSame([
            'customers' => ['created' => 7, 'linked' => 0, 'skipped' => 1],
            'balances' => ['created' => 5, 'linked' => 0, 'skipped' => 0],
            'groups' => ['created' => 2, 'linked' => 0, 'skipped' => 0],
            'servers' => ['created' => 0, 'linked' => 0, 'skipped' => 0],
            'plans' => ['created' => 4, 'linked' => 0, 'skipped' => 0],
            'categories' => ['created' => 2, 'linked' => 0, 'skipped' => 0],
            'services' => ['created' => 6, 'linked' => 0, 'skipped' => 3],
            'discount_codes' => ['created' => 3, 'linked' => 0, 'skipped' => 1],
            'gift_codes' => ['created' => 1, 'linked' => 0, 'skipped' => 2],
            'channels' => ['created' => 2, 'linked' => 0, 'skipped' => 0],
            'cards' => ['created' => 1, 'linked' => 0, 'skipped' => 1],
        ], $import['counts']);
        self::assertSame([
            'reseller' => 3,
            'panel_skipped' => 1,
            'used_up' => 2,
            'agents_only_plan' => 1,
            'multi_use_gift' => 1,
            'card_invalid' => 1,
        ], array_column($import['problems']['by_reason'], 'count', 'reason'), "the reseller bot's own customer and the two services bought there; Hiddify's service; a discount and a gift code of a limit of 0");
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
            $customers[$user->telegram_id] = [$user->username, $user->phone, $user->status->value, $user->role->value, $user->discount_percent, $user->referred_by === null ? null : $telegram[$user->referred_by], $user->groups->pluck('name')->all(), $user->balance(), (bool) $user->phone_exempt, $user->bought_elsewhere_at !== null];
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
            'channels' => BotChannel::query()->orderBy('id')->get()->map(static fn(BotChannel $channel): array => [$channel->chat_id, $channel->title, $channel->username, $channel->invite_link])->all(),
        ];
    }

    /**
     * The owner's choices: MirzaBot's five panels on the shop's servers, Hiddify's left; its admins and channels too, its
     * plans switched on.
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
                'Hiddify-UK' => ['action' => 'skip', 'server_id' => null],
                'سوئد-ثنایی' => ['action' => 'server', 'server_id' => $this->sweden->id],
                'Rebecca-TR' => ['action' => 'server', 'server_id' => $this->turkey->id],
            ],
            'admins' => true,
            'channels' => true,
            'plans_on' => true,
        ];
    }

    /** A backup of the fixtures, as MirzaBot's report channel gets it: plain SQL, never zipped since 0.1.6. */
    private function backup(string $file): string
    {
        return (string) file_get_contents(self::FIXTURES . '/' . $file);
    }

    /**
     * The survey's panels, each as [key, type, driver, addable, services, suggested server, why unsupported].
     *
     * @param array<string, mixed> $survey
     * @return list<list<mixed>>
     */
    private static function panelRows(array $survey): array
    {
        return array_map(static fn(array $panel): array => [$panel['key'], $panel['type'], $panel['driver'], $panel['addable'], $panel['services'], $panel['suggested_server_id'], $panel['unsupported']], $survey['panels']);
    }

    /**
     * What the import keeps of a panel's connection (encrypted apart from the survey), as `$fields`.
     *
     * @param list<string> $fields
     * @return list<mixed>
     */
    private function kept(int $id, string $panel, array $fields): array
    {
        $kept = json_decode($this->service(Encrypter::class)->decrypt((string) Import::query()->findOrFail($id)->panels), true);
        self::assertIsArray($kept);
        self::assertIsArray($kept[$panel] ?? null, $panel);

        return array_map(static fn(string $field): mixed => $kept[$panel][$field] ?? null, $fields);
    }

    /**
     * A backup uploaded and read through to its review, the page calling continue as it does.
     *
     * @return array<string, mixed> The import in review
     */
    private function read(string $name, string $bytes): array
    {
        $uploaded = $this->upload('/api/admin/imports', 'file', $name, $bytes, ['migrator' => 'mirzabot']);
        self::assertSame(201, $uploaded->getStatusCode(), (string) $uploaded->getBody());

        return $this->continueWhile($this->decode($uploaded)['import']['id'], 'reading');
    }

    /**
     * MirzaBot's backup brought in whole on the review's proposed choices.
     *
     * @return array<string, mixed> The import, done
     */
    private function importAll(): array
    {
        $import = $this->read('backup_2026-11-14.sql', $this->backup('backup-php.sql'));
        self::assertSame('importing', $this->decode($this->postJson("/api/admin/imports/{$import['id']}/start"))['import']['phase']);
        $done = $this->continueWhile($import['id'], 'importing');
        self::assertSame(['done', null], [$done['phase'], $done['error']]);

        return $done;
    }

    /**
     * MirzaBot's panels, answering by address: Marzban signs its admin in (a sudo admin) and lists its users, the
     * Netherlands' 3x-ui its settings and clients, PasarGuard its users, Rebecca its admin (sudo), a page of users and one
     * user's detail, as its Go handlers answer — each with the clients MirzaBot's live services are, and one that is no
     * service of its; anything else (Sweden's 3x-ui among them) is a 404, which no import should ask.
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
            'id' => $id, 'username' => $name, 'status' => 'active', 'used_traffic' => 3221225472, 'lifetime_used_traffic' => 3221225472, 'created_at' => '2024-11-08T01:40:00Z',
            'expire' => $expire, 'data_limit' => $limit, 'data_limit_reset_strategy' => 'no_reset', 'online_at' => '2026-11-14T19:05:00Z', 'is_online' => false,
            'service_id' => 2, 'service_name' => 'Turkey', 'admin_id' => 1, 'admin_username' => 'mirza', 'links' => [], 'subscription_url' => "/sub/{$token}",
            'subscription_urls' => ['primary' => "/sub/{$token}", 'username-key' => "/sub/{$name}/{$key}", 'key' => "/sub/{$key}", 'token' => "/sub/{$token}"], 'group' => null, 'tags' => [],
        ];
        $rzTr = $rebecca(11, 'rz_tr', 53687091200, 1906502400, self::REBECCA_TOKEN, '9d2e4f6a8b0c1d3e5f7a9b1c3d5e7f80');

        return new FulfilledPromise(match ($call) {
            'POST de.mirzashop.example.com/api/admin/token' => $json(['access_token' => 'jwt', 'token_type' => 'bearer']),
            'GET de.mirzashop.example.com/api/admin' => $json(['username' => 'admin', 'is_sudo' => true, 'telegram_id' => null, 'discord_webhook' => null, 'users_usage' => 0]),
            'GET de.mirzashop.example.com/api/users' => $json(['users' => [
                $marzban('5012345671_rz1', 53687091200, 1893456000, 'cnoxMQ'),
                $marzban('rz_custom', 53687091200, 1894752000, 'cnpjdXN0'),
                $marzban('not_from_mirzabot', 0, 1893456000, 'b3RoZXI'),
            ], 'total' => 3]),
            'POST nl.mirzashop.example.com/Xk9pQ/panel/api/setting/all' => $json(['success' => true, 'msg' => '', 'obj' => ['subEnable' => true, 'subPort' => 2096, 'subPath' => '/sub/']]),
            'GET nl.mirzashop.example.com/Xk9pQ/panel/api/clients/list' => $json(['success' => true, 'msg' => '', 'obj' => [
                ['email' => 'sara_x1', 'subId' => 'sarax1sub', 'enable' => true, 'totalGB' => 53687091200, 'expiryTime' => 1896134400000, 'traffic' => ['up' => 1, 'down' => 2, 'lastOnline' => 0]],
                ['email' => 'res_1', 'subId' => 'res1sub', 'enable' => true, 'totalGB' => 0, 'expiryTime' => 0, 'traffic' => ['up' => 0, 'down' => 0, 'lastOnline' => 0]],
            ]]),
            'GET fi.mirzashop.example.com/api/users' => $json(['users' => [
                $pasarguard('mina_test', 104857600, '2030-03-01T00:00:00', 'bWluYQ'),
                $pasarguard('agent2_9', 0, '2030-04-01T00:00:00', 'YWdlbnQy'),
            ], 'total' => 2]),
            'GET tr.mirzashop.example.com/api/admin' => $json(['username' => 'mirza', 'role' => 'sudo', 'permissions' => [], 'telegram_id' => null, 'users_usage' => 0]),
            'GET tr.mirzashop.example.com/api/users' => $json(['users' => [
                $rebecca(4, 'not_from_mirzabot', 0, 0, 'bm90X2Zyb21fbWlyemFib3QsMTczMDAwMDAwMA4f7Rk2Xp1Q', '1b2c3d4e5f60718293a4b5c6d7e8f90a'),
                $rzTr,
            ], 'link_templates' => [], 'total' => 2, 'status_breakdown' => ['active' => 2]]),
            'GET tr.mirzashop.example.com/api/user/rz_tr' => $json($rzTr + [
                'credential_key' => '9d2e4f6a8b0c1d3e5f7a9b1c3d5e7f80', 'flow' => null, 'note' => null, 'telegram_id' => null, 'contact_number' => null, 'sub_updated_at' => null,
                'sub_last_user_agent' => null, 'on_hold_expire_duration' => null, 'on_hold_timeout' => null, 'ip_limit' => 3, 'speed_limit_kbps' => 0, 'auto_delete_in_days' => null,
                'proxies' => [], 'excluded_inbounds' => [], 'inbounds' => [], 'next_plans' => [], 'service_host_orders' => [],
            ]),
            default => new Response(404, ['Content-Type' => 'application/json'], '{"detail":"Not Found"}'),
        });
    }
}
