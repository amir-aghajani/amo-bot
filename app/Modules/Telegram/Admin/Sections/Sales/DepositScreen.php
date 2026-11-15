<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Sales;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One bank deposit (the panel's deposit dialog, from the list's kept page — the panel has no route for one): what came of
 * it and why, its sum in Toman and Rial, the bank and its sender, when it came, the card payment it approved — its order
 * and customer, each a button to its own screen — and the SMS as the phone app sent it, or — Rikhtam sends no SMS, only
 * what it read of one — what Rikhtam sent, each spoken of as the panel does (SOURCES).
 */
final class DepositScreen extends BaseScreen
{
    public const ID = 'dpv';

    /** How a deposit is spoken of by where it came from: what it is, and what its text is; ForApp's for any other. */
    private const SOURCES = [
        'forapp' => ['پیامک واریز بانک، همان‌طور که فوراپ فرستاد.', 'متن پیامک'],
        'rikhtam' => ['واریزی که ریختم از پیامک بانک خبر داد؛ ریختم خود پیامک را نمی‌فرستد.', 'آنچه ریختم فرستاد'],
    ];

    public function show(Admin $admin, array $args): View
    {
        $rows = (array) ($admin->state(DepositsScreen::ID)['rows'] ?? []);
        $id = self::id($args);
        $row = $rows[$id] ?? $rows[(string) $id] ?? null;

        return is_array($row) ? $this->view($row) : $admin->open(DepositsScreen::ID);
    }

    /** @param array<string, mixed> $deposit */
    private function view(array $deposit): View
    {
        $amount = Json::text($deposit, 'amount');
        $rial = Json::text($deposit, 'amount_rial');
        $payment = Json::part($deposit, 'payment');
        $bank = Json::text($deposit, 'bank');
        $sender = Json::text($deposit, 'sender');
        $body = Json::text($deposit, 'body');
        [$said, $text] = self::SOURCES[(string) ($deposit['source'] ?? '')] ?? self::SOURCES['forapp'];

        $view = View::make('📩 ' . ($amount === null ? 'بدون مبلغ' : Fmt::money($amount)), 'مدیریت', 'فروش', 'واریزهای پیامکی')->line('<i>' . Fmt::e($said) . '</i>')->facts([
            'وضعیت' => Fmt::e(Sales::word(Sales::DEPOSIT_STATUS, $deposit['status'] ?? '')),
            'توضیح' => Json::text($deposit, 'note') !== null ? Fmt::e((string) Json::text($deposit, 'note')) : null,
            'مبلغ' => ($amount === null ? 'بدون مبلغ' : Fmt::money($amount)) . ($rial !== null ? ' (' . Fmt::number($rial) . ' ریال)' : ''),
            'بانک' => $bank === null && $sender === null ? Fmt::NONE : trim(Fmt::e($bank) . ' ' . ($sender !== null ? Fmt::ltr($sender) : '')),
            'زمان' => Fmt::date(Json::text($deposit, 'received_at') ?? Json::text($deposit, 'created_at'), true),
            'پرداخت' => $payment === [] ? null : Fmt::id((int) ($payment['id'] ?? 0)) . ' · سفارش ' . Fmt::id((int) ($payment['order_id'] ?? 0)),
        ]);
        if ($payment !== []) {
            $view->facts(Sales::customerFacts(Json::part($payment, 'customer')));
        }
        $view->line()->line('<b>' . Fmt::e($text) . '</b>')->line($body === null ? 'متنی نرسیده است.' : '<blockquote>' . Fmt::e($body) . '</blockquote>');

        if ($payment !== []) {
            $view->row(
                Button::to('💳 پرداخت ' . Fmt::id((int) ($payment['id'] ?? 0)), PaymentScreen::ID, 'show', (int) ($payment['id'] ?? 0)),
                Button::to('🛒 سفارش ' . Fmt::id((int) ($payment['order_id'] ?? 0)), OrderScreen::ID, 'show', (int) ($payment['order_id'] ?? 0)),
            )->row(...Sales::customerButtons(Json::part($payment, 'customer')));
        }

        return $view->back(DepositsScreen::ID);
    }
}
