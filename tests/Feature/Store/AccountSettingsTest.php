<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Accounts\Exceptions\AccountRefusedException;
use App\Modules\Accounts\Services\AccountNames;
use App\Modules\Accounts\Services\EmailSignIn;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Store\Models\Website;
use App\Support\Password;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;
use Tests\Support\FakeGoogleLogin;

/**
 * A signed-in customer's own account on the shop's website: their names — theirs to set, unless Telegram's are theirs
 * —, and their password, set for an account with an email and none, or changed with the one it has (a wrong one counted
 * as a failed sign-in): every other session of theirs ends, this one stays.
 */
final class AccountSettingsTest extends HttpTestCase
{
    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->website = $this->website();
    }

    public function testAWebsiteCustomerSetsTheirNames(): void
    {
        $sara = $this->webCustomer();
        $this->bearer($this->customerSession($sara));

        $renamed = $this->patchJson($this->storeApi($this->website, '/me'), ['first_name' => ' Sarah ', 'last_name' => '']);

        self::assertSame(200, $renamed->getStatusCode(), (string) $renamed->getBody());
        self::assertSame(['Sarah', null], [$this->decode($renamed)['customer']['first_name'], $this->decode($renamed)['customer']['last_name']]);
        self::assertSame(array_keys($this->decode($this->get($this->storeApi($this->website, '/me')))), array_keys($this->decode($renamed)), 'answered as GET /me answers');
        self::assertSame(['Sarah', null], [$sara->refresh()->first_name, $sara->last_name]);

        $last = $this->patchJson($this->storeApi($this->website, '/me'), ['last_name' => 'Karimi']);
        self::assertSame(['Sarah', 'Karimi'], [$this->decode($last)['customer']['first_name'], $this->decode($last)['customer']['last_name']], 'a field left out stays');
        self::assertSame(200, $this->patchJson($this->storeApi($this->website, '/me'), [])->getStatusCode(), 'nothing sent, nothing changed');
    }

    public function testANameIsHeldToTheFormsRule(): void
    {
        $this->bearer($this->customerSession($this->webCustomer()));

        $refused = $this->patchJson($this->storeApi($this->website, '/me'), ['first_name' => "\u{200C}", 'last_name' => str_repeat('ن', AccountNames::MAX + 1)]);

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['first_name' => ['نام را وارد کنید.'], 'last_name' => ['نام خانوادگی حداکثر 64 کاراکتر است.']], $this->decode($refused)['errors']);
        self::assertSame(422, $this->patchJson($this->storeApi($this->website, '/me'), ['first_name' => str_repeat('a', AccountNames::MAX + 1)])->getStatusCode());
    }

    public function testATelegramAccountsNamesAreTelegrams(): void
    {
        $ali = $this->customer(['email' => 'ali@example.com']);
        $this->bearer($this->customerSession($ali));

        $refused = $this->patchJson($this->storeApi($this->website, '/me'), ['first_name' => 'Alireza', 'last_name' => 'K']);

        self::assertSame([422, ['first_name' => [AccountNames::FROM_TELEGRAM], 'last_name' => [AccountNames::FROM_TELEGRAM]]], [$refused->getStatusCode(), $this->decode($refused)['errors']]);
        self::assertSame('Ali', $ali->refresh()->first_name);
    }

    public function testAnAccountWithAnEmailAndNoPasswordSetsOne(): void
    {
        $ali = $this->webCustomer(['email' => FakeGoogleLogin::EMAIL, 'password_hash' => null, 'google_sub' => FakeGoogleLogin::SUB]);
        $this->bearer($this->customerSession($ali));

        $set = $this->putJson($this->storeApi($this->website, '/me/password'), ['password' => 'a-new-secret']);

        self::assertSame(200, $set->getStatusCode(), (string) $set->getBody());
        self::assertTrue($this->decode($set)['customer']['has_password']);
        $this->bearer(null);
        self::assertSame(200, $this->postJson($this->storeApi($this->website, '/auth/login'), ['email' => FakeGoogleLogin::EMAIL, 'password' => 'a-new-secret'])->getStatusCode(), 'it signs in with the email now');
    }

    public function testAPasswordIsChangedWithTheOneItHasAndEveryOtherSessionEnds(): void
    {
        $sara = $this->webCustomer();
        $elsewhere = $this->customerSession($sara);
        $this->bearer($this->customerSession($sara));

        $blank = $this->putJson($this->storeApi($this->website, '/me/password'), ['password' => 'short']);
        self::assertSame(['password' => ['رمز عبور دست‌کم 8 کاراکتر است.'], 'current_password' => ['رمز عبور فعلی را وارد کنید.']], $this->decode($blank)['errors'], 'every refusal at once — the one password rule');
        $wrong = $this->putJson($this->storeApi($this->website, '/me/password'), ['current_password' => 'not-it', 'password' => 'a-new-secret']);
        self::assertSame([422, ['current_password' => [EmailSignIn::WRONG_PASSWORD]]], [$wrong->getStatusCode(), $this->decode($wrong)['errors']]);

        $changed = $this->putJson($this->storeApi($this->website, '/me/password'), ['current_password' => self::WEB_PASSWORD, 'password' => 'a-new-secret']);

        self::assertSame(200, $changed->getStatusCode(), (string) $changed->getBody());
        self::assertTrue(password_verify('a-new-secret', (string) $sara->refresh()->password_hash));
        self::assertSame(200, $this->get($this->storeApi($this->website, '/me'))->getStatusCode(), 'this session stays');
        $this->bearer($elsewhere);
        self::assertSame(401, $this->get($this->storeApi($this->website, '/me'))->getStatusCode(), 'every other one ended');
    }

    public function testAWrongCurrentPasswordCountsAsAFailedSignIn(): void
    {
        $this->bearer($this->customerSession($this->webCustomer()));

        for ($i = 1; $i <= SignInThrottle::WEBSITE_ATTEMPTS; $i++) {
            $this->putJson($this->storeApi($this->website, '/me/password'), ['current_password' => "guess-{$i}", 'password' => 'a-new-secret']);
        }

        self::assertSame(429, $this->putJson($this->storeApi($this->website, '/me/password'), ['current_password' => self::WEB_PASSWORD, 'password' => 'a-new-secret'])->getStatusCode());
    }

    public function testAPasswordWithANulByteIsRefused(): void
    {
        $this->bearer($this->customerSession($this->webCustomer()));

        $refused = $this->putJson($this->storeApi($this->website, '/me/password'), ['current_password' => self::WEB_PASSWORD, 'password' => "a-new-secret\0and-the-rest"]);

        self::assertSame([422, ['password' => [Password::NUL]]], [$refused->getStatusCode(), $this->decode($refused)['errors']], 'bcrypt would read it up to the NUL alone');
    }

    public function testAnAccountWithoutAnEmailHasNoPasswordToSet(): void
    {
        $this->bearer($this->customerSession($this->customer()));

        $refused = $this->putJson($this->storeApi($this->website, '/me/password'), ['password' => 'a-new-secret']);

        self::assertSame([422, AccountRefusedException::NEEDS_EMAIL], [$refused->getStatusCode(), $this->decode($refused)['message']]);
    }

    public function testTheirAccountSaysWhetherItsPasswordSignInAsksASecondStep(): void
    {
        $sara = $this->webCustomer();
        $this->bearer($this->customerSession($sara));
        self::assertFalse($this->me()['two_factor']);

        $this->twoFactorOn($this->website, $sara);

        self::assertTrue($this->me()['two_factor']);
    }

    /** @return array<string, mixed> GET /me's customer */
    private function me(): array
    {
        $response = $this->get($this->storeApi($this->website, '/me'));

        return $this->decode($response)['customer'] ?? self::fail((string) $response->getBody());
    }
}
