<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Mail\MailFailedException;
use App\Modules\Accounts\Models\AuthChallenge;
use App\Modules\Accounts\Models\CustomerSession;
use App\Modules\Accounts\Services\AuthChallenges;
use App\Modules\Accounts\Services\EmailSignIn;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Referrals\Services\ReferralService;
use App\Modules\Store\Models\Website;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Support\Persian;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\Fakes\RecordingMailTransport;
use Tests\HttpTestCase;

/**
 * Signing up on the shop's website with an email: the form, then a six-digit code to the address — the account it makes
 * kept with it —, then the code typed back: the account registered as every newcomer is (reported, made the referral of
 * the code's owner, who is told) and signed in. An address with an account gets an email saying so and the same answer;
 * a code is tried five times, a sign-up again replaces it — once a minute at most. Off, or with no email going out, the
 * website takes no sign-up.
 */
final class EmailSignUpTest extends HttpTestCase
{
    private const FORM = ['first_name' => 'Sara', 'last_name' => 'Ahmadi', 'email' => 'sara@example.com', 'password' => 'sara-secret-1'];

    private Website $website;

    private RecordingMailTransport $mail;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->telegram();
        $this->mail = $this->mail();
        $this->website = $this->website(['email_signup' => true]);
    }

    public function testANewcomerSignsUpWithTheCodeTheirEmailCarried(): void
    {
        $this->reportGroup();
        $this->referralProgram();
        $referrer = $this->customer(['telegram_id' => 7070, 'first_name' => 'Owner']);
        $invite = $this->service(ReferralService::class)->codeFor($referrer);

        $response = $this->register(['email' => ' Sara@Example.COM ', 'referral_code' => strtoupper($invite)]);

        self::assertSame([202, ['expires_in' => AuthChallenges::CODE_SECONDS]], [$response->getStatusCode(), $this->decode($response)]);
        self::assertSame(1, User::query()->count(), 'nobody made yet: the address is not proven');
        [$email] = $this->mail->to('sara@example.com');
        self::assertSame('کد تایید ثبت‌نام در AmoBot', $email->getSubject());
        self::assertStringNotContainsString('Sara', (string) $email->getTextBody() . $email->getHtmlBody(), 'nothing in it the requester typed: a sign-up may name anyone\'s address');
        self::assertStringContainsString('dir="ltr"', (string) $email->getHtmlBody(), 'the code set apart, left to right');
        $code = RecordingMailTransport::codeIn($email);
        self::assertStringNotContainsString($code, (string) AuthChallenge::query()->sole()->secret_hash, 'the code is kept nowhere as it is');

        $signedUp = $this->verify(Persian::digits($code));

        self::assertSame(200, $signedUp->getStatusCode(), (string) $signedUp->getBody());
        $sara = User::query()->where('email', 'sara@example.com')->sole();
        $answer = $this->decode($signedUp);
        self::assertSame([
            'id' => $sara->id,
            'first_name' => 'Sara',
            'last_name' => 'Ahmadi',
            'telegram' => null,
            'email' => 'sara@example.com',
            'google' => false,
            'has_password' => true,
            'two_factor' => false,
            'phone' => null,
            'balance' => '0.00',
            'created_at' => '2026-10-07T12:00:00+00:00',
        ], $answer['customer']);
        self::assertTrue(password_verify('sara-secret-1', (string) $sara->password_hash), 'the password as typed, hashed');
        self::assertSame([null, $referrer->id], [$sara->telegram_id, $sara->referred_by], 'no Telegram account; the code\'s owner brought her');
        $reports = ReportMessage::query()->where('topic', Topic::Users)->pluck('text')->all();
        self::assertCount(1, $reports, 'reported, as every newcomer is');
        self::assertStringContainsString("Sara Ahmadi · sara@example.com · <code>#{$sara->id}</code>", $reports[0], 'no Telegram profile to link: the name, the email, her number');
        self::assertStringContainsString('<code>7070</code>', $reports[0], 'with whoever brought her');
        self::assertSame([self::text(BotText::ReferralJoined)], $this->telegram()->sentTo(7070), 'who is told');

        $this->bearer($answer['token']);
        self::assertSame($answer['customer'], $this->decode($this->get($this->storeApi($this->website, '/me')))['customer']);
        $this->bearer(null);
        self::assertSame(422, $this->verify($code)->getStatusCode(), 'a code opens one account, once');
    }

    public function testAnAddressWithAnAccountGetsAnEmailSayingSoAndTheSameAnswer(): void
    {
        $this->webCustomer();

        $response = $this->register();

        self::assertSame([202, ['expires_in' => AuthChallenges::CODE_SECONDS]], [$response->getStatusCode(), $this->decode($response)], 'the answer tells nothing');
        [$email] = $this->mail->to(self::WEB_EMAIL);
        self::assertSame('حساب شما در AmoBot', $email->getSubject(), 'the address\'s owner learns it has an account');
        self::assertDoesNotMatchRegularExpression('/^\d{6}$/m', (string) $email->getTextBody(), 'no code');
        self::assertSame(0, AuthChallenge::query()->count());
        self::assertSame(1, User::query()->count());
    }

    public function testACodeTakesFiveTriesAndOneThatIsNoCodeTakesNone(): void
    {
        $this->register();
        $code = RecordingMailTransport::codeIn($this->mail->to('sara@example.com')[0]);
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->assertWrongCode($this->verify('not a code'));
        for ($try = 1; $try <= AuthChallenges::CODE_ATTEMPTS; $try++) {
            $this->assertWrongCode($this->verify($wrong), "try {$try}");
        }

        $this->assertWrongCode($this->verify($code), 'the right code, tried out');
        self::assertSame(0, User::query()->count());
    }

    public function testSigningUpAgainReplacesTheCodeOnceAMinute(): void
    {
        $this->register();
        $first = RecordingMailTransport::codeIn($this->mail->to('sara@example.com')[0]);

        $soon = $this->register(['password' => 'another-secret']);
        self::assertSame(429, $soon->getStatusCode(), 'the address had a code a moment ago');
        self::assertLessThanOrEqual(60, (int) $soon->getHeaderLine('Retry-After'));
        self::assertCount(1, $this->mail->sent(), 'no second email');

        Carbon::setTestNow(now()->addSeconds(61));
        self::assertSame(202, $this->register(['password' => 'another-secret'])->getStatusCode());
        $second = RecordingMailTransport::codeIn($this->mail->to('sara@example.com')[1]);
        if ($first !== $second) {
            $this->assertWrongCode($this->verify($first), 'the first code is replaced');
        }
        self::assertSame(200, $this->verify($second)->getStatusCode());
        self::assertTrue(password_verify('another-secret', (string) User::query()->sole()->password_hash), 'the account the last sign-up kept');
    }

    public function testASignUpThatComesWhileTheLastOnesEmailIsStillGoingSendsNone(): void
    {
        // The same sign-up sent again while the mail server still talks to the first: the address's turn was taken
        // before its email went, so the second waits — one email, not two.
        $second = null;
        $first = $this->whileListening('eloquent.created: ' . AuthChallenge::class, function () use (&$second): void {
            if ($second === null) {
                $second = false; // what the second one raises is not this moment
                $second = $this->register();
            }
        }, fn(): ResponseInterface => $this->register());

        self::assertSame(202, $first->getStatusCode(), (string) $first->getBody());
        self::assertInstanceOf(ResponseInterface::class, $second);
        self::assertSame(429, $second->getStatusCode(), 'the address has a code on its way');
        self::assertCount(1, $this->mail->sent(), 'one email');
    }

    public function testEveryRefusalOfTheFormIsSaidAtOnce(): void
    {
        $response = $this->register(['first_name' => "\u{200C}", 'last_name' => str_repeat('ن', 65), 'email' => 'sara@', 'password' => 'short']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([
            'email' => ['ایمیل درست نیست؛ آن را مثل name@example.com بنویسید.'],
            'first_name' => ['نام را وارد کنید.'],
            'last_name' => ['نام خانوادگی حداکثر 64 کاراکتر است.'],
            'password' => ['رمز عبور دست‌کم 8 کاراکتر است.'],
        ], $this->decode($response)['errors']);
        self::assertSame([], $this->mail->sent());
    }

    public function testNoSignUpWhileItIsOffOrNoEmailGoesOut(): void
    {
        $this->website->forceFill(['email_signup' => false])->save();
        $off = $this->register();
        self::assertSame([422, SignInRefusedException::EMAIL_OFF], [$off->getStatusCode(), $this->decode($off)['message']]);

        $this->website->forceFill(['email_signup' => true])->save();
        $this->config(['mail.from_address' => '']);
        $noMail = $this->register();
        self::assertSame([503, SignInRefusedException::MAIL_OFF], [$noMail->getStatusCode(), $this->decode($noMail)['message']]);
        self::assertSame([], $this->mail->sent());
    }

    public function testAnEmailThatDidNotGoIsAskedForAgainAtOnce(): void
    {
        $this->mail->failing('Connection could not be established with host "mail.example.com:587"');

        $failed = $this->register();

        self::assertSame([502, MailFailedException::MESSAGE], [$failed->getStatusCode(), $this->decode($failed)['message']], 'the customer is told it did not go, never why');
        $this->mail->failing(null);
        self::assertSame(202, $this->register()->getStatusCode(), 'no minute to wait for an email that never went');
    }

    public function testAnAddressAnotherSignUpTookMeanwhileIsA409(): void
    {
        $this->register();
        $code = RecordingMailTransport::codeIn($this->mail->to('sara@example.com')[0]);
        $this->webCustomer();

        $response = $this->verify($code);

        self::assertSame([409, SignInRefusedException::EMAIL_TAKEN], [$response->getStatusCode(), $this->decode($response)['message']]);
        self::assertSame(1, User::query()->count());
        self::assertSame(0, CustomerSession::query()->count());
    }

    public function testAnAgentsWebsiteSignsUpItsOwnShopsCustomersSignedWithItsName(): void
    {
        $bot = $this->agentBot();
        $website = CurrentBot::run($bot, fn(): Website => $this->website(['email_signup' => true]));
        $this->webCustomer();

        $response = $this->postJson($this->storeApi($website, '/auth/register'), self::FORM);

        self::assertSame(202, $response->getStatusCode());
        [$email] = $this->mail->to(self::WEB_EMAIL);
        self::assertSame('کد تایید ثبت‌نام در فروشگاه نماینده', $email->getSubject(), "the main shop's customer is no customer of the agent's: a code, in the agent's shop's name");
        self::assertSame('فروشگاه نماینده', $email->getFrom()[0]->getName(), 'from the shop the email is about');
        $verified = $this->postJson($this->storeApi($website, '/auth/register/verify'), ['email' => self::WEB_EMAIL, 'code' => RecordingMailTransport::codeIn($email)]);
        self::assertSame(200, $verified->getStatusCode());
        self::assertSame(1, CurrentBot::run($bot, static fn(): int => User::query()->where('email', self::WEB_EMAIL)->count()));
    }

    /** @param array<string, string> $fields */
    private function register(array $fields = []): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->website, '/auth/register'), $fields + self::FORM);
    }

    private function verify(string $code, string $email = 'sara@example.com'): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->website, '/auth/register/verify'), ['email' => $email, 'code' => $code]);
    }

    private function assertWrongCode(ResponseInterface $response, string $message = ''): void
    {
        self::assertSame([422, ['code' => [EmailSignIn::WRONG_CODE]]], [$response->getStatusCode(), $this->decode($response)['errors'] ?? null], $message);
    }
}
