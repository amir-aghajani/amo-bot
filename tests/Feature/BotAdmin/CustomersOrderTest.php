<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Admin\Door\BotAdminDoor;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;

/**
 * «➕ افزودن سفارش» from a customer's page in the bot: the panel's dialog as a wizard — a plan (its only open server picked
 * by itself) or support's own numbers, the payment and its amount —, the summary with the optional name and note offered
 * on it and the wallet as the order leaves it, and one order made under its key however often «✔️» is pressed; the
 * `manual_orders` grant for a shop's admin.
 */
final class CustomersOrderTest extends BotTestCase
{
    private const STAFF = 7100;

    private User $ali;
    private Server $server;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->withoutQr();
        $this->server = $this->sellingServer();
        $this->plan = $this->plan([], $this->server);
        $this->ali = $this->customer(['telegram_id' => 1111, 'first_name' => 'Ali', 'username' => 'ali_r']);
    }

    public function testTheOwnerGivesAPlanFreeOnceThroughTheWizard(): void
    {
        $this->owner(UserRole::Admin);
        $id = $this->ali->id;

        $this->send($this->tap(Nav::to('uso', 'new', $id), chat: self::OWNER));
        self::assertContains(Nav::to('uso', 'k', $id, 'plan'), $this->window());
        $this->send($this->tap(Nav::to('uso', 'k', $id, 'plan'), chat: self::OWNER));
        self::assertContains(Nav::to('uso', 'p', $id, $this->plan->id), $this->window());

        $this->send($this->tap(Nav::to('uso', 'p', $id, $this->plan->id), chat: self::OWNER));
        self::assertContains(Nav::to('uso', 'pm', $id, 'free'), $this->window(), 'its one server picked by itself: the payment next');
        $this->send($this->tap(Nav::to('uso', 'pm', $id, 'free'), chat: self::OWNER));
        self::assertContains(Nav::to('uso', 'make', $id), $this->window(), 'paid for: the order said, nothing more asked');
        self::assertContains(Nav::to('uso', 'name', $id), $this->window(), 'the name, optional, offered on it');
        self::assertContains(Nav::to('uso', 'note', $id), $this->window(), 'and the note');
        $this->send($this->tap(Nav::to('uso', 'note', $id), chat: self::OWNER));
        self::assertContains(Nav::to('uso', 'ntskip', $id), $this->window(), 'a note may be left out');
        $this->send($this->message('جبران قطعی', chat: self::OWNER));
        $summary = $this->screen();
        self::assertStringContainsString('جبران قطعی', $summary, 'the order said again with its note');
        self::assertStringContainsString('تحویل می‌شود', $summary);
        self::assertStringContainsString('یک‌ماهه', $summary);
        self::assertStringContainsString('رایگان؛ هدیه است', $summary);
        self::assertSame(0, Order::query()->count(), 'nothing made before «✔️»');

        $this->send($this->tap(Nav::to('uso', 'make', $id), chat: self::OWNER));
        $order = Order::query()->sole();
        self::assertSame(OrderStatus::Fulfilled, $order->status);
        self::assertSame('جبران قطعی', $order->support_note, 'its note');
        self::assertStringContainsString('ثبت و تحویل شد', $this->screen());
        $service = Subscription::query()->sole();
        self::assertContains(Nav::to('orv', 'show', $order->id), $this->window());
        self::assertContains(Nav::to('sbv', 'show', $service->id), $this->window());

        $this->send($this->tap(Nav::to('uso', 'make', $id), chat: self::OWNER));
        self::assertSame(1, Order::query()->count(), 'pressed twice, made once');
        self::assertStringContainsString('دیگر در جریان نیست', (string) $this->popup());
        self::assertSame([], array_values(array_intersect($this->calls(), ['sendMessage', 'editMessageText'])), 'the result left in the window, no new order begun over it');
    }

    public function testTheKindIsChangedFromTheOrderWithoutLosingIt(): void
    {
        $this->owner(UserRole::Admin);
        $id = $this->ali->id;
        $this->send($this->tap(Nav::to('uso', 'new', $id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('uso', 'k', $id, 'plan'), chat: self::OWNER));
        $this->send($this->tap(Nav::to('uso', 'p', $id, $this->plan->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('uso', 'pm', $id, 'free'), chat: self::OWNER));
        self::assertContains(Nav::to('uso', 'make', $id), $this->window());

        // «✏️ نوع سرویس», then «✖️ انصراف»: back to the order as it was.
        $this->send($this->tap(Nav::to('uso', 'k', $id, '_'), chat: self::OWNER));
        self::assertContains('✅ ' . Icons::PLANS . ' یکی از پلن‌ها', $this->buttonLabels($this->buttonsCall()), 'the kind picked, marked');
        self::assertContains(Nav::to('uso', 'show', $id), $this->window(), 'an edit\'s «✖️ انصراف»: back to the order');
        $this->send($this->tap(Nav::to('uso', 'show', $id), chat: self::OWNER));
        self::assertContains(Nav::to('uso', 'make', $id), $this->window(), 'the order kept');
        self::assertStringContainsString('یک‌ماهه', $this->screen());

        // The same kind again changes nothing; another asks what, where and for how much again.
        $this->send($this->tap(Nav::to('uso', 'k', $id, '_'), chat: self::OWNER));
        $this->send($this->tap(Nav::to('uso', 'k', $id, 'plan'), chat: self::OWNER));
        self::assertContains(Nav::to('uso', 'make', $id), $this->window());
        $this->send($this->tap(Nav::to('uso', 'k', $id, '_'), chat: self::OWNER));
        $this->send($this->tap(Nav::to('uso', 'k', $id, 'custom'), chat: self::OWNER));
        self::assertContains(Nav::to('uso', 'gbq', $id, 10), $this->window(), 'support\'s own numbers asked');
        self::assertSame(0, Order::query()->count());
    }

    public function testSupportsOwnNumbersArePaidFromTheWallet(): void
    {
        $this->owner(UserRole::Admin);
        $this->walletMethod();
        $this->wallet($this->ali, '200000');
        $id = $this->ali->id;

        $this->send($this->tap(Nav::to('uso', 'new', $id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('uso', 'k', $id, 'custom'), chat: self::OWNER));
        self::assertContains(Nav::to('uso', 'gbq', $id, 50), $this->window(), 'quick picks');
        $this->send($this->tap(Nav::to('uso', 'gbq', $id, 50), chat: self::OWNER));
        $this->send($this->message('۳۰ روز', chat: self::OWNER));
        $this->send($this->tap(Nav::to('uso', 'vq', $id, 2), chat: self::OWNER));
        self::assertContains(Nav::to('uso', 's', $id, $this->server->id), $this->window(), 'every server that can take one');
        $this->send($this->tap(Nav::to('uso', 's', $id, $this->server->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('uso', 'pm', $id, 'wallet'), chat: self::OWNER));
        self::assertStringContainsString('۲۰۰٬۰۰۰ تومان', $this->screen(), 'the wallet said beside the amount');
        $this->send($this->message('۱۵۰ هزار تومان', chat: self::OWNER));
        self::assertStringContainsString('بعد از سفارش ۵۰٬۰۰۰ تومان', $this->screen(), 'the wallet as the order leaves it');
        $this->send($this->tap(Nav::to('uso', 'name', $id), chat: self::OWNER));
        $this->send($this->message('ali_vip', chat: self::OWNER));
        self::assertStringContainsString('سرویس دلخواه', $this->screen());
        self::assertStringContainsString('ali_vip', $this->screen());

        $this->send($this->tap(Nav::to('uso', 'make', $id), chat: self::OWNER));
        self::assertSame('50000.00', $this->ali->balance());
        self::assertSame('ali_vip', Subscription::query()->sole()->remote_name);
    }

    public function testAWalletTooShortIsSaidBeforeTheOrderAndTheOrderRefused(): void
    {
        $this->owner(UserRole::Admin);
        $this->walletMethod();
        $this->wallet($this->ali, '20000');
        $id = $this->ali->id;

        $this->send($this->tap(Nav::to('uso', 'new', $id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('uso', 'k', $id, 'plan'), chat: self::OWNER));
        $this->send($this->tap(Nav::to('uso', 'p', $id, $this->plan->id), chat: self::OWNER));
        $this->send($this->tap(Nav::to('uso', 'pm', $id, 'wallet'), chat: self::OWNER));
        $this->send($this->tap(Nav::to('uso', 'amtp', $id, 120000), chat: self::OWNER));
        self::assertStringContainsString('برای این مبلغ ۱۰۰٬۰۰۰ تومان کم دارد', $this->screen(), 'what the wallet lacks, before «✔️»');
        self::assertStringNotContainsString('بعد از سفارش', $this->screen(), 'no balance after an order that cannot be paid');

        $this->send($this->tap(Nav::to('uso', 'make', $id), chat: self::OWNER));
        self::assertSame(0, Order::query()->count(), 'the panel refuses it');
        self::assertSame('20000.00', $this->ali->balance());
    }

    public function testAShopsAdminOrdersByHandOnlyWithTheGrant(): void
    {
        $this->customer(['telegram_id' => self::STAFF, 'first_name' => 'Staff', 'role' => UserRole::Admin]);
        $id = $this->ali->id;

        $this->send($this->tap(Nav::to('usv', 'show', $id), chat: self::STAFF));
        self::assertNotContains(Nav::to('uso', 'new', $id), $this->window());

        // A forged way in: the door says no when the order is sent.
        $this->send($this->tap(Nav::to('uso', 'new', $id), chat: self::STAFF));
        $this->send($this->tap(Nav::to('uso', 'k', $id, 'plan'), chat: self::STAFF));
        $this->send($this->tap(Nav::to('uso', 'p', $id, $this->plan->id), chat: self::STAFF));
        $this->send($this->tap(Nav::to('uso', 'pm', $id, 'free'), chat: self::STAFF));
        $this->send($this->tap(Nav::to('uso', 'nskip', $id), chat: self::STAFF));
        $this->send($this->tap(Nav::to('uso', 'ntskip', $id), chat: self::STAFF));
        $this->send($this->tap(Nav::to('uso', 'make', $id), chat: self::STAFF));
        self::assertSame(BotAdminDoor::NOT_GRANTED, $this->popup());
        self::assertSame(0, Order::query()->count());

        $this->botSettings('bot_admins', ['bot_admins_grant_manual_orders' => true]);
        $this->send($this->tap(Nav::to('usv', 'show', $id), chat: self::STAFF));
        self::assertContains(Nav::to('uso', 'new', $id), $this->window(), 'granted');
        $this->send($this->tap(Nav::to('uso', 'make', $id), chat: self::STAFF));
        self::assertSame(1, Order::query()->count(), 'the draft kept, sent once granted');
    }

    /** @return list<string> The window's buttons, as the last screen drew them. */
    private function window(): array
    {
        $calls = $this->calls();
        for ($i = count($calls) - 1; $i >= 0; $i--) {
            if (in_array($calls[$i], ['sendMessage', 'editMessageText'], true) && ($this->params($i)['chat_id'] ?? null) !== '1111') {
                return $this->callbacks($i);
            }
        }

        return [];
    }

    /** What the window says now. */
    private function screen(): string
    {
        $calls = $this->calls();
        for ($i = count($calls) - 1; $i >= 0; $i--) {
            if (in_array($calls[$i], ['sendMessage', 'editMessageText'], true) && ($this->params($i)['chat_id'] ?? null) !== '1111') {
                return $this->params($i)['text'] ?? '';
            }
        }

        return '';
    }
}
