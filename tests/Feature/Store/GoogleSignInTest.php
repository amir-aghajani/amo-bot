<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Accounts\Models\CustomerSession;
use App\Modules\Auth\Exceptions\SignInRefusedException;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Referrals\Services\ReferralService;
use App\Modules\Store\Models\Website;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;
use Tests\Support\FakeGoogleLogin;

/**
 * Signing in on the shop's website with a Google account: Google Identity Services hands the page an id_token for a
 * nonce the shop gave the site, checked the one way — Google's keys, either spelling of its issuer, the website's client
 * id, the nonce spent once. The customer is the shop's with that Google account; else the one with the address Google
 * speaks for — verified, and an @gmail.com address or a Workspace account's — (which takes the Google account on, its
 * customer told; not one with two-factor sign-in on, nor one another Google account signs in to: a 409); else a
 * newcomer — the address theirs only when Google speaks for it. A banned customer is refused; Google sign-in off is a
 * 422; Google out of reach a 502 that counts for nothing.
 */
final class GoogleSignInTest extends HttpTestCase
{
    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->telegram();
        $this->googleLogin();
        $this->website = $this->website(['google_client_id' => self::GOOGLE_CLIENT_ID]);
    }

    public function testANewcomerIsRegisteredWithTheAddressGoogleVouchesFor(): void
    {
        $this->reportGroup();
        $this->referralProgram();
        $referrer = $this->customer(['telegram_id' => 7070]);

        $response = $this->signIn([], ['referral_code' => $this->service(ReferralService::class)->codeFor($referrer)]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $ali = User::query()->where('google_sub', FakeGoogleLogin::SUB)->sole();
        self::assertSame([
            'id' => $ali->id,
            'first_name' => 'Ali',
            'last_name' => 'Rezaei',
            'telegram' => null,
            'email' => FakeGoogleLogin::EMAIL,
            'google' => true,
            'has_password' => false,
            'two_factor' => false,
            'phone' => null,
            'balance' => '0.00',
            'created_at' => '2026-10-07T12:00:00+00:00',
        ], $this->decode($response)['customer']);
        self::assertSame($referrer->id, $ali->referred_by);
        self::assertSame(1, ReportMessage::query()->where('topic', Topic::Users)->count(), 'reported, as every newcomer is');
        self::assertSame(['GET https://www.googleapis.com/oauth2/v3/certs'], $this->googleLogin()->calls());

        self::assertSame($ali->id, $this->decode($this->signIn())['customer']['id'], 'signed in again: the same account, by its Google account');
        self::assertSame(2, User::query()->count());
    }

    public function testTheAccountWithTheAddressGoogleVouchesForTakesTheGoogleAccountOn(): void
    {
        $sara = $this->webCustomer(['email' => FakeGoogleLogin::EMAIL]);

        $response = $this->signIn();

        $customer = $this->decode($response)['customer'];
        self::assertSame([$sara->id, 'Sara', true, true], [$customer['id'], $customer['first_name'], $customer['google'], $customer['has_password']], 'their account as it was, Google one more way in');
        self::assertSame(FakeGoogleLogin::SUB, $sara->refresh()->google_sub, 'Google proved the address theirs');
        self::assertSame(1, User::query()->count());
    }

    public function testAnAddressGoogleDoesNotVouchForIsNeitherFollowedNorKept(): void
    {
        $sara = $this->webCustomer(['email' => FakeGoogleLogin::EMAIL]);

        foreach ([false, 'false', null] as $verified) {
            $response = $this->signIn(['email_verified' => $verified, 'sub' => 'unverified-' . var_export($verified, true)]);

            $customer = $this->decode($response)['customer'];
            self::assertNotSame($sara->id, $customer['id'], 'not the account with that address');
            self::assertNull($customer['email'], 'nor the address kept for the newcomer');
        }
        self::assertNull($sara->refresh()->google_sub);
        self::assertSame(FakeGoogleLogin::EMAIL, $this->decode($this->signIn(['email_verified' => 'true', 'sub' => 'vouched-as-text']))['customer']['email'], '"true" as text vouches as well');
    }

    public function testAnAccountWithTwoFactorSignInIsNotSignedInToByItsAddressAlone(): void
    {
        $sara = $this->webCustomer(['email' => FakeGoogleLogin::EMAIL]);
        $this->twoFactorOn($this->website, $sara);

        $refused = $this->signIn();

        self::assertSame([409, SignInRefusedException::GOOGLE_ACCOUNT_EXISTS], [$refused->getStatusCode(), $this->decode($refused)['message']], 'the address is all a password reset asks, and a reset stops at the second step');
        self::assertNull($sara->refresh()->google_sub, 'not taken on by the address');
        self::assertSame(1, User::query()->count(), 'nor another account made with it');
        self::assertSame(0, CustomerSession::query()->count());

        // Taken on from her account's settings, the Google account signs her in itself.
        $sara->forceFill(['google_sub' => FakeGoogleLogin::SUB])->save();
        self::assertSame($sara->id, $this->decode($this->signIn())['customer']['id']);
    }

    public function testAnAccountWithAnotherGoogleAccountIsNotSignedInToByItsAddress(): void
    {
        $sara = $this->webCustomer(['email' => FakeGoogleLogin::EMAIL, 'google_sub' => 'her-first-google-account']);

        $refused = $this->signIn();

        self::assertSame([409, SignInRefusedException::GOOGLE_ACCOUNT_EXISTS], [$refused->getStatusCode(), $this->decode($refused)['message']], 'her own way in signs her in, then she adds this Google account from her settings');
        self::assertSame('her-first-google-account', $sara->refresh()->google_sub, 'her own Google account stays the one kept');
        self::assertSame([1, 0], [User::query()->count(), CustomerSession::query()->count()]);
    }

    public function testOnlyAnAddressGoogleIsTheAuthorityOfFindsOrIsKept(): void
    {
        $sara = $this->webCustomer(['email' => 'sara@company.example']);

        $foreign = $this->signIn(['email' => 'Sara@Company.example', 'sub' => 'google-of-another-domain']);

        self::assertSame(200, $foreign->getStatusCode(), (string) $foreign->getBody());
        self::assertNotSame($sara->id, $this->decode($foreign)['customer']['id'], 'an address of another domain Google verified once finds no account: its owner today may be another');
        self::assertNull($this->decode($foreign)['customer']['email'], 'nor is it kept for the newcomer');
        self::assertNull($sara->refresh()->google_sub, 'nor taken on');

        $workspace = $this->signIn(['email' => 'sara@company.example', 'hd' => 'company.example', 'sub' => 'google-workspace-sara']);

        self::assertSame($sara->id, $this->decode($workspace)['customer']['id'], "a Workspace account's address — Google is its domain's authority — finds hers");
        self::assertSame('google-workspace-sara', $sara->refresh()->google_sub);
        self::assertSame(FakeGoogleLogin::EMAIL, $this->decode($this->signIn())['customer']['email'], 'and an @gmail.com address is kept for a newcomer');
    }

    public function testAnAccountItsAddressTakesTheGoogleAccountOnIsTold(): void
    {
        $ali = $this->customer(['email' => FakeGoogleLogin::EMAIL]);

        self::assertSame($ali->id, $this->decode($this->signIn())['customer']['id']);

        self::assertSame([self::text(BotText::WayInAdded, ['way' => 'گوگل'])], $this->telegram()->sentTo(self::TELEGRAM_ID), 'one more way into their account: told on every door it has');
        self::assertSame(1, Notification::query()->where('type', NoticeType::WayInAdded->value)->count());
        $this->signIn();
        self::assertCount(1, $this->telegram()->sentTo(self::TELEGRAM_ID), 'once: the next sign-in is by the Google account itself');
    }

    public function testATokenForAnotherSiteOrNonceSignsNobodyIn(): void
    {
        $other = $this->signIn(['aud' => '999-another.apps.googleusercontent.com']);
        self::assertSame([401, SignInRefusedException::TOKEN_ELSEWHERE], [$other->getStatusCode(), $this->decode($other)['message']]);

        $telegrams = $this->signIn(['iss' => 'https://oauth.telegram.org']);
        self::assertSame([401, SignInRefusedException::TOKEN_ELSEWHERE], [$telegrams->getStatusCode(), $this->decode($telegrams)['message']], "another provider's issuer");

        $nonce = $this->nonce();
        $twice = ['id_token' => FakeGoogleLogin::idToken(FakeGoogleLogin::claims(self::GOOGLE_CLIENT_ID, $nonce)), 'nonce' => $nonce];
        self::assertSame(200, $this->postJson($this->storeApi($this->website, '/auth/google'), $twice)->getStatusCode());
        $spent = $this->postJson($this->storeApi($this->website, '/auth/google'), $twice);
        self::assertSame([401, SignInRefusedException::SIGN_IN_SPENT], [$spent->getStatusCode(), $this->decode($spent)['message']], 'a nonce is spent once');

        $asked = $this->nonce();
        $mismatch = $this->postJson($this->storeApi($this->website, '/auth/google'), ['id_token' => FakeGoogleLogin::idToken(FakeGoogleLogin::claims(self::GOOGLE_CLIENT_ID, 'another-nonce')), 'nonce' => $asked]);
        self::assertSame([401, SignInRefusedException::SIGN_IN_SPENT], [$mismatch->getStatusCode(), $this->decode($mismatch)['message']], 'the token was signed for another');

        $noAccount = $this->signIn(['sub' => null]);
        self::assertSame([401, SignInRefusedException::TOKEN_INVALID], [$noAccount->getStatusCode(), $this->decode($noAccount)['message']], 'no Google account named');
        self::assertSame(1, User::query()->count());
    }

    public function testGoogleSignsInUnderEitherSpellingOfItsIssuer(): void
    {
        self::assertSame(200, $this->signIn(['iss' => 'accounts.google.com'])->getStatusCode());
    }

    public function testABannedCustomerIsRefused(): void
    {
        $this->webCustomer(['google_sub' => FakeGoogleLogin::SUB, 'status' => UserStatus::Banned]);

        $response = $this->signIn();

        self::assertSame([403, SignInRefusedException::BANNED], [$response->getStatusCode(), $this->decode($response)['message']]);
        self::assertSame(0, CustomerSession::query()->count());
    }

    public function testGoogleSignInOffIsNoWayIn(): void
    {
        $nonce = $this->nonce();
        $this->website->forceFill(['google_client_id' => null])->save();

        $response = $this->postJson($this->storeApi($this->website, '/auth/google'), ['id_token' => FakeGoogleLogin::idToken(FakeGoogleLogin::claims(self::GOOGLE_CLIENT_ID, $nonce)), 'nonce' => $nonce]);

        self::assertSame([422, SignInRefusedException::GOOGLE_OFF], [$response->getStatusCode(), $this->decode($response)['message']]);
        self::assertSame([], $this->googleLogin()->calls(), 'Google is not even asked');
    }

    public function testGoogleOutOfReachIsA502ThatCountsForNothingAndAFailureCounts(): void
    {
        $this->googleLogin()->down();
        $logs = $this->logs();
        for ($i = 0; $i <= SignInThrottle::WEBSITE_ATTEMPTS; $i++) {
            $unreachable = $this->signIn();
            self::assertSame([502, SignInRefusedException::GOOGLE_UNREACHABLE], [$unreachable->getStatusCode(), $this->decode($unreachable)['message']], "try {$i}: no keys kept to check it with");
        }
        self::assertTrue($logs->hasWarningThatContains('could not ask Google'), "the owner's to look into");

        $this->googleLogin()->down(false);
        for ($i = 1; $i <= SignInThrottle::WEBSITE_ATTEMPTS; $i++) {
            self::assertSame(401, $this->signIn(['aud' => 'another'])->getStatusCode(), "failure {$i}: Google's silence was counted for nothing");
        }
        self::assertSame(429, $this->signIn()->getStatusCode(), 'the failures are');
    }

    public function testASignInWithoutGooglesTokenIsSaidUnderItsField(): void
    {
        $nonce = $this->nonce();
        $missing = $this->unchecked()->postJson($this->storeApi($this->website, '/auth/google'), ['nonce' => $nonce]);

        self::assertSame(422, $missing->getStatusCode());
        self::assertArrayHasKey('id_token', $this->decode($missing)['errors']);
    }

    /**
     * A sign-in with Google: a nonce asked for, a token of Google's for it — `$claims` over its defaults —, posted with
     * `$more`.
     *
     * @param array<string, mixed> $claims
     * @param array<string, string> $more
     */
    private function signIn(array $claims = [], array $more = []): ResponseInterface
    {
        $nonce = $this->nonce();

        return $this->postJson($this->storeApi($this->website, '/auth/google'), ['id_token' => FakeGoogleLogin::idToken(FakeGoogleLogin::claims(self::GOOGLE_CLIENT_ID, $nonce, $claims)), 'nonce' => $nonce] + $more);
    }

    private function nonce(): string
    {
        return $this->decode($this->postJson($this->storeApi($this->website, '/auth/nonce')))['nonce'];
    }
}
