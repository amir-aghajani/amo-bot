<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderPricing;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Handlers\RenewalHandler;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Users\Models\User;
use App\Support\Money;
use Tests\BotTestCase;

/**
 * A plan's first-purchase price in the bot: a customer who has bought no service yet sees it as the plan's price — on its
 * button in «خرید اشتراک», in its details with the plan's own price beside it, at its checkout — and pays it; once they
 * have, every screen says the plan's price. A purchase of theirs paid elsewhere in the same moment leaves this one at the
 * plan's price — its checkout drawn again at it before the wallet pays. Its service renews at the plan's price, «تمدید
 * سرویس» and «تمدید خودکار» on its screen as on any.
 */
final class BotFirstPurchasePricesTest extends BotTestCase
{
    private Server $server;
    private Plan $plan;
    private Plan $plain;
    private User $ali;
    private PaymentMethod $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutQr();
        $this->server = $this->sellingServer();
        $this->plan = $this->plan(['price' => '150000.00', 'first_purchase_price' => '50000.00'], $this->server);
        $this->plain = $this->plan(['name' => 'سه‌ماهه', 'price' => '300000.00'], $this->server);
        $this->ali = $this->wallet($this->customer(), '500000.00');
        $this->wallet = $this->walletMethod();
    }

    public function testTheListPricesItsPlansForTheCustomer(): void
    {
        $this->send($this->tap(MainMenu::PLANS));
        self::assertSame([$this->button($this->plan, '50000'), $this->button($this->plain, '300000')], $this->labels(), 'a newcomer: its first-purchase price, a plan without one its price');

        $this->buy($this->ali, $this->plain, $this->server);

        $this->send($this->tap(MainMenu::PLANS));
        self::assertSame([$this->button($this->plan, '150000'), $this->button($this->plain, '300000')], $this->labels(), 'bought: the plans\' prices');
    }

    public function testItsDetailsSayTheFirstPurchasePriceWithThePlansOwn(): void
    {
        $this->send($this->tap(PurchaseHandler::planCallback($this->plan->id)));
        self::assertSame([BotTexts::paragraphs($this->details('50000'), self::text(BotText::PlanFirstPurchase, ['regular' => Money::format('150000')]), self::text(BotText::PlanPickServer))], $this->said(), 'under the details, over the servers');

        // The admin may empty the line: no line, and no gap where it was.
        $this->service(BotTexts::class)->save(BotText::PlanFirstPurchase, '');
        $this->send($this->tap(PurchaseHandler::planCallback($this->plan->id)));
        self::assertSame([BotTexts::paragraphs($this->details('50000'), self::text(BotText::PlanPickServer))], $this->said());

        $this->buy($this->ali, $this->plain, $this->server);
        $this->send($this->tap(PurchaseHandler::planCallback($this->plan->id)));
        self::assertSame([BotTexts::paragraphs($this->details('150000'), self::text(BotText::PlanPickServer))], $this->said(), 'bought: the plan\'s price, nothing of the first purchase');
    }

    public function testANewcomerPaysTheFirstPurchasePriceAtItsCheckout(): void
    {
        $this->send($this->tap($this->checkoutOf($this->plan, $this->server)));
        self::assertSame([$this->checkoutText('50000')], $this->said());

        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->wallet)));

        $order = Order::query()->sole();
        self::assertSame([OrderStatus::Fulfilled, '50000.00', OrderPricing::FirstPurchase], [$order->status, $order->amount, $order->pricing]);
        self::assertSame('450000.00', $this->ali->balance());

        $this->send($this->tap($this->checkoutOf($this->plan, $this->server)));
        self::assertSame([$this->checkoutText('150000')], $this->said(), 'the next one at the plan\'s price');
    }

    public function testAPurchaseOfTheirsPaidElsewhereInTheSameMomentLeavesThisOneAtThePrice(): void
    {
        $this->send($this->tap($this->checkoutOf($this->plan, $this->server)));

        // As the way to pay is read, another purchase of theirs is paid elsewhere — on the website, a moment ago.
        $paid = false;
        $this->whileListening('eloquent.retrieved: ' . PaymentMethod::class, function () use (&$paid): void {
            if (!$paid) {
                $paid = true;
                $this->purchaseOrder($this->ali, $this->plain, $this->server, ['status' => OrderStatus::Fulfilled]);
            }
        }, fn() => $this->send($this->tap($this->payButton($this->plan, $this->server, $this->wallet))));

        self::assertTrue($paid);
        self::assertSame([$this->checkoutText('150000')], $this->said(), 'not their first purchase any more: the checkout again, at the plan\'s price');
        self::assertSame(0, Order::query()->where('plan_id', $this->plan->id)->count(), 'the wallet pays no price the customer did not see');
        self::assertSame('500000.00', $this->ali->balance());

        $this->send($this->tap($this->payButton($this->plan, $this->server, $this->wallet)));
        $order = Order::query()->where('plan_id', $this->plan->id)->sole();
        self::assertSame([OrderStatus::Fulfilled, '150000.00', OrderPricing::Regular], [$order->status, $order->amount, $order->pricing]);
        self::assertSame('350000.00', $this->ali->balance());
    }

    public function testAFreeFirstPurchaseWhoseFirstPurchaseWasMadeMeanwhileTakesNothingUnseen(): void
    {
        $this->plan->forceFill(['first_purchase_price' => '0.00'])->save();
        $this->send($this->tap($this->checkoutOf($this->plan, $this->server)));
        $take = $this->inlineKeyboard(0)[0][0]['callback_data'];

        // Their first purchase is paid on the website while «🎁 دریافت رایگان» is on screen.
        $this->purchaseOrder($this->ali, $this->plain, $this->server, ['status' => OrderStatus::Fulfilled]);
        $this->send($this->tap($take));

        self::assertSame([$this->checkoutText('150000')], $this->said(), 'not free any more: its price, and the ways to pay');
        self::assertSame(0, Order::query()->where('plan_id', $this->plan->id)->count(), 'nothing ordered');
        self::assertSame('500000.00', $this->ali->balance(), 'nothing taken from the wallet');
    }

    public function testAFreeFirstPurchaseIsTakenWithOneButton(): void
    {
        $this->plan->forceFill(['first_purchase_price' => '0.00'])->save();

        $this->send($this->tap($this->checkoutOf($this->plan, $this->server)));
        self::assertSame([$this->checkoutText('0')], $this->said());
        self::assertSame(self::text(BotText::CheckoutFree), $this->inlineKeyboard(0)[0][0]['text'], 'one button instead of the ways to pay');

        $this->send($this->tap($this->inlineKeyboard(0)[0][0]['callback_data']));

        $order = Order::query()->sole();
        self::assertSame([OrderStatus::Fulfilled, '0.00', OrderPricing::FirstPurchase], [$order->status, $order->amount, $order->pricing]);
        self::assertSame('500000.00', $this->ali->balance(), 'nothing charged');
    }

    public function testItsServiceRenewsAtThePlansPrice(): void
    {
        $this->botSettings('auto_renew', ['auto_renew_days' => 2, 'auto_renew_default' => true]);
        $service = $this->buy($this->ali, $this->plan, $this->server)->subscription ?? self::fail('nothing delivered');

        $this->send($this->tap(SubscriptionHandler::serviceCallback($service->id)));
        self::assertContains(SubscriptionHandler::serviceCallback($service->id, 'renew'), $this->callbacks(0), '«تمدید سرویس» on its screen');
        self::assertContains(SubscriptionHandler::serviceCallback($service->id, 'autorenew'), $this->callbacks(0), 'and «تمدید خودکار»');

        $this->send($this->tap(RenewalHandler::checkoutCallback($service->id)));
        self::assertStringContainsString('<b>' . Money::format('150000') . '</b>', $this->said()[0], 'renewed at the plan\'s price');
        self::assertStringNotContainsString('<b>' . Money::format('50000') . '</b>', $this->said()[0]);
    }

    /** The plan's button in «خرید اشتراک» at `$price`. */
    private function button(Plan $plan, string $price): string
    {
        return self::text(BotText::PlanButton, [
            'plan' => $plan->name,
            'traffic' => Messages::traffic($plan->trafficBytes()),
            'duration' => Messages::duration($plan->duration_hours),
            'price' => Money::format($price),
        ]);
    }

    /** @return list<string> The labels of the last screen's buttons, the way back left out. */
    private function labels(): array
    {
        $labels = array_map(static fn(array $row): string => $row[0]['text'], $this->inlineKeyboard(0));

        return array_values(array_filter($labels, static fn(string $label): bool => str_contains($label, '—')));
    }

    /** The plan's details at `$price`. */
    private function details(string $price): string
    {
        return self::text(BotText::PlanDetails, [
            'plan' => $this->plan->name,
            'description' => '',
            'traffic' => Messages::traffic($this->plan->trafficBytes()),
            'duration' => Messages::duration($this->plan->duration_hours),
            'devices' => Messages::devices($this->plan->ip_limit),
            'price' => Money::format($price),
        ]);
    }

    /** The plan's checkout on the server at `$price`. */
    private function checkoutText(string $price): string
    {
        return self::text(BotText::Checkout, ['plan' => $this->plan->name, 'server' => $this->server->name, 'amount' => Money::format($price)]);
    }
}
