<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Sales;

use App\Modules\Telegram\Admin\Sections\Customers\CustomerRowsScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * One order (the panel's order dialog, GET /orders/{id}): its summary card — what it was for, its price and discounts,
 * who made it by hand, its customer, plan and server, why it failed or was cancelled, its moments —, and its payments
 * and service as buttons, each the row itself (its chip, method and amount; its name and state), opening its own screen
 * (PaymentScreen: receipts, refunds and reminders are there). What its state allows (`actions`): «🔁 تلاش دوباره برای
 * تحویل», and «🚫 لغو سفارش» asked first, with or without a note for the customer.
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
                $order = self::row($admin->api->post("/orders/{$id}/retry"));
                if (($order['status'] ?? '') !== 'fulfilled') {
                    $admin->alert('سفارش ' . Fmt::id($id) . ' باز هم تحویل نشد. ' . (Json::text($order, 'notes') ?? ''));
                } else {
                    $admin->notice('✅ سفارش ' . Fmt::id($id) . ' تحویل داده شد');
                }

                return $this->view($admin, $order);
            case 'cx':
                $order = self::order($admin, $id);

                return Sales::choose(
                    $admin,
                    'سفارش ' . Fmt::id($id) . ' لغو شود؟',
                    Fmt::e(self::cancelling($order)),
                    true,
                    Button::to(Sales::WITH_NOTE, self::ID, 'cxn', $id),
                    Button::to(Sales::WITHOUT_NOTE, self::ID, 'cxg', $id),
                    Button::to(Words::CANCEL, self::ID, 'show', $id),
                );
            case 'cxg':
                return $this->cancelled($admin, $id, null);
            case 'cxn':
                return $admin->ask((new Prompt('توضیح برای مشتری', self::ID, 'cxn', [$id], 'مشتری این متن را با خبر لغو می‌بیند.'))
                    ->example('سفارش تکراری بود')
                    ->limits(Sales::NOTE_LIMIT));
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
        return self::row($admin->api->get("/orders/{$id}"));
    }

    /**
     * The order an answer carries.
     *
     * @param array<string, mixed> $answer
     * @return array<string, mixed>
     */
    private static function row(array $answer): array
    {
        return Json::part($answer, 'order');
    }

    private function cancelled(Admin $admin, int $id, ?string $note): View
    {
        $order = self::row($admin->api->post("/orders/{$id}/cancel", $note === null ? null : ['note' => $note]));
        $admin->notice(Sales::CANCEL . ' سفارش ' . Fmt::id($id) . ' لغو شد');

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

    /**
     * A payment of the order as its button says it: its chip, number, method and amount — held or gone to the wallet
     * marked.
     *
     * @param array<string, mixed> $payment
     */
    private static function paymentLabel(array $payment): string
    {
        return Sales::paymentMark($payment) . ' پرداخت ' . Fmt::id((int) ($payment['id'] ?? 0)) . ' · ' . Fmt::money(Json::text($payment, 'amount')) . ' · ' . Fmt::cut((string) ($payment['method'] ?? ''), 18)
            . (Json::on($payment, 'to_wallet') ? ' · ' . Icons::WALLET : '');
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
        $payments = Json::rows($order, 'payments');

        $view = View::make(Icons::ORDERS . ' سفارش ' . Fmt::id($id), ...Sales::under(Sales::ORDERS));
        if ($notes !== null && $status === 'failed') {
            $view->line('⚠️ <b>تحویل انجام نشد:</b> ' . Fmt::e($notes));
        }
        $view->card([
            'وضعیت' => Fmt::e(Sales::word(Sales::ORDER_STATUS, $status)),
            'نوع' => Fmt::e(Sales::word(Sales::ORDER_TYPE, $order['type'] ?? '')),
            'روش تمدید' => Sales::renewal($order) !== null ? Fmt::e((string) Sales::renewal($order)) : null,
            ($brings[0] ?? '') => $brings !== null ? $brings[1] : null,
            'مبلغ' => '<b>' . Fmt::money(Json::text($order, 'amount')) . '</b>' . ($pricing !== null ? ' · ' . Fmt::e($pricing) : ''),
            'تخفیف' => Sales::discounts($order),
            'ثبت دستی' => $manual === [] ? null : Sales::reviewer(Json::text($manual, 'by') ?? 'پشتیبانی', Json::text($manual, 'by_name')),
            'یادداشت پشتیبانی' => Json::text($manual, 'note') !== null ? Fmt::e((string) Json::text($manual, 'note')) : null,
            ...Sales::customerFacts($user),
            'پلن' => Json::text($order, 'package_name') !== null ? Fmt::e((string) Json::text($order, 'package_name')) : null,
            'سرور' => Json::text(Json::part($order, 'server'), 'name') !== null ? Fmt::e((string) Json::text(Json::part($order, 'server'), 'name')) : null,
            'دلیل لغو' => $notes !== null && $status === 'cancelled' ? Fmt::e($notes) : null,
            'ثبت' => Sales::when($order, 'created_at'),
            'پرداخت' => Sales::when($order, 'paid_at'),
            'تحویل' => Sales::when($order, 'fulfilled_at'),
            'پرداخت‌ها' => $payments === [] ? 'مشتری هنوز روش پرداختی انتخاب نکرده است' : null,
        ]);

        $actions = Json::part($order, 'actions');
        $view->row(Json::on($actions, 'retry') ? Button::to(Sales::RETRY . ' تلاش دوباره برای تحویل', self::ID, 'rt', $id)->primary() : null);
        foreach ($payments as $payment) {
            $view->row(Button::to(self::paymentLabel($payment), PaymentScreen::ID, 'show', (int) ($payment['id'] ?? 0)));
        }
        if ($service !== []) {
            $view->row(Button::to(Sales::mark(Sales::SUBSCRIPTION_STATUS, $service['status'] ?? '') . ' سرویس ' . (string) ($service['name'] ?? ''), SubscriptionScreen::ID, 'show', (int) ($service['id'] ?? 0)));
        }
        if ($user !== []) {
            $view->row(...Sales::customerButtons($user));
        }
        $view->row(Json::on($actions, 'cancel') ? Button::to(Sales::CANCEL . ' لغو سفارش', self::ID, 'cx', $id)->danger() : null);

        return $view->back(CustomerRowsScreen::back($admin, self::ID, $id, OrdersScreen::ID))->refresh(self::ID, 'show', $id);
    }
}
