<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Security\Totp;
use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Store\Models\Website;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Support\Password;
use Illuminate\Support\Carbon;
use Symfony\Component\Mime\Email;
use Tests\Fakes\RecordingMailTransport;
use Tests\HttpTestCase;
use Tests\Support\FakeGoogleLogin;
use Tests\Support\FakeTelegramLogin;

/**
 * Every change of how a customer's account on the shop's website is signed in to is told them — a way in added or taken
 * away (which kind), the password set or changed, two-factor sign-in on or off, two accounts made one — on every door the
 * account has: kept in their website's feed, and sent to their Telegram chat and their email both, so whoever made the
 * change holding one of them leaves the other to tell. An address taken off the account is emailed too: the account's
 * notices reach it no more.
 */
final class AccountNoticesTest extends HttpTestCase
{
    private const ALI_EMAIL = 'ali@example.com';

    private Website $website;

    private RecordingMailTransport $mail;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->telegram();
        $this->mail = $this->mail();
        $this->website = $this->website(['google_client_id' => self::GOOGLE_CLIENT_ID]);
    }

    public function testAWayInAddedIsToldOnEveryDoorTheAccountHas(): void
    {
        $this->googleLogin();
        $ali = $this->bothDoors();
        $this->bearer($this->customerSession($ali));
        $nonce = $this->nonce();

        $added = $this->postJson($this->storeApi($this->website, '/me/identities/google'), ['id_token' => FakeGoogleLogin::idToken(FakeGoogleLogin::claims(self::GOOGLE_CLIENT_ID, $nonce)), 'nonce' => $nonce]);

        self::assertSame(200, $added->getStatusCode(), (string) $added->getBody());
        $words = self::text(BotText::WayInAdded, ['way' => 'گوگل']);
        self::assertSame([$words], $this->telegram()->sentTo(self::TELEGRAM_ID), 'in Telegram');
        self::assertSame([NoticeType::WayInAdded->subject()], $this->subjectsTo(self::ALI_EMAIL), 'and by email both');
        $this->assertKept($ali, NoticeType::WayInAdded, $words);
    }

    public function testATelegramAccountAddedToAnEmailAccountIsToldAtItsAddressToo(): void
    {
        $this->telegramLogin();
        $sara = $this->webCustomer();
        $this->bearer($this->customerSession($sara));
        $nonce = $this->nonce();

        $this->postJson($this->storeApi($this->website, '/me/identities/telegram'), ['id_token' => FakeTelegramLogin::idToken(FakeTelegramLogin::claims(self::WEBSITE_CLIENT_ID, $nonce)), 'nonce' => $nonce]);

        self::assertSame(FakeTelegramLogin::USER_ID, $sara->refresh()->telegram_id);
        self::assertSame([NoticeType::WayInAdded->subject()], $this->subjectsTo(self::WEB_EMAIL), "the address the account had: whoever's that Telegram account is, it hears of it");
        self::assertCount(1, $this->telegram()->sentTo(FakeTelegramLogin::USER_ID), 'and the chat it reaches now');
    }

    public function testAnAddressTakenOffTheAccountIsEmailedThatItIsNoWayInAnyMore(): void
    {
        $ali = $this->bothDoors();
        $this->bearer($this->customerSession($ali));

        $removed = $this->deleteJson($this->storeApi($this->website, '/me/identities/email'));

        self::assertSame(200, $removed->getStatusCode(), (string) $removed->getBody());
        $words = self::text(BotText::WayInRemoved, ['way' => 'ایمیل']);
        self::assertSame([$words], $this->telegram()->sentTo(self::TELEGRAM_ID), 'the account told where it is reached now');
        [$email] = $this->mail->to(self::ALI_EMAIL);
        self::assertSame('ایمیل از حساب شما در AmoBot برداشته شد', $email->getSubject(), 'and the address it no longer has');
        self::assertStringContainsString('دیگر راه ورود به آن حساب نیست', (string) $email->getTextBody());
        $this->assertKept($ali, NoticeType::WayInRemoved, $words);
    }

    public function testAPasswordSetOrChangedIsTold(): void
    {
        $sara = $this->webCustomer();
        $this->bearer($this->customerSession($sara));

        self::assertSame(200, $this->putJson($this->storeApi($this->website, '/me/password'), ['current_password' => self::WEB_PASSWORD, 'password' => 'a-new-secret'])->getStatusCode());

        self::assertSame([NoticeType::PasswordChanged->subject()], $this->subjectsTo(self::WEB_EMAIL));
        $this->assertKept($sara, NoticeType::PasswordChanged, self::text(BotText::PasswordChanged));
    }

    public function testAPasswordResetIsTold(): void
    {
        $sara = $this->webCustomer();
        $this->postJson($this->storeApi($this->website, '/auth/password/forgot'), ['email' => self::WEB_EMAIL]);

        $reset = $this->postJson($this->storeApi($this->website, '/auth/password/reset'), ['email' => self::WEB_EMAIL, 'code' => RecordingMailTransport::codeIn($this->mail->to(self::WEB_EMAIL)[0]), 'password' => 'a-new-secret']);

        self::assertSame(200, $reset->getStatusCode(), (string) $reset->getBody());
        $this->assertKept($sara, NoticeType::PasswordChanged, self::text(BotText::PasswordChanged));
    }

    public function testTwoFactorSignInTurnedOnAndOffIsTold(): void
    {
        $ali = $this->bothDoors();
        $this->bearer($this->customerSession($ali));
        $secret = $this->decode($this->postJson($this->storeApi($this->website, '/me/2fa/setup')))['secret'];

        self::assertSame(200, $this->postJson($this->storeApi($this->website, '/me/2fa/enable'), ['code' => Totp::code($secret)])->getStatusCode());
        self::assertSame(200, $this->postJson($this->storeApi($this->website, '/me/2fa/disable'), ['password' => 'ali-secret-1'])->getStatusCode());

        self::assertSame([self::text(BotText::TwoFactorTurnedOn), self::text(BotText::TwoFactorTurnedOff)], $this->telegram()->sentTo(self::TELEGRAM_ID));
        self::assertSame([NoticeType::TwoFactorEnabled->subject(), NoticeType::TwoFactorDisabled->subject()], $this->subjectsTo(self::ALI_EMAIL));
        self::assertSame([NoticeType::TwoFactorEnabled, NoticeType::TwoFactorDisabled], Notification::query()->orderBy('id')->pluck('type')->all());
    }

    public function testTwoAccountsMadeOneAreTold(): void
    {
        $this->telegramLogin();
        Carbon::setTestNow('2026-09-01 10:00:00');
        $ali = $this->customer();
        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->bearer($this->customerSession($this->webCustomer()));
        $nonce = $this->nonce();
        $offer = $this->decode($this->postJson($this->storeApi($this->website, '/me/identities/telegram'), ['id_token' => FakeTelegramLogin::idToken(FakeTelegramLogin::claims(self::WEBSITE_CLIENT_ID, $nonce)), 'nonce' => $nonce]))['merge'];

        self::assertSame(200, $this->postJson($this->storeApi($this->website, '/me/merge'), ['token' => $offer['token']])->getStatusCode());

        self::assertSame([self::text(BotText::AccountMerged)], $this->telegram()->sentTo(self::TELEGRAM_ID), 'the account that stays — its Telegram chat');
        self::assertSame([NoticeType::AccountMerged->subject()], $this->subjectsTo(self::WEB_EMAIL), 'and the address it took on');
        $this->assertKept($ali, NoticeType::AccountMerged, self::text(BotText::AccountMerged));
    }

    public function testAMergeThatSetsAPasswordTellsThePasswordChangedToo(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');
        $sara = $this->webCustomer();
        Carbon::setTestNow('2026-10-07 12:00:00');
        // Ali, signed in by Telegram, adds Sara's address with a password of his choosing: proven, a merge — Sara's stays.
        $this->bearer($this->customerSession($this->customer()));
        $this->postJson($this->storeApi($this->website, '/me/identities/email'), ['email' => self::WEB_EMAIL, 'password' => 'a-new-secret']);
        $code = RecordingMailTransport::codeIn($this->mail->to(self::WEB_EMAIL)[0]);
        $offer = $this->decode($this->postJson($this->storeApi($this->website, '/me/identities/email/verify'), ['email' => self::WEB_EMAIL, 'code' => $code]))['merge'];

        self::assertSame(200, $this->postJson($this->storeApi($this->website, '/me/merge'), ['token' => $offer['token']])->getStatusCode());

        self::assertTrue(password_verify('a-new-secret', (string) $sara->refresh()->password_hash), 'the merge set the password');
        self::assertSame([self::text(BotText::AccountMerged), self::text(BotText::PasswordChanged)], $this->telegram()->sentTo(self::TELEGRAM_ID), 'the merge, and the new password it set: in Telegram');
        self::assertSame([NoticeType::AccountMerged->subject(), NoticeType::PasswordChanged->subject()], array_slice($this->subjectsTo(self::WEB_EMAIL), 1), 'and by email both, after the code');
        self::assertSame([NoticeType::AccountMerged, NoticeType::PasswordChanged], Notification::query()->where('user_id', $sara->id)->orderBy('id')->pluck('type')->all(), "each kept for the website's feed");
    }

    /** Ali: the bot's customer, with an email and a password too — both doors open. */
    private function bothDoors(): User
    {
        return $this->customer(['email' => self::ALI_EMAIL, 'password_hash' => Password::hash('ali-secret-1')]);
    }

    /** @return list<string> The subjects of the emails `$address` got, in order. */
    private function subjectsTo(string $address): array
    {
        return array_map(static fn(Email $email): string => (string) $email->getSubject(), $this->mail->to($address));
    }

    private function assertKept(User $user, NoticeType $type, string $words): void
    {
        $notice = Notification::query()->where('user_id', $user->id)->latest('id')->firstOrFail();

        self::assertSame([$type, $words, null], [$notice->type, $notice->text, $notice->subject_type], "kept for their website's feed — about their account, nothing else");
    }

    private function nonce(): string
    {
        return $this->decode($this->postJson($this->storeApi($this->website, '/auth/nonce')))['nonce'];
    }
}
