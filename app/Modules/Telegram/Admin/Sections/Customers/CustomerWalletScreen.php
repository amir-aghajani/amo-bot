<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Auth\StaffGrant;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Messages;
use App\Support\Unit;

/**
 * A customer's wallet (the panel's «کیف پول» card and dialog): the balance, the ledger — a page of lines, each with what
 * it was, its date, the balance after, a transfer's other customer and the admin who wrote it by hand — and, for a seat
 * the `wallet` grant opens, a credit or a debit by hand: the amount, a note the customer sees (or none), the two said
 * once more, then sent once under its Idempotency-Key (the same change pressed twice is made once).
 *
 * Opened with the customer's id and a ledger page. Actions: `add` / `sub` (id: a credit / a debit asked), `amt` (id:
 * the amount asked again), `note` (id: the note asked again), `skip` (id: no note), `do` (id: sent).
 */
final class CustomerWalletScreen extends BaseScreen
{
    public const ID = 'usw';

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

        $view = View::make('👛 کیف پول', 'مدیریت', 'فروش', 'کاربران', Customer::name($user))
            ->facts(['موجودی فعلی' => '<b>' . Fmt::e(Messages::balance(Customer::money($user['balance'] ?? null))) . '</b>'])
            ->line('افزایش یا کاهش دستی، با توضیحی که مشتری در تاریخچه کیف پولش می‌بیند.')
            ->line()
            ->line('<b>تراکنش‌ها</b>' . ($pages > 1 ? ' · صفحه ' . Fmt::number($page) . ' از ' . Fmt::number($pages) : ''));
        if ($lines === []) {
            $view->line('هنوز تراکنشی ندارد؛ شارژها، خریدها و تغییرهای دستی این‌جا ثبت می‌شوند.');
        }
        foreach ($shown as $line) {
            $view->line(self::ledgerLine($line));
        }

        if ($admin->seat->holds(StaffGrant::Wallet) && !Customer::own($admin, $id)) {
            $view->row(Button::to('➕ افزایش موجودی', self::ID, 'add', $id), Button::to('➖ کاهش موجودی', self::ID, 'sub', $id));
        }
        $view->pager($page, $pages, self::ID, 'show', $id);

        return $view->back(CustomerScreen::ID, 'show', $id);
    }

    /**
     * One ledger line as the panel's list says it: in or out and how much, what it was, when, the balance after, a
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

    /** The next step of the change: the amount, the note, then the change said once more. */
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

        return View::make($credit ? '➕ افزایش موجودی' : '➖ کاهش موجودی', 'مدیریت', 'فروش', 'کاربران', Customer::known($admin, $id), 'کیف پول')
            ->facts([
                'مبلغ' => '<b>' . Fmt::money($draft['amount']) . '</b>',
                'توضیح' => $note === null ? 'بدون توضیح' : Fmt::e($note),
            ])
            ->note('مشتری توضیح را در تاریخچه کیف پولش می‌بیند.')
            ->row(Button::to($credit ? '✔️ افزایش موجودی' : '✔️ کاهش موجودی', self::ID, 'do', $id))
            ->row(Button::to('✏️ مبلغ', self::ID, 'amt', $id), Button::to('✏️ توضیح', self::ID, 'note', $id))
            ->row(Button::to('✖️ انصراف', self::ID, 'show', $id));
    }

    private function askAmount(Admin $admin, int $id): View
    {
        $draft = $this->draft($admin, $id) ?? [];

        return $admin->ask(new Prompt(
            ($draft['type'] ?? '') === 'debit' ? 'چه مبلغی از کیف پول کم شود؟' : 'چه مبلغی به کیف پول اضافه شود؟',
            self::ID,
            'amt',
            [$id],
            'مبلغ را به تومان بنویسید، مثل ۵۰۰۰۰ یا «۵۰ هزار تومان».' . (is_int($draft['amount'] ?? null) ? "\nالان: " . Fmt::money($draft['amount']) : ''),
        ));
    }

    private function askNote(Admin $admin, int $id): View
    {
        $draft = $this->draft($admin, $id) ?? [];
        $example = ($draft['type'] ?? '') === 'debit' ? 'مثلا: اصلاح اشتباه' : 'مثلا: هدیه، جبران قطعی';
        $now = is_string($draft['note'] ?? null) && $draft['note'] !== '' ? "\nالان: " . Fmt::e($draft['note']) : '';

        return $admin->ask((new Prompt('توضیح', self::ID, 'note', [$id], 'مشتری این متن را در تاریخچه کیف پولش می‌بیند. ' . $example . $now))
            ->choices(Button::to('⏭ بدون توضیح', self::ID, 'skip', $id)));
    }

    /** The change sent once, under the key its draft keeps for these very values; the wallet as it then stands. */
    private function send(Admin $admin, int $id): View
    {
        $draft = $this->draft($admin, $id);
        if ($draft === null || !is_int($draft['amount'] ?? null)) {
            return $this->show($admin, [(string) $id]);
        }
        $body = ['type' => $draft['type'], 'amount' => (string) $draft['amount'], 'description' => is_string($draft['note'] ?? null) ? $draft['note'] : ''];
        $key = 'bot-' . (string) ($draft['nonce'] ?? '') . '-' . substr(sha1((string) json_encode($body)), 0, 16);
        $answer = $admin->api->post("/users/{$id}/wallet", $body, ['Idempotency-Key' => $key]);
        $admin->forget(self::ID);
        $user = Customer::row($answer);
        $admin->notice(($draft['type'] === 'credit' ? '✅ افزایش' : '✅ کاهش') . ' موجودی ثبت شد · موجودی جدید: ' . Messages::balance(Customer::money($user['balance'] ?? null)));

        return $this->view($admin, $answer, 1);
    }
}
