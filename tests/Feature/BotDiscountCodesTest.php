<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Discounts\Enums\DiscountType;
use App\Modules\Discounts\Services\DiscountCodes;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Telegram\Handlers\DiscountCodeHandler;
use App\Modules\Telegram\Handlers\ExtrasHandler;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Handlers\RenewalHandler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Models\User;
use App\Support\Money;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;

/**
 * Discounts in the bot: a plan's price on its button and in its details after the customer's largest automatic discount,
 * the details saying why; a checkout that may be discounted listing what its amount took off and offering «🏷 کد تخفیف
 * دارم» while the shop has codes — the code typed next, any case, Persian digits; one that holds draws the checkout again
 * with its line and «❌ حذف کد تخفیف», one that does not says why and waits for another; the code kept for that checkout
 * through a card's screen and back, and judged again as it is paid — used up meanwhile, nothing ordered.
 */
final class BotDiscountCodesTest extends BotTestCase
{
    private Server $server;
    private Plan $plan;
    private User $ali;
    private PaymentMethod $wallet;
    private PaymentMethod $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutQr();
        $this->server = $this->sellingServer();
        $this->plan = $this->plan(['price' => '100000.00'], $this->server);
        $this->ali = $this->wallet($this->customer(), '500000.00');
        $this->wallet = $this->walletMethod();
        $this->card = $this->cardMethod();
    }

    public function testThePlansPricesAreAfterTheAutomaticDiscountAndItsDetailsSayWhy(): void
    {
        $this->sale('فروش ویژه نوروز', 20);

        $this->send($this->tap(MainMenu::PLANS));
        self::assertSame(self::text(BotText::PlanButton, [
            'plan' => $this->plan->name,
            'traffic' => Messages::traffic($this->plan->trafficBytes()),
            'duration' => Messages::duration($this->plan->duration_hours),
            'price' => Money::format('80000'),
        ]), $this->inlineKeyboard(0)[0][0]['text']);

        $this->send($this->tap(PurchaseHandler::planCallback($this->plan->id)));
        self::assertStringContainsString(self::text(BotText::PlanSale, ['discount' => 'فروش ویژه نوروز ۲۰٪', 'regular' => Money::format('100000')]), $this->said()[0]);
        self::assertStringContainsString('<b>' . Money::format('80000') . '</b>', $this->said()[0]);

        $this->ali->forceFill(['discount_percent' => 30])->save();
        $this->send($this->tap(PurchaseHandler::planCallback($this->plan->id)));
        self::assertStringContainsString(self::text(BotText::PlanPersonalDiscount, ['discount' => 'تخفیف اختصاصی شما ۳۰٪', 'regular' => Money::format('100000')]), $this->said()[0], 'the larger one');
    }

    public function testTheCheckoutOffersACodeOnlyWhileTheShopHasOne(): void
    {
        $this->send($this->tap($this->checkout()));
        self::assertNotContains(DiscountCodeHandler::askCallback($this->checkout()), $this->callbacks(0), 'no code in the shop: nobody is asked for one');

        $this->discountCode('NOWRUZ', 20);
        $this->send($this->tap($this->checkout()));
        self::assertContains(DiscountCodeHandler::askCallback($this->checkout()), $this->callbacks(0));
    }

    public function testACodeTypedIsAppliedRemovedAndPaid(): void
    {
        $this->discountCode('NOWRUZ', 20);
        $this->send($this->tap($this->checkout()));

        $this->send($this->tap(DiscountCodeHandler::askCallback($this->checkout())));
        self::assertSame([self::text(BotText::DiscountCodeAsk)], $this->said());

        $this->send($this->message('nowruz'));
        self::assertSame([$this->checkoutText('80000', ['کد NOWRUZ' => '20000'], '100000')], $this->said(), 'drawn again as a new message, with its line');
        self::assertContains(DiscountCodeHandler::removeCallback($this->checkout()), $this->callbacks(0));

        $this->send($this->tap(DiscountCodeHandler::removeCallback($this->checkout())));
        self::assertSame([$this->checkoutText('100000')], $this->said(), 'taken off');

        $this->send($this->tap(DiscountCodeHandler::askCallback($this->checkout())));
        $this->send($this->message('NOWRUZ'));
        $this->send($this->tap($this->pay($this->wallet)));

        $order = Order::query()->sole();
        self::assertSame([OrderStatus::Fulfilled, '80000.00', '20000.00'], [$order->status, $order->amount, $order->discount_amount]);
        self::assertSame('420000.00', $this->ali->balance());
    }

    public function testACodeThatDoesNotHoldSaysWhyAndAnotherIsAwaited(): void
    {
        $this->discountCode('ONCE', 20, ['ends_at' => now()->subHour()]);
        $this->send($this->tap($this->checkout()));
        $this->discountCode('GOOD', 10);
        $this->send($this->tap(DiscountCodeHandler::askCallback($this->checkout())));

        $this->send($this->message('ONCE'));
        self::assertSame([self::text(BotText::DiscountCodeRefused, ['reason' => DiscountCodes::ENDED])], $this->said());
        self::assertSame([$this->checkout()], $this->callbacks(0), '«بازگشت» to the checkout');

        $this->send($this->message('gööd'));
        self::assertSame([self::text(BotText::DiscountCodeRefused, ['reason' => DiscountCodes::NOT_FOUND])], $this->said(), 'still awaited');

        $this->send($this->message('good'));
        self::assertSame([$this->checkoutText('90000', ['کد GOOD' => '10000'], '100000')], $this->said());
    }

    public function testACardsScreenKeepsTheCodeAndItIsJudgedAgainAsItIsPaid(): void
    {
        $code = $this->discountCode('LAST', 50, ['max_uses' => 1]);
        $this->send($this->tap($this->checkout()));
        $this->send($this->tap(DiscountCodeHandler::askCallback($this->checkout())));
        $this->send($this->message('LAST'));

        $this->send($this->tap($this->pay($this->card)));
        $order = Order::query()->sole();
        self::assertSame(['50000.00', $code->id], [$order->amount, $order->discount_code_id]);

        $this->send($this->tap($this->checkout()));
        self::assertSame([$this->checkoutText('50000', ['کد LAST' => '50000'], '100000')], $this->said(), 'back from the card: still applied — its own order holds the use');
        $this->send($this->tap($this->pay($this->card)));
        self::assertSame(1, Order::query()->count(), 'the same order found');

        // Someone else's order took the use once theirs was cancelled.
        $this->service(OrderService::class)->cancel($order, OrderService::NOTE_CANCELLED_BY_SUPPORT);
        $sara = $this->customer(['telegram_id' => 7001]);
        $this->service(OrderService::class)->openPurchase($sara, $this->plan, $this->server, quote: $this->service(\App\Modules\Orders\Services\Pricing::class)->purchase($sara, $this->plan, $this->server, 'LAST'));

        $this->send($this->tap($this->pay($this->wallet)));
        self::assertSame([self::text(BotText::DiscountCodeLost, ['reason' => DiscountCodes::USED_UP])], $this->said());
        self::assertSame(0, Order::query()->where('user_id', $this->ali->id)->where('status', OrderStatus::Pending->value)->count(), 'nothing ordered');
        self::assertSame('500000.00', $this->ali->balance());

        $this->send($this->tap($this->checkout()));
        self::assertSame([$this->checkoutText('100000')], $this->said(), 'the checkout without it');
    }

    public function testAFreeCodeTakesItFree(): void
    {
        $this->discountCode('GIFT', 0, ['type' => DiscountType::Free]);
        $this->send($this->tap($this->checkout()));
        $this->send($this->tap(DiscountCodeHandler::askCallback($this->checkout())));
        $this->send($this->message('GIFT'));

        self::assertSame(self::text(BotText::CheckoutFree), $this->inlineKeyboard(0)[0][0]['text']);
        $this->send($this->tap($this->inlineKeyboard(0)[0][0]['callback_data']));

        $order = Order::query()->sole();
        self::assertSame([OrderStatus::Fulfilled, '0.00', '100000.00'], [$order->status, $order->amount, $order->discount_amount]);
        self::assertSame('500000.00', $this->ali->balance());
    }

    public function testARenewalsAndAnExtrasCheckoutTakeACode(): void
    {
        FakeProvider::$subscriptionBase = 'https://sub.example';
        $this->extrasOnSale();
        $service = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1');
        FakeProvider::mirror($service);
        $this->discountCode('KEEP', 10, ['order_types' => ['renewal', 'extra_traffic']]);

        $renewal = RenewalHandler::checkoutCallback($service->id);
        $this->send($this->tap($renewal));
        $this->send($this->tap(DiscountCodeHandler::askCallback($renewal)));
        $this->send($this->message('keep'));
        self::assertStringContainsString(self::text(BotText::CheckoutDiscount, ['discount' => 'کد KEEP', 'amount' => Money::format('10000')]), $this->said()[0]);
        $this->send($this->tap(CallbackData::build($renewal, $this->wallet->id)));
        self::assertSame(['90000.00', OrderStatus::Fulfilled], [Order::query()->sole()->amount, Order::query()->sole()->status]);

        $extra = ExtrasHandler::checkoutCallback($service->id, ExtraKind::Traffic, 10);
        $this->send($this->tap($extra));
        $this->send($this->tap(DiscountCodeHandler::askCallback($extra)));
        $this->send($this->message('KEEP'));
        self::assertStringContainsString(self::text(BotText::CheckoutDiscount, ['discount' => 'کد KEEP', 'amount' => Money::format('1800')]), $this->said()[0]);

        $time = ExtrasHandler::checkoutCallback($service->id, ExtraKind::Time, 7);
        $this->send($this->tap($time));
        $this->send($this->tap(DiscountCodeHandler::askCallback($time)));
        $this->send($this->message('KEEP'));
        self::assertSame([self::text(BotText::DiscountCodeRefused, ['reason' => 'این کد تخفیف برای زمان اضافه نیست.'])], $this->said());
    }

    /**
     * The plan's checkout at `$amount`, the discounts it took — each line and the amount before them — under it.
     *
     * @param array<string, string> $lines
     */
    private function checkoutText(string $amount, array $lines = [], ?string $before = null): string
    {
        $text = self::text(BotText::Checkout, ['plan' => $this->plan->name, 'server' => $this->server->name, 'amount' => Money::format($amount)]);
        if ($lines === []) {
            return $text;
        }
        $parts = [];
        foreach ($lines as $label => $off) {
            $parts[] = self::text(BotText::CheckoutDiscount, ['discount' => $label, 'amount' => Money::format($off)]);
        }
        $parts[] = self::text(BotText::CheckoutBeforeDiscount, ['regular' => Money::format((string) $before)]);

        return BotTexts::paragraphs($text, implode("\n", $parts));
    }

    private function checkout(): string
    {
        return PurchaseHandler::serverCallback($this->plan->id, $this->server->id);
    }

    private function pay(PaymentMethod $method): string
    {
        return CallbackData::build($this->checkout(), $method->id);
    }
}
