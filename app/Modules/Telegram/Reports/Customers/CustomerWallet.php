<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports\Customers;

use App\Core\Database\Ledger;
use App\Core\Exceptions\DomainRuleException;
use App\Core\Exceptions\KeyReusedException;
use App\Core\Exceptions\ValidationException;
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
 * writes one line. A debit that would leave the wallet below zero is asked again first — the debt it leaves, «✅ تایید»
 * and «⬅️ انصراف» — so a zero too many is caught before it is written. Never the admin's own account nor an agent's.
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

    /**
     * «✅ تایید» and «⬅️ انصراف» on a debit that leaves a debt: `uw:y:<customer id>:<nonce>`, `uw:n:<customer id>:<nonce>`
     * — what it writes (the customer, the amount, the question, the note) recorded on the card with the nonce, never
     * read from the data a client sends.
     */
    private const CONFIRM = 'y';
    private const CANCEL = 'n';

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

    /** A debit that leaves a debt, asked again under the wallet's words; and the word under the reply. */
    private const ASK_DEBIT = '➖ <b>کاهش موجودی: %s</b>';
    private const ASK_NOTE = '📝 توضیح: %s';
    private const ASK_AFTER = 'موجودی بعد از آن: <b>%s</b>';
    private const ASK_INTO_DEBT = '⚠️ موجودی از صفر کمتر می‌شود و مشتری بدهکار می‌شود؛ ثبت شود؟';
    private const ASK_DEEPER = '⚠️ بدهی مشتری بیشتر می‌شود؛ ثبت شود؟';
    private const ASKED = '⚠️ بعد از این کاهش، موجودی کمتر از صفر است؛ برای ثبت آن «✅ تایید» را روی کارت بزنید.';

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
        if (in_array($press->action, [self::CONFIRM, self::CANCEL], true) && count($press->args) === 1 && preg_match('/^[0-9a-z]{6}$/', $press->args[0]) === 1) {
            if ($press->action === self::CONFIRM) {
                $this->confirmed($press, $press->args[0]);
            } else {
                $this->cards->dropNonces((int) $press->update->chatId(), (int) $press->update->messageId(), self::PREFIX);
                $this->cards->show($press, $this->view($press->user, $press->admin), self::buttons($press->user, $press->admin));
            }

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
     * admin's name, keyed by the question. A debit that would leave a debt the wallet takes is not written yet: the card
     * asks again (askDebt()), and «✅ تایید» writes it (confirmed()). Refused by the wallet's rules (a debt past
     * UserActions::DEBT_MAX, their own wallet) or the card's (an agent's account): its words, the question kept.
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
        $after = $type === WalletTransactionType::Debit ? self::debtAfter($reply->user, $amount, $note) : null;
        if ($after !== null) {
            $this->askDebt($reply, $amount, $note, $after);

            return;
        }
        try {
            $line = $this->adjust($reply->user, $reply->admin, $type, $amount, $note, self::key($reply->chatId, $reply->promptId));
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

    /**
     * The balance a debit of `$amount` leaves when it is a debt the wallet would write — null when it leaves none, or
     * when the wallet refuses it anyway (an amount that is none, a note too long, a debt past UserActions::DEBT_MAX):
     * that refusal is said at once, by the wallet's own words.
     */
    private static function debtAfter(User $user, int $amount, string $note): ?string
    {
        if ($amount <= 0 || $amount > Money::AMOUNT_MAX || mb_strlen($note) > Ledger::NOTE_MAX) {
            return null;
        }
        $after = Money::subtract($user->balance(), $amount);

        return Messages::isDebt($after) && Money::compare($after, Money::subtract(0, UserActions::DEBT_MAX)) >= 0 ? $after : null;
    }

    /**
     * A debit that leaves a debt asked again: the wallet's words with the amount, the note and the balance it leaves,
     * «⬅️ انصراف» back to the wallet and «✅ تایید» — one nonce for both, recorded on the card with what it writes (the
     * customer, the amount, the question for the key, the note), an earlier ask's on the card dropped —; the question
     * deleted (it is answered: the card asks now).
     */
    private function askDebt(CardReply $reply, int $amount, string $note, string $after): void
    {
        $user = $reply->user;
        $lines = [sprintf(self::ASK_DEBIT, Money::format($amount))];
        if ($note !== '') {
            $lines[] = sprintf(self::ASK_NOTE, htmlspecialchars($note));
        }
        $lines[] = sprintf(self::ASK_AFTER, Messages::balance($after));
        $lines[] = Messages::isDebt($user->balance()) ? self::ASK_DEEPER : self::ASK_INTO_DEBT;

        $this->cards->dropNonces($reply->chatId, $reply->cardId, self::PREFIX);
        $nonce = $this->cards->replyNonce($reply, self::PREFIX, (string) json_encode(['customer' => $user->id, 'amount' => $amount, 'prompt' => $reply->promptId, 'note' => $note], JSON_UNESCAPED_UNICODE));
        $rows = InlineKeyboard::make()->row(
            CustomerCards::button('⬅️ انصراف', CustomerCards::data(self::PREFIX, self::CANCEL, $user->id, $nonce)),
            CustomerCards::button('✅ تایید', CustomerCards::data(self::PREFIX, self::CONFIRM, $user->id, $nonce), 'danger'),
        )->build()['inline_keyboard'];

        $this->cards->done($reply, self::ASKED, $this->view($user, $reply->admin) . "\n\n" . implode("\n", $lines), $rows);
    }

    /**
     * «✅ تایید» on a debit that leaves a debt: what its card recorded with the nonce — for this customer, or it is an old
     * button —, written once (CustomerCards::once()) under the key its question gave it, by the wallet's rules as they now
     * stand — refused meanwhile (a locked account, a debt now past UserActions::DEBT_MAX, the button pressed already or
     * cancelled): the wallet as it stands, with why in a popup.
     */
    private function confirmed(CardPress $press, string $nonce): void
    {
        $user = $press->user;
        $made = null;
        try {
            CustomerCards::mayChange($user, $press->admin);
            $asked = json_decode((string) $this->cards->recorded($press, self::PREFIX, $nonce), true);
            if (!is_array($asked) || ($asked['customer'] ?? null) !== $user->id || !is_int($asked['amount'] ?? null) || !is_int($asked['prompt'] ?? null) || !is_string($asked['note'] ?? null)) {
                throw ValidationException::on('status', CustomerCards::BUTTON_OLD);
            }
            [$amount, $promptId, $note] = [$asked['amount'], $asked['prompt'], $asked['note']];
            $this->cards->once($press, self::PREFIX, $nonce, function () use ($press, $amount, $note, $promptId, &$made): void {
                $made = $this->adjust($press->user, $press->admin, WalletTransactionType::Debit, $amount, $note, self::key((int) $press->update->chatId(), $promptId));
            });
        } catch (KeyReusedException) {
            $this->cards->show($press, $this->view($user, $press->admin), self::buttons($user, $press->admin), self::ANSWERED, alert: true);

            return;
        } catch (DomainRuleException $e) {
            $this->cards->show($press, $this->view($user, $press->admin), self::buttons($user, $press->admin), CustomerCards::words($e), alert: true);

            return;
        }

        $balance = Messages::balance($user->balance());
        $word = $made instanceof WalletTransaction && $made->wasRecentlyCreated ? sprintf(self::DEBITED, Money::format($amount), $balance) : sprintf(self::ALREADY, $balance);
        $this->cards->show($press, $this->view($user, $press->admin), self::buttons($user, $press->admin), $word);
    }

    /**
     * The wallet adjusted as the panel does it (UserActions::adjustWallet()), under the admin's name and `$key`.
     *
     * @throws DomainRuleException|KeyReusedException
     */
    private function adjust(User $user, User $admin, WalletTransactionType $type, int $amount, string $note, string $key): WalletTransaction
    {
        return $this->actions->adjustWallet($user, Actor::groupAdmin($admin), [
            'type' => $type->value,
            'amount' => $amount,
            'description' => $note === '' ? null : $note,
        ], $key);
    }

    /** A question's line's key: the group and the question — the same reply, or its «✅ تایید», writes one line. */
    private static function key(int $chatId, int $promptId): string
    {
        return 'report-group:' . $chatId . ':' . $promptId;
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
        // Its row numbers marked before it is escaped: an entity's own «#039» is no row's number.
        $description = $line->description === null || trim($line->description) === '' ? '—' : htmlspecialchars(Messages::rowNumbers(Limits::fit(trim($line->description), self::DESCRIPTION_MAX)));

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
