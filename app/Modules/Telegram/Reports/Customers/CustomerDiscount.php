<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports\Customers;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Discounts\Services\PersonalDiscounts;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Update\GroupHandler;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use App\Support\Persian;

/**
 * «🏷 تخفیف» on a customer's card in the report group (CustomerCards: the checks, the look, the questions): their own
 * discount («تخفیف اختصاصی») and what it does, a button a preset percent — set at once, the current one ✅ — and
 * «✍️ درصد دیگر», which asks for any percent from 0 to 100. Set as the panel sets it (PersonalDiscounts::set(), under
 * the admin's name): never the admin's own account, an agent's or another admin's — the card offers none of them.
 */
final class CustomerDiscount implements GroupHandler, AnswersPrompts
{
    /** What the section's buttons' callback data starts with: `ud:<action>:<customer id>[:<arg>…]`. */
    public const PREFIX = 'ud:';

    /** The section opened from the card's front. */
    public const OPEN = 'o';

    /** A preset percent set: `ud:s:<customer id>:<percent>`. */
    private const SET = 's';
    /** «✍️ درصد دیگر» asked — and the question's kind, which the reply comes back with. */
    private const ASK = 'a';

    /** The percents the section offers a button for; 0 none. */
    private const PRESETS = [0, 5, 10, 15, 20, 25, 30, 50];

    private const ABOUT = 'ℹ️ از هر خریدش کم می‌شود؛ اگر فروش ویژه یا تخفیف سطح وفاداری‌اش بیشتر باشد همان جایش را می‌گیرد و با هم جمع نمی‌شوند.';
    private const ADMIN_LOCK = '🔒 حساب مدیر ربات است؛ تخفیف اختصاصی‌اش فقط از پنل تغییر می‌کند.';
    private const QUESTION = 'تخفیف اختصاصی چند درصد باشد؟ عددی از ۰ تا ۱۰۰ بنویسید؛ ۰ یعنی بدون تخفیف.';
    private const PLACEHOLDER = 'درصد، از ۰ تا ۱۰۰';
    private const NO_PERCENT = 'درصد را با رقم بنویسید: عددی از ۰ تا ۱۰۰؛ ۰ یعنی بدون تخفیف.';
    private const SET_TO = 'تخفیف اختصاصی %s٪ شد.';
    private const REMOVED = 'تخفیف اختصاصی برداشته شد.';

    public function __construct(
        private readonly CustomerCards $cards,
        private readonly PersonalDiscounts $discounts,
    ) {}

    public function handle(Update $update): void
    {
        $press = $this->cards->press($update, self::PREFIX);
        if ($press === null) {
            return;
        }
        $shaped = match ($press->action) {
            self::OPEN, self::ASK => $press->args === [],
            self::SET => count($press->args) === 1 && $press->int(0) !== null,
            default => false,
        };
        if (!$shaped) {
            $this->cards->answer($press);

            return;
        }

        try {
            match ($press->action) {
                self::SET => $this->set($press, (int) $press->int(0)),
                self::ASK => $this->ask($press),
                default => $this->show($press),
            };
        } catch (DomainRuleException $e) {
            // A press the section did not offer (a forged one, or a card older than the account): refused as
            // PersonalDiscounts refuses it, the section as the account now stands.
            $this->show($press, CustomerCards::words($e), alert: true);
        }
    }

    /**
     * The percent the admin typed (0 to 100, in any digits, «٪» allowed): set, the section rewritten. Anything else is
     * asked again; a refusal (an agent's account, another admin's, their own) is its words, the question kept.
     *
     * @throws DomainRuleException
     */
    public function answered(CardReply $reply): void
    {
        if ($reply->kind !== self::ASK) {
            return;
        }
        $percent = CustomerCards::number((string) preg_replace('/[\s%٪\x{200C}]+$/u', '', $reply->text));
        if ($percent === null || $percent > 100) {
            $this->cards->retry($reply, self::NO_PERCENT);

            return;
        }

        $user = $this->discounts->set($reply->user, Actor::groupAdmin($reply->admin), ['percent' => $percent]);
        $this->cards->done($reply, '✅ ' . self::changed($user), self::view($user, $reply->admin), self::buttons($user, $reply->admin));
    }

    /** @throws DomainRuleException */
    private function set(CardPress $press, int $percent): void
    {
        $this->discounts->set($press->user, Actor::groupAdmin($press->admin), ['percent' => $percent]);
        $this->show($press, '🏷 ' . self::changed($press->user));
    }

    /** @throws DomainRuleException */
    private function ask(CardPress $press): void
    {
        self::mayChange($press->user, $press->admin);
        $this->cards->ask($press, self::PREFIX, self::ASK, [], '✍️ ' . self::QUESTION, self::PLACEHOLDER);
    }

    /** The section rewritten as the account now stands, with its buttons; the press answered with `$word`. */
    private function show(CardPress $press, ?string $word = null, bool $alert = false): void
    {
        $press->user->refresh();
        $this->cards->show($press, self::view($press->user, $press->admin), self::buttons($press->user, $press->admin), $word, $alert);
    }

    /** The section's words: the percent, what it does — and why it is locked. */
    private static function view(User $user, User $admin): string
    {
        $lines = [
            CustomerCards::header($user, 'تخفیف اختصاصی'),
            '🏷 تخفیف اختصاصی: ' . ($user->discount_percent > 0 ? Persian::digits($user->discount_percent) . '٪' : 'ندارد'),
            self::ABOUT,
        ];
        $locked = self::lockOf($user, $admin);

        return implode("\n", $locked === null ? $lines : [...$lines, '', $locked]);
    }

    /**
     * The presets — the current one ✅ —, «✍️ درصد دیگر» and «⬅️ بازگشت», three a row, each row's first on the right;
     * only the way back when the section is locked.
     *
     * @return list<list<array<string, mixed>>>
     */
    private static function buttons(User $user, User $admin): array
    {
        $keyboard = InlineKeyboard::make();
        if (self::lockOf($user, $admin) === null) {
            $presets = array_map(static fn(int $percent): array => CustomerCards::button(
                ($percent === $user->discount_percent ? '✅ ' : '') . ($percent === 0 ? 'بدون تخفیف' : Persian::digits($percent) . '٪'),
                CustomerCards::data(self::PREFIX, self::SET, $user->id, $percent),
            ), self::PRESETS);
            $keyboard->grid([...$presets, CustomerCards::button('✍️ درصد دیگر', CustomerCards::data(self::PREFIX, self::ASK, $user->id))], 3);
        }

        return $keyboard->row(CustomerCards::back($user->id))->build()['inline_keyboard'];
    }

    /** The word for a percent set: «تخفیف اختصاصی ۱۵٪ شد.», or taken away. */
    private static function changed(User $user): string
    {
        return $user->discount_percent > 0 ? sprintf(self::SET_TO, Persian::digits($user->discount_percent)) : self::REMOVED;
    }

    /**
     * The card's rules (CustomerCards::mayChange()) and the discount's own: never another admin's, whose prices are the
     * panel's to lower.
     *
     * @throws ActorRefusedException 403
     */
    private static function mayChange(User $user, User $admin): void
    {
        CustomerCards::mayChange($user, $admin);
        if ($user->isAdmin()) {
            throw ActorRefusedException::adminDiscount();
        }
    }

    /** The section's word for why it offers no change; null when it offers them. */
    private static function lockOf(User $user, User $admin): ?string
    {
        return CustomerCards::lockOf($user, $admin) ?? ($user->isAdmin() ? self::ADMIN_LOCK : null);
    }
}
