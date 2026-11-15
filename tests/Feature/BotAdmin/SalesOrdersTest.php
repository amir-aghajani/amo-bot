<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Providers\Models\Server;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use Tests\BotTestCase;

/**
 * «🛒 سفارش‌ها» in the bot's management (Telegram\Admin\Sections\Sales): the stuck tab with its count, an order's page
 * with its payments, a failed delivery retried until it is delivered, an unpaid order cancelled with a note typed for the
 * customer, and the list narrowed by type, days, a customer and a search — each through the panel's own routes.
 */
final class SalesOrdersTest extends BotTestCase
{
    private User $ali;
    private Server $server;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->withoutQr();
        $this->ali = $this->customer(['first_name' => 'Ali']);
        $this->server = $this->sellingServer('Berlin');
        $this->plan = $this->plan(['name' => 'Basic', 'price' => '50000.00'], $this->server);
    }

    public function testAStuckOrderIsRetriedUntilItIsDelivered(): void
    {
        $this->owner();
        $this->server->forceFill(['is_active' => false])->save();
        $payment = $this->receipt($this->cardPayment($this->purchaseOrder($this->ali, $this->plan, $this->server), $this->cardMethod()));
        $this->service(PaymentActions::class)->approve($payment, $this->panelActor());
        $order = $payment->order()->sole();
        self::assertSame(OrderStatus::Failed, $order->status, 'paid, and its delivery failed');

        $this->send($this->tap(Nav::to('hl'), chat: 7001));
        self::assertContains(Nav::to('or'), $this->callbacks(0), 'the sales menu leads to the orders');

        $this->send($this->tap(Nav::to('or'), chat: 7001));
        self::assertContains('🆘 نیازمند رسیدگی (۱)', $this->buttonLabels(0), 'the stuck tab counts what waits');
        $this->send($this->tap(Nav::to('or', 'f', 'status', 'stuck'), chat: 7001));
        self::assertContains(Nav::to('orv', 'show', $order->id), $this->callbacks(0));

        $this->send($this->tap(Nav::to('orv', 'show', $order->id), chat: 7001));
        self::assertStringContainsString('سفارش ' . Fmt::id($order->id), $this->said()[0]);
        self::assertStringContainsString('خطای تحویل', $this->said()[0]);
        self::assertContains(Nav::to('pyv', 'show', $payment->id), $this->callbacks(0), 'its payment, on its own screen');
        self::assertContains(Nav::to('orv', 'rt', $order->id), $this->callbacks(0));
        self::assertNotContains(Nav::to('orv', 'cx', $order->id), $this->callbacks(0), 'paid: not cancelled here');

        $this->send($this->tap(Nav::to('orv', 'rt', $order->id), chat: 7001));
        self::assertStringContainsString('باز هم تحویل نشد', (string) $this->popup());
        self::assertSame(OrderStatus::Failed, $order->refresh()->status);

        $this->server->forceFill(['is_active' => true])->save();
        $this->send($this->tap(Nav::to('orv', 'rt', $order->id), chat: 7001));
        self::assertStringContainsString('تحویل داده شد', (string) $this->popup());
        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status, 'delivered through the panel\'s route');
        self::assertContains(Nav::to('sbv', 'show', (int) $order->subscription_id), $this->callbacks($this->windowCall()), 'the service it made');
        self::assertNotContains(Nav::to('orv', 'rt', $order->id), $this->callbacks($this->windowCall()), 'delivered: nothing to retry');
    }

    public function testAnAdminCancelsAnUnpaidOrderWithANoteForTheCustomer(): void
    {
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);
        $order = $this->purchaseOrder($this->ali, $this->plan, $this->server);
        $this->cardPayment($order, $this->cardMethod());

        $this->send($this->tap(Nav::to('orv', 'show', $order->id), chat: 7100));
        self::assertContains(Nav::to('orv', 'cx', $order->id), $this->callbacks(0));

        $this->send($this->tap(Nav::to('orv', 'cx', $order->id), chat: 7100));
        self::assertStringContainsString('لغو شود؟', $this->said()[0], 'asked first');
        self::assertStringContainsString('مشتری پرداختی را شروع کرده بود', $this->said()[0], 'in the panel\'s words');
        self::assertSame(OrderStatus::Pending, $order->refresh()->status);

        $this->send($this->tap(Nav::to('orv', 'cxn', $order->id), chat: 7100));
        self::assertStringContainsString('توضیح برای مشتری', $this->said()[0]);

        $this->send($this->message('سفارش تکراری بود', chat: 7100));
        self::assertSame(OrderStatus::Cancelled, $order->refresh()->status, 'cancelled through the panel\'s route');
        self::assertStringContainsString('سفارش تکراری بود', (string) $order->notes);
        self::assertStringContainsString('لغو شد', implode("\n", $this->said()));
        self::assertNotContains(Nav::to('orv', 'cx', $order->id), $this->callbacks($this->windowCall()), 'cancelled: no cancelling again');
    }

    public function testTheListIsNarrowedByTypeDaysACustomerAndASearch(): void
    {
        $this->owner();
        $sara = $this->customer(['telegram_id' => 5252, 'first_name' => 'Sara']);
        $purchase = $this->purchaseOrder($this->ali, $this->plan, $this->server);
        $topUp = $this->topUpOrder($sara, '20000.00');

        $this->send($this->tap(Nav::to('orf'), chat: 7001));
        self::assertContains(Nav::to('orf', 's', 'type', 'wallet_topup'), $this->callbacks(0));
        $this->send($this->tap(Nav::to('orf', 's', 'type', 'wallet_topup'), chat: 7001));
        self::assertContains('● شارژ کیف پول', $this->buttonLabels(0), 'the pick in force is marked');

        $this->send($this->tap(Nav::to('or'), chat: 7001));
        self::assertStringContainsString('نوع: شارژ کیف پول', $this->said()[0]);
        self::assertContains(Nav::to('orv', 'show', $topUp->id), $this->callbacks(0));
        self::assertNotContains(Nav::to('orv', 'show', $purchase->id), $this->callbacks(0));

        // Days typed: a range long before these orders leaves nothing.
        $this->send($this->tap(Nav::to('orf', 'dc'), chat: 7001));
        $this->send($this->message('۱۴۰۰/۰۱/۰۱ تا ۱۴۰۰/۰۱/۰۲', chat: 7001));
        $this->send($this->tap(Nav::to('or'), chat: 7001));
        self::assertStringContainsString('۱ فروردین ۱۴۰۰ تا ۲ فروردین ۱۴۰۰', $this->said()[0]);
        self::assertStringContainsString('سفارشی پیدا نشد', $this->said()[0]);

        $this->send($this->tap(Nav::to('or', 'unx'), chat: 7001));
        self::assertContains(Nav::to('orv', 'show', $purchase->id), $this->callbacks(0), 'every narrowing off');
        self::assertContains(Nav::to('orv', 'show', $topUp->id), $this->callbacks(0));

        // A customer's own orders, as a customer's page links them.
        $this->send($this->tap(Nav::to('or', 'user', $sara->id), chat: 7001));
        self::assertSame([Nav::to('orv', 'show', $topUp->id)], array_values(array_filter($this->callbacks(0), static fn(string $data): bool => str_starts_with($data, 'a:orv:'))));

        $this->send($this->tap(Nav::to('or', 'unx'), chat: 7001));
        $this->send($this->tap(Nav::to('or', 'search'), chat: 7001));
        $this->send($this->message('Basic', chat: 7001));
        self::assertSame([Nav::to('orv', 'show', $purchase->id)], array_values(array_filter($this->callbacks($this->windowCall()), static fn(string $data): bool => str_starts_with($data, 'a:orv:'))));
    }

    /** The account 7001 linked as the owner. */
    private function owner(): void
    {
        if (User::query()->where('telegram_id', 7001)->doesntExist()) {
            $this->customer(['telegram_id' => 7001, 'first_name' => 'Amir']);
        }
        $this->linkOwner(7001);
    }
}
