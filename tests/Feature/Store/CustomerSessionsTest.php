<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Database\ChangeFeed;
use App\Core\Security\Totp;
use App\Modules\Accounts\Enums\ChallengePurpose;
use App\Modules\Accounts\Enums\SignInMethod;
use App\Modules\Accounts\Http\CustomerAuthMiddleware;
use App\Modules\Accounts\Models\AuthChallenge;
use App\Modules\Accounts\Models\CustomerSession;
use App\Modules\Accounts\Services\AuthChallenges;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Accounts\Tasks\PruneAccountsTask;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Store\Models\Website;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;
use Tests\Support\FakeGoogleLogin;
use Tests\Support\FakeTelegramLogin;

/**
 * A customer signed in on the shop's website: their bearer token opens their session — in that shop alone — until it
 * has gone CustomerSessions::IDLE_DAYS unused or LIFETIME_DAYS have passed since the sign-in, or they end it; they read
 * their account and the devices they are signed in on, end any of them (never another customer's), and sign this one
 * out. A banned customer is refused. Each session keeps how it was signed in — Telegram, Google, a password alone, or a
 * password and its second step —, which the shop's admins are judged by on its website. A session's use and the
 * customer's presence are written as they come due — a visit alone moves no panel's lists —, and what ended is forgotten
 * by the hourly housekeeping, every shop's.
 */
final class CustomerSessionsTest extends HttpTestCase
{
    private Website $website;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->website = $this->website();
        $this->customer = $this->wallet($this->customer(['username' => 'ali', 'last_name' => 'Rezaei', 'phone' => '+989121234567']), '50000');
    }

    public function testTheirTokenOpensTheirAccount(): void
    {
        $this->bearer($this->customerSession($this->customer));

        self::assertSame(['customer' => [
            'id' => $this->customer->id,
            'first_name' => 'Ali',
            'last_name' => 'Rezaei',
            'telegram' => ['id' => self::TELEGRAM_ID, 'username' => 'ali'],
            'email' => null,
            'google' => false,
            'has_password' => false,
            'two_factor' => false,
            'phone' => '+989121234567',
            'balance' => '50000.00',
            'created_at' => '2026-10-07T12:00:00+00:00',
        ], 'unread_notifications' => 0, 'staff' => null], $this->decode($this->get($this->storeApi($this->website, '/me'))));
    }

    public function testNoTokenAWrongOneOrAnotherShopsSignsNobodyIn(): void
    {
        $none = $this->unchecked()->get($this->storeApi($this->website, '/me'));
        self::assertSame([401, CustomerAuthMiddleware::SIGNED_OUT], [$none->getStatusCode(), $this->decode($none)['message']]);
        self::assertSame('Bearer', $none->getHeaderLine('WWW-Authenticate'));

        foreach (['not-a-token', str_repeat('a', 64)] as $token) {
            $this->bearer($token);
            self::assertSame(401, $this->get($this->storeApi($this->website, '/me'))->getStatusCode(), $token);
        }

        // A session of an agent's shop, under this shop's key: the customer of another shop is not this one's.
        $bot = $this->agentBot();
        $theirs = CurrentBot::run($bot, fn(): string => $this->customerSession($this->customer(['telegram_id' => self::TELEGRAM_ID])));
        $this->bearer($theirs);
        self::assertSame(401, $this->get($this->storeApi($this->website, '/me'))->getStatusCode());
        $agents = CurrentBot::run($bot, fn(): Website => $this->website());
        self::assertSame(200, $this->get($this->storeApi($agents, '/me'))->getStatusCode(), 'under its own shop\'s key it opens');
    }

    public function testASessionEndsUnusedForThirtyDaysOrAtItsEnd(): void
    {
        $this->bearer($this->customerSession($this->customer));

        Carbon::setTestNow(now()->addDays(CustomerSessions::IDLE_DAYS)->subMinute());
        self::assertSame(200, $this->get($this->storeApi($this->website, '/me'))->getStatusCode(), 'used once more within its days…');
        Carbon::setTestNow(now()->addDays(CustomerSessions::IDLE_DAYS)->subMinute());
        self::assertSame(200, $this->get($this->storeApi($this->website, '/me'))->getStatusCode(), '…and its days begin again');
        Carbon::setTestNow(now()->addDays(CustomerSessions::IDLE_DAYS)->addMinute());
        self::assertSame(401, $this->get($this->storeApi($this->website, '/me'))->getStatusCode(), 'unused for its days: ended');

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->bearer($this->customerSession($this->customer));
        for ($day = 25; $day < CustomerSessions::LIFETIME_DAYS; $day += 25) {
            Carbon::setTestNow(Carbon::parse('2026-10-07 12:00:00')->addDays($day));
            self::assertSame(200, $this->get($this->storeApi($this->website, '/me'))->getStatusCode(), "day {$day}");
        }
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:00:00')->addDays(CustomerSessions::LIFETIME_DAYS));
        self::assertSame(401, $this->get($this->storeApi($this->website, '/me'))->getStatusCode(), 'its end, however much it was used');
    }

    public function testTheyListTheirSessionsNewestFirstAndEndOneButNotAnothersCustomers(): void
    {
        $older = $this->customerSession($this->customer);
        Carbon::setTestNow(now()->addHour());
        $current = $this->customerSession($this->customer);
        $someoneElse = $this->customer(['telegram_id' => 7272]);
        $this->customerSession($someoneElse);
        $theirs = CustomerSession::query()->where('user_id', $someoneElse->id)->sole();
        [$olderRow, $currentRow] = CustomerSession::query()->where('user_id', $this->customer->id)->orderBy('id')->get()->all();
        $this->bearer($current);

        self::assertSame(['sessions' => [
            ['id' => $currentRow->id, 'device' => 'Chrome در Windows', 'ip' => '203.0.113.7', 'created_at' => '2026-10-07T13:00:00+00:00', 'last_used_at' => '2026-10-07T13:00:00+00:00', 'current' => true],
            ['id' => $olderRow->id, 'device' => 'Chrome در Windows', 'ip' => '203.0.113.7', 'created_at' => '2026-10-07T12:00:00+00:00', 'last_used_at' => '2026-10-07T12:00:00+00:00', 'current' => false],
        ]], $this->decode($this->get($this->storeApi($this->website, '/me/sessions'))));

        $another = $this->deleteJson($this->storeApi($this->website, "/me/sessions/{$theirs->id}"));
        self::assertSame(404, $another->getStatusCode(), "another customer's session is not theirs to end");
        self::assertSame(404, $this->deleteJson($this->storeApi($this->website, '/me/sessions/999999'))->getStatusCode());

        self::assertSame(204, $this->deleteJson($this->storeApi($this->website, "/me/sessions/{$olderRow->id}"))->getStatusCode());
        $this->bearer($older);
        self::assertSame(401, $this->get($this->storeApi($this->website, '/me'))->getStatusCode(), 'the device it was is signed out');
        $this->bearer($current);
        self::assertCount(1, $this->decode($this->get($this->storeApi($this->website, '/me/sessions')))['sessions']);
        self::assertTrue(CustomerSession::query()->whereKey($theirs->id)->exists());
    }

    public function testACustomerKeepsTheirNewestTwentySessionsOneMoreEndsTheOldest(): void
    {
        $oldest = $this->customerSession($this->customer);
        $second = $this->customerSession($this->customer);
        for ($i = 3; $i <= CustomerSessions::MAX_SESSIONS; $i++) {
            $this->customerSession($this->customer);
        }
        $someoneElse = $this->customerSession($this->customer(['telegram_id' => 7272]));
        $this->bearer($oldest);
        self::assertSame(200, $this->get($this->storeApi($this->website, '/me'))->getStatusCode(), 'twenty: all kept');

        $this->bearer($this->customerSession($this->customer));

        self::assertCount(CustomerSessions::MAX_SESSIONS, $this->decode($this->get($this->storeApi($this->website, '/me/sessions')))['sessions']);
        $this->bearer($oldest);
        self::assertSame(401, $this->get($this->storeApi($this->website, '/me'))->getStatusCode(), 'the twenty-first ended the oldest');
        $this->bearer($second);
        self::assertSame(200, $this->get($this->storeApi($this->website, '/me'))->getStatusCode());
        $this->bearer($someoneElse);
        self::assertSame(200, $this->get($this->storeApi($this->website, '/me'))->getStatusCode(), "another customer's sessions are theirs to count");
    }

    public function testSigningOutEndsThisDeviceAlone(): void
    {
        $other = $this->customerSession($this->customer);
        $this->bearer($this->customerSession($this->customer));

        self::assertSame(204, $this->postJson($this->storeApi($this->website, '/auth/logout'))->getStatusCode());

        self::assertSame(401, $this->get($this->storeApi($this->website, '/me'))->getStatusCode());
        $this->bearer($other);
        self::assertSame(200, $this->get($this->storeApi($this->website, '/me'))->getStatusCode());
    }

    public function testEachWayInKeepsHowItsSessionWasSignedIn(): void
    {
        $this->telegram();
        $this->website->forceFill(['google_client_id' => self::GOOGLE_CLIENT_ID])->save();
        $sara = $this->webCustomer();
        $signedIn = static function (ResponseInterface $response): ?SignInMethod {
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

            return CustomerSession::query()->latest('id')->firstOrFail()->method;
        };
        $nonce = fn(): string => $this->decode($this->postJson($this->storeApi($this->website, '/auth/nonce')))['nonce'];
        $password = fn(): ResponseInterface => $this->postJson($this->storeApi($this->website, '/auth/login'), ['email' => self::WEB_EMAIL, 'password' => self::WEB_PASSWORD]);

        // Each provider's keys answer on the shop's outgoing client in turn.
        $this->telegramLogin();
        $telegram = $nonce();
        self::assertSame(SignInMethod::Telegram, $signedIn($this->postJson($this->storeApi($this->website, '/auth/telegram'), ['id_token' => FakeTelegramLogin::idToken(FakeTelegramLogin::claims(self::WEBSITE_CLIENT_ID, $telegram)), 'nonce' => $telegram])));
        $this->googleLogin();
        $google = $nonce();
        self::assertSame(SignInMethod::Google, $signedIn($this->postJson($this->storeApi($this->website, '/auth/google'), ['id_token' => FakeGoogleLogin::idToken(FakeGoogleLogin::claims(self::GOOGLE_CLIENT_ID, $google)), 'nonce' => $google])));
        self::assertSame(SignInMethod::Password, $signedIn($password()));

        $secret = $this->twoFactorOn($this->website, $sara)['secret'];
        Carbon::setTestNow(now()->addSeconds(30));
        $challenge = $this->decode($password())['two_factor']['challenge'];
        self::assertSame(SignInMethod::PasswordAndCode, $signedIn($this->postJson($this->storeApi($this->website, '/auth/login/2fa'), ['challenge' => $challenge, 'code' => Totp::code($secret)])));
    }

    public function testABannedCustomerIsRefused(): void
    {
        $this->bearer($this->customerSession($this->customer));
        $this->customer->forceFill(['status' => UserStatus::Banned])->save();

        $response = $this->get($this->storeApi($this->website, '/me'));

        self::assertSame([403, SignInRefusedException::BANNED], [$response->getStatusCode(), $this->decode($response)['message']]);
    }

    public function testUseAndPresenceAreWrittenAsTheyComeDueAndAVisitMovesNoPanelsList(): void
    {
        $this->bearer($this->customerSession($this->customer));
        $session = CustomerSession::query()->sole();
        $users = fn(): int => $this->service(ChangeFeed::class)->versions()['users'] ?? 0;
        $before = $users();

        Carbon::setTestNow(now()->addMinutes(4));
        $this->get($this->storeApi($this->website, '/me'));
        self::assertEquals(Carbon::parse('2026-10-07 12:00:00'), $session->refresh()->last_used_at, 'its use within five minutes: not written again');
        self::assertEquals(now(), $this->customer->refresh()->last_seen_at, 'their presence, a minute old: written');

        Carbon::setTestNow(now()->addMinutes(2));
        $this->get($this->storeApi($this->website, '/me'));
        self::assertEquals(now(), $session->refresh()->last_used_at);
        self::assertSame($before, $users(), 'a visit alone moves no panel\'s customer lists');
    }

    public function testTheHousekeepingForgetsWhatEndedInEveryShop(): void
    {
        $bot = $this->agentBot();
        $this->customerSession($this->customer, ['last_used_at' => now()->subDays(CustomerSessions::IDLE_DAYS + 1)]);
        $this->customerSession($this->customer, ['expires_at' => now()->subSecond()]);
        $this->customerSession($this->customer, ['last_used_at' => null, 'created_at' => now()->subDays(CustomerSessions::IDLE_DAYS + 1)]);
        $live = $this->customerSession($this->customer);
        CurrentBot::run($bot, fn(): string => $this->customerSession($this->customer(['telegram_id' => 8383]), ['expires_at' => now()->subDay()]));
        $challenges = $this->service(AuthChallenges::class);
        $challenges->issue(ChallengePurpose::Nonce, null, null, [], 60);
        $kept = $challenges->nonce();
        Carbon::setTestNow(now()->addMinutes(2));

        $this->service(PruneAccountsTask::class)->run();

        self::assertSame(1, CurrentBot::everywhere(static fn(): int => CustomerSession::query()->count()), "the live session alone, of every shop's");
        $this->bearer($live);
        self::assertSame(200, $this->get($this->storeApi($this->website, '/me'))->getStatusCode());
        self::assertSame(1, CurrentBot::everywhere(static fn(): int => AuthChallenge::query()->count()), 'the expired challenge went');
        self::assertSame([], $challenges->spend(ChallengePurpose::Nonce, $kept), 'the other stays good');
    }
}
