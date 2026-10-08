<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Accounts\Models\AuthChallenge;
use App\Modules\Accounts\Models\CustomerSession;
use App\Modules\Accounts\Services\AuthChallenges;
use App\Modules\Accounts\Services\EmailSignIn;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Bots\CurrentBot;
use App\Modules\Store\Models\Website;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Fakes\RecordingMailTransport;
use Tests\HttpTestCase;

/**
 * Signing in on the shop's website with an email and its password — either wrong is one answer, a failure counted
 * against the address and against the account of its shop (a success clears nobody's count), the hash made again once
 * PHP's default moved on —, and a forgotten password: a code to the address when it has an account (an email saying it
 * has none otherwise, and the same answer), then the new password with it, every other session ended, signed in.
 */
final class EmailSignInTest extends HttpTestCase
{
    private Website $website;

    private RecordingMailTransport $mail;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->mail = $this->mail();
        $this->website = $this->website();
    }

    public function testACustomerSignsInWithTheirEmailAndPassword(): void
    {
        $sara = $this->webCustomer();
        self::assertTrue(password_needs_rehash((string) $sara->password_hash, PASSWORD_DEFAULT), 'a hash of a cheaper day');
        $sara->forceFill(['last_seen_at' => now()->subDay()])->save();

        $response = $this->login(' Sara@Example.com ', self::WEB_PASSWORD);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $answer = $this->decode($response);
        self::assertSame([$sara->id, null, self::WEB_EMAIL, true], [$answer['customer']['id'], $answer['customer']['telegram'], $answer['customer']['email'], $answer['customer']['has_password']]);
        $sara->refresh();
        self::assertFalse(password_needs_rehash((string) $sara->password_hash, PASSWORD_DEFAULT), 'made again as PHP makes one today');
        self::assertTrue(password_verify(self::WEB_PASSWORD, (string) $sara->password_hash), 'the same password');
        self::assertTrue($sara->last_seen_at?->equalTo(now()), 'seen');
        $this->bearer($answer['token']);
        self::assertSame(200, $this->get($this->storeApi($this->website, '/me'))->getStatusCode());
    }

    public function testAWrongPasswordAndAnAddressWithoutOneAreOneAnswer(): void
    {
        $this->webCustomer();
        $this->customer(['email' => 'bot@example.com']);

        foreach ([[self::WEB_EMAIL, 'wrong-password'], ['nobody@example.com', self::WEB_PASSWORD], ['bot@example.com', 'any-password']] as [$email, $password]) {
            $response = $this->login($email, $password);

            self::assertSame([401, SignInRefusedException::WRONG_EMAIL_OR_PASSWORD], [$response->getStatusCode(), $this->decode($response)['message']], $email);
        }
        self::assertSame(0, CustomerSession::query()->count());

        $blank = $this->login('not an address', '');
        self::assertSame(422, $blank->getStatusCode());
        self::assertSame(['email', 'password'], array_keys($this->decode($blank)['errors']));
    }

    public function testABannedCustomerIsRefused(): void
    {
        $this->webCustomer(['status' => UserStatus::Banned]);

        $response = $this->login(self::WEB_EMAIL, self::WEB_PASSWORD);

        self::assertSame([403, SignInRefusedException::BANNED], [$response->getStatusCode(), $this->decode($response)['message']]);
        self::assertSame(0, CustomerSession::query()->count());
    }

    public function testAnAddressWhoseSignInsKeepFailingWaits(): void
    {
        $this->webCustomer();
        for ($i = 1; $i < SignInThrottle::WEBSITE_ATTEMPTS; $i++) {
            self::assertSame(401, $this->login(self::WEB_EMAIL, "guess-{$i}")->getStatusCode(), "failure {$i}");
        }
        self::assertSame(200, $this->login(self::WEB_EMAIL, self::WEB_PASSWORD)->getStatusCode(), 'one more may still be tried — and works');
        self::assertSame(401, $this->login('nobody@example.com', 'guess')->getStatusCode(), 'its count stood: the last failure the window takes');

        $waiting = $this->login(self::WEB_EMAIL, self::WEB_PASSWORD);

        self::assertSame(429, $waiting->getStatusCode(), 'even the right password waits now');
        self::assertGreaterThan(0, (int) $waiting->getHeaderLine('Retry-After'));
    }

    public function testAPasswordGuessedAtFromManyAddressesMakesTheAccountWait(): void
    {
        $this->webCustomer();
        $throttle = $this->service(SignInThrottle::class);
        for ($i = 1; $i <= SignInThrottle::MAX_ACCOUNT_ATTEMPTS; $i++) {
            $throttle->failed(SignInThrottle::WEBSITE, self::from("10.0.{$i}.1"), ' SARA@example.com');
        }

        self::assertSame(429, $this->login(self::WEB_EMAIL, self::WEB_PASSWORD)->getStatusCode(), 'the account waits, its own owner too, from any address');
        self::assertSame(401, $this->login('someone@example.com', 'guess')->getStatusCode(), 'another account is not held up, nor this address');
    }

    public function testAnAddressGuessedAtInAnotherShopHoldsNoAccountOfThisOneUp(): void
    {
        $this->webCustomer();
        $throttle = $this->service(SignInThrottle::class);
        CurrentBot::run($this->agentBot(), static function () use ($throttle): void {
            for ($i = 1; $i <= SignInThrottle::MAX_ACCOUNT_ATTEMPTS; $i++) {
                $throttle->failed(SignInThrottle::WEBSITE, self::from("10.0.{$i}.1"), self::WEB_EMAIL);
            }
        });

        self::assertSame(200, $this->login(self::WEB_EMAIL, self::WEB_PASSWORD)->getStatusCode(), "an agent's website's customer of that address is another account: this shop's is not locked out");
    }

    public function testAForgottenPasswordIsSetAgainWithTheCodeEveryOtherSessionEnded(): void
    {
        $sara = $this->webCustomer();
        $elsewhere = $this->customerSession($sara);

        $forgot = $this->forgot(self::WEB_EMAIL);

        self::assertSame([202, ['expires_in' => AuthChallenges::CODE_SECONDS]], [$forgot->getStatusCode(), $this->decode($forgot)]);
        [$email] = $this->mail->to(self::WEB_EMAIL);
        self::assertSame('کد بازیابی رمز عبور AmoBot', $email->getSubject());
        $code = RecordingMailTransport::codeIn($email);

        $reset = $this->postJson($this->storeApi($this->website, '/auth/password/reset'), ['email' => self::WEB_EMAIL, 'code' => $code, 'password' => 'a-new-secret']);

        self::assertSame(200, $reset->getStatusCode(), (string) $reset->getBody());
        self::assertSame($sara->id, $this->decode($reset)['customer']['id'], 'signed in');
        self::assertTrue(password_verify('a-new-secret', (string) $sara->refresh()->password_hash));
        self::assertSame(1, CustomerSession::query()->count(), 'this device alone');
        $this->bearer($elsewhere);
        self::assertSame(401, $this->get($this->storeApi($this->website, '/me'))->getStatusCode(), 'every other session ended');
        $this->bearer(null);
        self::assertSame(401, $this->login(self::WEB_EMAIL, self::WEB_PASSWORD)->getStatusCode(), 'the old password opens nothing');
        self::assertSame(200, $this->login(self::WEB_EMAIL, 'a-new-secret')->getStatusCode());
        self::assertWrongCode($this->postJson($this->storeApi($this->website, '/auth/password/reset'), ['email' => self::WEB_EMAIL, 'code' => $code, 'password' => 'yet-another-one']), 'a code is spent once');
    }

    public function testAnAddressWithoutAnAccountIsEmailedThatItHasNoneAndTheAnswerIsTheSame(): void
    {
        $response = $this->forgot('nobody@example.com');

        self::assertSame([202, ['expires_in' => AuthChallenges::CODE_SECONDS]], [$response->getStatusCode(), $this->decode($response)]);
        [$email] = $this->mail->to('nobody@example.com');
        self::assertSame('بازیابی رمز عبور AmoBot', $email->getSubject(), 'an email either way, so its time tells nothing either');
        self::assertStringContainsString('این ایمیل حسابی در این فروشگاه ندارد', (string) $email->getTextBody());
        self::assertDoesNotMatchRegularExpression('/^\d{6}$/m', (string) $email->getTextBody(), 'no code in it');
        self::assertSame(0, AuthChallenge::query()->count());
        self::assertSame(429, $this->forgot('nobody@example.com')->getStatusCode(), 'and it waits the minute an address with an account would: the wait tells nothing either');
        self::assertCount(1, $this->mail->sent());
    }

    public function testAResetCodeIsTheAccountsAndTakesFiveTries(): void
    {
        $this->webCustomer();
        $this->forgot(self::WEB_EMAIL);
        $code = RecordingMailTransport::codeIn($this->mail->to(self::WEB_EMAIL)[0]);
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($try = 1; $try <= AuthChallenges::CODE_ATTEMPTS; $try++) {
            $this->assertWrongCode($this->postJson($this->storeApi($this->website, '/auth/password/reset'), ['email' => self::WEB_EMAIL, 'code' => $wrong, 'password' => 'a-new-secret']), "try {$try}");
        }
        $this->assertWrongCode($this->postJson($this->storeApi($this->website, '/auth/password/reset'), ['email' => self::WEB_EMAIL, 'code' => $code, 'password' => 'a-new-secret']), 'tried out');
        self::assertTrue(password_verify(self::WEB_PASSWORD, (string) User::query()->sole()->password_hash), 'the password is as it was');

        $short = $this->postJson($this->storeApi($this->website, '/auth/password/reset'), ['email' => self::WEB_EMAIL, 'code' => $code, 'password' => 'short']);
        self::assertSame(['password' => ['رمز عبور دست‌کم 8 کاراکتر است.']], $this->decode($short)['errors'], 'the one password rule');
    }

    public function testNoCodeGoesOutWhileTheShopsEmailDoesNot(): void
    {
        $this->webCustomer();
        $this->config(['mail.from_address' => '']);

        $response = $this->forgot(self::WEB_EMAIL);

        self::assertSame([503, SignInRefusedException::MAIL_OFF], [$response->getStatusCode(), $this->decode($response)['message']]);
    }

    private function login(string $email, string $password): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->website, '/auth/login'), ['email' => $email, 'password' => $password]);
    }

    private function forgot(string $email): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->website, '/auth/password/forgot'), ['email' => $email]);
    }

    private function assertWrongCode(ResponseInterface $response, string $message = ''): void
    {
        self::assertSame([422, ['code' => [EmailSignIn::WRONG_CODE]]], [$response->getStatusCode(), $this->decode($response)['errors'] ?? null], $message);
    }

    /** A sign-in from that address. */
    private static function from(string $address): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/', ['REMOTE_ADDR' => $address]);
    }
}
