<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Agency;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Owner\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Support\Unit;

/**
 * An agent's traffic (the panel's TrafficModal): what their bot may still sell, «➕ افزایش حجم» / «➖ کاهش حجم» — the
 * gigabytes, then the change asked first: «✍️ با توضیح» (a note on their ledger, then sent), «⏭ بدون توضیح» (sent) or
 * «✖️ انصراف»; sent once (an Idempotency-Key made for the change) —, and their traffic ledger, the newest first, a page
 * at a time.
 *
 * Actions: `add`, `sub` (ask the gigabytes), `gp` (sign, preset), `nw` (asks the note), `nn` (no note: sends), `quit`
 * (the change dropped), `page` (n).
 */
final class AgentTrafficScreen extends BaseScreen
{
    public const ID = 'agt';

    /** What a line of an agent's traffic was (the panel's ledger words). */
    private const TYPES = ['purchase' => 'خرید حجم', 'sale' => 'فروش', 'renewal' => 'تمدید', 'extension' => 'افزایش حجم', 'refund' => 'بازگشت حجم', 'adjust' => 'اصلاح پشتیبانی'];

    /** The gigabytes a tap types. */
    private const PRESETS = [10, 50, 100];

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    public function show(Admin $admin, array $args): View
    {
        $id = self::id($args);
        if ($id > 0) {
            AgentScreen::load($admin, $id);
            $admin->remember(self::ID, ['agent' => $id, 'page' => 1, 'gb' => null, 'key' => null]);
        }

        return $this->view($admin);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        switch ($action) {
            case 'add':
            case 'sub':
                $sign = $action === 'add' ? 1 : 0;

                return $admin->ask((new Prompt(
                    $sign === 1 ? '➕ افزایش حجم (گیگابایت)' : '➖ کاهش حجم (گیگابایت)',
                    self::ID,
                    'gb',
                    [$sign],
                    'حجم باقی‌مانده ربات: ' . $this->balance($admin),
                    Button::to('✖️ لغو', self::ID),
                ))->choices(...array_map(static fn(int $n, int $gb): Button => Button::to(Fmt::number($gb) . ' گیگ', self::ID, 'gp', $sign, $n), array_keys(self::PRESETS), self::PRESETS)));
            case 'gp':
                return $this->note($admin, self::id($args) === 1, (string) (self::PRESETS[self::id($args, 1)] ?? self::PRESETS[0]));
            case 'nw':
                if ((string) ($admin->state(self::ID)['gb'] ?? '') === '') {
                    break;
                }

                return $admin->ask(new Prompt('توضیح', self::ID, 'note', [], 'نماینده این متن را در گردش حجمش می‌بیند؛ مثلا: ' . (str_starts_with((string) $admin->state(self::ID)['gb'], '-') ? 'اصلاح اشتباه' : 'هدیه شروع کار'), Button::to('✖️ انصراف', self::ID, 'quit')));
            case 'nn':
                return $this->send($admin, '');
            case 'quit':
                $admin->remember(self::ID, ['gb' => null, 'key' => null]);
                break;
            case 'page':
                $admin->remember(self::ID, ['page' => self::page($args)]);
                break;
        }

        return $this->view($admin);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        return match ($action) {
            // «۵۰», «۱٫۵» or «۵۰ گیگ», as every other traffic question takes it.
            'gb' => $this->note($admin, self::id($args) === 1, $answer->decimal() ?? (($whole = $answer->amount(Unit::Gigabyte)) === null ? null : (string) $whole) ?? throw new ApiRefusal(422, 'حجم را به گیگابایت بنویسید، مثل ۵۰ یا «۵۰ گیگ».')),
            'note' => $this->send($admin, $answer->text()),
            default => $this->view($admin),
        };
    }

    /** The gigabytes kept for the change, with its key; the change asked first, with a note or without. */
    private function note(Admin $admin, bool $add, string $gb): View
    {
        $gb = ltrim($gb, '-');
        $admin->remember(self::ID, ['gb' => ($add ? '' : '-') . $gb, 'key' => bin2hex(random_bytes(16))]);
        $name = Words::user((AgentScreen::row($admin, (int) ($admin->state(self::ID)['agent'] ?? 0)) ?? [])['user'] ?? null);

        return Words::withNote(
            Fmt::number($gb) . ' گیگابایت ' . ($add ? 'به حجم ربات ' . $name . ' اضافه شود؟' : 'از حجم ربات ' . $name . ' کم شود؟'),
            'حجم باقی‌مانده ربات: ' . $this->balance($admin) . "\n" . 'نماینده تغییر و توضیحش را در گردش حجمش می‌بیند.',
            Button::to('✍️ با توضیح', self::ID, 'nw'),
            Button::to('⏭ بدون توضیح', self::ID, 'nn'),
            Button::to('✖️ انصراف', self::ID, 'quit'),
        );
    }

    /** The change sent, once: the agent as the API then has them. */
    private function send(Admin $admin, string $note): View
    {
        $state = $admin->state(self::ID);
        $id = (int) ($state['agent'] ?? 0);
        $gb = (string) ($state['gb'] ?? '');
        if ($id === 0 || $gb === '') {
            return $this->view($admin);
        }
        $agent = (array) ($admin->api->post("/agency/agents/{$id}/traffic", ['gb' => $gb, 'note' => $note], ['Idempotency-Key' => (string) ($state['key'] ?? bin2hex(random_bytes(16)))])['agent'] ?? []);
        AgentScreen::keep($admin, $agent);
        $admin->remember(self::ID, ['gb' => null, 'key' => null, 'page' => 1]);
        $admin->notice('✅ حجم ' . (str_starts_with($gb, '-') ? 'کم' : 'اضافه') . ' شد · باقی‌مانده: ' . Fmt::plain(Fmt::bytes((int) ($agent['bot']['traffic_balance'] ?? 0))));

        return $this->view($admin);
    }

    private function balance(Admin $admin): string
    {
        $row = AgentScreen::row($admin, (int) ($admin->state(self::ID)['agent'] ?? 0)) ?? [];

        return Fmt::bytes((int) ($row['bot']['traffic_balance'] ?? 0));
    }

    private function view(Admin $admin): View
    {
        $state = $admin->state(self::ID);
        $id = (int) ($state['agent'] ?? 0);
        $row = AgentScreen::row($admin, $id);
        if ($row === null) {
            return $admin->open(AgentsScreen::ID);
        }
        $answer = $admin->api->get("/agency/agents/{$id}/traffic", ['page' => max(1, (int) ($state['page'] ?? 1))]);
        $lines = Json::rows($answer, 'lines');
        $meta = (array) ($answer['meta'] ?? []);

        $view = View::make('📦 حجم ' . Words::user($row['user'] ?? null), 'مدیریت', 'نمایندگان', 'نماینده‌ها')
            ->line('حجم باقی‌مانده ربات: <b>' . $this->balance($admin) . '</b>')
            ->line()
            ->line('<b>گردش حجم</b>');
        if ($lines === []) {
            $view->line('هنوز گردشی ندارد. خرید حجم، فروش، تمدید و افزایش حجم سرویس‌های ربات این‌جا ثبت می‌شوند.');
        }
        foreach ($lines as $line) {
            $bytes = (int) ($line['bytes'] ?? 0);
            $view->line(($bytes > 0 ? '➕ ' : '➖ ') . Fmt::bytes(abs($bytes)) . ' · ' . Fmt::e(self::TYPES[(string) ($line['type'] ?? '')] ?? (string) ($line['type'] ?? ''))
                . (is_string($line['description'] ?? null) && $line['description'] !== '' ? ' · ' . Fmt::e(Fmt::cut($line['description'], 50)) : '')
                . ' · ' . Fmt::date(is_string($line['created_at'] ?? null) ? $line['created_at'] : null)
                . ' · مانده ' . Fmt::bytes((int) ($line['balance_after'] ?? 0))
                . (Words::reviewer($line) !== null ? ' · ' . Words::reviewer($line) : ''));
        }

        return $view->pager((int) ($meta['page'] ?? 1), (int) ($meta['last_page'] ?? 1), self::ID, 'page')
            ->row(Button::to('➕ افزایش حجم', self::ID, 'add'), Button::to('➖ کاهش حجم', self::ID, 'sub'))
            ->back(AgentScreen::ID, 'show', $id);
    }
}
