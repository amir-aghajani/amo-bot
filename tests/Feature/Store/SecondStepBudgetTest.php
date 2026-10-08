<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Security\Totp;
use App\Modules\Accounts\Enums\ChallengePurpose;
use App\Modules\Accounts\Services\AuthChallenges;
use App\Modules\Accounts\Services\TwoFactor;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Store\Models\Website;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;

/**
 * Whoever has an account's password may guess its second step's codes no further than a budget of its own: ten wrong
 * codes an hour and twenty a day, whatever challenges and addresses they come by — each counted before it is judged, so
 * codes sent at the same moment cannot pass it. Spent, the next is a 429 whatever it is, and the customer is told — once a
 * day: someone has their password. A code that opens gives its room back; the code a password proven again asks counts
 * against the same budget.
 */
final class SecondStepBudgetTest extends HttpTestCase
{
    private Website $website;

    private User $sara;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->telegram();
        $this->website = $this->website();
        $this->sara = $this->webCustomer();
        $this->secret = $this->twoFactorOn($this->website, $this->sara)['secret'];
        Carbon::setTestNow(now()->addSeconds(30));
    }

    public function testTenWrongCodesAnHourThenTheAccountWaitsItsCustomerToldOnce(): void
    {
        for ($try = 1; $try <= SignInThrottle::SECOND_STEP_HOURLY; $try++) {
            self::assertSame(422, $this->secondStep($this->wrong())->getStatusCode(), "wrong code {$try}");
        }

        $refused = $this->secondStep(Totp::code($this->secret));

        self::assertSame(429, $refused->getStatusCode(), 'the right code even: the budget is spent');
        self::assertEqualsWithDelta(3600, (int) $refused->getHeaderLine('Retry-After'), 5, 'until the hour is over');
        self::assertSame(1, $this->told(), 'the customer told: someone has their password');
        self::assertSame(429, $this->secondStep(Totp::code($this->secret))->getStatusCode());
        self::assertSame(1, $this->told(), 'once');

        Carbon::setTestNow(now()->addHour());
        self::assertSame(200, $this->secondStep(Totp::code($this->secret))->getStatusCode(), 'an hour on, it may try again');
    }

    public function testTwentyWrongCodesADayThenTheAccountWaitsTheDayOut(): void
    {
        for ($try = 1; $try <= SignInThrottle::SECOND_STEP_DAILY; $try++) {
            if ($try === SignInThrottle::SECOND_STEP_HOURLY + 1) {
                Carbon::setTestNow(now()->addHour());
            }
            self::assertSame(422, $this->secondStep($this->wrong())->getStatusCode(), "wrong code {$try}");
        }
        Carbon::setTestNow(now()->addHour());

        $refused = $this->secondStep(Totp::code($this->secret));

        self::assertSame(429, $refused->getStatusCode(), 'twenty in a day');
        self::assertGreaterThan(3600, (int) $refused->getHeaderLine('Retry-After'), "the day's to wait out");
        self::assertSame(1, $this->told());
        Carbon::setTestNow(now()->addDay());
        self::assertSame(200, $this->secondStep(Totp::code($this->secret))->getStatusCode(), 'the next day');
    }

    public function testCodesBeingTriedAtOnceAreCountedBeforeTheyAreJudged(): void
    {
        // Ten codes tried in the same moment from as many addresses: each counted as it arrived, none judged yet.
        $throttle = $this->service(SignInThrottle::class);
        for ($try = 1; $try <= SignInThrottle::SECOND_STEP_HOURLY; $try++) {
            $throttle->secondStep($this->sara->id, static fn() => null);
        }

        self::assertSame(429, $this->secondStep(Totp::code($this->secret))->getStatusCode(), 'an eleventh is not judged at all');

        // One of them was right: it gives its room back.
        $throttle->secondStepOpened($this->sara->id);
        self::assertSame(200, $this->secondStep(Totp::code($this->secret))->getStatusCode());
    }

    public function testACodeThatOpensGivesItsRoomBack(): void
    {
        for ($sign = 1; $sign <= SignInThrottle::SECOND_STEP_HOURLY + 2; $sign++) {
            self::assertSame(200, $this->secondStep(Totp::code($this->secret))->getStatusCode(), "sign-in {$sign}: right codes spend none of it");
            Carbon::setTestNow(now()->addSeconds(30));
        }
        self::assertSame(0, $this->told());
    }

    public function testTheCodeAPasswordProvenAgainAsksCountsAgainstTheSameBudget(): void
    {
        $this->bearer($this->customerSession($this->sara));
        Carbon::setTestNow(now()->addHour());
        for ($try = 1; $try <= SignInThrottle::SECOND_STEP_HOURLY; $try++) {
            self::assertSame(422, $this->postJson($this->storeApi($this->website, '/me/reauthenticate'), ['method' => 'password', 'password' => self::WEB_PASSWORD, 'code' => $this->wrong()])->getStatusCode(), "wrong code {$try}");
        }
        $this->bearer(null);

        self::assertSame(429, $this->secondStep(Totp::code($this->secret))->getStatusCode(), "the sign-in's second step waits too");
    }

    /** A password sign-in's second step for Sara: a challenge of her own, and `$code`. */
    private function secondStep(string $code): ResponseInterface
    {
        $challenge = $this->service(AuthChallenges::class)->issue(ChallengePurpose::TwoFactor, null, $this->sara, [], TwoFactor::CHALLENGE_SECONDS);

        return $this->postJson($this->storeApi($this->website, '/auth/login/2fa'), ['challenge' => $challenge, 'code' => $code]);
    }

    /** A code of the app's shape that is not the one it shows now. */
    private function wrong(): string
    {
        return Totp::code($this->secret) === '000000' ? '111111' : '000000';
    }

    /** How many times the customer was told someone failed their second step until it waits. */
    private function told(): int
    {
        return Notification::query()->where('type', NoticeType::SecondStepLocked->value)->count();
    }
}
