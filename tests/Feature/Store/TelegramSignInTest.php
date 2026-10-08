<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Accounts\Models\CustomerSession;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Bots\CurrentBot;
use App\Modules\Referrals\Services\ReferralService;
use App\Modules\Store\Models\Website;
use App\Modules\Telegram\Api\TelegramUnreachableException;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;
use Tests\Support\FakeTelegramLogin;

/**
 * Signing in on the shop's website with Telegram's popup (telegram-login.js): the site asks the shop for a nonce,
 * Telegram signs an id_token with it, and the site posts both. The token is checked against Telegram's published keys —
 * kept an hour, read again for a key they lack, at most once a minute — for this site's Client ID, in its time, its nonce
 * spent once; its `id` is the shop's customer with that Telegram account — the bot's, wallet and all, their profile as
 * Telegram has it now — or a newcomer, registered as the bot registers one: reported, made a referral by the site's
 * code, an agent the admin of their own bot. A banned customer is refused; Telegram sign-in off is a 422; Telegram out
 * of reach a 502; an address whose sign-ins keep failing waits.
 */
final class TelegramSignInTest extends HttpTestCase
{
    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->telegram();
        $this->telegramLogin();
        $this->website = $this->website();
    }

    public function testABotCustomerSignsInWithTheirTelegramAccountAndSeesTheirAccount(): void
    {
        $customer = $this->wallet($this->customer(['first_name' => 'Old', 'username' => 'old_handle']), '50000');
        $nonce = $this->nonce();

        $response = $this->send('POST', $this->storeApi($this->website, '/auth/telegram'), ['id_token' => self::token($nonce), 'nonce' => $nonce], ['X-Requested-With' => 'XMLHttpRequest', 'User-Agent' => self::IPHONE]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $answer = $this->decode($response);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $answer['token']);
        self::assertSame([
            'id' => $customer->id,
            'first_name' => 'Ali',
            'last_name' => 'Rezaei',
            'telegram' => ['id' => self::TELEGRAM_ID, 'username' => 'ali'],
            'email' => null,
            'google' => false,
            'has_password' => false,
            'two_factor' => false,
            'phone' => null,
            'balance' => '50000.00',
            'created_at' => '2026-10-07T12:00:00+00:00',
        ], $answer['customer'], "the bot's customer, their profile as Telegram has it now");
        self::assertSame(1, User::query()->count(), 'nobody new');
        self::assertSame('Safari در iOS', CustomerSession::query()->sole()->device);
        self::assertNotSame($answer['token'], CustomerSession::query()->sole()->token_hash, 'the token itself is kept nowhere');

        $this->bearer($answer['token']);
        self::assertSame($answer['customer'], $this->decode($this->get($this->storeApi($this->website, '/me')))['customer']);
        self::assertSame(['GET https://oauth.telegram.org/.well-known/jwks.json'], $this->telegramLogin()->calls());
    }

    public function testANewcomerIsRegisteredAsTheBotRegistersOne(): void
    {
        $this->reportGroup();
        $this->referralProgram();
        $referrer = $this->customer(['telegram_id' => 7070, 'first_name' => 'Owner']);
        $code = $this->service(ReferralService::class)->codeFor($referrer);

        $response = $this->signIn(['id' => 8080], ['referral_code' => strtoupper($code)]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $newcomer = User::query()->where('telegram_id', 8080)->sole();
        self::assertSame(['Ali', 'Rezaei', 'ali', $referrer->id], [$newcomer->first_name, $newcomer->last_name, $newcomer->username, $newcomer->referred_by]);
        self::assertSame($newcomer->id, $this->decode($response)['customer']['id']);
        $reports = ReportMessage::query()->where('topic', Topic::Users)->pluck('text')->all();
        self::assertCount(1, $reports, 'reported to the admins');
        self::assertStringContainsString('کاربر جدید', $reports[0]);
        self::assertStringContainsString('<code>7070</code>', $reports[0], 'with whoever brought them');
        self::assertSame([self::text(BotText::ReferralJoined)], $this->telegram()->sentTo(7070), 'who is told');

        // Signing in again changes none of it: they are the shop's customer now.
        $other = $this->customer(['telegram_id' => 7171]);
        $this->signIn(['id' => 8080], ['referral_code' => $this->service(ReferralService::class)->codeFor($other)]);
        self::assertSame($referrer->id, $newcomer->refresh()->referred_by);
        self::assertSame(1, ReportMessage::query()->where('topic', Topic::Users)->count());
    }

    public function testAnAgentIsTheAdminOfTheirOwnBotOnItsWebsiteToo(): void
    {
        $bot = $this->agentBot();
        $website = CurrentBot::run($bot, fn(): Website => $this->website());
        $nonce = $this->decode($this->postJson($this->storeApi($website, '/auth/nonce')))['nonce'];

        $response = $this->postJson($this->storeApi($website, '/auth/telegram'), ['id_token' => self::token($nonce, ['id' => self::AGENT_TELEGRAM_ID]), 'nonce' => $nonce]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $agent = CurrentBot::run($bot, static fn(): User => User::query()->where('telegram_id', self::AGENT_TELEGRAM_ID)->sole());
        self::assertSame(UserRole::Admin, $agent->role, "a customer of their own bot's shop, its admin");
    }

    /**
     * A token the site might post — made as the test runs, in its time — and why it signs nobody in.
     *
     * @return array<string, array{\Closure(string): string, string}>
     */
    public static function refusedTokens(): array
    {
        $signed = static fn(\Closure $overrides): \Closure => static fn(string $nonce): string => FakeTelegramLogin::idToken(FakeTelegramLogin::claims(self::WEBSITE_CLIENT_ID, $nonce, $overrides()));

        return [
            'claims changed after it was signed' => [static function (string $nonce): string {
                [$header, , $signature] = explode('.', FakeTelegramLogin::idToken(FakeTelegramLogin::claims(self::WEBSITE_CLIENT_ID, $nonce, ['id' => 8080])));

                return $header . '.' . JWT::urlsafeB64Encode((string) json_encode(FakeTelegramLogin::claims(self::WEBSITE_CLIENT_ID, $nonce))) . '.' . $signature;
            }, SignInRefusedException::TOKEN_INVALID],
            'signed with a shared secret under the key\'s id' => [static fn(string $nonce): string => JWT::encode(FakeTelegramLogin::claims(self::WEBSITE_CLIENT_ID, $nonce), str_repeat('k', 64), 'HS256', FakeTelegramLogin::KID), SignInRefusedException::TOKEN_INVALID],
            'a key Telegram does not publish' => [static fn(string $nonce): string => FakeTelegramLogin::idToken(FakeTelegramLogin::claims(self::WEBSITE_CLIENT_ID, $nonce), ['kid' => 'not-published']), SignInRefusedException::TOKEN_INVALID],
            'no token at all' => [static fn(string $nonce): string => 'not.a-token', SignInRefusedException::TOKEN_INVALID],
            'for another site' => [$signed(static fn(): array => ['aud' => '999999']), SignInRefusedException::TOKEN_ELSEWHERE],
            'by another issuer' => [$signed(static fn(): array => ['iss' => 'https://accounts.google.com']), SignInRefusedException::TOKEN_ELSEWHERE],
            'expired' => [$signed(static fn(): array => ['iat' => now()->subHours(2)->getTimestamp(), 'exp' => now()->subMinutes(2)->getTimestamp()]), SignInRefusedException::TOKEN_EXPIRED],
            'without an expiry' => [$signed(static fn(): array => ['exp' => null]), SignInRefusedException::TOKEN_INVALID],
            'issued later than now' => [$signed(static fn(): array => ['iat' => now()->addMinutes(5)->getTimestamp()]), SignInRefusedException::TOKEN_INVALID],
            'without the profile' => [$signed(static fn(): array => ['id' => null, 'given_name' => null, 'family_name' => null, 'name' => null, 'preferred_username' => null]), SignInRefusedException::NO_TELEGRAM_ID],
        ];
    }

    /** @param \Closure(string): string $token */
    #[DataProvider('refusedTokens')]
    public function testATokenThatIsNotTelegramsForThisSiteSignsNobodyIn(\Closure $token, string $why): void
    {
        $nonce = $this->nonce();

        $response = $this->postJson($this->storeApi($this->website, '/auth/telegram'), ['id_token' => $token($nonce), 'nonce' => $nonce]);

        self::assertSame([401, $why], [$response->getStatusCode(), $this->decode($response)['message']]);
        self::assertStringNotContainsString('eyJ', (string) $response->getBody(), 'the token is never repeated');
        self::assertSame(0, CustomerSession::query()->count());
        self::assertSame(0, User::query()->count());
    }

    public function testATokenWithinAMinuteOfTheClocksDifferenceIsTaken(): void
    {
        $nonce = $this->nonce();
        $late = self::token($nonce, ['iat' => now()->subHour()->getTimestamp(), 'exp' => now()->subSeconds(30)->getTimestamp()]);

        self::assertSame(200, $this->postJson($this->storeApi($this->website, '/auth/telegram'), ['id_token' => $late, 'nonce' => $nonce])->getStatusCode());
    }

    public function testTheNonceIsTheShopsOwnAndSpentOnce(): void
    {
        $never = bin2hex(random_bytes(32));
        self::assertRefused($this->postJson($this->storeApi($this->website, '/auth/telegram'), ['id_token' => self::token($never), 'nonce' => $never]), SignInRefusedException::SIGN_IN_SPENT);

        $asked = $this->nonce();
        $other = $this->nonce();
        self::assertRefused($this->postJson($this->storeApi($this->website, '/auth/telegram'), ['id_token' => self::token($asked), 'nonce' => $other]), SignInRefusedException::SIGN_IN_SPENT, 'the token was signed for another');

        $nonce = $this->nonce();
        $sign = ['id_token' => self::token($nonce), 'nonce' => $nonce];
        self::assertSame(200, $this->postJson($this->storeApi($this->website, '/auth/telegram'), $sign)->getStatusCode());
        self::assertRefused($this->postJson($this->storeApi($this->website, '/auth/telegram'), $sign), SignInRefusedException::SIGN_IN_SPENT, 'the same sign-in again');

        $stale = $this->nonce();
        $token = self::token($stale);
        Carbon::setTestNow(now()->addMinutes(31));
        self::assertRefused($this->postJson($this->storeApi($this->website, '/auth/telegram'), ['id_token' => $token, 'nonce' => $stale]), SignInRefusedException::SIGN_IN_SPENT, 'a nonce waits half an hour');
    }

    public function testWhatTheSignInNeedsIsSaidUnderItsField(): void
    {
        $withoutNonce = $this->unchecked()->postJson($this->storeApi($this->website, '/auth/telegram'), ['id_token' => self::token('x')]);
        self::assertSame(422, $withoutNonce->getStatusCode());
        self::assertArrayHasKey('nonce', $this->decode($withoutNonce)['errors']);

        $nothing = $this->unchecked()->postJson($this->storeApi($this->website, '/auth/telegram'), ['referral_code' => 'abc']);
        self::assertSame(422, $nothing->getStatusCode());
        self::assertArrayHasKey('id_token', $this->decode($nothing)['errors']);
    }

    public function testABannedCustomerIsRefusedAndNoSessionOpens(): void
    {
        $this->customer(['status' => UserStatus::Banned]);

        $response = $this->signIn();

        self::assertSame([403, SignInRefusedException::BANNED], [$response->getStatusCode(), $this->decode($response)['message']]);
        self::assertSame(0, CustomerSession::query()->count());
    }

    public function testTelegramSignInSwitchedOffIsNoWayIn(): void
    {
        $nonce = $this->nonce();
        $this->website->forceFill(['telegram_login' => false])->save();

        $response = $this->postJson($this->storeApi($this->website, '/auth/telegram'), ['id_token' => self::token($nonce), 'nonce' => $nonce]);

        self::assertSame([422, SignInRefusedException::TELEGRAM_OFF], [$response->getStatusCode(), $this->decode($response)['message']]);
        self::assertSame([], $this->telegramLogin()->calls(), 'Telegram is not even asked');
    }

    public function testAnAddressWhoseSignInsKeepFailingWaitsAndASuccessClearsNobodysCount(): void
    {
        for ($i = 1; $i < SignInThrottle::WEBSITE_ATTEMPTS; $i++) {
            self::assertSame(401, $this->signIn(['aud' => 'another-site'])->getStatusCode(), "failure {$i}");
        }
        self::assertSame(200, $this->signIn()->getStatusCode(), 'one more may still be tried — and works');
        self::assertSame(401, $this->signIn(['aud' => 'another-site'])->getStatusCode(), 'its count stood: this is the last failure the window takes');

        $waiting = $this->signIn();

        self::assertSame(429, $waiting->getStatusCode(), 'even a good sign-in waits now');
        self::assertGreaterThan(0, (int) $waiting->getHeaderLine('Retry-After'));
        self::assertSame(1, CustomerSession::query()->count());
    }

    public function testTelegramOutOfReachIsA502ThatCountsForNothing(): void
    {
        $this->telegramLogin()->down();
        $logs = $this->logs();

        for ($i = 0; $i <= SignInThrottle::WEBSITE_ATTEMPTS; $i++) {
            $response = $this->signIn();
            self::assertSame([502, TelegramUnreachableException::MESSAGE], [$response->getStatusCode(), $this->decode($response)['message']], "try {$i}");
        }
        self::assertTrue($logs->hasWarningThatContains('could not ask Telegram'), 'the owner\'s to look into');

        $this->telegramLogin()->down(false);
        self::assertSame(200, $this->signIn()->getStatusCode(), 'no address waits for Telegram\'s silence');
    }

    public function testTelegramsKeysAreKeptAnHourAndReadAgainForOneTheyLack(): void
    {
        $jwks = fn(): int => count(array_filter($this->telegramLogin()->calls(), static fn(string $call): bool => str_ends_with($call, '/jwks.json')));

        $this->signIn();
        $this->signIn();
        self::assertSame(1, $jwks(), 'kept');

        $rotated = static fn(string $nonce): string => FakeTelegramLogin::idToken(FakeTelegramLogin::claims(self::WEBSITE_CLIENT_ID, $nonce), ['kid' => 'rotated']);
        $nonce = $this->nonce();
        self::assertSame(401, $this->postJson($this->storeApi($this->website, '/auth/telegram'), ['id_token' => $rotated($nonce), 'nonce' => $nonce])->getStatusCode());
        self::assertSame(1, $jwks(), 'a key they lack, a moment after they were read: not read again');

        Carbon::setTestNow(now()->addMinutes(2));
        $nonce = $this->nonce();
        $this->postJson($this->storeApi($this->website, '/auth/telegram'), ['id_token' => $rotated($nonce), 'nonce' => $nonce]);
        self::assertSame(2, $jwks(), 'a minute on: read again, for the key they lack');

        Carbon::setTestNow(now()->addMinutes(59));
        $this->signIn();
        self::assertSame(2, $jwks(), 'within the hour of the last read');
        Carbon::setTestNow(now()->addMinutes(2));
        $this->signIn();
        self::assertSame(3, $jwks(), 'an hour on');
    }

    public function testKeptKeysStandInWhileTelegramIsOutOfReach(): void
    {
        self::assertSame(200, $this->signIn()->getStatusCode());
        Carbon::setTestNow(now()->addHours(3));
        $this->telegramLogin()->down();
        $logs = $this->logs();

        self::assertSame(200, $this->signIn()->getStatusCode());
        self::assertTrue($logs->hasWarningThatContains('could not be read again'));
    }

    /**
     * A sign-in by the popup: a nonce asked for, a token of Telegram's for it — `$claims` over its defaults —, posted
     * with `$more`.
     *
     * @param array<string, mixed> $claims
     * @param array<string, string> $more
     */
    private function signIn(array $claims = [], array $more = []): ResponseInterface
    {
        $nonce = $this->nonce();

        return $this->postJson($this->storeApi($this->website, '/auth/telegram'), ['id_token' => self::token($nonce, $claims), 'nonce' => $nonce] + $more);
    }

    private function nonce(): string
    {
        $answer = $this->decode($this->postJson($this->storeApi($this->website, '/auth/nonce')));
        self::assertSame(1800, $answer['expires_in']);

        return $answer['nonce'];
    }

    /** @param array<string, mixed> $claims */
    private static function token(string $nonce, array $claims = []): string
    {
        return FakeTelegramLogin::idToken(FakeTelegramLogin::claims(self::WEBSITE_CLIENT_ID, $nonce, $claims));
    }

    private function assertRefused(ResponseInterface $response, string $why, string $message = ''): void
    {
        self::assertSame([401, $why], [$response->getStatusCode(), $this->decode($response)['message']], $message);
    }
}
