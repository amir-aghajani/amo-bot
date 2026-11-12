<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Accounts\Exceptions\AccountRefusedException;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Accounts\Services\EmailSignIn;
use App\Modules\Accounts\Services\PhoneSignIn;
use App\Modules\Accounts\Services\Reauthentication;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Store\Models\MiniApp;
use App\Modules\Store\Models\Website;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Support\Mobile;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\Fakes\RecordingSmsSender;
use Tests\HttpTestCase;

/**
 * A mobile number as a signed-in customer's way in: added by the code texted to it (a merge offered when another account
 * holds it), taken off — never the last way in, and a number counts as one only where it signs in —, and the account's
 * own proven again by a code for that very session.
 */
final class PhoneIdentitiesTest extends HttpTestCase
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

    public function testANumberNobodyHasIsAddedWithTheCodeTextedToIt(): void
    {
        $sara = $this->webCustomer();
        $this->bearer($this->customerSession($sara));

        $sent = $this->postJson($this->storeApi($this->website, '/me/identities/phone'), ['phone' => '09121234567']);

        self::assertSame([202, PhoneSignIn::sent(PhoneSignIn::CODE_SECONDS)], [$sent->getStatusCode(), $this->decode($sent)], (string) $sent->getBody());
        $added = $this->verifyLink((string) $this->sms->lastCodeTo(self::PHONE));

        self::assertSame(200, $added->getStatusCode(), (string) $added->getBody());
        self::assertSame(self::PHONE, $this->decode($added)['customer']['phone']);
        self::assertSame(self::PHONE, $sara->refresh()->phone);
        $notice = Notification::query()->where('user_id', $sara->id)->sole();
        self::assertSame([NoticeType::WayInAdded, self::text(BotText::WayInAdded, ['way' => 'شماره موبایل'])], [$notice->type, $notice->text], 'the customer is told');
        self::assertSame([422, AccountRefusedException::HAS_PHONE], $this->message($this->verifyLink('000000')), 'one number an account');
    }

    public function testAnAccountWithANumberAddsNoneAndACodeIsTheAccountsItWasTextedFor(): void
    {
        $ali = $this->customer(['phone' => '+989351234567', 'email' => 'ali@example.com']);
        $this->bearer($this->customerSession($ali));
        self::assertSame([422, AccountRefusedException::HAS_PHONE], $this->message($this->postJson($this->storeApi($this->website, '/me/identities/phone'), ['phone' => self::PHONE])));

        $saraToken = $this->customerSession($this->webCustomer());
        $leila = $this->webCustomer(['email' => 'leila@example.com']);
        $this->bearer($this->customerSession($leila));
        $this->postJson($this->storeApi($this->website, '/me/identities/phone'), ['phone' => self::PHONE]);
        $leilas = (string) $this->sms->lastCodeTo(self::PHONE);

        $this->bearer($saraToken);
        self::assertSame([422, ['code' => [EmailSignIn::WRONG_CODE]]], $this->refused($this->verifyLink($leilas)), "another account's code for the same number");
        self::assertNull(User::query()->where('email', self::WEB_EMAIL)->sole()->phone);
        self::assertSame(0, User::query()->where('phone', self::PHONE)->count());
    }

    public function testTheNumberOfAnotherAccountIsProvenFirstThenAMergeOffered(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');
        $ali = $this->customer(['phone' => self::PHONE]);
        Carbon::setTestNow('2026-10-07 12:00:00');
        $sara = $this->webCustomer();
        $this->bearer($this->customerSession($sara));
        $this->postJson($this->storeApi($this->website, '/me/identities/phone'), ['phone' => self::PHONE]);

        $offered = $this->verifyLink((string) $this->sms->lastCodeTo(self::PHONE));

        self::assertSame(202, $offered->getStatusCode(), (string) $offered->getBody());
        $merge = $this->decode($offered)['merge'];
        self::assertSame([true, true, 'other'], [$merge['account']['telegram'], $merge['account']['phone'], $merge['keeps']]);
        self::assertNull($sara->refresh()->phone, 'not on two accounts');

        $merged = $this->postJson($this->storeApi($this->website, '/me/merge'), ['token' => $merge['token']]);

        self::assertSame(200, $merged->getStatusCode(), (string) $merged->getBody());
        self::assertSame([$ali->id, self::PHONE, self::WEB_EMAIL], [$this->decode($merged)['customer']['id'], $this->decode($merged)['customer']['phone'], $this->decode($merged)['customer']['email']]);
    }

    public function testNoNumberIsAddedWhileTheSiteSignsInBySmsNot(): void
    {
        $this->website->forceFill(['phone_login' => false])->save();
        $this->bearer($this->customerSession($this->webCustomer()));

        self::assertSame([422, SignInRefusedException::PHONE_OFF], $this->message($this->postJson($this->storeApi($this->website, '/me/identities/phone'), ['phone' => self::PHONE])));
        self::assertSame([422, SignInRefusedException::PHONE_OFF], $this->message($this->verifyLink('123456')));
        self::assertSame([], $this->sms->sent);
    }

    public function testANumberGoesButNeverTheLastWayIn(): void
    {
        $sara = $this->webCustomer(['phone' => self::PHONE]);
        $this->bearer($this->customerSession($sara));

        $removed = $this->deleteJson($this->storeApi($this->website, '/me/identities/phone'));

        self::assertSame(200, $removed->getStatusCode(), (string) $removed->getBody());
        self::assertNull($this->decode($removed)['customer']['phone']);
        self::assertSame(NoticeType::WayInRemoved, Notification::query()->where('user_id', $sara->id)->sole()->type);
        self::assertSame([422, AccountRefusedException::NOT_LINKED], $this->message($this->deleteJson($this->storeApi($this->website, '/me/identities/phone'))));

        $only = $this->customer(['telegram_id' => null, 'phone' => '+989351234567']);
        $this->bearer($this->customerSession($only));
        self::assertSame([422, AccountRefusedException::LAST_WAY_IN], $this->message($this->deleteJson($this->storeApi($this->website, '/me/identities/phone'))));
        self::assertSame('+989351234567', $only->refresh()->phone);
    }

    public function testANumberIsAWayInThatStaysOnlyWhereItSignsIn(): void
    {
        $ali = $this->customer(['phone' => self::PHONE]);
        $this->bearer($this->customerSession($ali));

        $this->website->forceFill(['phone_login' => false])->save();
        self::assertSame([422, AccountRefusedException::LAST_WAY_IN], $this->message($this->deleteJson($this->storeApi($this->website, '/me/identities/telegram'))), 'the number opens nothing on this site: it is no way in that stays');

        $this->website->forceFill(['phone_login' => true])->save();
        self::assertSame(200, $this->deleteJson($this->storeApi($this->website, '/me/identities/telegram'))->getStatusCode(), 'while it signs in by SMS, the number stays a way in');
        $ali->refresh();
        self::assertSame([null, self::PHONE], [$ali->telegram_id, $ali->phone]);
    }

    public function testANumberNoSmsReachesIsNoWayInThatStays(): void
    {
        $ali = $this->customer(['phone' => '+14155550100']);
        $this->bearer($this->customerSession($ali));

        self::assertSame([422, AccountRefusedException::LAST_WAY_IN], $this->message($this->deleteJson($this->storeApi($this->website, '/me/identities/telegram'))), 'a number shared with the bot from abroad: no sign-in by SMS takes it');
        self::assertSame([self::TELEGRAM_ID, '+14155550100'], [$ali->refresh()->telegram_id, $ali->phone]);

        $this->bearer(null);
        self::assertSame([422, ['phone' => [Mobile::NOT_A_MOBILE]]], $this->refused($this->postJson($this->storeApi($this->website, '/auth/phone'), ['phone' => '+14155550100'])), 'as nothing signs in by it');
    }

    public function testANumberASignInOpensAnotherAccountByIsNoWayInThatStays(): void
    {
        // Of two accounts with the number, a sign-in by SMS opens the one with Telegram: the other only hears of it as a merge.
        $this->customer(['telegram_id' => 7001, 'phone' => self::PHONE]);
        $sara = $this->webCustomer(['phone' => self::PHONE]);
        $this->bearer($this->customerSession($sara));

        self::assertSame([422, AccountRefusedException::LAST_WAY_IN], $this->message($this->deleteJson($this->storeApi($this->website, '/me/identities/email'))));
        self::assertSame(self::WEB_EMAIL, $sara->refresh()->email);
    }

    public function testTheNumberOfAnAccountWithTwoFactorSignInIsNoWayPastItsSecondStep(): void
    {
        $leila = $this->webCustomer(['email' => 'leila@example.com', 'phone' => self::PHONE]);
        $this->twoFactorOn($this->website, $leila);
        $sara = $this->webCustomer();
        $this->bearer($this->customerSession($sara));
        $this->postJson($this->storeApi($this->website, '/me/identities/phone'), ['phone' => self::PHONE]);

        $refused = $this->verifyLink((string) $this->sms->lastCodeTo(self::PHONE));

        self::assertSame([422, AccountRefusedException::TWO_FACTOR_NUMBER], $this->message($refused), 'a sign-in by the number stops at that step: a merge by it must too');
        self::assertNull($sara->refresh()->phone);
    }

    public function testAnAccountWithTwoFactorSignInIsNotProvenAgainByItsNumberAlone(): void
    {
        $sara = $this->webCustomer(['phone' => self::PHONE]);
        $this->twoFactorOn($this->website, $sara);
        $this->bearer($this->customerSession($sara, ['authenticated_at' => now()->subHour()]));

        self::assertSame([422, ['method' => [PhoneSignIn::TWO_FACTOR_FIRST]]], $this->refused($this->postJson($this->storeApi($this->website, '/me/reauthenticate/phone'))), 'its password and code prove it');
        self::assertSame([422, ['method' => [PhoneSignIn::TWO_FACTOR_FIRST]]], $this->refused($this->reauthenticate('123456')));
        self::assertSame([], $this->sms->sent);
    }

    public function testTheAccountsMobileIsProvenAgainByACodeForThatVerySession(): void
    {
        $ali = $this->customer(['phone' => self::PHONE, 'email' => 'ali@example.com']);
        $phone = $this->customerSession($ali);
        $laptop = $this->customerSession($ali);
        Carbon::setTestNow(now()->addHour());
        $this->bearer($phone);
        self::assertSame(403, $this->deleteJson($this->storeApi($this->website, '/me/identities/email'))->getStatusCode(), 'an hour old: asked again');

        $sent = $this->postJson($this->storeApi($this->website, '/me/reauthenticate/phone'));
        self::assertSame([202, PhoneSignIn::sent(PhoneSignIn::CODE_SECONDS)], [$sent->getStatusCode(), $this->decode($sent)]);
        $code = (string) $this->sms->lastCodeTo(self::PHONE);
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->bearer($laptop);
        self::assertSame([422, ['code' => [EmailSignIn::WRONG_CODE]]], $this->refused($this->reauthenticate($code)), "another session's code proves nothing here");
        $this->bearer($phone);
        self::assertSame([422, ['code' => [EmailSignIn::WRONG_CODE]]], $this->refused($this->reauthenticate($wrong)));
        self::assertSame(403, $this->deleteJson($this->storeApi($this->website, '/me/identities/email'))->getStatusCode(), 'nothing proven yet');

        $proven = $this->reauthenticate($code);

        self::assertSame([200, ['expires_in' => CustomerSessions::RECENT_SECONDS]], [$proven->getStatusCode(), $this->decode($proven)], (string) $proven->getBody());
        self::assertSame('password', $this->decode($this->get($this->storeApi($this->website, '/me')))['session']['method'], 'a code by SMS proves the holder again, but makes no session a strong one');
        self::assertSame(200, $this->deleteJson($this->storeApi($this->website, '/me/identities/email'))->getStatusCode(), 'proven: the change goes through');
    }

    public function testOnlyAnAccountWithAnIranianMobileOnASiteThatTextsProvesItByOne(): void
    {
        $foreign = $this->customer(['phone' => '+14155550100']);
        $this->bearer($this->customerSession($foreign, ['authenticated_at' => now()->subHour()]));
        self::assertSame([422, ['method' => [PhoneSignIn::NO_PHONE]]], $this->refused($this->postJson($this->storeApi($this->website, '/me/reauthenticate/phone'))), 'a number shared with the bot from abroad: no SMS reaches it');
        self::assertSame([422, ['method' => [PhoneSignIn::NO_PHONE]]], $this->refused($this->reauthenticate('123456')));

        $this->website->forceFill(['phone_login' => false])->save();
        $ali = $this->customer(['telegram_id' => 7001, 'phone' => self::PHONE]);
        $this->bearer($this->customerSession($ali, ['authenticated_at' => now()->subHour()]));
        self::assertSame([422, SignInRefusedException::PHONE_OFF], $this->message($this->postJson($this->storeApi($this->website, '/me/reauthenticate/phone'))));
        self::assertSame([422, SignInRefusedException::PHONE_OFF], $this->message($this->reauthenticate('123456')));

        $app = $this->miniApp();
        self::assertSame(404, $this->postJson($this->storeApi($app, '/me/reauthenticate/phone'))->getStatusCode(), "the website's way: no route through the mini app");
        self::assertSame([422, SignInRefusedException::PHONE_OFF], $this->message($this->postJson($this->storeApi($app, '/me/reauthenticate'), ['method' => 'phone', 'code' => '123456'])));
        self::assertSame([], $this->sms->sent);
        self::assertStringContainsString('phone', Reauthentication::METHOD);
        self::assertInstanceOf(MiniApp::class, $app);
    }

    public function testASignedInCustomersCodesHaveADailyBudgetOfTheirOwn(): void
    {
        $this->bearer($this->customerSession($this->webCustomer()));

        // Number after number, each with a budget of its own: the account's day is what runs out.
        for ($code = 1; $code <= SignInThrottle::SMS_ACCOUNT_DAILY; $code++) {
            $number = sprintf('0912000%04d', $code);
            self::assertSame(202, $this->postJson($this->storeApi($this->website, '/me/identities/phone'), ['phone' => $number])->getStatusCode(), "code {$code}");
        }
        $more = $this->postJson($this->storeApi($this->website, '/me/identities/phone'), ['phone' => '09129999999']);

        self::assertSame(429, $more->getStatusCode());
        self::assertStringContainsString('پیامک زیادی درخواست کرده‌اید', (string) $this->decode($more)['message']);
        self::assertCount(SignInThrottle::SMS_ACCOUNT_DAILY, $this->sms->sent);
    }

    private function verifyLink(string $code, string $phone = self::PHONE): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->website, '/me/identities/phone/verify'), ['phone' => $phone, 'code' => $code]);
    }

    private function reauthenticate(string $code): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->website, '/me/reauthenticate'), ['method' => 'phone', 'code' => $code]);
    }
}
