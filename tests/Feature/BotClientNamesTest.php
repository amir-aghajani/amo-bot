<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\ClientNameMode;
use App\Modules\Subscriptions\Services\ClientNaming;
use App\Modules\Telegram\Handlers\ClientNameHandler;
use App\Modules\Telegram\Handlers\DiscountCodeHandler;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Handlers\RenewalHandler;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Users\Models\User;
use App\Support\Money;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;

/**
 * «نام کاربری سرویس» at a purchase's checkout: nothing asked while the shop names every service by its pattern; once it
 * offers a random or a typed name, a line says what the service will be called and a button per way offered changes it —
 * «🎲 رندوم» draws a name, «✏️ دلخواه» waits for one typed (a refusal says why and waits again), «🔢 ترتیبی» goes back to
 * the pattern. The name is kept for that checkout alone, through a discount code's redraw and a way to pay switched off,
 * and the order is made with it: the service delivered carries it. «پرداخت دوباره» draws its order's name again.
 */
final class BotClientNamesTest extends BotTestCase
{
    private Server $berlin;
    private Server $paris;
    private Plan $plan;
    private User $ali;
    private PaymentMethod $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutQr();
        $this->berlin = $this->sellingServer('Berlin');
        $this->paris = $this->sellingServer('Paris');
        $this->plan = $this->plan(['price' => '100000.00'], [$this->berlin, $this->paris]);
        $this->ali = $this->wallet($this->customer(), '500000.00');
        $this->wallet = $this->walletMethod();
    }

    public function testNothingIsAskedWhileTheShopNamesByItsPatternAlone(): void
    {
        $this->send($this->tap($this->checkoutOf($this->plan, $this->berlin)));

        self::assertSame([$this->checkoutText()], $this->said());
        self::assertSame([], array_filter($this->callbacks(0), static fn(string $data): bool => str_starts_with($data, ClientNameHandler::PREFIX)));
    }

    public function testTheWaysOfferedAreButtonsUnderALineSayingTheName(): void
    {
        $this->botSettings('client_names', ['name_random' => true]);
        $this->send($this->tap($this->checkoutOf($this->plan, $this->berlin)));

        self::assertSame([BotTexts::paragraphs($this->checkoutText(), self::text(BotText::CheckoutNamePattern))], $this->said());
        self::assertSame([
            ['text' => self::text(BotText::CheckoutNameRandom), 'callback_data' => $this->mode(ClientNameMode::Random)],
            ['text' => self::text(BotText::CheckoutNameSequential), 'callback_data' => $this->mode(ClientNameMode::Sequential)],
        ], $this->inlineKeyboard(0)[1], 'under the ways to pay, «ترتیبی» first, on the right');

        $this->botSettings('client_names', ['name_custom' => true]);
        $this->send($this->tap($this->checkoutOf($this->plan, $this->berlin)));
        self::assertContains($this->mode(ClientNameMode::Custom), $this->callbacks(0));
    }

    public function testARandomNameIsDrawnShownAndGivenToTheService(): void
    {
        $this->botSettings('client_names', ['name_random' => true]);
        $this->send($this->tap($this->checkoutOf($this->plan, $this->berlin)));

        $this->send($this->tap($this->mode(ClientNameMode::Random)));
        $name = $this->shownName();
        self::assertMatchesRegularExpression('/^[23456789a-z]{6}$/', $name);

        $this->send($this->tap($this->payButton($this->plan, $this->berlin, $this->wallet)));

        $order = Order::query()->sole();
        self::assertSame([OrderStatus::Fulfilled, $name, $name], [$order->status, $order->client_name, $order->subscription?->remote_name]);
        self::assertStringContainsString($name, implode("\n", $this->said()), 'the delivery shows the name, as ever');
    }

    public function testATypedNameIsAwaitedRefusedWithItsReasonAndKept(): void
    {
        $this->botSettings('client_names', ['name_custom' => true]);
        $this->subscription($this->ali, $this->plan, $this->berlin, 'taken');
        $this->send($this->tap($this->checkoutOf($this->plan, $this->berlin)));

        $this->send($this->tap($this->mode(ClientNameMode::Custom)));
        self::assertSame([self::text(BotText::CheckoutNameAsk)], $this->said());
        self::assertSame([$this->checkoutOf($this->plan, $this->berlin)], $this->callbacks(0), '«بازگشت» to the checkout');

        $this->send($this->message('ab'));
        self::assertSame([self::text(BotText::CheckoutNameRefused, ['reason' => ClientNaming::NOT_A_NAME])], $this->said());
        $this->send($this->message('taken'));
        self::assertSame([self::text(BotText::CheckoutNameRefused, ['reason' => ClientNaming::TAKEN])], $this->said(), 'still awaited');

        $this->send($this->message('my-vpn'));
        self::assertSame([$this->namedText('my-vpn')], $this->said(), 'the checkout drawn again as a new message, with the name');

        $this->send($this->tap($this->payButton($this->plan, $this->berlin, $this->wallet)));
        self::assertSame('my-vpn', Order::query()->sole()->subscription?->remote_name);
    }

    public function testTheNameSurvivesACodesRedrawAndAWaySwitchedOff(): void
    {
        $this->botSettings('client_names', ['name_custom' => true]);
        $this->discountCode('NOWRUZ', 20);
        $card = $this->cardMethod();
        $this->send($this->tap($this->checkoutOf($this->plan, $this->berlin)));
        $this->send($this->tap($this->mode(ClientNameMode::Custom)));
        $this->send($this->message('my-vpn'));

        $this->send($this->tap(DiscountCodeHandler::askCallback($this->checkoutOf($this->plan, $this->berlin))));
        $this->send($this->message('NOWRUZ'));
        self::assertStringContainsString(self::text(BotText::CheckoutName, ['client' => 'my-vpn']), $this->said()[0], 'drawn again with the code');
        self::assertStringContainsString(self::text(BotText::CheckoutDiscount, ['discount' => 'کد NOWRUZ', 'amount' => Money::format('20000')]), $this->said()[0]);

        $card->forceFill(['enabled' => false])->save();
        $this->send($this->tap($this->payButton($this->plan, $this->berlin, $card)));
        self::assertStringContainsString(self::text(BotText::CheckoutName, ['client' => 'my-vpn']), $this->said()[0], 'the ways that are left, the name still there');

        $this->send($this->tap($this->payButton($this->plan, $this->berlin, $this->wallet)));
        $order = Order::query()->sole();
        self::assertSame(['my-vpn', '80000.00'], [$order->client_name, $order->amount]);
    }

    public function testThePatternTakesItBackAndANameIsKeptForItsCheckoutAlone(): void
    {
        $this->botSettings('client_names', ['name_random' => true]);
        $this->send($this->tap($this->checkoutOf($this->plan, $this->berlin)));
        $this->send($this->tap($this->mode(ClientNameMode::Random)));

        $paris = $this->checkoutOf($this->plan, $this->paris);
        $this->send($this->tap($paris));
        self::assertStringContainsString(self::text(BotText::CheckoutNamePattern), $this->said()[0], 'another checkout: the pattern');

        $this->send($this->tap($this->checkoutOf($this->plan, $this->berlin)));
        $this->send($this->tap($this->mode(ClientNameMode::Sequential)));
        self::assertStringContainsString(self::text(BotText::CheckoutNamePattern), $this->said()[0]);

        $this->send($this->tap($this->payButton($this->plan, $this->berlin, $this->wallet)));
        $order = Order::query()->sole();
        self::assertSame([null, 'USER_1'], [$order->client_name, $order->subscription?->remote_name], "the shop's pattern");
        self::assertSame('USER_1', FakeProvider::$created[0]['spec']->name);
    }

    public function testANameOfAWaySwitchedOffSinceIsNotOrdered(): void
    {
        $this->botSettings('client_names', ['name_random' => true]);
        $this->send($this->tap($this->checkoutOf($this->plan, $this->berlin)));
        $this->send($this->tap($this->mode(ClientNameMode::Random)));

        $this->botSettings('client_names', ['name_random' => false]);
        $this->send($this->tap($this->payButton($this->plan, $this->berlin, $this->wallet)));

        self::assertSame([null, 'USER_1'], [Order::query()->sole()->client_name, Order::query()->sole()->subscription?->remote_name]);
    }

    public function testAWaySwitchedOffSinceTheCheckoutWasDrawnDrawsItAgain(): void
    {
        $this->botSettings('client_names', ['name_random' => true, 'name_custom' => true]);
        $this->send($this->tap($this->checkoutOf($this->plan, $this->berlin)));

        $this->botSettings('client_names', ['name_custom' => false]);
        $this->send($this->tap($this->mode(ClientNameMode::Custom)));
        self::assertSame([BotTexts::paragraphs($this->checkoutText(), self::text(BotText::CheckoutNamePattern))], $this->said(), 'no name awaited: the checkout, with the ways left');
        self::assertNotContains($this->mode(ClientNameMode::Custom), $this->callbacks(0));

        $this->botSettings('client_names', ['name_custom' => true]);
        $this->send($this->tap($this->mode(ClientNameMode::Custom)));
        $this->botSettings('client_names', ['name_custom' => false]);
        $this->send($this->message('my-vpn'));
        self::assertSame([BotTexts::paragraphs($this->checkoutText(), self::text(BotText::CheckoutNamePattern))], $this->said(), 'switched off while it was typed: the checkout without it');
    }

    public function testANameIsJudgedOnTheServerItsCheckoutNames(): void
    {
        $this->botSettings('client_names', ['name_custom' => true]);
        $this->subscription($this->customer(['telegram_id' => 7001]), $this->plan, $this->paris, 'taken');
        $paris = $this->checkoutOf($this->plan, $this->paris);

        $this->send($this->tap($this->checkoutOf($this->plan, $this->berlin)));
        $this->send($this->tap($this->mode(ClientNameMode::Custom)));
        $this->send($this->message('taken'));
        self::assertSame([$this->namedText('taken')], $this->said(), 'free on Berlin');

        $this->send($this->tap($paris));
        $this->send($this->tap(ClientNameHandler::modeCallback(ClientNameMode::Custom, $paris)));
        $this->send($this->message('taken'));
        self::assertSame([self::text(BotText::CheckoutNameRefused, ['reason' => ClientNaming::TAKEN])], $this->said(), 'taken on Paris');

        // A button under a checkout that makes no service (a renewal's) has no server to judge a name on.
        $this->send($this->tap(ClientNameHandler::modeCallback(ClientNameMode::Random, RenewalHandler::checkoutCallback(1))));
        self::assertSame([self::text(BotText::Unknown)], $this->said());
    }

    public function testPayAgainShowsTheNameItsOrderAskedForAndPaysThatOrder(): void
    {
        $this->botSettings('client_names', ['name_custom' => true]);
        $order = $this->service(OrderService::class)->openPurchase($this->ali, $this->plan, $this->berlin, clientName: 'my-vpn');

        $this->send($this->tap(PurchaseHandler::reopenCallback($order->id)));
        self::assertSame([$this->namedText('my-vpn')], $this->said(), "the name it asked for, not the shop's pattern");

        $this->send($this->tap($this->payButton($this->plan, $this->berlin, $this->wallet)));
        self::assertSame([OrderStatus::Fulfilled, 'my-vpn'], [$order->refresh()->status, $order->subscription?->remote_name], 'paying it found that very order');
        self::assertSame(1, Order::query()->count());
    }

    /** The plan's checkout on Berlin as drawn without a name or a discount. */
    private function checkoutText(): string
    {
        return self::text(BotText::Checkout, ['plan' => $this->plan->name, 'server' => $this->berlin->name, 'amount' => Money::format('100000')]);
    }

    /** The checkout on Berlin with the name `$name` under it. */
    private function namedText(string $name): string
    {
        return BotTexts::paragraphs($this->checkoutText(), self::text(BotText::CheckoutName, ['client' => $name]));
    }

    /** The name the checkout just drawn says. */
    private function shownName(): string
    {
        self::assertSame(1, preg_match('/<code>([^<]+)<\/code>/', $this->said()[0], $match), $this->said()[0]);

        return $match[1];
    }

    private function mode(ClientNameMode $mode): string
    {
        return ClientNameHandler::modeCallback($mode, $this->checkoutOf($this->plan, $this->berlin));
    }
}
