<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Sales;

use App\Modules\Telegram\Admin\Sections\Customers\CustomerRowsScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One order (the panel's order dialog, GET /orders/{id}): what it was for, its price and discounts, who made it by hand,
 * its customer, plan, server and service, why it failed or was cancelled, its moments, and its payments — each opening
 * its own screen (PaymentScreen), where receipts, refunds and reminders are. What its state allows (`actions`):
 * «🔁 تلاش دوباره برای تحویل», and «🚫 لغو سفارش» asked first, with or without a note for the customer.
 *
 * Actions: `rt` (id), `cx` (id: asks), `cxg` (id: cancels), `cxn` (id: asks the note, then cancels).
 */
final class OrderScreen extends BaseScreen
{
    public const ID = 'orv';

    public function show(Admin $admin, array $args): View
    {
        CustomerRowsScreen::opened($admin, self::ID, self::id($args), $args);

        return $this->view($admin, self::order($admin, self::id($args)));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        switch ($action) {
            case 'rt':
                $order = (array) ($admin->api->post("/orders/{$id}/retry")['order'] ?? []);
                if (($order['status'] ?? '') !== 'fulfilled') {
                    $admin->alert('سفارش ' . Fmt::id($id) . ' باز هم تحویل نشد. ' . (Json::text($order, 'notes') ?? ''));
                } else {
                    $admin->notice('✅ سفارش ' . Fmt::id($id) . ' تحویل داده شد');
                }

                return $this->view($admin, $order);
            case 'cx':
                $order = self::order($admin, $id);

                return Sales::choose(
                    'سفارش ' . Fmt::id($id) . ' لغو شود؟',
                    Fmt::e(self::cancelling($order)),
                    Button::to(Sales::WITH_NOTE, self::ID, 'cxn', $id),
                    Button::to(Sales::WITHOUT_NOTE, self::ID, 'cxg', $id),
                    Button::to('✖️ انصراف', self::ID, 'show', $id),
                );
            case 'cxg':
                return $this->cancelled($admin, $id, null);
            case 'cxn':
                return $admin->ask(new Prompt('توضیح برای مشتری', self::ID, 'cxn', [$id], 'مثلا: سفارش تکراری بود. ' . Sales::NOTE_HINT));
        }

        return $this->show($admin, $args);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        return $action === 'cxn' ? $this->cancelled($admin, self::id($args), $answer->text()) : $this->show($admin, $args);
    }

    /**
     * @return array<string, mixed>
     */
    private static function order(Admin $admin, int $id): array
    {
        return (array) ($admin->api->get("/orders/{$id}")['order'] ?? []);
    }

    private function cancelled(Admin $admin, int $id, ?string $note): View
    {
        $order = (array) ($admin->api->post("/orders/{$id}/cancel", $note === null ? null : ['note' => $note])['order'] ?? []);
        $admin->notice('🚫 سفارش ' . Fmt::id($id) . ' لغو شد');

        return $this->view($admin, $order);
    }

    /**
     * What cancelling the order does, as the panel's confirmation says it: the receipts in review it drops too, counted.
     *
     * @param array<string, mixed> $order
     */
    private static function cancelling(array $order): string
    {
        $inReview = count(array_filter(Json::rows($order, 'payments'), static fn(array $payment): bool => ($payment['status'] ?? '') === 'awaiting_review'));

        return $inReview === 0
            ? 'سفارش و پرداخت‌های انجام‌نشده‌اش لغو می‌شوند؛ اگر مشتری پرداختی را شروع کرده بود، خبردار می‌شود.'
            : 'سفارش و پرداخت‌های انجام‌نشده‌اش لغو می‌شوند، از جمله ' . Fmt::number($inReview) . ' رسید در انتظار بررسی، و مشتری خبردار می‌شود.';
    }

    /** @param array<string, mixed> $order */
    private function view(Admin $admin, array $order): View
    {
        $id = (int) ($order['id'] ?? 0);
        $status = (string) ($order['status'] ?? '');
        $user = Json::part($order, 'user');
        $manual = Json::part($order, 'manual');
        $brings = Sales::brings($order);
        $service = Json::part($order, 'subscription');
        $pricing = Sales::pricing($order);
        $notes = Json::text($order, 'notes');

        $view = View::make('🛒 سفارش ' . Fmt::id($id), 'مدیریت', 'فروش', 'سفارش‌ها')->facts([
            'وضعیت' => Fmt::e(Sales::word(Sales::ORDER_STATUS, $status)),
            'نوع' => Fmt::e(Sales::word(Sales::ORDER_TYPE, $order['type'] ?? '')),
            'روش تمدید' => Sales::renewal($order) !== null ? Fmt::e((string) Sales::renewal($order)) : null,
            ($brings[0] ?? '') => $brings !== null ? $brings[1] : null,
            'مبلغ' => '<b>' . Fmt::money(Json::text($order, 'amount')) . '</b>' . ($pricing !== null ? ' · ' . Fmt::e($pricing) : ''),
            'تخفیف' => Sales::discounts($order),
            'ثبت دستی' => $manual === [] ? null : Sales::reviewer(Json::text($manual, 'by') ?? 'پشتیبانی', Json::text($manual, 'by_name')),
            'یادداشت پشتیبانی' => Json::text($manual, 'note') !== null ? Fmt::e((string) Json::text($manual, 'note')) : null,
        ])->facts(Sales::customerFacts($user))->facts([
            'پلن' => Json::text($order, 'package_name') !== null ? Fmt::e((string) Json::text($order, 'package_name')) : null,
            'سرور' => Json::text(Json::part($order, 'server'), 'name') !== null ? Fmt::e((string) Json::text(Json::part($order, 'server'), 'name')) : null,
            'سرویس' => $service === [] ? null : Fmt::ltr(Json::text($service, 'name')) . ' · ' . Fmt::e(Sales::word(Sales::SUBSCRIPTION_STATUS, $service['status'] ?? '')),
            'خطای تحویل' => $notes !== null && $status === 'failed' ? Fmt::e($notes) : null,
            'دلیل لغو' => $notes !== null && $status === 'cancelled' ? Fmt::e($notes) : null,
            'ثبت' => Sales::when($order, 'created_at'),
            'پرداخت' => Sales::when($order, 'paid_at'),
            'تحویل' => Sales::when($order, 'fulfilled_at'),
        ]);

        $payments = Json::rows($order, 'payments');
        $view->line()->line('<b>💳 پرداخت‌ها</b>');
        if ($payments === []) {
            $view->line('مشتری هنوز روش پرداختی انتخاب نکرده است.');
        }
        $links = [];
        foreach ($payments as $payment) {
            $paymentId = (int) ($payment['id'] ?? 0);
            $view->line('• ' . Fmt::id($paymentId) . ' · ' . Fmt::e((string) ($payment['method'] ?? '')) . ' · ' . Fmt::money(Json::text($payment, 'amount')) . ' · '
                . Fmt::e(Sales::word(Sales::PAYMENT_STATUS, $payment['status'] ?? '')) . ' · ' . Fmt::date(Json::text($payment, 'paid_at') ?? Json::text($payment, 'created_at'), true)
                . (Json::on($payment, 'to_wallet') ? ' · ' . Sales::TO_WALLET : '') . (Json::on($payment, 'held') ? ' · ' . Sales::HELD : ''));
            $links[] = Button::to(Sales::mark(Sales::PAYMENT_STATUS, $payment['status'] ?? '') . ' پرداخت ' . Fmt::id($paymentId), PaymentScreen::ID, 'show', $paymentId);
        }

        $actions = Json::part($order, 'actions');
        $view->row(
            Json::on($actions, 'retry') ? Button::to('🔁 تلاش دوباره برای تحویل', self::ID, 'rt', $id) : null,
            Json::on($actions, 'cancel') ? Button::to('🚫 لغو سفارش', self::ID, 'cx', $id) : null,
        );
        $view->grid(2, ...$links);
        if ($service !== []) {
            $view->row(Button::to('📡 سرویس ' . (string) ($service['name'] ?? ''), SubscriptionScreen::ID, 'show', (int) ($service['id'] ?? 0)));
        }
        if ($user !== []) {
            $view->row(...Sales::customerButtons($user));
        }

        return $view->row(Button::to('🔄 تازه‌سازی', self::ID, 'show', $id))->back(CustomerRowsScreen::back($admin, self::ID, $id, OrdersScreen::ID));
    }
}
