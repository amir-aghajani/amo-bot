<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports\Customers;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Accounts\Exceptions\AccountRefusedException;
use App\Modules\Accounts\Services\CustomerSessions;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Update\GroupHandler;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\UserActions;
use App\Support\Persian;

/**
 * «🔐 ورود به سایت» on a customer's card in the report group: how they sign in to the shop's website — Telegram, email,
 * password, Google, two-factor sign-in — and on how many devices they are signed in now. Two buttons, each asked again
 * first: «🔓 خاموش کردن ورود دو مرحله‌ای» while it is on (a customer who lost the phone; the service tells them) and
 * «🚪 خروج از همه دستگاه‌ها» while they are signed in somewhere (nobody is told).
 *
 * Both go through UserActions under the presser's name (Actor::groupAdmin()), by its rules for the report group: never
 * the admin's own account nor an agent's (CustomerCards::lockOf()), never another admin's sign-in — it opens the
 * website's admin side, the panel's to change. Such an account gets only what reads, and a line why.
 */
final class CustomerAccount implements GroupHandler
{
    /** What the section's buttons' callback data starts with: `ua:<action>:<customer id>`. */
    public const PREFIX = 'ua:';

    /** The section opened from the card's front, as the account now stands: every «⬅️ انصراف» too. */
    public const OPEN = 'o';

    /** Two-factor sign-in's turning off asked; then done. */
    private const TWO_FACTOR = 't';
    private const TWO_FACTOR_OFF = 'tf';
    /** The sign-out on every device asked; then done. */
    private const SESSIONS = 's';
    private const SIGN_OUT = 'so';

    private const TURNED_OFF = '🔓 ورود دو مرحله‌ای خاموش شد.';
    private const SIGNED_OUT = '🚪 از همه دستگاه‌ها خارج شد.';
    private const NO_DEVICES = 'در هیچ دستگاهی وارد سایت نیست.';

    /** Why a bot admin's sign-in has no buttons here (UserActions refuses it: ActorRefusedException::adminAccount()). */
    private const ADMIN_LOCK = '🔒 حساب مدیر ربات است؛ ورودش به سایت فقط از پنل تغییر می‌کند.';

    public function __construct(
        private readonly CustomerCards $cards,
        private readonly UserActions $actions,
        private readonly CustomerSessions $sessions,
    ) {}

    public function handle(Update $update): void
    {
        $press = $this->cards->press($update, self::PREFIX);
        if ($press === null) {
            return;
        }
        if ($press->args !== [] || !in_array($press->action, [self::OPEN, self::TWO_FACTOR, self::TWO_FACTOR_OFF, self::SESSIONS, self::SIGN_OUT], true)) {
            $this->cards->answer($press);

            return;
        }

        try {
            match ($press->action) {
                self::TWO_FACTOR => $this->askTwoFactor($press),
                self::TWO_FACTOR_OFF => $this->turnOff($press),
                self::SESSIONS => $this->askSignOut($press),
                self::SIGN_OUT => $this->signOut($press),
                default => $this->show($press),
            };
        } catch (DomainRuleException $e) {
            // A press the view did not offer (a forged one, or a view older than the account): refused as UserActions
            // refuses it, in a popup, the view as the account now stands.
            $this->show($press, CustomerCards::words($e), alert: true);
        }
    }

    /**
     * The view's words: how they sign in — Telegram, email, password, Google, two-factor — and the devices signed in
     * (`$devices`); then why it offers no change, when it offers none.
     */
    public static function view(User $user, User $admin, int $devices): string
    {
        $lines = [
            CustomerCards::header($user, 'ورود به سایت'),
            '',
            '✈️ تلگرام: ' . ($user->telegram_id === null ? 'وصل نیست' : 'وصل است'),
            // After a left-to-right mark, as a phone number on the front: an address after Persian words.
            '📧 ایمیل: ' . ($user->email === null ? 'ندارد' : "\u{200E}" . htmlspecialchars($user->email)),
            '🔑 رمز عبور: ' . ($user->password_hash === null ? 'ندارد' : 'دارد'),
            '🌐 گوگل: ' . ($user->google_sub === null ? 'وصل نیست' : 'وصل است'),
            '🛡 ورود دو مرحله‌ای: ' . ($user->hasTwoFactor() ? 'روشن' : 'خاموش'),
            '💻 دستگاه‌ها: ' . ($devices > 0 ? Persian::number($devices) . ' دستگاه وارد است' : 'در هیچ دستگاهی وارد نیست'),
        ];
        $locked = self::lockOf($user, $admin);

        return implode("\n", $locked === null ? $lines : [...$lines, '', $locked]);
    }

    /**
     * The view's buttons as the account stands — each on a row of its own, its words being long —, then «⬅️ بازگشت»;
     * only that for an account it may not change.
     *
     * @return list<list<array<string, mixed>>>
     */
    public static function buttons(User $user, User $admin, int $devices): array
    {
        $keyboard = InlineKeyboard::make();
        if (self::lockOf($user, $admin) === null) {
            if ($user->hasTwoFactor()) {
                $keyboard->row(CustomerCards::button('🔓 خاموش کردن ورود دو مرحله‌ای', CustomerCards::data(self::PREFIX, self::TWO_FACTOR, $user->id)));
            }
            if ($devices > 0) {
                $keyboard->row(CustomerCards::button('🚪 خروج از همه دستگاه‌ها', CustomerCards::data(self::PREFIX, self::SESSIONS, $user->id)));
            }
        }

        return $keyboard->row(CustomerCards::back($user->id))->build()['inline_keyboard'];
    }

    /** Why the view offers no change: the card's lock (CustomerCards::lockOf()), or another admin's sign-in; null when it offers them. */
    private static function lockOf(User $user, User $admin): ?string
    {
        return CustomerCards::lockOf($user, $admin) ?? ($user->isAdmin() ? self::ADMIN_LOCK : null);
    }

    /**
     * Whether the admin may change the customer's website sign-in from here: what CustomerCards::mayChange() allows, and
     * never another admin's.
     *
     * @throws ActorRefusedException 403
     */
    private static function mayChange(User $user, User $admin): void
    {
        CustomerCards::mayChange($user, $admin);
        if ($user->isAdmin()) {
            throw ActorRefusedException::adminAccount();
        }
    }

    /** The view rewritten as the account now stands, with its buttons; the press answered with `$word`. */
    private function show(CardPress $press, ?string $word = null, bool $alert = false): void
    {
        $press->user->refresh();
        $devices = $this->sessions->count($press->user);
        $this->cards->show($press, self::view($press->user, $press->admin, $devices), self::buttons($press->user, $press->admin, $devices), $word, $alert);
    }

    /**
     * Two-factor sign-in's turning off asked again: the view with its question, «✔️ بله» and «⬅️ انصراف».
     *
     * @throws DomainRuleException
     */
    private function askTwoFactor(CardPress $press): void
    {
        self::mayChange($press->user, $press->admin);
        if (!$press->user->hasTwoFactor()) {
            throw AccountRefusedException::twoFactorOff();
        }

        $this->ask($press, self::TWO_FACTOR_OFF, '❓ <b>ورود دو مرحله‌ای‌اش خاموش شود؟</b> برای مشتری‌ای که گوشی برنامه احراز هویتش را از دست داده است: از این پس برای ورود به سایت کد برنامه از او خواسته نمی‌شود و رمز عبورش به تنهایی کافی است. به او خبر داده می‌شود.');
    }

    /**
     * The sign-out on every device asked again: the view with its question, «✔️ بله» and «⬅️ انصراف»; the view as it
     * stands, with a word, when they are signed in nowhere.
     *
     * @throws DomainRuleException
     */
    private function askSignOut(CardPress $press): void
    {
        self::mayChange($press->user, $press->admin);
        if ($this->sessions->count($press->user) === 0) {
            $this->show($press, self::NO_DEVICES);

            return;
        }

        $this->ask($press, self::SIGN_OUT, '❓ <b>از همه دستگاه‌ها خارج شود؟</b> برای ادامه باید دوباره وارد سایت شود؛ ربات تلگرام و سرویس‌هایش دست نمی‌خورد.');
    }

    /** The view with `$question` under it, «⬅️ انصراف» back to it and «✔️ بله» pressing `$do`. */
    private function ask(CardPress $press, string $do, string $question): void
    {
        $user = $press->user;
        $rows = InlineKeyboard::make()->row(
            CustomerCards::button('⬅️ انصراف', CustomerCards::data(self::PREFIX, self::OPEN, $user->id)),
            CustomerCards::button('✔️ بله', CustomerCards::data(self::PREFIX, $do, $user->id), 'danger'),
        )->build()['inline_keyboard'];

        $this->cards->show($press, self::view($user, $press->admin, $this->sessions->count($user)) . "\n\n" . $question, $rows);
    }

    /** @throws DomainRuleException */
    private function turnOff(CardPress $press): void
    {
        $this->actions->disableTwoFactor($press->user, Actor::groupAdmin($press->admin));
        $this->show($press, self::TURNED_OFF);
    }

    /** @throws DomainRuleException */
    private function signOut(CardPress $press): void
    {
        $this->actions->endSessions($press->user, Actor::groupAdmin($press->admin));
        $this->show($press, self::SIGNED_OUT);
    }
}
