<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports\Customers;

use App\Core\Database\Page;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Drivers\Wallet\WalletGateway;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Referrals\Models\ReferralCommission;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Ticket;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Reports\ShopReports;
use App\Modules\Telegram\Update\GroupHandler;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Text;
use Illuminate\Database\Eloquent\Builder;

/**
 * «🧾 سوابق» on a customer's card in the report group: what they did in the shop, in four tabs — «🧾 سفارش‌ها» (the
 * one it opens on), «💳 پرداخت‌ها», «🎫 تیکت‌ها», «👥 زیرمجموعه‌ها» —, each its latest SHOWN, newest first, a line
 * each, and what they add up to; the tabs under it, the one shown marked, and «⬅️ بازگشت» to the card's front.
 *
 * It only reads, so every account has it — the admin's own and an agent's too. The checks and the look are
 * CustomerCards'.
 */
final class CustomerHistory implements GroupHandler
{
    /** What the section's buttons' callback data starts with: `uh:<tab>:<customer id>`. */
    public const PREFIX = 'uh:';

    /** The section opened from the card's front: its orders tab. */
    public const OPEN = 'o';

    private const PAYMENTS = 'p';
    private const TICKETS = 't';
    private const REFERRALS = 'r';

    /** The tabs in their order, by their action: the icon of each, and its name. */
    private const TABS = [
        self::OPEN => ['🧾', 'سفارش‌ها'],
        self::PAYMENTS => ['💳', 'پرداخت‌ها'],
        self::TICKETS => ['🎫', 'تیکت‌ها'],
        self::REFERRALS => ['👥', 'زیرمجموعه‌ها'],
    ];

    /** The rows a tab shows: the latest. */
    private const SHOWN = 10;

    /** A name or a subject on a row: ten rows stay well within a message's 4096 characters. */
    private const NAME_MAX = 40;

    public function __construct(private readonly CustomerCards $cards) {}

    public function handle(Update $update): void
    {
        $press = $this->cards->press($update, self::PREFIX);
        if ($press === null) {
            return;
        }
        if ($press->args !== [] || !isset(self::TABS[$press->action])) {
            $this->cards->answer($press);

            return;
        }

        $this->cards->show($press, self::view($press->user, $press->action), self::tabs($press->user->id, $press->action));
    }

    /** The tab's words: the card's header, then its rows and what they add up to. */
    public static function view(User $user, string $tab): string
    {
        $lines = match ($tab) {
            self::PAYMENTS => self::payments($user),
            self::TICKETS => self::tickets($user),
            self::REFERRALS => self::referrals($user),
            default => self::orders($user),
        };

        return CustomerCards::header($user, 'سوابق › ' . (self::TABS[$tab] ?? self::TABS[self::OPEN])[1]) . "\n\n" . implode("\n", $lines);
    }

    /**
     * The tabs, two a row (a Persian reader's right first), the one shown marked «•», then «⬅️ بازگشت».
     *
     * @return list<list<array<string, mixed>>>
     */
    public static function tabs(int $userId, string $shown): array
    {
        $buttons = [];
        foreach (self::TABS as $tab => [$icon, $name]) {
            $buttons[] = CustomerCards::button(($tab === $shown ? '•' : $icon) . ' ' . $name, CustomerCards::data(self::PREFIX, $tab, $userId));
        }

        return InlineKeyboard::make()->grid($buttons, 2)->row(CustomerCards::back($userId))->build()['inline_keyboard'];
    }

    /**
     * «🧾 سفارش‌ها»: a row an order — its number, what it was for, its amount, where it stands, when —, then what they
     * bought: the orders paid and not given back (Order::KEPT), wallet top-ups aside (what they bought with that money
     * is among the others).
     *
     * @return list<string>
     */
    private static function orders(User $user): array
    {
        $theirs = Order::query()->where('user_id', $user->id);
        $orders = (clone $theirs)->with(['plan', 'subscription'])->orderByDesc('id')->limit(self::SHOWN)->get();
        if ($orders->isEmpty()) {
            return ['هنوز سفارشی ندارد.'];
        }

        $lines = $orders->map(static fn(Order $order): string => sprintf(
            "سفارش \u{200E}#%d · %s · %s · %s · %s",
            $order->id,
            self::purpose($order),
            Money::format($order->amount),
            self::orderStatus($order->status),
            Persian::date($order->created_at),
        ))->all();
        [$count, $sum] = Page::tally((clone $theirs)->whereIn('status', Order::KEPT)->where('type', '!=', OrderType::WalletTopUp->value), 'amount');

        return [
            ...$lines,
            ...self::older((clone $theirs)->count(), 'سفارش'),
            '',
            sprintf('💰 خریدها: %s در %s سفارش پرداخت‌شده، جدا از شارژ کیف پول', Money::format($sum), Persian::number($count)),
        ];
    }

    /**
     * What an order was for, as the report group words it (ShopReports): the plan bought, the service — its client's
     * name — renewed or given extras, the wallet, an agent's traffic.
     */
    private static function purpose(Order $order): string
    {
        $client = $order->subscription === null ? '' : ' <code>' . self::name($order->subscription->remote_name) . '</code>';
        $package = $order->packageName();
        $kind = ExtraKind::of($order->type);

        return match ($order->type) {
            OrderType::Purchase => 'خرید' . ($package === null ? '' : ' ' . self::name($package)),
            OrderType::Renewal => 'تمدید' . $client,
            OrderType::ExtraTraffic, OrderType::ExtraTime, OrderType::ExtraDevices => $order->type->label() . ($kind === null ? '' : ' ' . $kind->words($kind->quantityOf($order))) . ($client === '' ? '' : ' برای' . $client),
            OrderType::WalletTopUp => $order->type->label(),
            OrderType::Traffic => sprintf('%s (%s)', $order->type->label(), Messages::bytes((int) $order->traffic_bytes)),
        };
    }

    /**
     * «💳 پرداخت‌ها»: a row a payment — its number, amount, method, where it stands, when —, then the money that came in:
     * the payments paid (not given back), the wallet's aside (that money came in by another).
     *
     * @return list<string>
     */
    private static function payments(User $user): array
    {
        $theirs = Payment::query()->whereIn('order_id', Order::query()->where('user_id', $user->id)->select('id'));
        $payments = (clone $theirs)->with('method')->orderByDesc('id')->limit(self::SHOWN)->get();
        if ($payments->isEmpty()) {
            return ['هنوز پرداختی ندارد.'];
        }

        $lines = $payments->map(static fn(Payment $payment): string => sprintf(
            "پرداخت \u{200E}#%d · %s · %s · %s · %s",
            $payment->id,
            Money::format($payment->amount),
            self::name($payment->method->label),
            self::paymentStatus($payment->status),
            Persian::date($payment->created_at),
        ))->all();
        [$count, $sum] = Page::tally(
            (clone $theirs)->where('status', PaymentStatus::Paid->value)->whereHas('method', static fn(Builder $method) => $method->where('driver', '!=', WalletGateway::key())),
            'amount',
        );

        return [
            ...$lines,
            ...self::older((clone $theirs)->count(), 'پرداخت'),
            '',
            sprintf('💳 واریزها: %s در %s پرداخت موفق، جدا از کیف پول', Money::format($sum), Persian::number($count)),
        ];
    }

    /**
     * «🎫 تیکت‌ها»: a row a ticket — its number, subject, where it stands, its last word's date —, the latest-written
     * first; then how many wait on support.
     *
     * @return list<string>
     */
    private static function tickets(User $user): array
    {
        $tickets = Ticket::of($user)->orderByDesc('last_message_at')->orderByDesc('id')->limit(self::SHOWN)->get();
        if ($tickets->isEmpty()) {
            return ['هنوز تیکتی ندارد.'];
        }

        $lines = $tickets->map(static fn(Ticket $ticket): string => sprintf(
            "تیکت \u{200E}#%d · «%s» · %s · %s",
            $ticket->id,
            self::name($ticket->subject),
            self::ticketStatus($ticket->status),
            Persian::date($ticket->last_message_at ?? $ticket->created_at),
        ))->all();
        $total = Ticket::of($user)->count();

        return [
            ...$lines,
            ...self::older($total, 'تیکت'),
            '',
            sprintf('🎫 %s تیکت · %s در انتظار پاسخ', Persian::number($total), Persian::number(Ticket::of($user)->where('status', TicketStatus::Open->value)->count())),
        ];
    }

    /**
     * «👥 زیرمجموعه‌ها»: who brought them, how many their link brought and how many of those bought (Order::keptPurchases(),
     * as the referral gifts count them), what the commissions earned them; then the latest who came through it.
     *
     * @return list<string>
     */
    private static function referrals(User $user): array
    {
        $lines = ['🤝 معرف: ' . ($user->referrer === null ? 'ندارد' : ShopReports::customer($user->referrer))];
        $count = $user->referrals()->count();
        if ($count === 0) {
            return [...$lines, '👥 زیرمجموعه: هنوز کسی را با لینکش نیاورده است.'];
        }

        $buyers = Order::keptPurchases()->whereIn('user_id', $user->referrals()->select('id'))->distinct()->pluck('user_id')->map(intval(...))->all();
        [, $earned] = Page::tally(ReferralCommission::query()->where('referrer_id', $user->id), 'commission');
        $latest = $user->referrals()->orderByDesc('id')->limit(self::SHOWN)->get();

        return [
            ...$lines,
            sprintf('👥 زیرمجموعه: %s نفر · %s نفر خرید کرده', Persian::number($count), Persian::number(count($buyers))),
            '💰 پورسانت دریافتی: ' . Money::format($earned),
            '',
            ...$latest->map(static fn(User $referral): string => sprintf(
                // Its words first: a name in Latin letters first would turn the row left to right.
                '%s · %s · %s',
                in_array($referral->id, $buyers, true) ? '🛍 خرید کرده' : '▫️ خرید نکرده',
                ShopReports::customer($referral),
                Persian::date($referral->created_at),
            ))->all(),
            ...self::older($count, 'نفر'),
        ];
    }

    /** @return list<string> «… و ۱۳ سفارش قدیمی‌تر» when there are more than the tab shows. */
    private static function older(int $total, string $unit): array
    {
        return $total > self::SHOWN ? [sprintf('… و %s %s قدیمی‌تر', Persian::number($total - self::SHOWN), $unit)] : [];
    }

    /** Where an order stands, in the panels' words. */
    private static function orderStatus(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Pending => 'در انتظار پرداخت',
            OrderStatus::Paid => 'پرداخت‌شده',
            OrderStatus::Processing => 'در حال ساخت',
            OrderStatus::Fulfilled => 'تکمیل‌شده',
            OrderStatus::Failed => 'ناموفق',
            OrderStatus::Cancelled => 'لغوشده',
            OrderStatus::Refunded => 'بازپرداخت‌شده',
        };
    }

    /** Where a payment stands, in the panels' words. */
    private static function paymentStatus(PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::Pending => 'در انتظار پرداخت',
            PaymentStatus::AwaitingReview => 'در انتظار بررسی',
            PaymentStatus::Paid => 'پرداخت‌شده',
            PaymentStatus::Failed => 'ناموفق',
            PaymentStatus::Cancelled => 'لغوشده',
            PaymentStatus::Refunded => 'بازپرداخت‌شده',
        };
    }

    /** Where a ticket stands, in the panels' words. */
    private static function ticketStatus(TicketStatus $status): string
    {
        return match ($status) {
            TicketStatus::Open => 'در انتظار پاسخ',
            TicketStatus::Answered => 'پاسخ‌داده‌شده',
            TicketStatus::Closed => 'بسته‌شده',
        };
    }

    private static function name(string $name): string
    {
        return htmlspecialchars(Text::fit(trim($name), self::NAME_MAX));
    }
}
