<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports\Customers;

use App\Core\Database\Ledger;
use App\Core\Exceptions\DomainRuleException;
use App\Core\Exceptions\KeyReusedException;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Services\Reviewers;
use App\Modules\Telegram\Api\Limits;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Update\GroupHandler;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Enums\WalletTransactionType;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use App\Modules\Users\Services\UserActions;
use App\Modules\Users\Services\WalletService;
use App\Support\Input;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Unit;

/**
 * «👛 کیف پول» on a customer's card in the report group (CustomerCards: the checks, the look, the questions): the
 * balance, an agent's credit, the ledger's latest lines — who of support wrote one by hand —, and «➕ افزایش موجودی» /
 * «➖ کاهش موجودی». Each asks for the amount in Toman, and on a second line a note the customer reads in their wallet's
 * history; the reply adjusts the wallet as the panel does (UserActions::adjustWallet(), under the admin's name). The
 * question is answered once (CustomerCards::reply() takes it), and the line is keyed by it besides — a reply served twice
 * writes one line. Never the admin's own account nor an agent's.
 */
final class CustomerWallet implements GroupHandler, AnswersPrompts
{
    /** What the section's buttons' callback data starts with: `uw:<action>:<customer id>[:<arg>…]`. */
    public const PREFIX = 'uw:';

    /** The section opened from the card's front. */
    public const OPEN = 'o';

    /** «➕ افزایش موجودی» and «➖ کاهش موجودی»: the question asked — and its kind, which the reply comes back with. */
    private const CREDIT = 'c';
    private const DEBIT = 'd';

    /** The ledger's latest lines the section shows. */
    private const LINES = 5;

    /** The most of a line's description the section shows, in Telegram's units; a name the words show. */
    private const DESCRIPTION_MAX = 80;
    private const NAME_MAX = 64;

    private const NO_LINES = 'هنوز تراکنشی ندارد.';
    private const CREDIT_QUESTION = 'چه مبلغی به کیف پول اضافه شود؟ مبلغ را به تومان در خط اول بنویسید.';
    private const DEBIT_QUESTION = 'چه مبلغی از کیف پول کم شود؟ موجودی: %s. مبلغ را به تومان در خط اول بنویسید.';
    private const NOTE_LINE = 'اگر خواستید، در خط دوم توضیحی بنویسید که مشتری در تاریخچه کیف پولش می‌بیند (تا %s حرف).';
    private const PLACEHOLDER = 'مبلغ به تومان';
    private const NO_AMOUNT = 'مبلغ را به تومان و با رقم در خط اول بنویسید، مثلا ۵۰٬۰۰۰؛ توضیح، اگر خواستید، در خط دوم.';
    private const CREDITED = '✅ %s به کیف پول اضافه شد؛ موجودی: %s.';
    private const DEBITED = '✅ %s از کیف پول کم شد؛ موجودی: %s.';
    private const ALREADY = '✅ این تغییر پیش‌تر ثبت شده بود؛ موجودی: %s.';
    private const ANSWERED = 'به این پرسش پیش‌تر جواب داده شده و همان ثبت شده است؛ برای تغییر دیگری دوباره دکمه افزایش یا کاهش را بزنید.';

    public function __construct(
        private readonly CustomerCards $cards,
        private readonly UserActions $actions,
        private readonly WalletService $wallet,
        private readonly Reviewers $reviewers,
    ) {}

    public function handle(Update $update): void
    {
        $press = $this->cards->press($update, self::PREFIX);
        if ($press === null) {
            return;
        }
        if (!in_array($press->action, [self::OPEN, self::CREDIT, self::DEBIT], true) || $press->args !== []) {
            $this->cards->answer($press);

            return;
        }

        if ($press->action === self::OPEN) {
            $this->cards->show($press, $this->view($press->user, $press->admin), self::buttons($press->user, $press->admin));

            return;
        }
        try {
            CustomerCards::mayChange($press->user, $press->admin);
        } catch (DomainRuleException $e) {
            $this->cards->refused($press, $e);

            return;
        }
        $question = $press->action === self::CREDIT ? '➕ ' . self::CREDIT_QUESTION : '➖ ' . sprintf(self::DEBIT_QUESTION, Messages::balance($press->user->balance()));
        $this->cards->ask($press, self::PREFIX, $press->action, [], $question . "\n" . sprintf(self::NOTE_LINE, Persian::digits(Ledger::NOTE_MAX)), self::PLACEHOLDER);
    }

    /**
     * The amount on the reply's first line, its note on the second: the wallet credited or debited under the replying
     * admin's name, keyed by the question. Refused by the wallet's rules (a debit past the balance, their own wallet) or
     * the card's (an agent's account): its words, the question kept.
     *
     * @throws DomainRuleException
     */
    public function answered(CardReply $reply): void
    {
        $type = match ($reply->kind) {
            self::CREDIT => WalletTransactionType::Credit,
            self::DEBIT => WalletTransactionType::Debit,
            default => null,
        };
        if ($type === null) {
            return;
        }
        CustomerCards::mayChange($reply->user, $reply->admin);

        // «۵۰٬۰۰۰», «50000», «۵۰ هزار تومان».
        [$typed, $note] = array_pad(preg_split('/\R/u', $reply->text, 2) ?: [], 2, '');
        $typed = trim((string) $typed);
        $amount = CustomerCards::number($typed) ?? Input::amountOf($typed, Unit::Toman);
        if ($amount === null) {
            $this->cards->retry($reply, self::NO_AMOUNT);

            return;
        }

        // The note on one line, as the customer's history shows it.
        $note = trim((string) preg_replace('/\s+/u', ' ', (string) $note));
        try {
            $line = $this->actions->adjustWallet($reply->user, Actor::groupAdmin($reply->admin), [
                'type' => $type->value,
                'amount' => $amount,
                'description' => $note === '' ? null : $note,
            ], 'report-group:' . $reply->chatId . ':' . $reply->promptId);
        } catch (KeyReusedException) {
            $this->cards->retry($reply, self::ANSWERED);

            return;
        }

        // The balance now: a line written earlier may have others after it.
        $balance = Messages::balance($reply->user->balance());
        $word = match (true) {
            !$line->wasRecentlyCreated => sprintf(self::ALREADY, $balance),
            $type === WalletTransactionType::Credit => sprintf(self::CREDITED, Money::format($line->amount), $balance),
            default => sprintf(self::DEBITED, Money::format($line->amount), $balance),
        };
        $this->cards->done($reply, $word, $this->view($reply->user, $reply->admin), self::buttons($reply->user, $reply->admin));
    }

    /** The section's words: the balance, an agent's credit, the ledger's latest lines — and why it is locked. */
    private function view(User $user, User $admin): string
    {
        $lines = [CustomerCards::header($user, 'کیف پول'), '👛 موجودی: ' . Messages::balance($user->balance())];
        if (Money::isPositive($user->credit())) {
            $lines[] = '💳 اعتبار نمایندگی: ' . Messages::credit($user->credit());
        }

        $ledger = $this->wallet->lines($user, self::LINES);
        $names = $this->reviewers->names($ledger->pluck('reviewer'));
        $lines[] = '';
        if ($ledger->isEmpty()) {
            $lines[] = self::NO_LINES;
        } else {
            $lines[] = '🧾 <b>آخرین تراکنش‌ها</b>';
            foreach ($ledger as $line) {
                $lines[] = $this->line($line, $names);
            }
        }
        $locked = CustomerCards::lockOf($user, $admin);

        return implode("\n", $locked === null ? $lines : [...$lines, '', $locked]);
    }

    /**
     * A ledger line: «➕ ۱۰٬۰۰۰ تومان · <its description> · 🎧 <who of support wrote it> · ۲۱ آبان ۱۴۰۵».
     *
     * @param array<string, string> $names The names of the admins who wrote lines by hand (Reviewers::names())
     */
    private function line(WalletTransaction $line, array $names): string
    {
        $description = $line->description === null || trim($line->description) === '' ? '—' : Messages::rowNumbers(htmlspecialchars(Limits::fit(trim($line->description), self::DESCRIPTION_MAX)));

        return implode(' · ', array_filter([
            ($line->type === WalletTransactionType::Credit ? '➕ ' : '➖ ') . Money::format($line->amount),
            $description,
            $this->writer($line->reviewer, $names),
            Persian::date($line->created_at),
        ], static fn(?string $part): bool => $part !== null));
    }

    /**
     * «🎧 …» — who of support wrote a line by hand, as the report group reads them (Reviewers::shared()): an admin by
     * name, else by handle, number or Telegram id; the owner as plain «پشتیبانی». Null for a line the shop wrote.
     *
     * @param array<string, string> $names
     */
    private function writer(?string $reviewer, array $names): ?string
    {
        $by = $this->reviewers->shared($reviewer);
        if ($reviewer === null || $by === null) {
            return null;
        }

        $who = match (true) {
            isset($names[$reviewer]) => htmlspecialchars(mb_substr($names[$reviewer], 0, self::NAME_MAX)),
            $by === Reviewers::SUPPORT => $by,
            preg_match('/^(user|agent)#(\d+)$/', $by, $kept) === 1 => ($kept[1] === 'user' ? 'کاربر' : 'نماینده') . " \u{200E}#" . $kept[2],
            str_starts_with($by, 'tg:') => "شناسه تلگرام \u{200E}" . substr($by, 3),
            // A handle, after a left-to-right mark: after the Persian words it would read «sara@».
            default => "\u{200E}" . htmlspecialchars(mb_substr($by, 0, self::NAME_MAX)),
        };

        return '🎧 ' . $who;
    }

    /**
     * «➖ کاهش موجودی» and «➕ افزایش موجودی» (the increase on the right), then «⬅️ بازگشت» — only the way back when the
     * card is locked (CustomerCards::lockOf()).
     *
     * @return list<list<array<string, mixed>>>
     */
    private static function buttons(User $user, User $admin): array
    {
        $keyboard = InlineKeyboard::make();
        if (CustomerCards::lockOf($user, $admin) === null) {
            $keyboard->row(
                CustomerCards::button('➖ کاهش موجودی', CustomerCards::data(self::PREFIX, self::DEBIT, $user->id)),
                CustomerCards::button('➕ افزایش موجودی', CustomerCards::data(self::PREFIX, self::CREDIT, $user->id)),
            );
        }

        return $keyboard->row(CustomerCards::back($user->id))->build()['inline_keyboard'];
    }
}
