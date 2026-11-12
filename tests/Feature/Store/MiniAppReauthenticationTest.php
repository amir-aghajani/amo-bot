<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Http\ErrorHandler;
use App\Modules\Accounts\Enums\SignInMethod;
use App\Modules\Accounts\Exceptions\AccountRefusedException;
use App\Modules\Accounts\Models\CustomerSession;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Accounts\Services\Reauthentication;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Store\Models\MiniApp;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;
use Tests\Support\FakeTelegram;

/**
 * Proving a way in again in the mini app (POST /me/reauthenticate): the launch data of the last quarter of an hour —
 * recent from when Telegram made it, never less recent than the session was —, or the secret the app keeps behind the
 * device's fingerprint or face (PUT and DELETE /me/biometric), this session's alone — each through the mini app's key
 * alone. Every failure counts as a failed sign-in, and none is the 401 that would end the session.
 */
final class MiniAppReauthenticationTest extends HttpTestCase
{
    private MiniApp $miniApp;

    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-27 12:00:00');
        $this->telegram();
        $this->miniApp = $this->miniApp();
        $this->ali = $this->customer();
    }

    public function testALaunchOfTheLastQuarterOfAnHourProvesTheAccountFromWhenItWasMade(): void
    {
        $this->bearer($this->staleSession($this->ali));
        $madeAt = now()->subMinutes(2);

        $proven = $this->reauthenticate(['method' => 'mini_app', 'init_data' => $this->launchData(madeAt: $madeAt)]);

        self::assertSame([200, ['expires_in' => CustomerSessions::RECENT_SECONDS]], [$proven->getStatusCode(), $this->decode($proven)], (string) $proven->getBody());
        self::assertSame($madeAt->getTimestamp(), CustomerSession::query()->sole()->authenticated_at?->getTimestamp());
        self::assertSame($madeAt->copy()->addSeconds(CustomerSessions::RECENT_SECONDS)->toIso8601String(), $this->me()['session']['recent_until']);
        self::assertSame(200, $this->putJson($this->storeApi($this->miniApp, '/me/biometric'))->getStatusCode(), 'what a recent sign-in guards goes through');

        Carbon::setTestNow($madeAt->copy()->addSeconds(CustomerSessions::RECENT_SECONDS + 1));
        self::assertSame(403, $this->putJson($this->storeApi($this->miniApp, '/me/biometric'))->getStatusCode(), 'a quarter of an hour after the app opened, not after the proof');
    }

    public function testALaunchProvesOnlyTheAccountItIsAboutAndOnlyWhileItIsFresh(): void
    {
        $this->bearer($this->staleSession($this->ali));

        self::assertSame([422, ['init_data' => [Reauthentication::NOT_THIS_ACCOUNT]]], $this->refused($this->reauthenticate(['method' => 'mini_app', 'init_data' => $this->launchData(9090)])), "another Telegram account's launch proves nothing of this one");
        self::assertSame([422, ['init_data' => [SignInRefusedException::LAUNCH_REFUSED]]], $this->refused($this->reauthenticate(['method' => 'mini_app', 'init_data' => $this->launchData(madeAt: now()->subSeconds(CustomerSessions::RECENT_SECONDS + 1))])), 'older than a recent sign-in: open the app again');
        self::assertSame([422, ['init_data' => [SignInRefusedException::LAUNCH_REFUSED]]], $this->refused($this->reauthenticate(['method' => 'mini_app', 'init_data' => $this->launchData(token: FakeTelegram::AGENT_TOKEN)])), "another bot's");
        $missing = $this->unchecked()->postJson($this->storeApi($this->miniApp, '/me/reauthenticate'), ['method' => 'mini_app']);
        self::assertSame(422, $missing->getStatusCode());
        self::assertArrayHasKey('init_data', $this->decode($missing)['errors']);

        self::assertNull($this->me()['session']['recent_until'], 'none of them proved anything — and the session stands');
    }

    public function testAnAccountWithoutTelegramIsNoLaunchsAccount(): void
    {
        $sara = $this->webCustomer();
        $this->bearer($this->staleSession($sara));

        self::assertSame([422, ['init_data' => [Reauthentication::NOT_THIS_ACCOUNT]]], $this->refused($this->reauthenticate(['method' => 'mini_app', 'init_data' => $this->launchData()])));
    }

    public function testAnOlderLaunchLeavesALaterProofStanding(): void
    {
        $this->bearer($this->customerSession($this->ali, ['authenticated_at' => now()->subMinute()]));

        self::assertSame(200, $this->reauthenticate(['method' => 'mini_app', 'init_data' => $this->launchData(madeAt: now()->subMinutes(10))])->getStatusCode());

        self::assertSame(now()->subMinute()->getTimestamp(), CustomerSession::query()->sole()->authenticated_at?->getTimestamp(), 'never less recent than it was');
    }

    public function testALaunchMakesAPasswordOnlySessionAStrongOne(): void
    {
        $this->bearer($this->staleSession($this->ali, SignInMethod::Password));

        self::assertSame(200, $this->reauthenticate(['method' => 'mini_app', 'init_data' => $this->launchData()])->getStatusCode());

        self::assertSame(SignInMethod::MiniApp, CustomerSession::query()->sole()->method);
        self::assertSame('mini_app', $this->me()['session']['method']);
    }

    public function testTheFingerprintSecretIsSetProvenAndForgotten(): void
    {
        $this->bearer($this->customerSession($this->ali, ['method' => SignInMethod::MiniApp]));

        $set = $this->putJson($this->storeApi($this->miniApp, '/me/biometric'));
        self::assertSame(200, $set->getStatusCode(), (string) $set->getBody());
        $secret = $this->decode($set)['token'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
        self::assertSame(hash('sha256', $secret), CustomerSession::query()->sole()->biometric_hash, 'only its hash is kept');
        self::assertTrue($this->me()['session']['biometric']);

        Carbon::setTestNow(now()->addHour());
        self::assertNull($this->me()['session']['recent_until']);
        self::assertSame([422, ['token' => [Reauthentication::BIOMETRIC_REFUSED]]], $this->refused($this->reauthenticate(['method' => 'biometric', 'token' => str_repeat('0', 64)])));
        self::assertSame(200, $this->reauthenticate(['method' => 'biometric', 'token' => $secret])->getStatusCode());
        self::assertSame(now()->addSeconds(CustomerSessions::RECENT_SECONDS)->toIso8601String(), $this->me()['session']['recent_until'], 'recent from now');
        self::assertSame(SignInMethod::MiniApp, CustomerSession::query()->sole()->method, 'its way in as it was');

        $again = $this->decode($this->putJson($this->storeApi($this->miniApp, '/me/biometric')))['token'];
        self::assertNotSame($secret, $again);
        self::assertSame(422, $this->reauthenticate(['method' => 'biometric', 'token' => $secret])->getStatusCode(), 'a new secret replaces the one before');

        self::assertSame(204, $this->deleteJson($this->storeApi($this->miniApp, '/me/biometric'))->getStatusCode());
        self::assertFalse($this->me()['session']['biometric']);
        self::assertNull(CustomerSession::query()->sole()->biometric_hash);
        self::assertSame([422, ['token' => [Reauthentication::BIOMETRIC_REFUSED]]], $this->refused($this->reauthenticate(['method' => 'biometric', 'token' => $again])), 'forgotten: it proves nothing');
    }

    /**
     * A website has no fingerprint and no launch data: through its key the secret is no route at all — a website would
     * keep it beside the bearer token, a pair that keeps the session recent for as long as it lasts —, and proving either
     * way is refused on `method`, however well it holds.
     */
    public function testAWebsiteHasNeitherTheFingerprintNorTheLaunchDataToProveBy(): void
    {
        $website = $this->website();
        $this->bearer($this->customerSession($this->ali, ['method' => SignInMethod::MiniApp]));
        $secret = $this->decode($this->putJson($this->storeApi($this->miniApp, '/me/biometric')))['token'];
        Carbon::setTestNow(now()->addHour());

        self::assertSame([404, ErrorHandler::MESSAGES[404]], $this->answer($this->putJson($this->storeApi($website, '/me/biometric'))), 'no secret made beside the token');
        self::assertSame([404, ErrorHandler::MESSAGES[404]], $this->answer($this->deleteJson($this->storeApi($website, '/me/biometric'))));
        foreach ([['method' => 'biometric', 'token' => $secret], ['method' => 'mini_app', 'init_data' => $this->launchData()]] as $proof) {
            $refused = $this->postJson($this->storeApi($website, '/me/reauthenticate'), $proof);
            self::assertSame([422, ['method' => [Reauthentication::MINI_APP_ONLY]]], $this->refused($refused), "{$proof['method']} through the website");
        }

        self::assertSame([null, true], [$this->me()['session']['recent_until'], $this->me()['session']['biometric']], 'nothing proven, the secret still kept');
        self::assertSame(200, $this->reauthenticate(['method' => 'biometric', 'token' => $secret])->getStatusCode(), 'it proves the session through the mini app');
    }

    /**
     * A session the website signed in is a token the mini app's public key takes too: it keeps no fingerprint secret,
     * which would keep it recent for as long as it lasts — however recent it is now.
     */
    public function testOnlyASessionTheMiniAppSignedInKeepsAFingerprintSecret(): void
    {
        foreach ([SignInMethod::Password, SignInMethod::Telegram, SignInMethod::Google, SignInMethod::PasswordAndCode] as $method) {
            CustomerSession::query()->delete();
            $this->bearer($this->customerSession($this->ali, ['method' => $method, 'authenticated_at' => now()]));

            self::assertSame([422, AccountRefusedException::NOT_MINI_APP_SESSION], $this->answer($this->putJson($this->storeApi($this->miniApp, '/me/biometric'))), $method->value);
            self::assertNull(CustomerSession::query()->sole()->biometric_hash, "{$method->value}: no secret kept");
        }
    }

    public function testAFingerprintSecretProvesItsOwnSessionAlone(): void
    {
        $phone = $this->customerSession($this->ali, ['method' => SignInMethod::MiniApp]);
        $tablet = $this->staleSession($this->ali);
        $this->bearer($phone);
        $secret = $this->decode($this->putJson($this->storeApi($this->miniApp, '/me/biometric')))['token'];

        $this->bearer($tablet);
        self::assertSame([422, ['token' => [Reauthentication::BIOMETRIC_REFUSED]]], $this->refused($this->reauthenticate(['method' => 'biometric', 'token' => $secret])), "the same customer's other session");
        self::assertSame([422, ['token' => [Reauthentication::BIOMETRIC_REFUSED]]], $this->refused($this->reauthenticate(['method' => 'biometric', 'token' => ''])), 'nothing, on a session that keeps none');

        $this->bearer($this->staleSession($this->customer(['telegram_id' => 9090])));
        self::assertSame(422, $this->reauthenticate(['method' => 'biometric', 'token' => $secret])->getStatusCode(), "another customer's session");
    }

    public function testEveryFailedProofCountsAsAFailedSignIn(): void
    {
        $this->bearer($this->customerSession($this->ali, ['method' => SignInMethod::MiniApp]));
        $secret = $this->decode($this->putJson($this->storeApi($this->miniApp, '/me/biometric')))['token'];
        Carbon::setTestNow(now()->addHour());

        for ($i = 1; $i <= SignInThrottle::WEBSITE_ATTEMPTS; $i++) {
            $guess = $i % 2 === 0
                ? ['method' => 'biometric', 'token' => bin2hex(random_bytes(32))]
                : ['method' => 'mini_app', 'init_data' => $this->launchData(9090)];
            self::assertSame(422, $this->reauthenticate($guess)->getStatusCode(), "guess {$i}");
        }

        self::assertSame(429, $this->reauthenticate(['method' => 'biometric', 'token' => $secret])->getStatusCode(), 'even the right secret waits now');
        self::assertSame(429, $this->reauthenticate(['method' => 'mini_app', 'init_data' => $this->launchData()])->getStatusCode(), 'and a launch of their own');
        self::assertSame(200, $this->get($this->storeApi($this->miniApp, '/me'))->getStatusCode(), 'the session stands');
    }

    /** A session of `$user`'s whose sign-in is long past a recent one's — a token that leaked, or a day-old launch. */
    private function staleSession(User $user, SignInMethod $method = SignInMethod::MiniApp): string
    {
        return $this->customerSession($user, ['method' => $method, 'authenticated_at' => now()->subHour()]);
    }

    /** @param array<string, mixed> $body */
    private function reauthenticate(array $body): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->miniApp, '/me/reauthenticate'), $body);
    }

    /** @return array<string, mixed> GET /me through the mini app */
    private function me(): array
    {
        return $this->decode($this->get($this->storeApi($this->miniApp, '/me')));
    }

    /** @return array{int, mixed} The answer's status and its refusals by field */
    private function refused(ResponseInterface $response): array
    {
        return [$response->getStatusCode(), $this->decode($response)['errors'] ?? null];
    }

    /** @return array{int, mixed} The answer's status and its message */
    private function answer(ResponseInterface $response): array
    {
        return [$response->getStatusCode(), $this->decode($response)['message'] ?? null];
    }
}
