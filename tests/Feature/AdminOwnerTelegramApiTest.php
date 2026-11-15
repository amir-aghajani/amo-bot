<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Admin\Api\OwnerTelegramController;
use App\Modules\Auth\Services\AdminAccount;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Telegram\Admin\OwnerLinks;
use App\Modules\Users\Enums\UserRole;
use Tests\HttpTestCase;

/**
 * «مدیریت از ربات» as the owner's panel reads and changes it (`/api/admin/settings/owner-telegram`, the card on «تنظیمات
 * پنل › ورود به پنل»): the Telegram accounts that are the owner in the main bot, by what the main bot knows of them; a
 * one-time link, which asks the panel's password first — counted with the login's failures — and ends the last; an
 * account let go, with the admin role its link gave it. The owner's alone. (The links themselves, opened in the bot, and
 * one made from there: BotAdmin\BotAdminFlowTest; every link ended by a new login: AdminCredentialsApiTest,
 * LoginRecoveryApiTest.)
 */
final class AdminOwnerTelegramApiTest extends HttpTestCase
{
    private const URL = '/api/admin/settings/owner-telegram';

    /** A link's request as the panel sends it: the panel's password typed again. */
    private const PROVEN = ['current_password' => self::ADMIN_PASSWORD];

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->loginAsAdmin();
    }

    public function testTheAccountsAreListedOldestFirstByWhatTheMainBotKnowsOfThem(): void
    {
        self::assertSame(['accounts' => [], 'link_open_until' => null], $this->decode($this->get(self::URL)));

        $this->customer(['telegram_id' => 7001, 'first_name' => 'Amir', 'username' => 'amir']);
        $this->customer(['telegram_id' => 7003, 'first_name' => 'Sara', 'username' => '']);
        foreach ([7001, 7002, 7003] as $id) {
            $this->linked($id);
        }

        self::assertSame([
            ['telegram_id' => 7001, 'name' => 'Amir', 'username' => 'amir'],
            ['telegram_id' => 7002, 'name' => null, 'username' => null],
            ['telegram_id' => 7003, 'name' => 'Sara', 'username' => null],
        ], $this->decode($this->get(self::URL))['accounts'], 'one that never wrote to the main bot has no name; one with no handle, none — never an empty one');
    }

    public function testALinkIsMadeOnceTheMainBotIsKnownAndEndsTheLastOne(): void
    {
        $this->config(['telegram.username' => '']);
        $refused = $this->postJson(self::URL . '/link', self::PROVEN);
        self::assertSame([422, [OwnerTelegramController::NO_USERNAME]], [$refused->getStatusCode(), $this->decode($refused)['errors']['link'] ?? null]);
        self::assertNull($this->decode($this->get(self::URL))['link_open_until']);

        $this->config(['telegram.username' => '@amo_shop_bot']);
        $first = $this->decode($this->postJson(self::URL . '/link', self::PROVEN));
        self::assertStringStartsWith('https://t.me/amo_shop_bot?start=' . OwnerLinks::PAYLOAD, $first['link']['url']);
        self::assertSame($first['link']['expires_at'], $first['link_open_until']);
        self::assertSame($first['link']['expires_at'], $this->decode($this->get(self::URL))['link_open_until'], 'the read says the same moment the link was made with');
        self::assertArrayNotHasKey('link', $this->decode($this->get(self::URL)), 'shown once: the read never carries it');

        $second = $this->decode($this->postJson(self::URL . '/link', self::PROVEN));
        $links = $this->service(OwnerLinks::class);
        self::assertNotSame($first['link']['url'], $second['link']['url']);
        self::assertFalse($links->opens(self::payload($first['link']['url'])), 'a new link ends the last');
        self::assertTrue($links->opens(self::payload($second['link']['url'])));
    }

    public function testALinkAsksThePanelsPasswordFirst(): void
    {
        $none = $this->unchecked()->postJson(self::URL . '/link');
        self::assertSame([422, ['رمز عبور فعلی را وارد کنید.']], [$none->getStatusCode(), $this->decode($none)['errors']['current_password'] ?? null], 'a session alone makes no link');

        $blank = $this->postJson(self::URL . '/link', ['current_password' => '']);
        self::assertSame(['current_password'], array_keys($this->decode($blank)['errors']));

        $wrong = $this->postJson(self::URL . '/link', ['current_password' => 'guess']);
        self::assertSame([422, [AdminAccount::WRONG_PASSWORD]], [$wrong->getStatusCode(), $this->decode($wrong)['errors']['current_password'] ?? null]);
        self::assertNull($this->decode($this->get(self::URL))['link_open_until'], 'no link made');

        $made = $this->postJson(self::URL . '/link', self::PROVEN);
        self::assertSame(200, $made->getStatusCode(), (string) $made->getBody());
        self::assertTrue($this->service(OwnerLinks::class)->opens(self::payload($this->decode($made)['link']['url'])));
    }

    public function testAWrongPasswordIsAFailedSignInCountedWithTheLogins(): void
    {
        for ($i = 0; $i < SignInThrottle::MAX_ATTEMPTS; $i++) {
            self::assertSame([AdminAccount::WRONG_PASSWORD], $this->decode($this->postJson(self::URL . '/link', ['current_password' => 'guess-' . $i]))['errors']['current_password']);
        }

        // Even the right password waits now, here and at the login: one count.
        $waiting = $this->postJson(self::URL . '/link', self::PROVEN);
        self::assertSame(429, $waiting->getStatusCode());
        self::assertGreaterThan(0, (int) $waiting->getHeaderLine('Retry-After'));
        self::assertSame(429, $this->postJson('/api/admin/auth/login', ['username' => self::ADMIN_USERNAME, 'password' => self::ADMIN_PASSWORD])->getStatusCode());
        self::assertNull($this->decode($this->get(self::URL))['link_open_until'], 'no link made');
    }

    public function testAnAccountLetGoLosesTheAdminRoleItsLinkGaveAndNoOtherOne(): void
    {
        $raised = $this->customer(['telegram_id' => 7001, 'first_name' => 'Amir']);
        $admin = $this->customer(['telegram_id' => 7002, 'first_name' => 'Sara', 'role' => UserRole::Admin]);
        $this->linked(7001);
        $raised->forceFill(['role' => UserRole::Admin])->save();
        $this->service(OwnerLinks::class)->raised(7001);
        $this->linked(7002);

        $left = $this->decode($this->deleteJson(self::URL . '/7001'));
        self::assertSame([7002], array_column($left['accounts'], 'telegram_id'));
        self::assertSame(UserRole::Customer, $raised->refresh()->role, 'the admin role the link gave goes with it');

        $none = $this->decode($this->deleteJson(self::URL . '/7002'));
        self::assertSame([], $none['accounts']);
        self::assertSame(UserRole::Admin, $admin->refresh()->role, 'an admin before the link stays one');

        self::assertSame(['accounts' => [], 'link_open_until' => null], $this->decode($this->deleteJson(self::URL . '/9999')), 'one not linked changes nothing');
    }

    public function testTheyAreTheOwnersAlone(): void
    {
        $this->loginAsAgent($this->agentBot());

        self::assertSame(404, $this->get('/api/agent/settings/owner-telegram')->getStatusCode());
        self::assertSame(404, $this->postJson('/api/agent/settings/owner-telegram/link', self::PROVEN)->getStatusCode());
        self::assertSame([], $this->service(OwnerLinks::class)->ids());
    }

    /** `$telegramId` linked as the owner, as opening a fresh link from that account does. */
    private function linked(int $telegramId): void
    {
        $links = $this->service(OwnerLinks::class);
        $link = $links->link() ?? self::fail('No link: the main bot has no username.');
        self::assertTrue($links->redeem(self::payload($link['url']), $telegramId));
    }

    /** What a link's /start carries. */
    private static function payload(string $url): string
    {
        return substr($url, (int) strpos($url, '=') + 1);
    }
}
