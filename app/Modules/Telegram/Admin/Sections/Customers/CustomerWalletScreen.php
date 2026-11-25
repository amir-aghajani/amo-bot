<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Core\Database\Ledger;
use App\Modules\Auth\StaffGrant;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Modules\Telegram\Messages;
use App\Modules\Users\Services\UserActions;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Unit;

/**
 * A customer's wallet (the panel's «کیف پول» card and dialog): the balance as a card, then the ledger — a page of lines,
 * each with what it was, its date, the balance after, a transfer's other customer and the admin who wrote it by hand —
 * and, for a seat the `wallet` grant opens, a credit or a debit by hand: the amount, a note the customer sees (or none),
 * then the change said once more with the balance it leaves — a debt in words when a debit goes below zero —, sent once
 * under its Idempotency-Key (the same change pressed twice is made once).
 *
 * Opened with the customer's id and a ledger page. Actions: `add` / `sub` (id: a credit / a debit asked), `amt` (id:
 * the amount asked again), `note` (id: the note asked again), `skip` (id: no note), `do` (id: sent).
 */
final class CustomerWalletScreen extends BaseScreen
{
    public const ID = 'usw';

    public const TITLE = Icons::WALLET . ' کیف پول';

    /** Ledger lines a page. */
    private const LINES = 10;

    public function show(Admin $admin, array $args): View
    {
        $id = Customer::current($admin, $args);
        if ($id === 0) {
            return $admin->open(UsersScreen::ID);
        }

        return $this->view($admin, $admin->api->get("/users/{$id}/wallet"), self::page($args, 1));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        switch ($action) {
            case 'add':
            case 'sub':
                $admin->remember(self::ID, ['user' => $id, 'type' => $action === 'add' ? 'credit' : 'debit', 'amount' => null, 'note' => null, 'noted' => false, 'nonce' => bin2hex(random_bytes(8))]);

                return $this->askAmount($admin, $id);
            case 'amt':
                return $this->draft($admin, $id) === null ? $this->show($admin, [(string) $id]) : $this->askAmount($admin, $id);
            case 'note':
                return $this->draft($admin, $id) === null ? $this->show($admin, [(string) $id]) : $this->askNote($admin, $id);
            case 'skip':
                if ($this->draft($admin, $id) !== null) {
                    $admin->remember(self::ID, ['note' => null, 'noted' => true]);
                }

                return $this->next($admin, $id);
            case 'do':
                return $this->send($admin, $id);
        }

        return $this->show($admin, $args);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $id = self::id($args);
        if ($this->draft($admin, $id) === null) {
            return $this->show($admin, [(string) $id]);
        }
        if ($action === 'amt') {
            $amount = $answer->amount(Unit::Toman) ?? throw new ApiRefusal(422, 'مبلغ را به تومان و بدون اعشار بنویسید، مثل ۵۰۰۰۰ یا «۵۰ هزار تومان».', ['amount' => ['مبلغ را به تومان و بدون اعشار بنویسید.']]);
            $admin->remember(self::ID, ['amount' => $amount]);
        } elseif ($action === 'note') {
            $admin->remember(self::ID, ['note' => $answer->text(), 'noted' => true]);
        }

        return $this->next($admin, $id);
    }

    /**
     * The wallet as the panel answered it, its ledger at page `$page`.
     *
     * @param array<string, mixed> $answer
     */
    private function view(Admin $admin, array $answer, int $page): View
    {
        $user = Customer::row($answer);
        $id = Customer::int($user, 'id');
        Customer::opened($admin, $user);
        $lines = Json::rows($answer, 'transactions');
        [$shown, $page, $pages] = View::slice($lines, $page, self::LINES);

        $view = View::make(self::TITLE, ...Customer::under(Customer::name($user)))
            ->card(['موجودی' => Fmt::e(Messages::balance(Customer::balance($answer)))])
            ->section('🧾 تراکنش‌ها');
        if ($lines === []) {
            $view->line('هنوز تراکنشی ندارد؛ شارژها، خریدها و تغییرهای دستی این‌جا ثبت می‌شوند.');
        }
        foreach ($shown as $line) {
            $view->line(self::ledgerLine($line));
        }
        if ($admin->seat->holds(StaffGrant::Wallet) && !Customer::own($admin, $id)) {
            $view->note('افزایش یا کاهش دستی، با توضیحی که مشتری در تاریخچه کیف پولش می‌بیند.')
                ->row(Button::to('➕ افزایش موجودی', self::ID, 'add', $id)->success(), Button::to('➖ کاهش موجودی', self::ID, 'sub', $id));
        }

        return $view->pager($page, $pages, self::ID, Nav::SHOW, $id)
            ->back(CustomerScreen::ID, Nav::SHOW, $id)
            ->refresh(self::ID, Nav::SHOW, $id, $page);
    }

    /**
     * One ledger line as the panel's list says it: in or out and how much, what it was, then when, the balance after, a
     * transfer's other customer, and who wrote it by hand.
     *
     * @param array<string, mixed> $line
     */
    private static function ledgerLine(array $line): string
    {
        $credit = ($line['type'] ?? null) === 'credit';
        $other = is_array($line['counterpart'] ?? null) ? $line['counterpart'] : null;
        $by = Customer::text($line, 'reviewer_name') ?? Customer::text($line, 'reviewer');
        $detail = array_filter([
            Fmt::date(Customer::text($line, 'created_at')),
            'مانده ' . Fmt::e(Messages::balance(Customer::money($line['balance_after'] ?? null))),
            $other === null ? null : ($credit ? 'از ' : 'به ') . Fmt::e(Customer::name($other)),
            $by === null ? null : Fmt::ltr($by),
        ]);

        return ($credit ? '➕ ' : '➖ ') . '<b>' . Fmt::money(Customer::money($line['amount'] ?? null)) . '</b> · ' . Fmt::e(Customer::text($line, 'description') ?? Fmt::NONE)
            . "\n" . '<i>' . implode(' · ', $detail) . '</i>';
    }

    /**
     * The change being written for customer `$id`; null when none is (another customer's, or none begun).
     *
     * @return array<string, mixed>|null
     */
    private function draft(Admin $admin, int $id): ?array
    {
        $draft = $admin->state(self::ID);

        return $id > 0 && ($draft['user'] ?? null) === $id && is_string($draft['type'] ?? null) ? $draft : null;
    }

    /** The next step of the change: the amount, the note, then the change said once more with the balance it leaves. */
    private function next(Admin $admin, int $id): View
    {
        $draft = $this->draft($admin, $id);
        if ($draft === null) {
            return $this->show($admin, [(string) $id]);
        }
        if (!is_int($draft['amount'] ?? null)) {
            return $this->askAmount($admin, $id);
        }
        if (($draft['noted'] ?? false) !== true) {
            return $this->askNote($admin, $id);
        }
        $credit = $draft['type'] === 'credit';
        $note = is_string($draft['note'] ?? null) && $draft['note'] !== '' ? $draft['note'] : null;
        $balance = Customer::balance(Customer::read($admin, $id));
        $after = $credit ? Money::add($balance, $draft['amount']) : Money::subtract($balance, $draft['amount']);
        $do = Button::to($credit ? '✔️ افزایش موجودی' : '✔️ کاهش موجودی', self::ID, 'do', $id);

        $view = View::make($credit ? '➕ افزایش موجودی' : '➖ کاهش موجودی', ...Customer::under(Customer::known($admin, $id), Fmt::bare(self::TITLE)))
            ->transient();
        // A debit may leave a debt (UserActions::adjustWallet(), as deep as DEBT_MAX): said before it is sent, so a zero
        // too many shows — and one past the deepest, which the panel refuses: only that said, no balance it would leave
        // and no «✔️».
        $refused = !$credit && Money::compare($after, Money::subtract(0, UserActions::DEBT_MAX)) < 0;
        if ($refused) {
            $view->line('⚠️ ' . Fmt::e(sprintf(UserActions::DEBT_PAST_MAX, Money::format(UserActions::DEBT_MAX), Messages::balance($balance))) . ' مبلغ را عوض کنید.');
        } elseif (!$credit && Money::compare($after, 0) < 0) {
            $view->line('⚠️ موجودی از صفر کمتر می‌شود و مشتری <b>' . Fmt::e(Money::format(Money::subtract(0, $after))) . '</b> بدهکار می‌شود؛ شارژهای بعدی‌اش اول این بدهی را صاف می‌کنند.');
        }

        return $view
            ->card([
                'مبلغ' => Fmt::money($draft['amount']),
                'توضیح' => $note === null ? '' : Fmt::e($note),
                'موجودی فعلی' => Fmt::e(Messages::balance($balance)),
                'موجودی بعد از آن' => $refused ? null : Fmt::e(Messages::balance($after)),
            ])
            ->note('مشتری توضیح را در تاریخچه کیف پولش می‌بیند.')
            ->row($refused ? null : ($credit ? $do->success() : $do->danger()))
            ->row(Button::to(Words::edit('مبلغ'), self::ID, 'amt', $id), Button::to(Words::edit('توضیح'), self::ID, 'note', $id))
            ->row(Button::to(Words::CANCEL, self::ID, Nav::SHOW, $id));
    }

    /** The amount asked, over the balance now. */
    private function askAmount(Admin $admin, int $id): View
    {
        $draft = $this->draft($admin, $id) ?? [];
        $prompt = (new Prompt(
            ($draft['type'] ?? '') === 'debit' ? 'چه مبلغی از کیف پول کم شود؟' : 'چه مبلغی به کیف پول اضافه شود؟',
            self::ID,
            'amt',
            [$id],
            'موجودی فعلی: <b>' . Fmt::e(Messages::balance(Customer::balance(Customer::read($admin, $id)))) . '</b>',
        ))->example('۵۰۰۰۰')->limits('بیش از صفر، به تومان؛ «۵۰ هزار تومان» هم پذیرفته می‌شود');
        if (is_int($draft['amount'] ?? null)) {
            $prompt->current(Fmt::plain(Fmt::money($draft['amount'])), false);
        }

        return $admin->ask($prompt);
    }

    private function askNote(Admin $admin, int $id): View
    {
        $draft = $this->draft($admin, $id) ?? [];
        $prompt = (new Prompt('توضیح', self::ID, 'note', [$id], 'مشتری این متن را در تاریخچه کیف پولش می‌بیند.'))
            ->example(($draft['type'] ?? '') === 'debit' ? 'اصلاح اشتباه' : 'هدیه تولد')
            ->limits('تا ' . Persian::number(Ledger::NOTE_MAX) . ' نویسه')
            ->choices(Button::to('⏭ بدون توضیح', self::ID, 'skip', $id));
        if (is_string($draft['note'] ?? null) && $draft['note'] !== '') {
            $prompt->current($draft['note']);
        }

        return $admin->ask($prompt);
    }

    /** The change sent once, under the key its draft keeps for these very values; the wallet as it then stands. */
    private function send(Admin $admin, int $id): View
    {
        $draft = $this->draft($admin, $id);
        if ($draft === null || !is_int($draft['amount'] ?? null)) {
            return $this->show($admin, [(string) $id]);
        }
        $body = ['type' => $draft['type'], 'amount' => (string) $draft['amount'], 'description' => is_string($draft['note'] ?? null) ? $draft['note'] : ''];
        $answer = $admin->api->post("/users/{$id}/wallet", $body, ['Idempotency-Key' => Customer::requestKey($draft, $body)]);
        $admin->forget(self::ID);
        $admin->notice(($draft['type'] === 'credit' ? '✅ افزایش' : '✅ کاهش') . ' موجودی ثبت شد · موجودی جدید: ' . Messages::balance(Customer::balance($answer)));

        return $this->view($admin, $answer, 1);
    }
}
