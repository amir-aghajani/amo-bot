<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Security\ReadableCode;
use App\Core\Security\Totp;
use App\Modules\Accounts\Enums\ChallengePurpose;
use App\Modules\Accounts\Exceptions\AccountRefusedException;
use App\Modules\Accounts\Models\AuthChallenge;
use App\Modules\Accounts\Models\CustomerSession;
use App\Modules\Accounts\Services\AuthChallenges;
use App\Modules\Accounts\Services\EmailSignIn;
use App\Modules\Accounts\Services\TwoFactor;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Store\Models\Website;
use App\Modules\Users\Enums\UserStatus;
use App\Support\Persian;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Fakes\RecordingMailTransport;
use Tests\HttpTestCase;
use Tests\Support\FakeGoogleLogin;

/**
 * Two-factor sign-in on the shop's website: a customer who signs in with an email and a password turns it on with a new
 * secret for their authenticator app and its first code — their ten recovery codes shown that once —, and their
 * password sign-in then answers a challenge the app's code opens (or a recovery code, spent): five codes a challenge, each
 * wrong one counted against the address and the account too, no code taken twice. A password reset leaves it on; Google
 * and Telegram sign in without it. Off again with their password, or by support for one who lost their phone.
 */
final class TwoFactorTest extends HttpTestCase
{
    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->telegram();
        $this->website = $this->website();
    }

    public function testACustomerTurnsItOnWithTheAppsFirstCodeAndGetsTenRecoveryCodes(): void
    {
        $sara = $this->webCustomer();
        $this->bearer($this->customerSession($sara));

        $first = $this->decode($this->newSecret())['secret'];
        $setup = $this->newSecret();

        self::assertSame(200, $setup->getStatusCode(), (string) $setup->getBody());
        ['secret' => $secret, 'uri' => $uri] = $this->decode($setup);
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        self::assertNotSame($first, $secret, 'a new secret each time');
        self::assertSame("otpauth://totp/AmoBot:sara%40example.com?secret={$secret}&issuer=AmoBot&algorithm=SHA1&digits=6&period=30", $uri, "the shop's name its issuer, the account's email its name");
        self::assertSame(1, AuthChallenge::query()->where('purpose', ChallengePurpose::TotpSetup->value)->count(), 'the earlier one replaced');
        self::assertSame([422, ['code' => [TwoFactor::WRONG_CODE]]], $this->refused($this->enable(Totp::code($first))), "the replaced secret's code turns nothing on");

        $on = $this->enable(Persian::digits(Totp::code($secret)));

        self::assertSame(200, $on->getStatusCode(), (string) $on->getBody());
        $codes = $this->decode($on)['recovery_codes'];
        self::assertCount(TwoFactor::RECOVERY_CODES, array_unique($codes));
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[' . ReadableCode::ALPHABET . ']{10}$/', $code);
        }
        $sara->refresh();
        self::assertTrue($sara->hasTwoFactor());
        self::assertSame($secret, $sara->totp_secret);
        self::assertStringNotContainsString($codes[0], (string) $sara->totp_recovery_codes, 'kept as keyed hashes, never as they are');
        self::assertStringNotContainsString($secret, (string) $sara->getRawOriginal('totp_secret'), 'the secret encrypted at rest');
        self::assertTrue($this->decode($this->get($this->storeApi($this->website, '/me')))['customer']['two_factor']);
        self::assertSame(0, AuthChallenge::query()->count(), 'the secret waiting for its code is spent');

        self::assertSame([422, AccountRefusedException::TWO_FACTOR_ON], $this->message($this->newSecret()), 'once on, a new secret waits for it to be off');
        self::assertSame([422, AccountRefusedException::TWO_FACTOR_ON], $this->message($this->enable(Totp::code($secret))));
    }

    public function testItIsForAnAccountThatSignsInWithAnEmailAndAPassword(): void
    {
        foreach ([$this->customer(), $this->webCustomer(['email' => FakeGoogleLogin::EMAIL, 'password_hash' => null, 'google_sub' => FakeGoogleLogin::SUB])] as $user) {
            $this->bearer($this->customerSession($user));

            self::assertSame([422, AccountRefusedException::TWO_FACTOR_NEEDS_PASSWORD], $this->message($this->newSecret()), (string) $user->email);
        }
        self::assertSame(0, AuthChallenge::query()->count());
    }

    public function testANewSecretWaitsFifteenMinutesAndFiveCodes(): void
    {
        $sara = $this->webCustomer();
        $this->bearer($this->customerSession($sara));

        $this->newSecret();
        Carbon::setTestNow(now()->addSeconds(TwoFactor::SETUP_SECONDS + 1));
        $this->bearer($this->customerSession($sara));
        self::assertSame([422, ['code' => [TwoFactor::SETUP_GONE]]], $this->refused($this->enable('123456')), 'its time is over — signed in again since');

        $secret = $this->decode($this->newSecret())['secret'];
        $wrong = Totp::code($secret) === '000000' ? '111111' : '000000';
        self::assertSame([422, ['code' => [TwoFactor::WRONG_CODE]]], $this->refused($this->enable('12 34')), 'no six digits: no try off it');
        for ($try = 1; $try <= AuthChallenges::CODE_ATTEMPTS; $try++) {
            self::assertSame([422, ['code' => [TwoFactor::WRONG_CODE]]], $this->refused($this->enable($wrong)), "try {$try}");
        }

        self::assertSame([422, ['code' => [TwoFactor::SETUP_GONE]]], $this->refused($this->enable(Totp::code($secret))), 'tried out: set it up again');
    }

    public function testAPasswordSignInAsksTheAppsCodeAndOpensWithIt(): void
    {
        $sara = $this->webCustomer();
        ['secret' => $secret] = $this->twoFactorOn($this->website, $sara);
        Carbon::setTestNow(now()->addSeconds(30));

        $login = $this->login();

        self::assertSame(202, $login->getStatusCode(), (string) $login->getBody());
        ['challenge' => $challenge, 'expires_in' => $expires] = $this->decode($login)['two_factor'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $challenge);
        self::assertSame(TwoFactor::CHALLENGE_SECONDS, $expires);
        self::assertSame(0, CustomerSession::query()->count(), 'no session before the second step');

        $code = Totp::code($secret);
        $signedIn = $this->secondStep($challenge, Persian::digits(substr($code, 0, 3)) . ' ' . substr($code, 3));

        self::assertSame(200, $signedIn->getStatusCode(), (string) $signedIn->getBody());
        $answer = $this->decode($signedIn);
        self::assertSame([$sara->id, true], [$answer['customer']['id'], $answer['customer']['two_factor']]);
        $this->bearer($answer['token']);
        self::assertSame(200, $this->get($this->storeApi($this->website, '/me'))->getStatusCode());
        self::assertSame([401, SignInRefusedException::SIGN_IN_SPENT], $this->message($this->secondStep($challenge, $code)), 'a challenge opens once');

        $again = $this->decode($this->login())['two_factor']['challenge'];
        self::assertSame([422, ['code' => [TwoFactor::WRONG_SIGN_IN_CODE]]], $this->refused($this->secondStep($again, $code)), 'nor does a code: no replay in its window');
        Carbon::setTestNow(now()->addSeconds(30));
        self::assertSame(200, $this->secondStep($again, Totp::code($secret))->getStatusCode(), "the next step's code does");
    }

    public function testARecoveryCodeSignsInOnceInPlaceOfTheApp(): void
    {
        $sara = $this->webCustomer();
        ['recovery_codes' => $codes] = $this->twoFactorOn($this->website, $sara);
        $typed = strtolower(substr($codes[3], 0, 5) . '-' . substr($codes[3], 5));

        $signedIn = $this->secondStep($this->decode($this->login())['two_factor']['challenge'], $typed);

        self::assertSame(200, $signedIn->getStatusCode(), (string) $signedIn->getBody());
        self::assertCount(TwoFactor::RECOVERY_CODES - 1, json_decode((string) $sara->refresh()->totp_recovery_codes, true), 'spent');
        self::assertSame(422, $this->secondStep($this->decode($this->login())['two_factor']['challenge'], $codes[3])->getStatusCode(), 'once');
        self::assertSame(200, $this->secondStep($this->decode($this->login())['two_factor']['challenge'], $codes[4])->getStatusCode(), 'another still opens');
    }

    public function testAChallengeTakesFiveWrongCodesEachCountedAgainstTheAddressAndTheAccount(): void
    {
        $sara = $this->webCustomer();
        ['secret' => $secret] = $this->twoFactorOn($this->website, $sara);
        Carbon::setTestNow(now()->addSeconds(30));
        $challenge = $this->decode($this->login())['two_factor']['challenge'];
        $wrong = Totp::code($secret) === '000000' ? '111111' : '000000';

        self::assertSame([422, ['code' => [TwoFactor::WRONG_SIGN_IN_CODE]]], $this->refused($this->secondStep($challenge, 'not a code')), 'neither shape: no try off the challenge');
        for ($try = 1; $try < AuthChallenges::CODE_ATTEMPTS; $try++) {
            self::assertSame(422, $this->secondStep($challenge, $wrong)->getStatusCode(), "try {$try}");
        }
        self::assertSame(422, $this->secondStep($challenge, $wrong)->getStatusCode(), 'the fifth wrong code');

        self::assertSame([401, SignInRefusedException::SIGN_IN_SPENT], $this->message($this->secondStep($challenge, Totp::code($secret))), 'tried out: the password again');
        self::assertSame(200, $this->secondStep($this->decode($this->login())['two_factor']['challenge'], Totp::code($secret))->getStatusCode(), 'a new sign-in opens');

        $throttle = $this->service(SignInThrottle::class);
        for ($i = 1; $i <= SignInThrottle::MAX_ACCOUNT_ATTEMPTS; $i++) {
            $throttle->failed(SignInThrottle::WEBSITE, (new ServerRequestFactory())->createServerRequest('POST', '/', ['REMOTE_ADDR' => "10.0.{$i}.1"]), self::WEB_EMAIL);
        }
        $challenge = $this->service(AuthChallenges::class)->issue(ChallengePurpose::TwoFactor, null, $sara, [], TwoFactor::CHALLENGE_SECONDS);
        self::assertSame(429, $this->secondStep($challenge, Totp::code($secret))->getStatusCode(), 'the account waits, from any address');
    }

    public function testAnAddressWhoseCodesKeepFailingWaits(): void
    {
        ['secret' => $secret] = $this->twoFactorOn($this->website, $this->webCustomer());
        Carbon::setTestNow(now()->addSeconds(30));
        $wrong = Totp::code($secret) === '000000' ? '111111' : '000000';

        for ($i = 1; $i <= SignInThrottle::WEBSITE_ATTEMPTS; $i++) {
            $this->secondStep(str_repeat('a', 64), $wrong);
        }

        self::assertSame(429, $this->login()->getStatusCode(), 'challenges no sign-in gave count as the failures they are');
    }

    public function testAChallengeOpensNothingOnceExpiredOrTwoFactorWentOff(): void
    {
        $sara = $this->webCustomer();
        ['secret' => $secret] = $this->twoFactorOn($this->website, $sara);
        $challenge = $this->decode($this->login())['two_factor']['challenge'];

        Carbon::setTestNow(now()->addSeconds(TwoFactor::CHALLENGE_SECONDS + 1));
        self::assertSame([401, SignInRefusedException::SIGN_IN_SPENT], $this->message($this->secondStep($challenge, Totp::code($secret))), 'expired');

        $challenge = $this->decode($this->login())['two_factor']['challenge'];
        $sara->forceFill(['totp_enabled_at' => null])->save();
        self::assertSame(401, $this->secondStep($challenge, Totp::code($secret))->getStatusCode(), 'turned off since: the password alone signs in now');
        self::assertSame(200, $this->login()->getStatusCode());

        $missing = $this->unchecked()->postJson($this->storeApi($this->website, '/auth/login/2fa'), ['code' => '123456']);
        self::assertSame(['challenge'], array_keys($this->decode($missing)['errors']));
    }

    public function testABannedCustomerIsRefusedAtTheSecondStepToo(): void
    {
        $sara = $this->webCustomer();
        ['secret' => $secret] = $this->twoFactorOn($this->website, $sara);
        Carbon::setTestNow(now()->addSeconds(30));
        $challenge = $this->decode($this->login())['two_factor']['challenge'];
        $sara->forceFill(['status' => UserStatus::Banned])->save();

        self::assertSame([403, SignInRefusedException::BANNED], $this->message($this->secondStep($challenge, Totp::code($secret))));
        self::assertSame(0, CustomerSession::query()->count());
    }

    public function testAPasswordResetLeavesItOnAndItsSecondStepSetsTheNewPassword(): void
    {
        $sara = $this->webCustomer();
        ['secret' => $secret] = $this->twoFactorOn($this->website, $sara);
        $mail = $this->mail();
        $elsewhere = $this->customerSession($sara);
        Carbon::setTestNow(now()->addSeconds(30));
        $this->postJson($this->storeApi($this->website, '/auth/password/forgot'), ['email' => self::WEB_EMAIL]);

        $reset = $this->postJson($this->storeApi($this->website, '/auth/password/reset'), ['email' => self::WEB_EMAIL, 'code' => RecordingMailTransport::codeIn($mail->to(self::WEB_EMAIL)[0]), 'password' => 'a-new-secret']);

        self::assertSame(202, $reset->getStatusCode(), (string) $reset->getBody());
        self::assertTrue($sara->refresh()->hasTwoFactor(), 'still on');
        self::assertTrue(password_verify(self::WEB_PASSWORD, (string) $sara->password_hash), 'the code alone changes nothing: the old password stands');
        $this->bearer($elsewhere);
        self::assertSame(200, $this->get($this->storeApi($this->website, '/me'))->getStatusCode(), 'nor are her sessions ended');
        $this->bearer(null);
        self::assertSame(202, $this->login()->getStatusCode(), 'the old password still asks its second step');

        $signedIn = $this->secondStep($this->decode($reset)['two_factor']['challenge'], Totp::code($secret));

        self::assertSame(200, $signedIn->getStatusCode(), (string) $signedIn->getBody());
        self::assertTrue(password_verify('a-new-secret', (string) $sara->refresh()->password_hash), 'set as the second step opens');
        $this->bearer($elsewhere);
        self::assertSame(401, $this->get($this->storeApi($this->website, '/me'))->getStatusCode(), 'every other session ended then');
        self::assertSame(1, CustomerSession::query()->count(), 'this device alone');
        self::assertSame(NoticeType::PasswordChanged, Notification::query()->latest('id')->firstOrFail()->type, 'the customer told');
    }

    public function testGooglesSignInIsNotAskedForIt(): void
    {
        $this->googleLogin();
        $this->website->forceFill(['google_client_id' => self::GOOGLE_CLIENT_ID])->save();
        $sara = $this->webCustomer(['email' => FakeGoogleLogin::EMAIL, 'google_sub' => FakeGoogleLogin::SUB]);
        $this->twoFactorOn($this->website, $sara);
        $nonce = $this->decode($this->postJson($this->storeApi($this->website, '/auth/nonce')))['nonce'];

        $response = $this->postJson($this->storeApi($this->website, '/auth/google'), ['id_token' => FakeGoogleLogin::idToken(FakeGoogleLogin::claims(self::GOOGLE_CLIENT_ID, $nonce)), 'nonce' => $nonce]);

        self::assertSame(200, $response->getStatusCode(), 'Google signed them in');
        self::assertSame($sara->id, $this->decode($response)['customer']['id']);
    }

    public function testTheCustomerTurnsItOffWithTheirPassword(): void
    {
        $sara = $this->webCustomer();
        $this->twoFactorOn($this->website, $sara);
        $this->bearer($this->customerSession($sara));

        self::assertSame([422, ['password' => [EmailSignIn::WRONG_PASSWORD]]], $this->refused($this->disable('a wrong password')));
        self::assertTrue($sara->refresh()->hasTwoFactor());

        $off = $this->disable(self::WEB_PASSWORD);

        self::assertSame(200, $off->getStatusCode(), (string) $off->getBody());
        self::assertFalse($this->decode($off)['customer']['two_factor']);
        $sara->refresh();
        self::assertSame([null, null, null, null], [$sara->totp_secret, $sara->totp_recovery_codes, $sara->totp_enabled_at, $sara->totp_last_step]);
        self::assertSame([422, AccountRefusedException::TWO_FACTOR_OFF], $this->message($this->disable(self::WEB_PASSWORD)));
        $this->bearer(null);
        self::assertSame(200, $this->login()->getStatusCode(), 'the password alone signs in');
    }

    public function testAWrongPasswordToTurnItOffCountsAsAFailedSignIn(): void
    {
        $sara = $this->webCustomer();
        $this->twoFactorOn($this->website, $sara);
        $this->bearer($this->customerSession($sara));

        for ($i = 1; $i <= SignInThrottle::WEBSITE_ATTEMPTS; $i++) {
            self::assertSame(422, $this->disable("guess-{$i}")->getStatusCode(), "guess {$i}");
        }

        self::assertSame(429, $this->disable(self::WEB_PASSWORD)->getStatusCode(), 'even the right password waits now');
    }

    private function newSecret(): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->website, '/me/2fa/setup'));
    }

    private function enable(string $code): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->website, '/me/2fa/enable'), ['code' => $code]);
    }

    private function disable(string $password): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->website, '/me/2fa/disable'), ['password' => $password]);
    }

    private function login(): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->website, '/auth/login'), ['email' => self::WEB_EMAIL, 'password' => self::WEB_PASSWORD]);
    }

    private function secondStep(string $challenge, string $code): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->website, '/auth/login/2fa'), ['challenge' => $challenge, 'code' => $code]);
    }

    /** @return array{int, mixed} The status, and what it said under its fields */
    private function refused(ResponseInterface $response): array
    {
        return [$response->getStatusCode(), $this->decode($response)['errors'] ?? null];
    }

    /** @return array{int, mixed} The status, and its message */
    private function message(ResponseInterface $response): array
    {
        return [$response->getStatusCode(), $this->decode($response)['message'] ?? null];
    }
}
