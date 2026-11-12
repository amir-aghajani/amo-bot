<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Captcha\Verifier;
use App\Core\Exceptions\ValidationException;
use App\Core\Security\Totp;
use App\Core\Sms\SmsFailedException;
use App\Modules\Accounts\Enums\SignInMethod;
use App\Modules\Accounts\Models\AuthChallenge;
use App\Modules\Accounts\Models\CustomerSession;
use App\Modules\Accounts\Services\AuthChallenges;
use App\Modules\Accounts\Services\EmailSignIn;
use App\Modules\Accounts\Services\PhoneSignIn;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Bots\CurrentBot;
use App\Modules\Referrals\Services\ReferralService;
use App\Modules\Store\Models\Website;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Users\Models\User;
use App\Support\Mobile;
use App\Support\Persian;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Fakes\RecordingSmsSender;
use Tests\HttpTestCase;
use Tests\Support\FakeTurnstile;

/**
 * Signing in on the website with a code texted to a mobile: the number however it is typed, the code to it whether or not
 * it has an account (one answer either way), the customer who shared it with the bot — or a newcomer with the number
 * alone — signed in by the code, another account of the number offered as a merge, an account with two-factor sign-in
 * asked its second step; tries, life and resends limited, and guesses while no code waits costing nothing; the site's
 * switch, SMS set up and an agent's site the owner lets text; the provider's refusal; the captcha.
 */
final class PhoneSignInTest extends HttpTestCase
{
    use WebsiteSignIns;

    private const PHONE = '+989121234567';

    private Website $website;

    private RecordingSmsSender $sms;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->telegram();
        $this->sms = $this->sms();
        $this->website = $this->website(['phone_login' => true]);
    }

    public function testTheCustomerWhoSharedTheNumberWithTheBotIsSignedInByItsCode(): void
    {
        $ali = $this->customer(['phone' => self::PHONE, 'username' => 'ali']);

        $sent = $this->start('۰۹۱۲ ۱۲۳ ۴۵۶۷');

        self::assertSame([202, ['sent' => true, 'expires_in' => PhoneSignIn::CODE_SECONDS, 'resend_in' => SignInThrottle::CODE_EVERY_SECONDS]], [$sent->getStatusCode(), $this->decode($sent)]);
        $code = (string) $this->sms->lastCodeTo(self::PHONE);
        self::assertMatchesRegularExpression('/^\d{6}$/', $code, 'six digits, to the number in E.164');
        self::assertStringNotContainsString($code, (string) AuthChallenge::query()->sole()->secret_hash, 'the code is kept nowhere as it is');

        $signedIn = $this->verify(Persian::digits($code), '9121234567');

        self::assertSame(200, $signedIn->getStatusCode(), (string) $signedIn->getBody());
        $answer = $this->decode($signedIn);
        self::assertSame([$ali->id, ['id' => self::TELEGRAM_ID, 'username' => 'ali'], self::PHONE], [$answer['customer']['id'], $answer['customer']['telegram'], $answer['customer']['phone']], "the bot's own customer, with their services and wallet");
        self::assertSame(1, User::query()->count());
        self::assertArrayNotHasKey('merge', $answer, 'nobody else holds the number');
        $this->bearer($answer['token']);
        self::assertSame('phone', $this->decode($this->get($this->storeApi($this->website, '/me')))['session']['method'], 'signed in by SMS: no second step on an account without one');
        $this->bearer(null);
        $this->assertWrongCode($this->verify($code), 'a code opens one session, once');
    }

    public function testANumberNobodyHasIsANewcomerWithTheNumberAlone(): void
    {
        $this->reportGroup();
        $this->referralProgram();
        $referrer = $this->customer(['telegram_id' => 7070, 'first_name' => 'Owner']);
        $invite = $this->service(ReferralService::class)->codeFor($referrer);
        $this->start();

        $signedUp = $this->verify((string) $this->sms->lastCodeTo(self::PHONE), self::PHONE, ['referral_code' => $invite]);

        self::assertSame(200, $signedUp->getStatusCode(), (string) $signedUp->getBody());
        $newcomer = User::query()->where('phone', self::PHONE)->sole();
        self::assertSame([
            'id' => $newcomer->id,
            'first_name' => null,
            'last_name' => null,
            'telegram' => null,
            'email' => null,
            'google' => false,
            'has_password' => false,
            'two_factor' => false,
            'phone' => self::PHONE,
            'balance' => '0.00',
            'created_at' => '2026-10-07T12:00:00+00:00',
        ], $this->decode($signedUp)['customer'], 'the number and nothing else: they name themselves (PATCH /me)');
        self::assertSame($referrer->id, $newcomer->referred_by, "the code's owner brought them");
        $reports = ReportMessage::query()->where('topic', Topic::Users)->pluck('text')->all();
        self::assertStringContainsString("بدون نام · \u{200E}+989121234567 · \u{200E}<code>#{$newcomer->id}</code>", $reports[0], 'reported as every newcomer is, by their mobile');
    }

    public function testTheAnswerIsTheSameWhetherOrNotTheNumberHasAnAccount(): void
    {
        $this->customer(['phone' => self::PHONE]);

        $known = $this->start();
        $unknown = $this->start('09351234567');

        self::assertSame([$known->getStatusCode(), $this->decode($known)], [$unknown->getStatusCode(), $this->decode($unknown)], 'nothing tells which numbers have an account');
        self::assertNotNull($this->sms->lastCodeTo(self::PHONE));
        self::assertNotNull($this->sms->lastCodeTo('+989351234567'), 'a code goes to either: it signs in, or signs up');
    }

    public function testOnlyAnIranianMobileIsTaken(): void
    {
        foreach (['' => Mobile::MISSING, '02112345678' => Mobile::NOT_A_MOBILE, '+14155550100' => Mobile::NOT_A_MOBILE] as $typed => $problem) {
            self::assertSame([422, ['phone' => [$problem]]], $this->refused($this->start((string) $typed)), "«{$typed}»");
        }
        self::assertSame([422, ['phone' => [Mobile::NOT_A_MOBILE]]], $this->refused($this->verify('123456', '0912')));
        self::assertSame([], $this->sms->sent, 'nothing texted');
    }

    public function testACodeTakesFiveTriesAndOneThatIsNoCodeTakesNone(): void
    {
        $this->start();
        $code = (string) $this->sms->lastCodeTo(self::PHONE);
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->assertWrongCode($this->verify('not a code'));
        for ($try = 1; $try <= AuthChallenges::CODE_ATTEMPTS; $try++) {
            $this->assertWrongCode($this->verify($wrong), "try {$try}");
        }

        $this->assertWrongCode($this->verify($code), 'the right code, tried out');
        self::assertSame([422, ['code' => [PhoneSignIn::CODE_MISSING]]], $this->refused($this->verify('')));
        self::assertSame(0, User::query()->count());
        self::assertSame(0, CustomerSession::query()->count());
    }

    public function testACodeLivesFiveMinutes(): void
    {
        $this->start();
        $code = (string) $this->sms->lastCodeTo(self::PHONE);

        Carbon::setTestNow(now()->addSeconds(PhoneSignIn::CODE_SECONDS + 1));

        $this->assertWrongCode($this->verify($code), 'expired');
        self::assertSame(0, User::query()->count());
    }

    public function testANewCodeWaitsAMinuteAndReplacesTheLast(): void
    {
        $this->start();
        $first = (string) $this->sms->lastCodeTo(self::PHONE);

        $soon = $this->start();
        self::assertSame(429, $soon->getStatusCode(), 'the number had a code a moment ago');
        self::assertLessThanOrEqual(PhoneSignIn::RESEND_SECONDS, (int) $soon->getHeaderLine('Retry-After'));
        self::assertStringContainsString('ثانیه دیگر', (string) $this->decode($soon)['message']);
        self::assertCount(1, $this->sms->sent, 'no second SMS');

        Carbon::setTestNow(now()->addSeconds(PhoneSignIn::RESEND_SECONDS + 1));
        self::assertSame(202, $this->start()->getStatusCode());
        $second = (string) $this->sms->lastCodeTo(self::PHONE);
        if ($first !== $second) {
            $this->assertWrongCode($this->verify($first), 'the first code is replaced');
        }
        self::assertSame(200, $this->verify($second)->getStatusCode());
    }

    public function testANumberGetsAFewCodesAnHourWhoeverAsks(): void
    {
        for ($code = 1; $code <= SignInThrottle::SMS_NUMBER_HOURLY; $code++) {
            self::assertSame(202, $this->start()->getStatusCode(), "code {$code}");
            Carbon::setTestNow(now()->addSeconds(PhoneSignIn::RESEND_SECONDS + 1));
        }

        $more = $this->start();

        self::assertSame(429, $more->getStatusCode());
        self::assertStringContainsString('برای این شماره پیامک زیادی فرستاده شد', (string) $this->decode($more)['message']);
        self::assertCount(SignInThrottle::SMS_NUMBER_HOURLY, $this->sms->sent);
        self::assertSame(202, $this->start('09351234567')->getStatusCode(), "another number's own budget");
    }

    public function testTheNumbersWrongCodesAreBudgetedAndStopItsCodesBeingTried(): void
    {
        for ($code = 1; $code * AuthChallenges::CODE_ATTEMPTS <= SignInThrottle::CODE_FAILURES_HOURLY; $code++) {
            $this->start();
            for ($try = 1; $try <= AuthChallenges::CODE_ATTEMPTS; $try++) {
                $this->verify('000000');
            }
            Carbon::setTestNow(now()->addSeconds(PhoneSignIn::RESEND_SECONDS + 1));
        }

        self::assertSame(202, $this->start()->getStatusCode(), 'wrong codes never stop a code going');
        $locked = $this->verify('000000');
        self::assertSame(429, $locked->getStatusCode(), 'its budget of wrong codes spent');
        self::assertStringContainsString('کد اشتباه زیادی برای این شماره وارد شد', (string) $this->decode($locked)['message']);
        self::assertSame(429, $this->verify((string) $this->sms->lastCodeTo(self::PHONE))->getStatusCode(), 'they stop its codes being tried, the right one too');

        Carbon::setTestNow(now()->addHour());
        self::assertSame(202, $this->start()->getStatusCode());
        self::assertSame(200, $this->verify((string) $this->sms->lastCodeTo(self::PHONE))->getStatusCode(), 'the hour over, its holder signs in');
    }

    /**
     * A stranger guessing at a number nobody texted a code costs its holder nothing: neither six digits — however many —
     * against its budget of wrong codes, nor anything typed, from address after address, against its sign-ins.
     */
    public function testGuessesWhileNoCodeWaitsLockTheNumbersHolderOutOfNothing(): void
    {
        $this->webCustomer(['email' => null, 'password_hash' => null, 'phone' => self::PHONE]);
        for ($guess = 1; $guess <= SignInThrottle::CODE_FAILURES_DAILY; $guess++) {
            $this->assertWrongCode($this->verify(sprintf('%06d', $guess)), "blind guess {$guess}");
        }
        $signIn = $this->service(PhoneSignIn::class);
        $site = $this->website->smsOpen(true);
        for ($guess = 1; $guess <= SignInThrottle::MAX_ACCOUNT_ATTEMPTS; $guess++) {
            $from = (new ServerRequestFactory())->createServerRequest('POST', '/', ['REMOTE_ADDR' => "10.0.{$guess}.1"]);
            try {
                CurrentBot::run($site->shop(), fn() => $signIn->verify($site, $from, ['phone' => self::PHONE, 'code' => 'no code']));
                self::fail('no code opens anything');
            } catch (ValidationException) {
                // A wrong code, as every one is.
            }
        }
        self::assertSame([], $this->sms->sent, 'no code was ever texted');

        self::assertSame(202, $this->start()->getStatusCode(), 'the number still gets its code');
        $signedIn = $this->verify((string) $this->sms->lastCodeTo(self::PHONE));
        self::assertSame(200, $signedIn->getStatusCode(), 'and its holder signs in by it: ' . $signedIn->getBody());
    }

    public function testAProviderThatDoesNotTakeANewCodeLeavesTheLastOneWorking(): void
    {
        $ali = $this->customer(['phone' => self::PHONE]);
        $this->start();
        $first = (string) $this->sms->lastCodeTo(self::PHONE);
        Carbon::setTestNow(now()->addSeconds(PhoneSignIn::RESEND_SECONDS + 1));
        $this->sms->refuse('insufficient credit');

        self::assertSame([502, SmsFailedException::MESSAGE], $this->message($this->start()), 'asked again, the provider fails');
        self::assertSame(1, AuthChallenge::query()->count(), 'the code that did not go is kept nowhere');

        $signedIn = $this->verify($first);
        self::assertSame(200, $signedIn->getStatusCode(), 'the code the customer has still signs in: ' . $signedIn->getBody());
        self::assertSame($ali->id, $this->decode($signedIn)['customer']['id']);
    }

    public function testTheProvidersWordsAreLoggedWithTheNumberMaskedHoweverTheyWriteIt(): void
    {
        $logs = $this->logs();
        $this->sms->refuse('recipients 09121234567, 989121234567, +989121234567 and 9121234567 are blacklisted');

        $this->start();

        self::assertTrue($logs->hasWarningThatContains('are blacklisted'), 'why is logged');
        foreach ($logs->getRecords() as $record) {
            self::assertStringNotContainsString('1234567', $record->message . json_encode($record->context), 'never the number, however it is written');
        }
        self::assertTrue($logs->hasWarningThatContains('recipients +98912***4567, +98912***4567, +98912***4567 and +98912***4567'));
    }

    public function testNoSignInBySmsWhileTheSiteHasItOffOrNoSmsGoesOut(): void
    {
        $this->website->forceFill(['phone_login' => false])->save();
        self::assertSame([422, SignInRefusedException::PHONE_OFF], $this->message($this->start()));
        self::assertSame([422, SignInRefusedException::PHONE_OFF], $this->message($this->verify('123456')));
        self::assertFalse($this->decode($this->get($this->storeApi($this->website)))['sign_in']['phone']);

        $this->website->forceFill(['phone_login' => true])->save();
        self::assertTrue($this->decode($this->get($this->storeApi($this->website)))['sign_in']['phone']);
        $this->config(['sms.driver' => 'none']);
        self::assertSame([422, SignInRefusedException::PHONE_OFF], $this->message($this->start()), 'switched on, but no SMS is set up');
        self::assertFalse($this->decode($this->get($this->storeApi($this->website)))['sign_in']['phone']);
        self::assertSame([], $this->sms->sent);
    }

    public function testAnAgentsSiteTextsCodesOnlyWhileTheOwnerLetsIt(): void
    {
        $bot = $this->agentBot();
        $website = CurrentBot::run($bot, fn(): Website => $this->website(['phone_login' => true]));
        $this->customer(['phone' => self::PHONE]);

        $refused = $this->postJson($this->storeApi($website, '/auth/phone'), ['phone' => self::PHONE]);
        self::assertSame([422, SignInRefusedException::PHONE_OFF], $this->message($refused), 'the owner pays for every code: not for an agent\'s site without their leave');

        $this->sms(forAgents: true);
        self::assertSame(202, $this->postJson($this->storeApi($website, '/auth/phone'), ['phone' => self::PHONE])->getStatusCode());
        $signedIn = $this->postJson($this->storeApi($website, '/auth/phone/verify'), ['phone' => self::PHONE, 'code' => (string) $this->sms->lastCodeTo(self::PHONE)]);

        self::assertSame(200, $signedIn->getStatusCode(), (string) $signedIn->getBody());
        $theirs = CurrentBot::run($bot, static fn(): User => User::query()->where('phone', self::PHONE)->sole());
        self::assertSame($theirs->id, $this->decode($signedIn)['customer']['id'], "the main shop's customer is no customer of the agent's: a newcomer of their shop");
    }

    public function testAProviderThatDoesNotTakeTheCodeIsA502TheCustomerAsksAgainAtOnce(): void
    {
        $logs = $this->logs();
        $this->sms->refuse('insufficient credit');

        $failed = $this->start();

        self::assertSame([502, SmsFailedException::MESSAGE], $this->message($failed), 'the customer is told it did not go, never why');
        self::assertTrue($logs->hasWarningThatContains('insufficient credit'), 'why is logged');
        self::assertTrue($logs->hasWarningThatContains('+98912***4567'), 'the number masked');
        self::assertFalse($logs->hasWarningThatContains(self::PHONE));

        $this->sms->refuse(null);
        self::assertSame(202, $this->start()->getStatusCode(), 'no minute to wait for a code that never went');
        $code = (string) $this->sms->lastCodeTo(self::PHONE);
        foreach ($logs->getRecords() as $record) {
            self::assertStringNotContainsString($code, $record->message . json_encode($record->context), 'the code is never logged');
        }
    }

    public function testTheCaptchaGuardsTheCodeForItsOwnAction(): void
    {
        $this->turnstile();
        $this->website->forceFill(['captcha_driver' => 'turnstile', 'captcha_config' => ['site_key' => '0x4AAAAAAA-site', 'secret_key' => '0x4AAAAAAA-secret']])->save();

        self::assertSame([422, ['captcha' => [Verifier::REFUSED]]], $this->refused($this->start()), 'no token');
        self::assertSame([422, ['captcha' => [Verifier::REFUSED]]], $this->refused($this->start(self::PHONE, FakeTurnstile::passed('sign_in'))), "a password sign-in's token");
        self::assertSame([422, ['phone' => [Mobile::NOT_A_MOBILE]]], $this->refused($this->start('0912', FakeTurnstile::passed('phone_sign_in'))), 'the number judged first: the token is not spent on it');
        self::assertCount(1, $this->turnstile()->checks, 'a form without a token, or with a wrong number, never reaches Cloudflare');
        self::assertSame([], $this->sms->sent);

        self::assertSame(202, $this->start(self::PHONE, FakeTurnstile::passed('phone_sign_in'))->getStatusCode());
        self::assertSame(200, $this->verify((string) $this->sms->lastCodeTo(self::PHONE))->getStatusCode(), 'the code typed back asks no captcha');
    }

    public function testOfTwoCustomersWithTheNumberTheOneWithTelegramIsSignedIn(): void
    {
        $this->webCustomer(['phone' => self::PHONE, 'last_seen_at' => now()]);
        $ali = $this->customer(['phone' => self::PHONE, 'last_seen_at' => now()->subYear()]);
        $this->start();

        $signedIn = $this->verify((string) $this->sms->lastCodeTo(self::PHONE));

        self::assertSame($ali->id, $this->decode($signedIn)['customer']['id'], 'Telegram vouched for the number as its own: the bot\'s account');
    }

    public function testABannedCustomerIsRefusedOnceTheCodeHolds(): void
    {
        $this->customer(['phone' => self::PHONE, 'status' => 'banned']);
        $this->webCustomer(['phone' => self::PHONE]);
        $this->start();

        self::assertSame([403, SignInRefusedException::BANNED], $this->message($this->verify((string) $this->sms->lastCodeTo(self::PHONE))), 'never another account of the number in its place');
        self::assertSame(0, CustomerSession::query()->count());
    }

    /**
     * A website newcomer by phone whose number the bot's phone gate later keeps on a new Telegram account: a sign-in by SMS
     * opens the Telegram account and offers the website account as a merge — taken, the two are one and nothing is left
     * without a way in.
     */
    public function testAnotherAccountWithTheNumberIsOfferedAsAMergeSoNoneIsStranded(): void
    {
        $this->start();
        $web = (int) $this->decode($this->verify((string) $this->sms->lastCodeTo(self::PHONE)))['customer']['id'];
        Carbon::setTestNow(now()->addDay());
        // The bot's /start, then its phone gate: the customer's own contact card.
        $bot = $this->customer(['telegram_id' => 99001, 'phone' => self::PHONE]);
        $this->start();

        $signedIn = $this->verify((string) $this->sms->lastCodeTo(self::PHONE));

        self::assertSame(200, $signedIn->getStatusCode(), (string) $signedIn->getBody());
        $answer = $this->decode($signedIn);
        self::assertSame($bot->id, $answer['customer']['id'], 'the account Telegram vouched for the number as its own');
        self::assertSame([false, true, 'other'], [$answer['merge']['account']['telegram'], $answer['merge']['account']['phone'], $answer['merge']['keeps']], 'the website account, the older: it stays');

        $this->bearer($answer['token']);
        $merged = $this->postJson($this->storeApi($this->website, '/me/merge'), ['token' => $answer['merge']['token']]);

        self::assertSame(200, $merged->getStatusCode(), (string) $merged->getBody());
        $customer = $this->decode($merged)['customer'];
        self::assertSame([$web, 99001, self::PHONE], [$customer['id'], $customer['telegram']['id'] ?? null, $customer['phone']]);
        self::assertSame(1, User::query()->where('phone', self::PHONE)->count(), 'one account holds the number');

        $this->bearer(null);
        Carbon::setTestNow(now()->addSeconds(PhoneSignIn::RESEND_SECONDS + 1));
        $this->start();
        $again = $this->decode($this->verify((string) $this->sms->lastCodeTo(self::PHONE)));
        self::assertSame($web, $again['customer']['id']);
        self::assertArrayNotHasKey('merge', $again);
    }

    public function testNoMergeIsOfferedWithABannedAccountNorPastAnotherAccountsSecondStep(): void
    {
        $ali = $this->customer(['phone' => self::PHONE]);
        $banned = $this->customer(['telegram_id' => null, 'phone' => self::PHONE, 'status' => 'banned']);
        $this->start();

        $signedIn = $this->verify((string) $this->sms->lastCodeTo(self::PHONE));

        self::assertSame($ali->id, $this->decode($signedIn)['customer']['id']);
        self::assertArrayNotHasKey('merge', $this->decode($signedIn), 'a banned account is made one with nobody');

        $banned->delete();
        $sara = $this->webCustomer(['phone' => self::PHONE]);
        $this->twoFactorOn($this->website, $sara);
        Carbon::setTestNow(now()->addSeconds(PhoneSignIn::RESEND_SECONDS + 1));
        $this->start();

        $signedIn = $this->verify((string) $this->sms->lastCodeTo(self::PHONE));

        self::assertSame($ali->id, $this->decode($signedIn)['customer']['id']);
        self::assertArrayNotHasKey('merge', $this->decode($signedIn), 'the number takes nobody past the second step of the account it reaches');
    }

    /**
     * A code by SMS is weaker than Telegram or Google — a SIM swapped, or a number given to someone else, reads it —: an
     * account with two-factor sign-in is asked its second step, as after its password, and the session is the number's
     * with the app's code — offering then the merge the number would have.
     */
    public function testAnAccountWithTwoFactorSignInIsAskedItsSecondStep(): void
    {
        $sara = $this->webCustomer(['phone' => self::PHONE, 'last_seen_at' => now()]);
        $secret = $this->twoFactorOn($this->website, $sara)['secret'];
        $other = $this->customer(['telegram_id' => null, 'phone' => self::PHONE, 'last_seen_at' => now()->subYear()]);
        $this->start();

        $asked = $this->verify((string) $this->sms->lastCodeTo(self::PHONE));

        self::assertSame(202, $asked->getStatusCode(), (string) $asked->getBody());
        self::assertSame(0, CustomerSession::query()->count(), 'no session before the second step');
        Carbon::setTestNow(now()->addSeconds(30));
        $signedIn = $this->postJson($this->storeApi($this->website, '/auth/login/2fa'), ['challenge' => $this->decode($asked)['two_factor']['challenge'], 'code' => Totp::code($secret)]);

        self::assertSame(200, $signedIn->getStatusCode(), (string) $signedIn->getBody());
        $answer = $this->decode($signedIn);
        self::assertSame([$sara->id, 'this'], [$answer['customer']['id'], $answer['merge']['keeps']], 'the merge with the other account of the number');
        self::assertSame(CustomerSession::query()->sole()->method, SignInMethod::PhoneAndCode);
        $this->bearer($answer['token']);
        self::assertSame('phone_2fa', $this->decode($this->get($this->storeApi($this->website, '/me')))['session']['method']);
        self::assertNotNull(User::query()->find($other->id), 'nothing merged unasked');
    }

    /** @param string|null $captcha The widget's token, when the form carries one */
    private function start(string $phone = self::PHONE, ?string $captcha = null): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->website, '/auth/phone'), ['phone' => $phone] + ($captcha === null ? [] : ['captcha' => $captcha]));
    }

    /** @param array<string, string> $more */
    private function verify(string $code, string $phone = self::PHONE, array $more = []): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->website, '/auth/phone/verify'), ['phone' => $phone, 'code' => $code] + $more);
    }

    private function assertWrongCode(ResponseInterface $response, string $message = ''): void
    {
        self::assertSame([422, ['code' => [EmailSignIn::WRONG_CODE]]], [$response->getStatusCode(), $this->decode($response)['errors'] ?? null], $message);
    }
}
