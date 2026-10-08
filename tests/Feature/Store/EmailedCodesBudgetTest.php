<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Accounts\Services\AuthChallenges;
use App\Modules\Accounts\Services\EmailSignIn;
use App\Modules\Auth\Services\SignInThrottle;
use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Store\Models\Website;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\Fakes\RecordingMailTransport;
use Tests\HttpTestCase;

/**
 * Whoever asks codes for an address — a reset's, a sign-up's, an email being added to an account — guesses at them no
 * further than a budget of the address's own, however many codes they have sent: ten wrong codes an hour and twenty a day,
 * each counted before it is judged; spent, the next is a 429 whatever it is, no new code goes to the address until it has
 * room again, and the address's account is told — once a day. A code that opens gives its room back; a code of no six
 * digits takes none of it; an address without an account has its budget all the same, so a refusal gives none away;
 * and an account adding emails guesses at their codes from a budget of its own too.
 */
final class EmailedCodesBudgetTest extends HttpTestCase
{
    private Website $website;

    private RecordingMailTransport $mail;

    private User $sara;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->telegram();
        $this->mail = $this->mail();
        $this->website = $this->website();
        $this->sara = $this->webCustomer();
    }

    public function testTenWrongCodesAnHourThenTheAddressWaitsItsAccountToldOnce(): void
    {
        $code = $this->wrongCodes(SignInThrottle::CODE_FAILURES_HOURLY);

        $refused = $this->reset($code);

        self::assertSame(429, $refused->getStatusCode(), 'the right code even: the budget is spent');
        self::assertEqualsWithDelta(3600, (int) $refused->getHeaderLine('Retry-After'), 300, 'until the hour is over');
        self::assertSame(1, $this->told(), 'the account told: someone is guessing at its codes');
        self::assertSame(429, $this->reset($code)->getStatusCode());
        self::assertSame(1, $this->told(), 'once');
        self::assertTrue(password_verify(self::WEB_PASSWORD, (string) $this->sara->refresh()->password_hash), 'nothing changed');

        Carbon::setTestNow(now()->addSeconds(SignInThrottle::CODE_EVERY_SECONDS));
        $sent = count($this->mail->sent());
        self::assertSame(429, $this->forgot()->getStatusCode(), 'no new code goes to the address meanwhile');
        self::assertCount($sent, $this->mail->sent());

        Carbon::setTestNow(now()->addHour());
        self::assertSame(200, $this->reset($this->newCode())->getStatusCode(), 'an hour on');
    }

    public function testTwentyADayThenTheAddressWaitsUntilTheNextDay(): void
    {
        $this->wrongCodes(SignInThrottle::CODE_FAILURES_HOURLY);
        Carbon::setTestNow(now()->addHour());
        $this->wrongCodes(SignInThrottle::CODE_FAILURES_DAILY - SignInThrottle::CODE_FAILURES_HOURLY);
        Carbon::setTestNow(now()->addHour());

        self::assertSame(429, $this->forgot()->getStatusCode(), 'twenty wrong codes in a day: none goes until the next');

        Carbon::setTestNow(now()->addDay());
        self::assertSame(200, $this->reset($this->newCode())->getStatusCode());
    }

    public function testACodeThatOpensGivesItsRoomBack(): void
    {
        $code = $this->wrongCodes(SignInThrottle::CODE_FAILURES_HOURLY - 1);

        self::assertSame(200, $this->reset($code)->getStatusCode(), 'the right code, counted before it was judged…');
        $this->assertWrongCode($this->reset(self::wrong($this->newCode())), '…gave its room back: one more wrong code is still judged');
        self::assertSame(429, $this->reset('000000')->getStatusCode(), 'and the next is not');
        self::assertSame(1, $this->told());
    }

    public function testACodeOfNoSixDigitsSpendsNoneOfIt(): void
    {
        $code = $this->newCode();
        for ($try = 1; $try <= SignInThrottle::CODE_FAILURES_DAILY + 1; $try++) {
            $this->assertWrongCode($this->reset('not-a-code'), "try {$try}");
        }

        self::assertSame(200, $this->reset($code)->getStatusCode());
    }

    public function testAnAddressWithoutAnAccountHasItsBudgetAllTheSame(): void
    {
        for ($try = 1; $try <= SignInThrottle::CODE_FAILURES_HOURLY; $try++) {
            $this->assertWrongCode($this->reset('000000', 'nobody@example.com'), "wrong code {$try}");
        }

        self::assertSame(429, $this->reset('000000', 'nobody@example.com')->getStatusCode(), "refused as an account's address is: the refusal gives nothing away");
        self::assertSame(0, Notification::query()->count(), 'nobody to tell');
        self::assertSame(200, $this->reset($this->newCode())->getStatusCode(), "another address's budget is its own");
    }

    public function testAnAccountAddingEmailsGuessesFromABudgetOfItsOwn(): void
    {
        $this->bearer($this->customerSession($this->customer()));
        for ($try = 1; $try <= SignInThrottle::CODE_FAILURES_HOURLY; $try++) {
            $this->assertWrongCode($this->addEmail("guess{$try}@example.com"), "wrong code {$try}");
        }

        self::assertSame(429, $this->addEmail('another@example.com')->getStatusCode(), 'whatever address it guesses at next');
    }

    /**
     * `$count` wrong codes for Sara's address, a new code asked for before one is tried out — so a live one is left: it.
     */
    private function wrongCodes(int $count): string
    {
        $code = '';
        for ($try = 1; $try <= $count; $try++) {
            if (($try - 1) % (AuthChallenges::CODE_ATTEMPTS - 1) === 0) {
                $code = $this->newCode();
            }
            $this->assertWrongCode($this->reset(self::wrong($code)), "wrong code {$try}");
        }

        return $code;
    }

    /** A code of six digits that is not `$code`. */
    private static function wrong(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }

    /** A new reset code for Sara's address, a minute after the last one asked: the code. */
    private function newCode(): string
    {
        Carbon::setTestNow(now()->addSeconds(SignInThrottle::CODE_EVERY_SECONDS));
        self::assertSame(202, $this->forgot()->getStatusCode(), 'a code goes');
        $emails = $this->mail->to(self::WEB_EMAIL);

        return RecordingMailTransport::codeIn($emails[array_key_last($emails)]);
    }

    private function forgot(): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->website, '/auth/password/forgot'), ['email' => self::WEB_EMAIL]);
    }

    private function reset(string $code, string $email = self::WEB_EMAIL): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->website, '/auth/password/reset'), ['email' => $email, 'code' => $code, 'password' => 'a-new-secret']);
    }

    /** A wrong code for an email the signed-in customer is adding. */
    private function addEmail(string $email): ResponseInterface
    {
        return $this->postJson($this->storeApi($this->website, '/me/identities/email/verify'), ['email' => $email, 'code' => '000000']);
    }

    private function assertWrongCode(ResponseInterface $response, string $message): void
    {
        self::assertSame([422, ['code' => [EmailSignIn::WRONG_CODE]]], [$response->getStatusCode(), $this->decode($response)['errors'] ?? null], $message);
    }

    /** How many times the account was told its codes were failed until none goes. */
    private function told(): int
    {
        return Notification::query()->where('type', NoticeType::EmailCodesFailed->value)->count();
    }
}
