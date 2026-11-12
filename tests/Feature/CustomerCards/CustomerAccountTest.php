<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerCards;

use App\Modules\Accounts\Exceptions\AccountRefusedException;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Store\Models\Website;
use App\Modules\Telegram\Reports\GroupButtons;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use Tests\BotTestCase;

/**
 * «🔐 ورود به سایت» on a customer's card: how they sign in to the shop's website and on how many devices they are signed
 * in; two-factor sign-in turned off (the customer told) and every device signed out (nobody told), each asked again
 * first. Never the admin's own account, an agent's nor another admin's — none of their buttons, and a forged press
 * refused as the service refuses it. Only the report group's bot admins press them.
 */
final class CustomerAccountTest extends BotTestCase
{
    /** A card of the bot's in the group, under which the buttons are pressed. */
    private const CARD = 5252;

    /** @var array<string, int> */
    private array $threads;

    private Website $website;

    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->threads = $this->reportGroup();
        $this->website = $this->website();
        $this->admin(['telegram_id' => self::GROUP_ADMIN, 'username' => 'boss']);
        $this->ali = $this->signsInEveryWay($this->customer(['telegram_id' => 7070, 'username' => 'ali', 'email' => 'ali@example.com', 'google_sub' => 'ali-google']));
    }

    public function testTheViewSaysHowTheySignInAndOnHowManyDevices(): void
    {
        $this->send($this->press("ua:o:{$this->ali->id}"));

        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls());
        self::assertSame('HTML', $this->params(0)['parse_mode']);
        self::assertSame(implode("\n", [
            "⚙️ <b>مدیریت کاربر › ورود به سایت</b> · \u{200E}#{$this->ali->id}",
            "👤 <a href=\"tg://user?id=7070\">Ali</a> · \u{200E}@ali · <code>7070</code>",
            '',
            '✈️ تلگرام: وصل است',
            "📧 ایمیل: \u{200E}ali@example.com",
            '🔑 رمز عبور: دارد',
            '🌐 گوگل: وصل است',
            '🛡 ورود دو مرحله‌ای: روشن',
            '💻 دستگاه‌ها: ۲ دستگاه وارد است',
        ]), $this->params(0)['text'], 'the sessions that have not ended');
        self::assertSame([['🔓 خاموش کردن ورود دو مرحله‌ای'], ['🚪 خروج از همه دستگاه‌ها'], ['⬅️ بازگشت']], $this->labels());
        self::assertSame(["ua:t:{$this->ali->id}", "ua:s:{$this->ali->id}", "um:o:{$this->ali->id}"], $this->callbacks(0));

        $sara = $this->webCustomer(['email' => 'sara@example.com', 'password_hash' => null]);
        $this->send($this->press("ua:o:{$sara->id}"));

        self::assertSame(implode("\n", [
            "⚙️ <b>مدیریت کاربر › ورود به سایت</b> · \u{200E}#{$sara->id}",
            "👤 Sara Ahmadi · sara@example.com · \u{200E}<code>#{$sara->id}</code>",
            '',
            '✈️ تلگرام: وصل نیست',
            "📧 ایمیل: \u{200E}sara@example.com",
            '🔑 رمز عبور: ندارد',
            '🌐 گوگل: وصل نیست',
            '🛡 ورود دو مرحله‌ای: خاموش',
            '💻 دستگاه‌ها: در هیچ دستگاهی وارد نیست',
        ]), $this->params(0)['text']);
        self::assertSame([['⬅️ بازگشت']], $this->labels(), 'nothing to turn off, nowhere to sign out of');
    }

    public function testTwoFactorIsAskedThenTurnedOffTheCustomerTold(): void
    {
        $this->send($this->press("ua:t:{$this->ali->id}"));

        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls());
        self::assertStringContainsString("\n🛡 ورود دو مرحله‌ای: روشن\n", $this->params(0)['text']);
        self::assertStringEndsWith("\n\n❓ <b>ورود دو مرحله‌ای‌اش خاموش شود؟</b> برای مشتری‌ای که گوشی برنامه احراز هویتش را از دست داده است: از این پس برای ورود به سایت کد برنامه از او خواسته نمی‌شود و رمز عبورش به تنهایی کافی است. به او خبر داده می‌شود.", $this->params(0)['text']);
        self::assertSame([['⬅️ انصراف', '✔️ بله']], $this->labels());
        self::assertSame(["ua:o:{$this->ali->id}", "ua:tf:{$this->ali->id}"], $this->callbacks(0), '«انصراف», then «بله» on the right');
        self::assertSame('danger', $this->inlineKeyboard(0)[0][1]['style']);
        self::assertTrue($this->ali->refresh()->hasTwoFactor(), 'asked, not done');

        $this->send($this->press("ua:o:{$this->ali->id}"));
        self::assertTrue($this->ali->refresh()->hasTwoFactor(), '«انصراف» back to the view');

        $this->send($this->press("ua:tf:{$this->ali->id}"));

        self::assertFalse($this->ali->refresh()->hasTwoFactor());
        self::assertSame([self::text(BotText::TwoFactorDisabled)], $this->telegram()->sentTo(7070), 'told by the service');
        self::assertSame(['sendMessage', 'editMessageText', 'answerCallbackQuery'], $this->calls());
        self::assertStringContainsString("\n🛡 ورود دو مرحله‌ای: خاموش\n", $this->params(1)['text']);
        self::assertSame([['🚪 خروج از همه دستگاه‌ها'], ['⬅️ بازگشت']], array_map(static fn(array $row): array => array_column($row, 'text'), $this->inlineKeyboard(1)));
        self::assertSame('🔓 ورود دو مرحله‌ای خاموش شد.', $this->popup());

        // A view older than the change asks again, or says «بله» again: refused, the view as it now stands.
        foreach (["ua:t:{$this->ali->id}", "ua:tf:{$this->ali->id}"] as $data) {
            $this->send($this->press($data));
            self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls(), $data);
            self::assertSame(AccountRefusedException::TWO_FACTOR_OFF, $this->popup(), $data);
            self::assertSame('true', $this->params(1)['show_alert'], $data);
            self::assertStringNotContainsString('❓', $this->params(0)['text'], $data);
        }
    }

    public function testEveryDeviceIsAskedThenSignedOutNobodyTold(): void
    {
        $sessions = $this->service(CustomerSessions::class);

        $this->send($this->press("ua:s:{$this->ali->id}"));

        self::assertStringEndsWith("\n\n❓ <b>از همه دستگاه‌ها خارج شود؟</b> برای ادامه باید دوباره وارد سایت شود؛ ربات تلگرام و سرویس‌هایش دست نمی‌خورد.", $this->params(0)['text']);
        self::assertSame(["ua:o:{$this->ali->id}", "ua:so:{$this->ali->id}"], $this->callbacks(0));
        self::assertSame(2, $sessions->count($this->ali), 'asked, not done');

        $this->send($this->press("ua:so:{$this->ali->id}"));

        self::assertSame(0, $sessions->count($this->ali));
        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls(), 'nobody told');
        self::assertStringContainsString("\n💻 دستگاه‌ها: در هیچ دستگاهی وارد نیست", $this->params(0)['text']);
        self::assertSame([['🔓 خاموش کردن ورود دو مرحله‌ای'], ['⬅️ بازگشت']], $this->labels());
        self::assertSame('🚪 از همه دستگاه‌ها خارج شد.', $this->popup());
        self::assertTrue($this->ali->refresh()->hasTwoFactor(), 'two-factor stays as it was');

        $this->send($this->press("ua:s:{$this->ali->id}"));
        self::assertSame('در هیچ دستگاهی وارد سایت نیست.', $this->popup(), 'a view older than the sign-out');
        self::assertStringNotContainsString('❓', $this->params(0)['text']);
    }

    public function testTheirOwnAccountAnAgentsAndAnotherAdminsAreShownNotChanged(): void
    {
        $boss = $this->signsInEveryWay(User::query()->where('telegram_id', self::GROUP_ADMIN)->sole()->forceFill(['email' => 'boss@example.com']));
        $agent = $this->signsInEveryWay($this->agent(overrides: ['telegram_id' => 9292, 'username' => 'agent_reza', 'email' => 'reza@example.com']));
        $admin = $this->signsInEveryWay($this->admin(['telegram_id' => 9191, 'username' => 'sara', 'email' => 'sara@example.com']));
        $sessions = $this->service(CustomerSessions::class);

        $cases = [
            [$boss, '🔒 حساب خودتان است؛ با مدیر دیگری یا از پنل تغییر می‌کند.', ActorRefusedException::OWN_ACCOUNT],
            [$agent, '🔒 حساب نماینده است؛ فقط از پنل تغییر می‌کند.', ActorRefusedException::AGENT_ACCOUNT],
            [$admin, '🔒 حساب مدیر ربات است؛ ورودش به سایت فقط از پنل تغییر می‌کند.', ActorRefusedException::ADMIN_ACCOUNT],
        ];
        foreach ($cases as [$user, $lock, $refusal]) {
            $this->send($this->press("ua:o:{$user->id}"));
            self::assertStringContainsString("\n🛡 ورود دو مرحله‌ای: روشن\n💻 دستگاه‌ها: ۲ دستگاه وارد است\n\n{$lock}", $this->params(0)['text'], $refusal);
            self::assertStringEndsWith($lock, $this->params(0)['text'], $refusal);
            self::assertSame(["um:o:{$user->id}"], $this->callbacks(0), "{$refusal}: only back");

            foreach (['t', 'tf', 's', 'so'] as $action) {
                $this->send($this->press("ua:{$action}:{$user->id}"));
                self::assertSame($refusal, $this->popup(), "{$refusal}: {$action}");
                self::assertSame(["um:o:{$user->id}"], $this->callbacks(0), "{$refusal}: {$action} — the view as it stands");
            }
            self::assertTrue($user->refresh()->hasTwoFactor(), $refusal);
            self::assertSame(2, $sessions->count($user), $refusal);
        }
    }

    public function testOnlyTheGroupsBotAdminsPressAndOnlyWhatTheViewOffers(): void
    {
        $this->send($this->press("ua:so:{$this->ali->id}", from: self::GROUP_MEMBER));
        self::assertSame(['answerCallbackQuery'], $this->calls());
        self::assertSame(GroupButtons::onlyAdmins('کاربرها را مدیریت کنند'), $this->popup());
        $this->send($this->press("ua:tf:{$this->ali->id}", from: self::GROUP_MEMBER));
        self::assertTrue($this->ali->refresh()->hasTwoFactor(), 'a press is no decision of anyone but a bot admin');
        self::assertSame(2, $this->service(CustomerSessions::class)->count($this->ali));

        foreach (["ua:x:{$this->ali->id}", "ua:so:{$this->ali->id}:1"] as $data) {
            $this->send($this->press($data));
            self::assertSame(['answerCallbackQuery'], $this->calls(), "{$data}: no button of this view");
            self::assertNull($this->popup());
        }
        self::assertSame(2, $this->service(CustomerSessions::class)->count($this->ali));
    }

    /**
     * The account signing in to the website with a password, two-factor sign-in on, and signed in on two devices — its
     * email already set; the account read again.
     */
    private function signsInEveryWay(User $user): User
    {
        $user->forceFill(['password_hash' => password_hash('secret-1234', PASSWORD_BCRYPT, ['cost' => 4])])->save();
        $this->twoFactorOn($this->website, $user);
        $this->customerSession($user);
        $this->customerSession($user);

        return $user->refresh();
    }

    /** @return list<list<string>> The labels of the last rewrite's buttons, row by row. */
    private function labels(): array
    {
        return array_map(static fn(array $row): array => array_column($row, 'text'), $this->inlineKeyboard(0));
    }

    /** A press on the card's buttons, by the report group's bot admin unless told otherwise. */
    private function press(string $data, int $from = self::GROUP_ADMIN): Update
    {
        return $this->groupTap($data, self::CARD, $this->threads['users'], $from);
    }
}
