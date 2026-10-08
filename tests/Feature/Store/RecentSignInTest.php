<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Security\Totp;
use App\Modules\Accounts\Http\RecentSignInMiddleware;
use App\Modules\Accounts\Models\AuthChallenge;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Accounts\Services\EmailSignIn;
use App\Modules\Accounts\Services\Reauthentication;
use App\Modules\Accounts\Services\TwoFactor;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Store\Models\Website;
use Firebase\JWT\JWT;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;
use Tests\Support\FakeGoogleLogin;
use Tests\Support\FakeTelegramLogin;

/**
 * What changes how a customer's account on the shop's website is signed in to — a way in added or taken away, the
 * password, two-factor sign-in, a merge — asks the session's sign-in to be recent: a bearer token alone — one that leaked
 * from a device signed in a while ago — takes no account over. A sign-in is recent for 15 minutes; after that the
 * customer proves one way into this very account again (POST /me/reauthenticate) — its password (with its second step's
 * code while it asks one), its Telegram account or its Google account —, counted as a sign-in is, and the session may
 * change them for 15 minutes more. Anything else a session does asks nothing of the kind.
 */
final class RecentSignInTest extends HttpTestCase
{
    /** The PKCE verifier the site keeps for a redirect it begins (its challenge goes to the shop). */
    private const VERIFIER = 'the-sites-own-pkce-verifier.kept~in_this-browser-0123456789';

    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->telegram();
        $this->website = $this->website(['google_client_id' => self::GOOGLE_CLIENT_ID, 'telegram_client_secret' => 'tg-client-secret-1']);
    }

    public function testATokenAloneTakesNoWayInAwayNorAddsOneNorTurnsTwoFactorOff(): void
    {
        $mail = $this->mail();
        $sara = $this->webCustomer(['google_sub' => 'sara-google']);
        $this->twoFactorOn($this->website, $sara);
        $this->bearer($this->customerSession($sara));
        Carbon::setTestNow(now()->addSeconds(CustomerSessions::RECENT_SECONDS + 1));
        $sent = count($mail->sent());

        foreach ([
            ['DELETE', '/me/identities/email', null],
            ['DELETE', '/me/identities/google', null],
            ['POST', '/me/identities/email', ['email' => 'thief@example.com', 'password' => 'thief-secret-1']],
            ['POST', '/me/2fa/disable', ['password' => self::WEB_PASSWORD]],
            ['PUT', '/me/password', ['current_password' => self::WEB_PASSWORD, 'password' => 'thief-secret-1']],
            ['POST', '/me/merge', ['token' => str_repeat('a', 64)]],
        ] as [$method, $path, $body]) {
            $refused = $this->json($method, $this->storeApi($this->website, $path), $body);

            self::assertSame([403, RecentSignInMiddleware::SIGN_IN_AGAIN], [$refused->getStatusCode(), $this->decode($refused)['message']], "{$method} {$path}");
            self::assertSame('Bearer error="insufficient_user_authentication", max_age=900', $refused->getHeaderLine('WWW-Authenticate'), 'a more recent sign-in, as RFC 9470 asks one');
        }

        $sara->refresh();
        self::assertSame([self::WEB_EMAIL, 'sara-google', true], [$sara->email, $sara->google_sub, $sara->hasTwoFactor()], 'every way in as it was');
        self::assertTrue(password_verify(self::WEB_PASSWORD, (string) $sara->password_hash));
        self::assertCount($sent, $mail->sent(), 'no code sent to their address');
        self::assertSame(0, AuthChallenge::query()->where('purpose', 'link_email')->count());
        self::assertSame(200, $this->get($this->storeApi($this->website, '/me'))->getStatusCode(), 'the session stands: a 401 would have ended it');
    }

    public function testASignInIsRecentForAQuarterOfAnHour(): void
    {
        $sara = $this->webCustomer(['google_sub' => 'sara-google']);
        $this->bearer($this->customerSession($sara));

        Carbon::setTestNow(now()->addSeconds(CustomerSessions::RECENT_SECONDS - 1));
        self::assertSame(200, $this->deleteJson($this->storeApi($this->website, '/me/identities/google'))->getStatusCode(), 'signed in a moment ago');
        Carbon::setTestNow(now()->addSeconds(2));
        self::assertSame(403, $this->postJson($this->storeApi($this->website, '/me/2fa/setup'))->getStatusCode(), 'fifteen minutes on');
    }

    public function testThePasswordProvenAgainOpensTheChangesForAQuarterOfAnHour(): void
    {
        $sara = $this->webCustomer(['google_sub' => 'sara-google']);
        $this->bearer($this->customerSession($sara));
        Carbon::setTestNow(now()->addHour());
        self::assertSame(403, $this->deleteJson($this->storeApi($this->website, '/me/identities/google'))->getStatusCode());

        $proven = $this->reauthenticate(['method' => 'password', 'password' => self::WEB_PASSWORD]);

        self::assertSame([200, ['expires_in' => CustomerSessions::RECENT_SECONDS]], [$proven->getStatusCode(), $this->decode($proven)], (string) $proven->getBody());
        self::assertSame(200, $this->deleteJson($this->storeApi($this->website, '/me/identities/google'))->getStatusCode(), 'proven: the change goes through');
        Carbon::setTestNow(now()->addSeconds(CustomerSessions::RECENT_SECONDS + 1));
        self::assertSame(403, $this->postJson($this->storeApi($this->website, '/me/2fa/setup'))->getStatusCode(), 'a quarter of an hour, then asked again');
    }

    public function testAnAccountWithTwoFactorSignInProvesItsPasswordWithItsCode(): void
    {
        $sara = $this->webCustomer();
        ['secret' => $secret, 'recovery_codes' => $codes] = $this->twoFactorOn($this->website, $sara);
        $this->bearer($this->customerSession($sara));
        Carbon::setTestNow(now()->addHour());
        $wrong = Totp::code($secret) === '000000' ? '111111' : '000000';

        self::assertSame([422, ['code' => [TwoFactor::CODE_MISSING]]], $this->refused($this->reauthenticate(['method' => 'password', 'password' => self::WEB_PASSWORD])), 'the password alone is not its sign-in');
        self::assertSame([422, ['code' => [TwoFactor::WRONG_SIGN_IN_CODE]]], $this->refused($this->reauthenticate(['method' => 'password', 'password' => self::WEB_PASSWORD, 'code' => $wrong])));
        self::assertSame([422, ['password' => [EmailSignIn::WRONG_PASSWORD]]], $this->refused($this->reauthenticate(['method' => 'password', 'password' => 'a wrong one', 'code' => Totp::code($secret)])));
        self::assertSame(403, $this->postJson($this->storeApi($this->website, '/me/2fa/disable'), ['password' => self::WEB_PASSWORD])->getStatusCode(), 'none of them proved anything');

        self::assertSame(200, $this->reauthenticate(['method' => 'password', 'password' => self::WEB_PASSWORD, 'code' => Totp::code($secret)])->getStatusCode());
        Carbon::setTestNow(now()->addHour());
        self::assertSame(200, $this->reauthenticate(['method' => 'password', 'password' => self::WEB_PASSWORD, 'code' => $codes[0]])->getStatusCode(), 'or a recovery code — spent');
        self::assertCount(TwoFactor::RECOVERY_CODES - 1, json_decode((string) $sara->refresh()->totp_recovery_codes, true));
        self::assertSame(200, $this->postJson($this->storeApi($this->website, '/me/2fa/disable'), ['password' => self::WEB_PASSWORD])->getStatusCode());
    }

    public function testTelegramProvesTheAccountItSignsIn(): void
    {
        $this->telegramLogin();
        $ali = $this->customer(['email' => 'ali@example.com']);
        $this->bearer($this->customerSession($ali));
        Carbon::setTestNow(now()->addHour());

        $nonce = $this->nonce();
        $another = $this->reauthenticate(['method' => 'telegram', 'id_token' => FakeTelegramLogin::idToken(FakeTelegramLogin::claims(self::WEBSITE_CLIENT_ID, $nonce, ['id' => 9001])), 'nonce' => $nonce]);
        self::assertSame([422, ['id_token' => [Reauthentication::NOT_THIS_ACCOUNT]]], $this->refused($another), "another Telegram account's sign-in proves nothing of this one");

        $nonce = $this->nonce();
        $popup = $this->reauthenticate(['method' => 'telegram', 'id_token' => FakeTelegramLogin::idToken(FakeTelegramLogin::claims(self::WEBSITE_CLIENT_ID, $nonce)), 'nonce' => $nonce]);
        self::assertSame(200, $popup->getStatusCode(), (string) $popup->getBody());

        Carbon::setTestNow(now()->addHour());
        $query = $this->authorize('/auth/telegram/authorize');
        self::assertSame([422, ['code' => [SignInRefusedException::SIGN_IN_SPENT]]], $this->refused($this->reauthenticate(['method' => 'telegram', 'code' => 'the-code', 'state' => $query['state'], 'code_verifier' => self::VERIFIER])), "a sign-in's state is no customer's");

        $query = $this->authorize('/me/telegram/authorize');
        $this->telegramLogin()->answerCode(FakeTelegramLogin::idToken(FakeTelegramLogin::claims(self::WEBSITE_CLIENT_ID, $query['nonce'])));
        self::assertSame(200, $this->reauthenticate(['method' => 'telegram', 'code' => 'the-code', 'state' => $query['state'], 'code_verifier' => self::VERIFIER])->getStatusCode(), 'the redirect, by a state of her own');
        self::assertSame(200, $this->deleteJson($this->storeApi($this->website, '/me/identities/email'))->getStatusCode());
    }

    public function testGoogleProvesTheAccountItSignsIn(): void
    {
        $this->googleLogin();
        $sara = $this->webCustomer(['email' => FakeGoogleLogin::EMAIL, 'google_sub' => FakeGoogleLogin::SUB]);
        $this->bearer($this->customerSession($sara));
        Carbon::setTestNow(now()->addHour());

        self::assertSame([422, ['id_token' => [Reauthentication::NOT_THIS_ACCOUNT]]], $this->refused($this->google(['sub' => 'another-google-account'])));
        self::assertSame([422, ['id_token' => [SignInRefusedException::TOKEN_ELSEWHERE]]], $this->refused($this->google(['aud' => 'another-site'])), 'a proof that does not hold is said on its field — never the 401 that ends the session');

        self::assertSame(200, $this->google()->getStatusCode());
        self::assertSame(200, $this->deleteJson($this->storeApi($this->website, '/me/identities/google'))->getStatusCode());
    }

    public function testAProofOfAWayInTheAccountHasNotIsRefused(): void
    {
        $this->bearer($this->customerSession($ali = $this->customer()));
        Carbon::setTestNow(now()->addHour());

        self::assertSame([422, ['password' => [Reauthentication::NO_PASSWORD]]], $this->refused($this->reauthenticate(['method' => 'password', 'password' => 'any-password'])));
        $missing = $this->unchecked()->postJson($this->storeApi($this->website, '/me/reauthenticate'), ['password' => 'any-password']);
        self::assertSame([422, ['method' => [Reauthentication::METHOD]]], $this->refused($missing));
        self::assertNull($ali->refresh()->email);
    }

    public function testAWrongPasswordProvenAgainCountsAsAFailedSignIn(): void
    {
        $this->bearer($this->customerSession($this->webCustomer()));
        Carbon::setTestNow(now()->addHour());

        for ($i = 1; $i <= SignInThrottle::WEBSITE_ATTEMPTS; $i++) {
            self::assertSame(422, $this->reauthenticate(['method' => 'password', 'password' => "guess-{$i}"])->getStatusCode(), "guess {$i}");
        }

        self::assertSame(429, $this->reauthenticate(['method' => 'password', 'password' => self::WEB_PASSWORD])->getStatusCode(), 'even the right password waits now');
    }

    public function testWhatChangesNoWayInAsksNothingOfTheKind(): void
    {
        $this->bearer($this->customerSession($this->webCustomer()));
        Carbon::setTestNow(now()->addDays(10));

        self::assertSame(200, $this->get($this->storeApi($this->website, '/me'))->getStatusCode());
        self::assertSame(200, $this->patchJson($this->storeApi($this->website, '/me'), ['first_name' => 'Sarah'])->getStatusCode());
        self::assertSame(200, $this->get($this->storeApi($this->website, '/me/sessions'))->getStatusCode());
        self::assertSame(200, $this->postJson($this->storeApi($this->website, '/me/telegram/authorize'), ['redirect_uri' => 'https://shop.example/account', 'code_challenge' => JWT::urlsafeB64Encode(hash('sha256', self::VERIFIER, true))])->getStatusCode(), 'what proving a way in again begins with');
    }

    /** @param array<string, mixed> $body */
    private function reauthenticate(array $body): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->website, '/me/reauthenticate'), $body);
    }

    /** @param array<string, mixed> $claims Over a Google token's defaults */
    private function google(array $claims = []): ResponseInterface
    {
        $nonce = $this->nonce();

        return $this->reauthenticate(['method' => 'google', 'id_token' => FakeGoogleLogin::idToken(FakeGoogleLogin::claims(self::GOOGLE_CLIENT_ID, $nonce, $claims)), 'nonce' => $nonce]);
    }

    private function nonce(): string
    {
        return $this->decode($this->postJson($this->storeApi($this->website, '/auth/nonce')))['nonce'];
    }

    /**
     * Telegram's redirect sign-in begun at `$path` — a sign-in's, or the signed-in customer's own —: the state and the
     * nonce its address carries.
     *
     * @return array{state: string, nonce: string}
     */
    private function authorize(string $path): array
    {
        $address = $this->decode($this->postJson($this->storeApi($this->website, $path), ['redirect_uri' => 'https://shop.example/account', 'code_challenge' => JWT::urlsafeB64Encode(hash('sha256', self::VERIFIER, true))]))['url'];
        parse_str((string) parse_url($address, PHP_URL_QUERY), $query);

        return ['state' => (string) $query['state'], 'nonce' => (string) $query['nonce']];
    }

    /** @return array{int, mixed} The status, and what it said under its fields */
    private function refused(ResponseInterface $response): array
    {
        return [$response->getStatusCode(), $this->decode($response)['errors'] ?? null];
    }
}
