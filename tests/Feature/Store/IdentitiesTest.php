<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Accounts\Exceptions\AccountRefusedException;
use App\Modules\Accounts\Models\AccountMerge;
use App\Modules\Accounts\Oidc\OidcProvider;
use App\Modules\Accounts\Services\AccountMerger;
use App\Modules\Accounts\Services\AuthChallenges;
use App\Modules\Accounts\Services\EmailSignIn;
use App\Modules\Accounts\Services\MergeOffers;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Store\Models\Website;
use App\Modules\Users\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\Fakes\RecordingMailTransport;
use Tests\HttpTestCase;
use Tests\Support\FakeGoogleLogin;
use Tests\Support\FakeTelegramLogin;

/**
 * A signed-in customer's ways in on the shop's website — a Telegram account, a Google account, an email with its password
 * — added as their sign-in proves them, and taken away (never the last one). A way in another account of the shop has is
 * a merge offered: that account described, which of the two stays (the older), and a ticket — this account's, once, while
 * the other still has that way in — that makes them one, this session the account that stays.
 */
final class IdentitiesTest extends HttpTestCase
{
    /** The PKCE verifier the site keeps for a redirect it begins (its challenge goes to the shop). */
    private const VERIFIER = 'the-sites-own-pkce-verifier.kept~in_this-browser-0123456789';

    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->telegram();
        $this->website = $this->website(['google_client_id' => self::GOOGLE_CLIENT_ID]);
    }

    public function testATelegramAccountNobodyHasIsTheAccountsWithTelegramsProfile(): void
    {
        $this->telegramLogin();
        $sara = $this->webCustomer();
        $this->bearer($this->customerSession($sara));

        $linked = $this->linkTelegram();

        self::assertSame(200, $linked->getStatusCode(), (string) $linked->getBody());
        $customer = $this->decode($linked)['customer'];
        self::assertSame([$sara->id, ['id' => self::TELEGRAM_ID, 'username' => 'ali'], 'Ali', 'Rezaei', self::WEB_EMAIL], [$customer['id'], $customer['telegram'], $customer['first_name'], $customer['last_name'], $customer['email']], "its names are Telegram's now");
        self::assertSame(1, User::query()->count());
        self::assertSame(200, $this->linkTelegram()->getStatusCode(), "the account's own already: nothing to do");
    }

    public function testATelegramAccountIsAddedByTheRedirectsCodeToo(): void
    {
        $this->telegramLogin();
        $this->website->forceFill(['telegram_client_secret' => 'tg-client-secret-1'])->save();
        $this->bearer($this->customerSession($sara = $this->webCustomer()));
        $query = $this->authorize('/me/telegram/authorize');
        $this->telegramLogin()->answerCode(FakeTelegramLogin::idToken(FakeTelegramLogin::claims(self::WEBSITE_CLIENT_ID, $query['nonce'])));

        $linked = $this->postJson($this->storeApi($this->website, '/me/identities/telegram'), ['code' => 'the-code', 'state' => $query['state'], 'code_verifier' => self::VERIFIER]);

        self::assertSame(200, $linked->getStatusCode(), (string) $linked->getBody());
        self::assertSame(self::TELEGRAM_ID, $sara->refresh()->telegram_id);
    }

    public function testARedirectsStateIsTheCustomersWhoAskedForIt(): void
    {
        $this->telegramLogin();
        $this->website->forceFill(['telegram_client_secret' => 'tg-client-secret-1'])->save();

        // Someone signs in on their own browser and hands the victim the address Telegram sent them back to: a sign-in's
        // state, or one another customer asked for, adds nobody's Telegram account to the victim's.
        $signIn = $this->authorize('/auth/telegram/authorize');
        $this->bearer($this->customerSession($this->webCustomer(['email' => 'attacker@example.com'])));
        $theirs = $this->authorize('/me/telegram/authorize');
        $this->bearer($this->customerSession($sara = $this->webCustomer()));

        foreach (['a sign-in\'s state' => $signIn, "another customer's state" => $theirs] as $whose => $query) {
            $refused = $this->postJson($this->storeApi($this->website, '/me/identities/telegram'), ['code' => 'the-code', 'state' => $query['state'], 'code_verifier' => self::VERIFIER]);

            self::assertSame([422, ['code' => [SignInRefusedException::SIGN_IN_SPENT]]], [$refused->getStatusCode(), $this->decode($refused)['errors'] ?? null], "{$whose}: a 422 on the proof, never the 401 that ends her session");
        }
        self::assertNull($sara->refresh()->telegram_id);
        self::assertNotContains('POST ' . OidcProvider::telegram()->tokenEndpoint, $this->telegramLogin()->calls(), 'no code exchanged for any of them');
        self::assertSame(200, $this->get($this->storeApi($this->website, '/me'))->getStatusCode(), 'her session stands');

        // And the other way: a signed-in customer's state signs nobody in.
        $mine = $this->authorize('/me/telegram/authorize');
        $this->bearer(null);
        $this->telegramLogin()->answerCode(FakeTelegramLogin::idToken(FakeTelegramLogin::claims(self::WEBSITE_CLIENT_ID, $mine['nonce'])));
        $signedIn = $this->postJson($this->storeApi($this->website, '/auth/telegram'), ['code' => 'the-code', 'state' => $mine['state'], 'code_verifier' => self::VERIFIER]);
        self::assertSame([401, SignInRefusedException::SIGN_IN_SPENT], $this->message($signedIn));
        self::assertSame(0, User::query()->whereNotNull('telegram_id')->count(), 'nobody signed in by it');
    }

    public function testARedirectsStateIsTheVerySessionsThatAskedForIt(): void
    {
        $this->telegramLogin();
        $this->website->forceFill(['telegram_client_secret' => 'tg-client-secret-1'])->save();
        $sara = $this->webCustomer();
        $phone = $this->customerSession($sara);
        $laptop = $this->customerSession($sara);
        $this->bearer($phone);
        $query = $this->authorize('/me/telegram/authorize');

        // Her other device brings the code and the state back: it is not the session that asked.
        $this->bearer($laptop);
        $refused = $this->postJson($this->storeApi($this->website, '/me/identities/telegram'), ['code' => 'the-code', 'state' => $query['state'], 'code_verifier' => self::VERIFIER]);
        self::assertSame([422, ['code' => [SignInRefusedException::SIGN_IN_SPENT]]], [$refused->getStatusCode(), $this->decode($refused)['errors'] ?? null]);
        self::assertNotContains('POST ' . OidcProvider::telegram()->tokenEndpoint, $this->telegramLogin()->calls(), 'no code exchanged for it');

        // The state is left for the one that asked, which takes it.
        $this->bearer($phone);
        $this->telegramLogin()->answerCode(FakeTelegramLogin::idToken(FakeTelegramLogin::claims(self::WEBSITE_CLIENT_ID, $query['nonce'])));
        $linked = $this->postJson($this->storeApi($this->website, '/me/identities/telegram'), ['code' => 'the-code', 'state' => $query['state'], 'code_verifier' => self::VERIFIER]);
        self::assertSame(200, $linked->getStatusCode(), (string) $linked->getBody());
        self::assertSame(self::TELEGRAM_ID, $sara->refresh()->telegram_id);
    }

    public function testAProofThatDoesNotHoldIsAnswerOnItsFieldAndTheSessionStands(): void
    {
        $this->telegramLogin();
        $this->bearer($this->customerSession($sara = $this->webCustomer()));
        $nonce = $this->nonce();

        $refused = $this->postJson($this->storeApi($this->website, '/me/identities/telegram'), ['id_token' => FakeTelegramLogin::idToken(FakeTelegramLogin::claims('another-site', $nonce)), 'nonce' => $nonce]);
        $google = $this->postJson($this->storeApi($this->website, '/me/identities/google'), ['id_token' => 'not-a-token', 'nonce' => $this->nonce()]);

        self::assertSame([422, ['id_token' => [SignInRefusedException::TOKEN_ELSEWHERE]]], [$refused->getStatusCode(), $this->decode($refused)['errors'] ?? null]);
        self::assertSame([422, ['id_token' => [SignInRefusedException::TOKEN_INVALID]]], [$google->getStatusCode(), $this->decode($google)['errors'] ?? null]);
        self::assertSame(200, $this->get($this->storeApi($this->website, '/me'))->getStatusCode(), 'a 401 would have said her session ended');
        self::assertNull($sara->refresh()->telegram_id);
    }

    public function testAnAccountWithAnotherTelegramAccountIsRefused(): void
    {
        $this->telegramLogin();
        $this->bearer($this->customerSession($this->customer(['telegram_id' => 9001, 'email' => 'ali@example.com'])));

        self::assertSame([422, AccountRefusedException::OTHER_TELEGRAM], $this->message($this->linkTelegram()));
    }

    public function testTheBotsCustomerIsAMergeOfferedThenTaken(): void
    {
        $this->telegramLogin();
        Carbon::setTestNow('2026-09-01 10:00:00');
        $ali = $this->wallet($this->customer(['username' => 'ali']), '50000');
        $server = $this->fakeServer();
        $plan = $this->plan([], $server);
        $this->subscription($ali, $plan, $server, 'ali_1');
        $this->purchaseOrder($ali, $plan, $server);
        Carbon::setTestNow('2026-10-07 12:00:00');
        $sara = $this->webCustomer();
        $this->bearer($this->customerSession($sara));

        $offered = $this->linkTelegram();

        self::assertSame(202, $offered->getStatusCode(), (string) $offered->getBody());
        $merge = $this->decode($offered)['merge'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $merge['token']);
        self::assertSame(MergeOffers::TICKET_SECONDS, $merge['expires_in']);
        self::assertSame(['name' => 'Ali', 'created_at' => '2026-09-01T10:00:00+00:00', 'services' => 1, 'orders' => 1, 'balance' => '50000.00', 'telegram' => true, 'email' => false, 'google' => false], $merge['account'], 'the other account, for the customer to judge it is theirs');
        self::assertSame('other', $merge['keeps'], 'the older stays');
        self::assertSame(2, User::query()->count(), 'nothing merged yet');

        $merged = $this->postJson($this->storeApi($this->website, '/me/merge'), ['token' => $merge['token']]);

        self::assertSame(200, $merged->getStatusCode(), (string) $merged->getBody());
        $customer = $this->decode($merged)['customer'];
        self::assertSame([$ali->id, ['id' => self::TELEGRAM_ID, 'username' => 'ali'], self::WEB_EMAIL, true, '50000.00'], [$customer['id'], $customer['telegram'], $customer['email'], $customer['has_password'], $customer['balance']]);
        self::assertSame($ali->id, $this->decode($this->get($this->storeApi($this->website, '/me')))['customer']['id'], 'this session is the account that stays');
        self::assertNull(User::query()->find($sara->id));
        self::assertSame([$ali->id, $sara->id, AccountMerger::BY_CUSTOMER], [AccountMerge::query()->sole()->user_id, AccountMerge::query()->sole()->merged_user_id, AccountMerge::query()->sole()->actor]);
        self::assertSame([422, AccountRefusedException::OFFER_GONE], $this->message($this->postJson($this->storeApi($this->website, '/me/merge'), ['token' => $merge['token']])), 'a ticket is taken once');
    }

    public function testAMergeTicketIsTheAskingAccountsWhileTheOtherHasTheWayIn(): void
    {
        $this->telegramLogin();
        Carbon::setTestNow('2026-09-01 10:00:00');
        $ali = $this->customer();
        Carbon::setTestNow('2026-10-07 12:00:00');
        $saraToken = $this->customerSession($this->webCustomer());
        $leilaToken = $this->customerSession($this->webCustomer(['email' => 'leila@example.com']));
        $this->bearer($saraToken);
        $first = $this->decode($this->linkTelegram())['merge']['token'];

        $this->bearer($leilaToken);
        self::assertSame([422, AccountRefusedException::OFFER_GONE], $this->message($this->postJson($this->storeApi($this->website, '/me/merge'), ['token' => $first])), "another account's ticket");

        $this->bearer($saraToken);
        Carbon::setTestNow(now()->addSeconds(MergeOffers::TICKET_SECONDS + 1));
        self::assertSame(200, $this->postJson($this->storeApi($this->website, '/me/reauthenticate'), ['method' => 'password', 'password' => self::WEB_PASSWORD])->getStatusCode(), 'her sign-in a quarter of an hour old: proven again');
        self::assertSame([422, AccountRefusedException::OFFER_GONE], $this->message($this->postJson($this->storeApi($this->website, '/me/merge'), ['token' => $first])), 'expired');

        $second = $this->decode($this->linkTelegram())['merge']['token'];
        $ali->forceFill(['telegram_id' => 9002])->save();
        self::assertSame([422, AccountRefusedException::OFFER_GONE], $this->message($this->postJson($this->storeApi($this->website, '/me/merge'), ['token' => $second])), 'the other account no longer has that Telegram account');
        self::assertSame(3, User::query()->count());
    }

    public function testAGoogleAccountNobodyHasIsTheAccountsWithTheAddressGoogleVouchesFor(): void
    {
        $this->googleLogin();
        $ali = $this->customer();
        $this->bearer($this->customerSession($ali));

        $linked = $this->linkGoogle();

        self::assertSame(200, $linked->getStatusCode(), (string) $linked->getBody());
        $customer = $this->decode($linked)['customer'];
        self::assertSame([true, FakeGoogleLogin::EMAIL, false, 'Ali'], [$customer['google'], $customer['email'], $customer['has_password'], $customer['first_name']], 'its own names stay');
        self::assertSame(FakeGoogleLogin::SUB, $ali->refresh()->google_sub);
    }

    public function testAnAccountWithAnotherGoogleAccountIsRefused(): void
    {
        $this->googleLogin();
        $this->bearer($this->customerSession($this->webCustomer(['google_sub' => 'her-own'])));

        self::assertSame([422, AccountRefusedException::OTHER_GOOGLE], $this->message($this->linkGoogle()));
    }

    public function testTheAccountThatSignsInWithThatGoogleAccountIsAMergeOffered(): void
    {
        $this->googleLogin();
        Carbon::setTestNow('2026-09-01 10:00:00');
        $leila = $this->webCustomer(['email' => 'leila@example.com', 'google_sub' => FakeGoogleLogin::SUB, 'first_name' => 'Leila']);
        Carbon::setTestNow('2026-10-07 12:00:00');
        $ali = $this->customer();
        $this->bearer($this->customerSession($ali));

        $offered = $this->decode($this->linkGoogle())['merge'];
        $merged = $this->decode($this->postJson($this->storeApi($this->website, '/me/merge'), ['token' => $offered['token']]))['customer'];

        self::assertSame(['other', ['Leila Ahmadi', true]], [$offered['keeps'], [$offered['account']['name'], $offered['account']['google']]]);
        self::assertSame([$leila->id, self::TELEGRAM_ID, 'leila@example.com', true], [$merged['id'], $merged['telegram']['id'], $merged['email'], $merged['google']], 'its own address stays');
    }

    public function testTheAccountWithTheAddressGoogleVouchesForIsAMergeOffered(): void
    {
        $this->googleLogin();
        Carbon::setTestNow('2026-09-01 10:00:00');
        $sara = $this->webCustomer(['email' => FakeGoogleLogin::EMAIL]);
        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->bearer($this->customerSession($this->customer()));

        $offered = $this->linkGoogle();

        self::assertSame(202, $offered->getStatusCode(), (string) $offered->getBody());
        $merged = $this->decode($this->postJson($this->storeApi($this->website, '/me/merge'), ['token' => $this->decode($offered)['merge']['token']]))['customer'];
        self::assertSame([$sara->id, self::TELEGRAM_ID, true, true], [$merged['id'], $merged['telegram']['id'], $merged['google'], $merged['has_password']]);
        self::assertSame(FakeGoogleLogin::SUB, $sara->refresh()->google_sub, 'the Google account being added, its own once merged');
    }

    public function testAnAddressGoogleVouchesForIsNoWayPastTheOtherAccountsSecondStep(): void
    {
        $this->googleLogin();
        $sara = $this->webCustomer(['email' => FakeGoogleLogin::EMAIL]);
        $this->twoFactorOn($this->website, $sara);
        $this->bearer($this->customerSession($this->customer()));

        self::assertSame([422, AccountRefusedException::TWO_FACTOR_ACCOUNT], $this->message($this->linkGoogle()), 'the address alone is all a password reset asks');
        self::assertNull($sara->refresh()->google_sub);
        self::assertNull(User::query()->where('telegram_id', self::TELEGRAM_ID)->sole()->google_sub);
        self::assertSame(2, User::query()->count());
    }

    public function testAnAddressGoogleIsNotTheAuthorityOfIsNeitherAddedNorAMergeOffered(): void
    {
        $this->googleLogin();
        $sara = $this->webCustomer(['email' => 'sara@company.example']);
        $ali = $this->customer();
        $this->bearer($this->customerSession($ali));
        $nonce = $this->nonce();

        $linked = $this->postJson($this->storeApi($this->website, '/me/identities/google'), ['id_token' => FakeGoogleLogin::idToken(FakeGoogleLogin::claims(self::GOOGLE_CLIENT_ID, $nonce, ['email' => 'sara@company.example'])), 'nonce' => $nonce]);

        self::assertSame(200, $linked->getStatusCode(), (string) $linked->getBody());
        self::assertSame([true, null], [$this->decode($linked)['customer']['google'], $this->decode($linked)['customer']['email']], 'the Google account added; its address — another domain\'s, verified once — not');
        self::assertSame([2, null], [User::query()->count(), $sara->refresh()->google_sub], 'nor a merge offered with the account that has it');
    }

    public function testAMergeThatSetsThePasswordEndsTheOtherSessionsOfTheAccountThatStays(): void
    {
        $mail = $this->mail();
        Carbon::setTestNow('2026-09-01 10:00:00');
        $sara = $this->webCustomer();
        Carbon::setTestNow('2026-10-07 12:00:00');
        $elsewhere = $this->customerSession($sara);
        $this->bearer($asking = $this->customerSession($this->customer()));
        $offer = $this->decode($this->linkEmail($mail, self::WEB_EMAIL))['merge'];

        self::assertSame(200, $this->postJson($this->storeApi($this->website, '/me/merge'), ['token' => $offer['token']])->getStatusCode());

        self::assertTrue(password_verify('a-new-secret', (string) $sara->refresh()->password_hash), 'the password chosen with the address');
        $this->bearer($elsewhere);
        self::assertSame(401, $this->get($this->storeApi($this->website, '/me'))->getStatusCode(), 'a new password ends every other session of the account, as any does');
        $this->bearer($asking);
        self::assertSame($sara->id, $this->decode($this->get($this->storeApi($this->website, '/me')))['customer']['id'], 'this one stays, the account that stays its own');
    }

    public function testAnotherCustomersCodeForTheSameAddressLeavesTheirsStanding(): void
    {
        $mail = $this->mail();
        $aliToken = $this->customerSession($this->customer());
        $this->bearer($aliToken);
        $this->postJson($this->storeApi($this->website, '/me/identities/email'), ['email' => 'new@example.com', 'password' => 'ali-secret-1']);
        $code = RecordingMailTransport::codeIn($mail->to('new@example.com')[0]);

        Carbon::setTestNow(now()->addMinutes(2));
        $this->bearer($this->customerSession($this->customer(['telegram_id' => 5152])));
        self::assertSame(202, $this->postJson($this->storeApi($this->website, '/me/identities/email'), ['email' => 'new@example.com', 'password' => 'leila-secret-1'])->getStatusCode());

        $this->bearer($aliToken);
        self::assertSame(200, $this->postJson($this->storeApi($this->website, '/me/identities/email/verify'), ['email' => 'new@example.com', 'code' => $code])->getStatusCode(), "another customer asking for the address cancels no code of Ali's");
    }

    public function testAMergeTheRulesRefuseIsSaidAtTheOffer(): void
    {
        $this->googleLogin();
        $this->webCustomer(['email' => FakeGoogleLogin::EMAIL]);
        $this->bearer($this->customerSession($this->customer(['email' => 'ali@example.com'])));

        $refused = $this->linkGoogle();

        self::assertSame([422, 'هر دو حساب به یک نوع روش ورود متفاوت وصل هستند (ایمیل)؛ اول یکی را جدا کنید.'], $this->message($refused));
        self::assertNull(User::query()->where('telegram_id', self::TELEGRAM_ID)->sole()->google_sub);
    }

    public function testAnEmailIsAddedWithTheCodeItsAddressGot(): void
    {
        $mail = $this->mail();
        $ali = $this->customer();
        $this->bearer($this->customerSession($ali));

        $sent = $this->postJson($this->storeApi($this->website, '/me/identities/email'), ['email' => ' New@Example.com ', 'password' => 'ali-secret-1']);

        self::assertSame([202, ['expires_in' => AuthChallenges::CODE_SECONDS]], [$sent->getStatusCode(), $this->decode($sent)]);
        [$email] = $mail->to('new@example.com');
        self::assertSame('کد تایید ایمیل حساب AmoBot', $email->getSubject());
        $verified = $this->postJson($this->storeApi($this->website, '/me/identities/email/verify'), ['email' => 'new@example.com', 'code' => RecordingMailTransport::codeIn($email)]);

        self::assertSame(200, $verified->getStatusCode(), (string) $verified->getBody());
        self::assertSame(['new@example.com', true], [$this->decode($verified)['customer']['email'], $this->decode($verified)['customer']['has_password']]);
        $this->bearer(null);
        $login = $this->postJson($this->storeApi($this->website, '/auth/login'), ['email' => 'new@example.com', 'password' => 'ali-secret-1']);
        self::assertSame($ali->id, $this->decode($login)['customer']['id'], 'it signs in with them now');
    }

    public function testAnAccountWithAnEmailAddsNone(): void
    {
        $mail = $this->mail();
        $this->bearer($this->customerSession($this->webCustomer()));

        self::assertSame([422, AccountRefusedException::HAS_EMAIL], $this->message($this->postJson($this->storeApi($this->website, '/me/identities/email'), ['email' => 'other@example.com', 'password' => 'a-new-secret'])));
        self::assertSame([], $mail->sent());
    }

    public function testTheAddressOfAnotherAccountIsProvenFirstThenAMergeOffered(): void
    {
        $mail = $this->mail();
        Carbon::setTestNow('2026-09-01 10:00:00');
        $sara = $this->webCustomer();
        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->bearer($this->customerSession($this->customer()));

        $sent = $this->postJson($this->storeApi($this->website, '/me/identities/email'), ['email' => self::WEB_EMAIL, 'password' => 'a-new-secret']);
        self::assertSame(202, $sent->getStatusCode(), 'the same answer: it is proven before anything is said of it');
        $offered = $this->postJson($this->storeApi($this->website, '/me/identities/email/verify'), ['email' => self::WEB_EMAIL, 'code' => RecordingMailTransport::codeIn($mail->to(self::WEB_EMAIL)[0])]);

        self::assertSame(202, $offered->getStatusCode(), (string) $offered->getBody());
        self::assertSame(['other', true], [$this->decode($offered)['merge']['keeps'], $this->decode($offered)['merge']['account']['email']]);
        $merged = $this->decode($this->postJson($this->storeApi($this->website, '/me/merge'), ['token' => $this->decode($offered)['merge']['token']]))['customer'];
        self::assertSame([$sara->id, self::TELEGRAM_ID, self::WEB_EMAIL], [$merged['id'], $merged['telegram']['id'], $merged['email']]);
        self::assertTrue(password_verify('a-new-secret', (string) $sara->refresh()->password_hash), 'the password chosen with the address');
    }

    public function testAnEmailedCodeIsNoWayPastTheOtherAccountsSecondStep(): void
    {
        $mail = $this->mail();
        Carbon::setTestNow('2026-09-01 10:00:00');
        $sara = $this->webCustomer();
        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->bearer($this->customerSession($this->customer()));
        $offered = $this->decode($this->linkEmail($mail, self::WEB_EMAIL))['merge'];

        $this->twoFactorOn($this->website, $sara);

        self::assertSame([422, AccountRefusedException::TWO_FACTOR_ACCOUNT], $this->message($this->postJson($this->storeApi($this->website, '/me/merge'), ['token' => $offered['token']])), 'turned on since the offer');
        Carbon::setTestNow(now()->addMinutes(2));
        self::assertSame([422, AccountRefusedException::TWO_FACTOR_ACCOUNT], $this->message($this->linkEmail($mail, self::WEB_EMAIL)), 'nor offered: the code is all a password reset asks, which stops at the second step');
        self::assertSame(2, User::query()->count());
        self::assertTrue(password_verify(self::WEB_PASSWORD, (string) $sara->refresh()->password_hash), 'its password as it was');
    }

    public function testACodeIsTheAccountsItWasSentFor(): void
    {
        $mail = $this->mail();
        $aliToken = $this->customerSession($this->customer());
        $this->bearer($aliToken);
        $this->postJson($this->storeApi($this->website, '/me/identities/email'), ['email' => 'new@example.com', 'password' => 'ali-secret-1']);
        $code = RecordingMailTransport::codeIn($mail->to('new@example.com')[0]);

        $this->bearer($this->customerSession($this->customer(['telegram_id' => 5152])));
        $other = $this->postJson($this->storeApi($this->website, '/me/identities/email/verify'), ['email' => 'new@example.com', 'code' => $code]);
        self::assertSame([422, ['code' => [EmailSignIn::WRONG_CODE]]], [$other->getStatusCode(), $this->decode($other)['errors']]);

        $this->bearer($aliToken);
        self::assertSame(200, $this->postJson($this->storeApi($this->website, '/me/identities/email/verify'), ['email' => 'new@example.com', 'code' => $code])->getStatusCode());
    }

    public function testAWayInGoesButNeverTheLast(): void
    {
        $sara = $this->webCustomer(['google_sub' => 'sara-google']);
        $this->twoFactorOn($this->website, $sara);
        $this->bearer($this->customerSession($sara));

        self::assertSame([422, AccountRefusedException::NOT_LINKED], $this->message($this->deleteJson($this->storeApi($this->website, '/me/identities/telegram'))));

        $email = $this->deleteJson($this->storeApi($this->website, '/me/identities/email'));

        self::assertSame(200, $email->getStatusCode(), (string) $email->getBody());
        $customer = $this->decode($email)['customer'];
        self::assertSame([null, false, false, true], [$customer['email'], $customer['has_password'], $customer['two_factor'], $customer['google']], 'the email goes with its password and two-factor sign-in');
        self::assertNull($sara->refresh()->totp_secret);
        self::assertSame([422, AccountRefusedException::LAST_WAY_IN], $this->message($this->deleteJson($this->storeApi($this->website, '/me/identities/google'))));
        self::assertSame('sara-google', $sara->refresh()->google_sub);
    }

    public function testATelegramAccountGoesWithItsHandle(): void
    {
        $ali = $this->customer(['username' => 'ali', 'email' => 'ali@example.com', 'bot_blocked' => true]);
        $this->bearer($this->customerSession($ali));

        $removed = $this->deleteJson($this->storeApi($this->website, '/me/identities/telegram'));

        self::assertSame(200, $removed->getStatusCode(), (string) $removed->getBody());
        self::assertNull($this->decode($removed)['customer']['telegram']);
        $ali->refresh();
        self::assertSame([null, null, false, 'Ali'], [$ali->telegram_id, $ali->username, $ali->bot_blocked, $ali->first_name]);
    }

    private function linkTelegram(): ResponseInterface
    {
        $nonce = $this->nonce();

        return $this->postJson($this->storeApi($this->website, '/me/identities/telegram'), ['id_token' => FakeTelegramLogin::idToken(FakeTelegramLogin::claims(self::WEBSITE_CLIENT_ID, $nonce)), 'nonce' => $nonce]);
    }

    private function linkGoogle(): ResponseInterface
    {
        $nonce = $this->nonce();

        return $this->postJson($this->storeApi($this->website, '/me/identities/google'), ['id_token' => FakeGoogleLogin::idToken(FakeGoogleLogin::claims(self::GOOGLE_CLIENT_ID, $nonce)), 'nonce' => $nonce]);
    }

    /** An address added to the signed-in account: its code sent, then typed back from the last email it got. */
    private function linkEmail(RecordingMailTransport $mail, string $address): ResponseInterface
    {
        $this->postJson($this->storeApi($this->website, '/me/identities/email'), ['email' => $address, 'password' => 'a-new-secret']);
        $sent = $mail->to($address);

        return $this->postJson($this->storeApi($this->website, '/me/identities/email/verify'), ['email' => $address, 'code' => RecordingMailTransport::codeIn($sent[array_key_last($sent)])]);
    }

    private function nonce(): string
    {
        return $this->decode($this->postJson($this->storeApi($this->website, '/auth/nonce')))['nonce'];
    }

    /**
     * Telegram's redirect sign-in begun at `$path` — a sign-in's (/auth/telegram/authorize), or the signed-in customer's
     * own (/me/telegram/authorize) —: the state and the nonce its address carries.
     *
     * @return array{state: string, nonce: string}
     */
    private function authorize(string $path): array
    {
        $address = $this->decode($this->postJson($this->storeApi($this->website, $path), ['redirect_uri' => 'https://shop.example/account', 'code_challenge' => JWT::urlsafeB64Encode(hash('sha256', self::VERIFIER, true))]))['url'];
        parse_str((string) parse_url($address, PHP_URL_QUERY), $query);

        return ['state' => (string) $query['state'], 'nonce' => (string) $query['nonce']];
    }

    /** @return array{int, mixed} The status, and its message */
    private function message(ResponseInterface $response): array
    {
        return [$response->getStatusCode(), $this->decode($response)['message'] ?? null];
    }
}
