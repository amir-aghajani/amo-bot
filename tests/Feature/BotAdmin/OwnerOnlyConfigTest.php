<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Core\Config\ConfigFile;
use App\Core\Database\Drivers\ProbeResult;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Settings\Services\ConfigSettings;
use App\Modules\Telegram\Admin\AdminSeats;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Door\PanelBridge;
use App\Modules\Telegram\Admin\OwnerLinks;
use App\Modules\Users\Models\User;
use Tests\Fakes\ProbedDriver;
use Tests\HttpTestCase;

/**
 * What hands over the main bot or the owner's seat is the owner's themselves alone (ConfigSettings::update()): one of the
 * main shop's admins, asking the panels' API from the bot's management with the owner's seat (AdminSeats, PanelBridge),
 * moves no site address — every bot's webhook is under it, its secret in the path —, changes no main bot's token or
 * @username — another bot's webhook info shows the address with its secret; another @username gets the owner's one-time
 * link —, and saves or tries no database — whose settings say who the owner is in the bot. Each is refused before
 * anything is written or tried, in the house's words; the rest of each group stays theirs. The owner — from the panel,
 * or their linked Telegram account — keeps all of it, and the installer's own saves ask no one.
 */
final class OwnerOnlyConfigTest extends HttpTestCase
{
    private const TOKEN = '123456789:AAF' . 'yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy';

    private const OTHER_TOKEN = '987654321:AAG' . 'zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz';

    private ConfigFile $file;

    private ProbedDriver $mysql;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->file = $this->configFile([
            'APP_NAME' => 'AmoBot',
            'APP_URL' => 'https://shop.example.com/store',
            'APP_KEY' => 'base64:x',
            'APP_TIMEZONE' => 'UTC',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'amobot',
            'DB_USERNAME' => 'root',
            'DB_PASSWORD' => 'old pass',
            'TELEGRAM_BOT_TOKEN' => self::TOKEN,
            'TELEGRAM_BOT_USERNAME' => 'amo_shop_bot',
            'TELEGRAM_API_URL' => 'https://api.telegram.org',
            'TELEGRAM_WEBHOOK_SECRET' => 'owners_secret-0123456789',
            'LOG_LEVEL' => 'info',
        ]);
        $this->mysql = $this->probedDatabase('mysql');
    }

    public function testAnAdminMovesNoSiteAddressButSavesTheRestOfTheGroup(): void
    {
        $admin = $this->bridgeOf($this->admin(['telegram_id' => 5151]));
        $app = ['name' => 'AmoBot', 'debug' => false, 'timezone' => 'UTC'];

        foreach (['https://evil.example', 'https://shop.example.com:8443/store', 'http://shop.example.com/store', 'https://shop.example.com/elsewhere', 'https://shop.example.com'] as $url) {
            self::assertSame([403, ActorRefusedException::OWNERS_ADDRESS], self::refused(static fn() => $admin->put('/settings/config/app', ['url' => $url] + $app)), $url);
        }
        self::assertSame('https://shop.example.com/store', $this->file->get('APP_URL'), 'neither its origin nor its path moved');
        // Working an agent's shop with the owner's seat: config.php is still the main shop's, and they still an admin.
        $elsewhere = $this->service(PanelBridge::class)->for(($this->service(AdminSeats::class)->of($this->admin(['telegram_id' => 5152])) ?? throw new \LogicException('no seat'))->in($this->agentBot()));
        self::assertSame([403, ActorRefusedException::OWNERS_ADDRESS], self::refused(static fn() => $elsewhere->put('/settings/config/app', ['url' => 'https://evil.example'] + $app)), 'nor from another shop');
        self::assertSame([403, ActorRefusedException::OWNERS_DATABASE], self::refused(static fn() => $elsewhere->post('/settings/config/database/test', ['driver' => 'mysql', 'host' => 'db.attacker.example', 'port' => '3306', 'database' => 'x', 'username' => 'y', 'password' => 'z'])));

        $admin->put('/settings/config/app', ['url' => 'https://shop.example.com/store/', 'name' => 'فروشگاه امو', 'timezone' => 'Asia/Tehran'] + $app);
        self::assertSame(['فروشگاه امو', 'Asia/Tehran', 'https://shop.example.com/store'], [$this->file->get('APP_NAME'), $this->file->get('APP_TIMEZONE'), $this->file->get('APP_URL')], 'sent back as it stands — a trailing slash aside —, the rest saved');

        $this->bridgeOf($this->linkedOwner())->put('/settings/config/app', ['url' => 'https://new.example.com'] + $app);
        self::assertSame('https://new.example.com', $this->file->get('APP_URL'), 'the owner\'s own linked account moves it');

        $this->loginAsAdmin();
        self::assertSame(200, $this->putJson('/api/admin/settings/config/app', ['url' => 'https://panel.example.com'] + $app)->getStatusCode());
        self::assertSame('https://panel.example.com', $this->file->get('APP_URL'), 'and the panel');
    }

    public function testAnAdminChangesNoMainBotTokenNorUsername(): void
    {
        $admin = $this->bridgeOf($this->admin(['telegram_id' => 5151]));
        $telegram = ['token' => '', 'username' => 'amo_shop_bot', 'api_url' => 'https://api.telegram.org', 'poll_timeout' => '30'];

        foreach ([['token' => self::OTHER_TOKEN], ['clear_token' => true], ['username' => 'admins_own_bot'], ['username' => ''], ['token' => self::OTHER_TOKEN, 'webhook_secret' => 'owners_secret-0123456789']] as $change) {
            self::assertSame([403, ActorRefusedException::OWNERS_BOT], self::refused(static fn() => $admin->put('/settings/config/telegram', $change + $telegram)), (string) json_encode($change));
        }
        self::assertSame([self::TOKEN, 'amo_shop_bot'], [$this->file->get('TELEGRAM_BOT_TOKEN'), $this->file->get('TELEGRAM_BOT_USERNAME')], 'neither replaced nor cleared');

        $admin->put('/settings/config/telegram', ['poll_timeout' => '25'] + $telegram);
        $admin->put('/settings/config/telegram', ['poll_timeout' => '20', 'token' => self::TOKEN, 'username' => '@Amo_Shop_Bot'] + $telegram);
        self::assertSame([20, self::TOKEN, 'owners_secret-0123456789'], [$this->file->get('TELEGRAM_POLL_TIMEOUT'), $this->file->get('TELEGRAM_BOT_TOKEN'), $this->file->get('TELEGRAM_WEBHOOK_SECRET')], 'left blank, or sent as it stands — an @ or a letter\'s case aside —: the rest saved');

        $this->bridgeOf($this->linkedOwner())->put('/settings/config/telegram', ['token' => self::OTHER_TOKEN, 'username' => 'new_shop_bot'] + $telegram);
        self::assertSame([self::OTHER_TOKEN, 'new_shop_bot'], [$this->file->get('TELEGRAM_BOT_TOKEN'), $this->file->get('TELEGRAM_BOT_USERNAME')], 'the owner\'s own linked account changes both');
    }

    public function testTheDatabaseIsTheOwnersAloneToSaveOrTry(): void
    {
        $admin = $this->bridgeOf($this->admin(['telegram_id' => 5151]));
        $database = ['driver' => 'mysql', 'host' => 'db.attacker.example', 'port' => '3306', 'database' => 'shop', 'username' => 'shop', 'password' => 'theirs'];
        $this->mysql->probed = [];

        self::assertSame([403, ActorRefusedException::OWNERS_DATABASE], self::refused(static fn() => $admin->put('/settings/config/database', $database)));
        self::assertSame([403, ActorRefusedException::OWNERS_DATABASE], self::refused(static fn() => $admin->post('/settings/config/database/test', $database)));
        self::assertSame([403, ActorRefusedException::OWNERS_DATABASE], self::refused(static fn() => $admin->post('/settings/config/database/test', ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'amobot', 'username' => 'root'])), 'the stored one tried too');
        self::assertSame([], $this->mysql->probed, 'refused before any database is asked');
        self::assertSame(['127.0.0.1', 'amobot', 'old pass'], [$this->file->get('DB_HOST'), $this->file->get('DB_DATABASE'), $this->file->get('DB_PASSWORD')], 'nothing written');

        $owner = $this->bridgeOf($this->linkedOwner());
        $this->mysql->answers[] = new ProbeResult('MySQL 8.0.36', 3);
        self::assertSame('MySQL 8.0.36', $owner->post('/settings/config/database/test', $database)['database']['version'] ?? null, 'the owner\'s own linked account tries it');
        $owner->put('/settings/config/database', $database);
        self::assertSame('db.attacker.example', $this->file->get('DB_HOST'), 'and saves it');
    }

    /**
     * The Bot API's address is judged as its field writes it, never as typed: a port in Persian digits is that port. An
     * admin changes none of it — another server, or another path on the same one —; the owner moving it to another
     * server is asked the panel's password whatever digits the port is typed in, with no token kept to give it away.
     */
    public function testTheBotApiAddressIsJudgedAsItIsWritten(): void
    {
        $this->file->setMany(['TELEGRAM_BOT_TOKEN' => '']);
        $admin = $this->bridgeOf($this->admin(['telegram_id' => 5151]));
        $telegram = ['token' => '', 'username' => 'amo_shop_bot', 'poll_timeout' => '30'];

        foreach (['https://evil.example:8443', 'https://evil.example:۸۴۴۳', 'https://api.telegram.org/elsewhere', 'https://api.telegram.org:۴۴۳'] as $url) {
            self::assertSame([403, ActorRefusedException::OWNERS_API], self::refused(static fn() => $admin->put('/settings/config/telegram', ['api_url' => $url] + $telegram)), $url);
        }
        self::assertSame('https://api.telegram.org', $this->file->get('TELEGRAM_API_URL'), 'nothing written');

        $this->loginAsAdmin();
        $asked = $this->putJson('/api/admin/settings/config/telegram', ['api_url' => 'https://evil.example:۸۴۴۳'] + $telegram);
        self::assertSame([422, ['current_password']], [$asked->getStatusCode(), array_keys($this->decode($asked)['errors'])], 'the owner: another server, the password asked');
        self::assertSame('https://api.telegram.org', $this->file->get('TELEGRAM_API_URL'));
        self::assertSame(200, $this->putJson('/api/admin/settings/config/telegram', ['api_url' => 'https://botapi.example:۸۴۴۳', 'current_password' => self::ADMIN_PASSWORD] + $telegram)->getStatusCode());
        self::assertSame('https://botapi.example:8443', $this->file->get('TELEGRAM_API_URL'), 'with it, saved as the field writes it');
    }

    /**
     * With a token kept, moving the Bot API's address leaves it behind — a refusal of the form's own —: to an admin, the
     * Bot API address's words all the same, never the password's, and nothing written.
     */
    public function testAnAdminMovingTheBotApiAddressPastAKeptTokenIsToldItIsTheOwners(): void
    {
        $admin = $this->bridgeOf($this->admin(['telegram_id' => 5151]));
        $telegram = ['token' => '', 'username' => 'amo_shop_bot', 'poll_timeout' => '30'];

        foreach (['https://evil.example', 'https://evil.example:۸۴۴۳'] as $url) {
            self::assertSame([403, ActorRefusedException::OWNERS_API], self::refused(static fn() => $admin->put('/settings/config/telegram', ['api_url' => $url] + $telegram)), $url);
        }
        self::assertSame(['https://api.telegram.org', self::TOKEN], [$this->file->get('TELEGRAM_API_URL'), $this->file->get('TELEGRAM_BOT_TOKEN')], 'nothing written');
    }

    /**
     * What the screen tells one of the main shop's admins: no database settings — where it is and who signs in to it are
     * the owner's alone, as its save is — and the webhook's secret only said to be kept; the owner sees both.
     */
    public function testAnAdminIsToldNoDatabaseSettingsNorAHintOfTheWebhookSecret(): void
    {
        $admin = $this->bridgeOf($this->admin(['telegram_id' => 5151]))->get('/settings/config')['groups'] ?? [];
        self::assertSame([], (array) ($admin['database']['values'] ?? null), 'no host, user nor database name');
        self::assertSame('mysql', $admin['database']['driver'] ?? null, 'the driver in use, as the screen names it');
        self::assertSame(['set' => true, 'hint' => '••••••••'], $admin['telegram']['webhook_secret'] ?? null, 'kept, and no more');
        self::assertStringNotContainsString('127.0.0.1', (string) json_encode($admin), 'the host nowhere');

        $owner = $this->bridgeOf($this->linkedOwner())->get('/settings/config')['groups'] ?? [];
        self::assertSame('127.0.0.1', $owner['database']['values']['host'] ?? null, 'the owner\'s own linked account sees it');
        self::assertSame('••••••6789', $owner['telegram']['webhook_secret']['hint'] ?? null, 'and its last characters');
    }

    /**
     * The panels' session — how long a sign-in lasts without use, and whether its cookie is always secure — is the owner's
     * own sign-in: an admin changes neither, and saves the rest of the group with them as they stand; the owner changes
     * both.
     */
    public function testThePanelsSessionIsTheOwnersAlone(): void
    {
        $this->file->setMany(['SESSION_LIFETIME' => 120, 'SESSION_SECURE_COOKIE' => false]);
        $admin = $this->bridgeOf($this->admin(['telegram_id' => 5151]));
        $advanced = ['log_level' => 'info', 'session_lifetime' => '120', 'session_secure_cookie' => false, 'http_timeout' => '30'];

        foreach ([['session_lifetime' => '43200'], ['session_lifetime' => '۴۳۲۰۰'], ['session_secure_cookie' => true]] as $change) {
            self::assertSame([403, ActorRefusedException::OWNERS_SESSION], self::refused(static fn() => $admin->put('/settings/config/advanced', $change + $advanced)), (string) json_encode($change, JSON_UNESCAPED_UNICODE));
        }
        self::assertSame([120, false], [$this->file->get('SESSION_LIFETIME'), $this->file->get('SESSION_SECURE_COOKIE')], 'nothing written');

        $admin->put('/settings/config/advanced', ['log_level' => 'warning', 'session_lifetime' => '۱۲۰'] + $advanced);
        self::assertSame(['warning', 120], [$this->file->get('LOG_LEVEL'), $this->file->get('SESSION_LIFETIME')], 'sent as they stand — in Persian digits too —: the rest saved');

        $this->bridgeOf($this->linkedOwner())->put('/settings/config/advanced', ['session_lifetime' => '600', 'session_secure_cookie' => true] + $advanced);
        self::assertSame([600, true], [$this->file->get('SESSION_LIFETIME'), $this->file->get('SESSION_SECURE_COOKIE')], 'the owner\'s own linked account changes both');
    }

    public function testTheRestOfThePanelsSettingsStayTheAdminsAndTheInstallerAsksNoOne(): void
    {
        $admin = $this->bridgeOf($this->admin(['telegram_id' => 5151]));
        $advanced = (array) ($admin->get('/settings/config')['groups']['advanced'] ?? []);
        $admin->put('/settings/config/advanced', ['log_level' => 'debug', 'session_lifetime' => (string) ($advanced['session_lifetime'] ?? ''), 'session_secure_cookie' => $advanced['session_secure_cookie'] ?? false, 'http_timeout' => '30']);
        $admin->put('/settings/config/mail', ['transport' => 'none']);
        self::assertSame(['debug', 'none'], [$this->file->get('LOG_LEVEL'), $this->file->get('MAIL_TRANSPORT')], 'what hands over nothing: saved');

        // The installer's own steps: before any owner signs in, no principal to ask.
        $settings = $this->service(ConfigSettings::class);
        $settings->saveSite(['name' => 'فروشگاه تازه', 'url' => 'https://fresh.example.com']);
        $this->telegram()->reply(['id' => 987654321, 'is_bot' => true, 'first_name' => 'Fresh', 'username' => 'fresh_shop_bot']);
        $settings->saveBotToken(self::OTHER_TOKEN);
        $settings->saveDatabase(['driver' => 'mysql', 'host' => 'db.fresh.example', 'port' => '3306', 'database' => 'fresh', 'username' => 'fresh', 'password' => 'fresh pass']);
        self::assertSame(
            ['https://fresh.example.com', self::OTHER_TOKEN, 'fresh_shop_bot', 'db.fresh.example'],
            [$this->file->get('APP_URL'), $this->file->get('TELEGRAM_BOT_TOKEN'), $this->file->get('TELEGRAM_BOT_USERNAME'), $this->file->get('DB_HOST')],
        );
    }

    /** The owner's linked Telegram account in the main bot (OwnerLinks), as opening the panel's one-time link makes it. */
    private function linkedOwner(): User
    {
        $owner = $this->customer(['telegram_id' => 7001, 'first_name' => 'Amir']);
        $this->linkOwner(7001);

        return $owner;
    }

    /** `$telegramId` becomes the owner in the main bot, as opening the panel's one-time link makes it. */
    private function linkOwner(int $telegramId): void
    {
        $links = $this->service(OwnerLinks::class);
        $link = $links->link();
        self::assertNotNull($link);
        self::assertTrue($links->redeem(substr($link['url'], (int) strpos($link['url'], '=') + 1), $telegramId));
    }

    /** The panels' API as `$user`'s seat in the main bot's management asks it (AdminSeats::of(), PanelBridge). */
    private function bridgeOf(User $user): PanelBridge
    {
        $seat = $this->service(AdminSeats::class)->of($user);
        self::assertNotNull($seat, 'a seat');

        return $this->service(PanelBridge::class)->for($seat);
    }

    /** @return array{int, string} The refusal's status and words */
    private static function refused(\Closure $call): array
    {
        try {
            $call();
        } catch (ApiRefusal $refusal) {
            return [$refusal->status, $refusal->getMessage()];
        }

        self::fail('the API answered');
    }
}
