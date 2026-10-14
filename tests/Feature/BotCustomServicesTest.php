<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\DTO\Package;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\ClientNameMode;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Handlers\ClientNameHandler;
use App\Modules\Telegram\Handlers\CustomServiceHandler;
use App\Modules\Telegram\Handlers\DiscountCodeHandler;
use App\Modules\Telegram\Handlers\MenuHandler;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Update\CallbackData;
use App\Modules\Users\Models\User;
use App\Support\Money;
use App\Support\Traffic;
use Tests\BotTestCase;

/**
 * «🛠 ساخت سرویس دلخواه» in the bot: the button under the plans while the builder is offered; the GB, then the days —
 * each a button or typed, one outside the bounds refused saying them and another awaited —, then the server (none to
 * pick when there is one), then the checkout with the GB's and the days' prices, the customer's discounts and the
 * service's name as a plan's checkout asks it; paid from the wallet, the service arrives as any purchase's. A step tapped
 * that left the bounds since is shown again; once nothing is offered, the customer is told so.
 */
final class BotCustomServicesTest extends BotTestCase
{
    private User $ali;
    private Server $germany;
    private Server $france;
    private PaymentMethod $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutQr();
        $this->germany = $this->sellingServer('آلمان', ['sort' => 1]);
        $this->france = $this->sellingServer('فرانسه', ['sort' => 2]);
        $this->ali = $this->wallet($this->customer(), '500000.00');
        $this->wallet = $this->walletMethod();
    }

    public function testThePlansOfferTheBuilderWhileItIsOffered(): void
    {
        $plan = $this->plan(on: $this->germany);

        $this->send($this->tap(MainMenu::PLANS));
        self::assertNotContains(CustomServiceHandler::PREFIX, $this->callbacks(0), 'off by default');

        $this->customServicesOn([$this->germany]);
        $this->send($this->tap(MainMenu::PLANS));
        self::assertSame([PurchaseHandler::planCallback($plan->id), CustomServiceHandler::PREFIX], array_slice($this->callbacks(0), 0, 2), 'under the plans');
        self::assertSame(['text' => self::text(BotText::CustomButton), 'callback_data' => CustomServiceHandler::PREFIX, 'style' => 'primary'], $this->inlineKeyboard(0)[1][0]);

        $category = $this->category();
        $plan->forceFill(['category_id' => $category->id])->save();
        $this->send($this->tap(MainMenu::PLANS));
        self::assertSame([MenuHandler::categoryCallback($category->id), CustomServiceHandler::PREFIX], array_slice($this->callbacks(0), 0, 2), 'under the categories');
        $this->send($this->tap(MenuHandler::categoryCallback($category->id)));
        self::assertNotContains(CustomServiceHandler::PREFIX, $this->callbacks(0), "not in a category's plans");

        $plan->forceFill(['is_active' => false])->save();
        $category->forceFill(['is_active' => false])->save();
        $this->send($this->tap(MainMenu::PLANS));
        self::assertSame([self::text(BotText::PlansEmpty)], $this->said());
        self::assertContains(CustomServiceHandler::PREFIX, $this->callbacks(0), 'with no plan on sale too');
    }

    public function testButtonsLeadFromTheGbThroughTheDaysAndTheServerToTheCheckout(): void
    {
        $this->customServicesOn([$this->germany, $this->france]);

        $this->send($this->tap(CustomServiceHandler::PREFIX));
        self::assertSame([$this->trafficScreen()], $this->said());
        self::assertSame([['custom:10', 'custom:50'], ['custom:100'], [MainMenu::PLANS]], $this->rows(), 'two amounts a row, and back to the plans');
        self::assertSame(Messages::traffic(Traffic::bytesOfGb(10)), $this->inlineKeyboard(0)[0][0]['text']);

        $this->send($this->tap(CustomServiceHandler::daysCallback(50)));
        self::assertSame([$this->daysScreen(50)], $this->said());
        self::assertSame([['custom:50:30', 'custom:50:60'], [CustomServiceHandler::PREFIX]], $this->rows());
        self::assertSame(Messages::duration(30 * Package::HOURS_A_DAY), $this->inlineKeyboard(0)[0][0]['text']);

        $this->send($this->tap(CustomServiceHandler::serverCallback(50, 30)));
        self::assertSame([self::text(BotText::CustomServer, ['traffic' => Messages::traffic(Traffic::bytesOfGb(50)), 'duration' => Messages::duration(30 * Package::HOURS_A_DAY)])], $this->said());
        self::assertSame([[$this->at(50, 30, $this->germany)], [$this->at(50, 30, $this->france)], [CustomServiceHandler::daysCallback(50)]], $this->rows(), 'a server a row, by name');
        self::assertSame(self::text(BotText::ServerButton, ['server' => 'فرانسه']), $this->inlineKeyboard(0)[1][0]['text']);

        $this->send($this->tap($this->at(50, 30, $this->france)));
        self::assertSame([$this->checkoutText('فرانسه', '170000')], $this->said(), '50 GB at 2,500 and 30 days at 1,500');
        self::assertSame([$this->pay(50, 30, $this->france), CustomServiceHandler::serverCallback(50, 30)], $this->callbacks(0), 'a button per way to pay, and back to the servers');
        self::assertSame(0, Order::query()->count(), 'nothing is ordered before a way to pay is picked');
    }

    public function testWithOneServerTheDaysLeadStraightToItsCheckoutAndTheWalletPaysForTheService(): void
    {
        $this->customServicesOn([$this->germany]);

        $this->send($this->tap(CustomServiceHandler::serverCallback(50, 30)));
        self::assertSame([$this->checkoutText('آلمان', '170000')], $this->said());
        self::assertSame([$this->pay(50, 30, $this->germany), CustomServiceHandler::daysCallback(50)], $this->callbacks(0), 'back to the days: there was no server to pick');

        $this->send($this->tap($this->pay(50, 30, $this->germany)));
        self::assertSame(['deleteMessage', 'sendMessage', 'answerCallbackQuery'], $this->calls(), 'the service arrives as a new message');
        $order = Order::query()->sole();
        self::assertSame([OrderStatus::Fulfilled, null, Traffic::bytesOfGb(50), 30, 2, '170000.00'], [$order->status, $order->plan_id, $order->traffic_bytes, $order->days, $order->devices, $order->amount]);
        $service = Subscription::query()->sole();
        self::assertSame([null, $this->germany->id, Traffic::bytesOfGb(50), 30 * 24, 2], [$service->plan_id, $service->server_id, $service->traffic_limit_bytes, $service->duration_hours, $service->ip_limit]);
        self::assertStringContainsString(self::text(BotText::CustomPlanName), $this->said()[0], 'named a built service where a plan would be');
        self::assertStringContainsString(Messages::traffic(Traffic::bytesOfGb(50)), $this->said()[0]);
        self::assertSame('330000.00', $this->ali->balance());
    }

    public function testAmountsMayBeTypedAndOneOutsideTheBoundsIsRefusedAndAnotherAwaited(): void
    {
        $this->customServicesOn([$this->germany]);
        $this->send($this->tap(CustomServiceHandler::PREFIX));

        $this->send($this->message('۶۰۰ گیگ'));
        self::assertSame([self::text(BotText::CustomTrafficInvalid, ['least' => '۱', 'most' => '۵۰۰'])], $this->said());
        self::assertSame([CustomServiceHandler::PREFIX], $this->callbacks(0));
        $this->send($this->message('سلام'));
        self::assertSame([self::text(BotText::CustomTrafficInvalid, ['least' => '۱', 'most' => '۵۰۰'])], $this->said(), 'no number is none either');

        $this->send($this->message('۷۵ گیگ'));
        self::assertSame(['sendMessage'], $this->calls(), 'the next step as a new message');
        self::assertSame([$this->daysScreen(75)], $this->said());

        $this->send($this->message('100'));
        self::assertSame([self::text(BotText::CustomDaysInvalid, ['least' => '۱', 'most' => '۹۰'])], $this->said());
        self::assertSame([CustomServiceHandler::daysCallback(75)], $this->callbacks(0));

        $this->send($this->message('45'));
        self::assertSame([self::text(BotText::CustomCheckout, [
            'traffic' => Messages::traffic(Traffic::bytesOfGb(75)),
            'traffic_price' => Money::format('187500'),
            'duration' => Messages::duration(45 * 24),
            'days_price' => Money::format('67500'),
            'devices' => Messages::devices(2),
            'server' => 'آلمان',
            'amount' => Money::format('255000'),
        ])], $this->said(), '75 GB at 2,500 and 45 days at 1,500');

        $this->send($this->message('ممنون'));
        self::assertSame([self::text(BotText::Unknown)], $this->said(), 'at the checkout, nothing is awaited any more');
    }

    public function testAStepTappedThatLeftTheBoundsSinceIsShownAgain(): void
    {
        $this->customServicesOn([$this->germany]);

        $this->send($this->tap(CustomServiceHandler::daysCallback(600)));
        self::assertSame([$this->trafficScreen()], $this->said(), 'the GB to choose again');

        $this->send($this->tap($this->at(50, 91, $this->germany)));
        self::assertSame([$this->daysScreen(50)], $this->said(), 'the days to choose again');

        $this->send($this->tap($this->pay(50, 30, $this->france)));
        self::assertSame([$this->checkoutText('آلمان', '170000')], $this->said(), 'a server it does not sell on: the one it does');
        self::assertSame(0, Order::query()->count());
    }

    public function testOnceNothingIsOfferedTheCustomerIsToldSo(): void
    {
        $this->customServicesOn([$this->germany]);
        $this->send($this->tap(CustomServiceHandler::PREFIX));
        $this->customServicesOn([$this->germany], ['custom_enabled' => false]);

        $this->send($this->message('50'));
        self::assertSame([self::text(BotText::CustomUnavailable)], $this->said());
        self::assertSame([MainMenu::PLANS], $this->callbacks(0));

        $this->send($this->tap($this->pay(50, 30, $this->germany)));
        self::assertSame([self::text(BotText::CustomUnavailable)], $this->said());
        self::assertSame(0, Order::query()->count());
    }

    public function testADiscountCodeComesOffItsCheckout(): void
    {
        $this->customServicesOn([$this->germany]);
        $this->discountCode('NOWRUZ', 10);
        $checkout = $this->at(50, 30, $this->germany);

        $this->send($this->tap($checkout));
        self::assertContains(DiscountCodeHandler::askCallback($checkout), $this->callbacks(0));
        $this->send($this->tap(DiscountCodeHandler::askCallback($checkout)));
        $this->send($this->message('nowruz'));
        self::assertStringStartsWith($this->checkoutText('آلمان', '153000'), $this->said()[0]);
        self::assertStringContainsString(self::text(BotText::CheckoutDiscount, ['discount' => 'کد NOWRUZ', 'amount' => Money::format('17000')]), $this->said()[0]);

        $this->send($this->tap($this->pay(50, 30, $this->germany)));
        $order = Order::query()->sole();
        self::assertSame([OrderStatus::Fulfilled, '153000.00', '17000.00'], [$order->status, $order->amount, $order->discount_amount]);
    }

    public function testItsCheckoutAsksTheServicesNameAsAPlansDoesAndTheOrderIsMadeWithIt(): void
    {
        $this->customServicesOn([$this->germany]);
        $this->botSettings('client_names', ['name_custom' => true]);
        $checkout = $this->at(50, 30, $this->germany);
        $custom = ClientNameHandler::modeCallback(ClientNameMode::Custom, $checkout);

        $this->send($this->tap($checkout));
        self::assertSame([BotTexts::paragraphs($this->checkoutText('آلمان', '170000'), self::text(BotText::CheckoutNamePattern))], $this->said());
        self::assertSame([$this->pay(50, 30, $this->germany), ClientNameHandler::modeCallback(ClientNameMode::Sequential, $checkout), $custom, CustomServiceHandler::daysCallback(50)], $this->callbacks(0));

        $this->send($this->tap($custom));
        $this->send($this->message('my-vpn'));
        self::assertSame([BotTexts::paragraphs($this->checkoutText('آلمان', '170000'), self::text(BotText::CheckoutName, ['client' => 'my-vpn']))], $this->said(), 'drawn again with it');

        $this->send($this->tap($this->pay(50, 30, $this->germany)));
        $order = Order::query()->sole();
        self::assertSame([OrderStatus::Fulfilled, 'my-vpn', 'my-vpn'], [$order->status, $order->client_name, $order->subscription?->remote_name]);
    }

    public function testPayAgainOpensTheCheckoutOfWhatAnUnpaidOrderBuilt(): void
    {
        $this->customServicesOn([$this->germany]);
        $order = $this->service(OrderService::class)->openCustomPurchase($this->ali, 50, 30, $this->germany);

        $this->send($this->tap(PurchaseHandler::reopenCallback($order->id)));
        self::assertSame([$this->checkoutText('آلمان', '170000')], $this->said());
    }

    /** The GB step as the builder of customServicesOn() words it. */
    private function trafficScreen(): string
    {
        return self::text(BotText::CustomTraffic, [
            'least' => '۱',
            'most' => '۵۰۰',
            'tiers' => implode("\n", [
                self::text(BotText::CustomTier, ['from' => Messages::traffic(Traffic::bytesOfGb(1)), 'unit_price' => Money::format('3000')]),
                self::text(BotText::CustomTier, ['from' => Messages::traffic(Traffic::bytesOfGb(50)), 'unit_price' => Money::format('2500')]),
            ]),
        ]);
    }

    /** The days step after `$gb` GB. */
    private function daysScreen(int $gb): string
    {
        return self::text(BotText::CustomDays, [
            'traffic' => Messages::traffic(Traffic::bytesOfGb($gb)),
            'least' => '۱',
            'most' => '۹۰',
            'tiers' => implode("\n", [
                self::text(BotText::CustomTier, ['from' => Messages::duration(24), 'unit_price' => Money::format('2000')]),
                self::text(BotText::CustomTier, ['from' => Messages::duration(30 * 24), 'unit_price' => Money::format('1500')]),
            ]),
        ]);
    }

    /** The checkout of 50 GB for 30 days on `$server`, `$amount` to pay. */
    private function checkoutText(string $server, string $amount): string
    {
        return self::text(BotText::CustomCheckout, [
            'traffic' => Messages::traffic(Traffic::bytesOfGb(50)),
            'traffic_price' => Money::format('125000'),
            'duration' => Messages::duration(30 * 24),
            'days_price' => Money::format('45000'),
            'devices' => Messages::devices(2),
            'server' => $server,
            'amount' => Money::format($amount),
        ]);
    }

    private function at(int $gb, int $days, Server $server): string
    {
        return CustomServiceHandler::checkoutCallback($gb, $days, $server->id);
    }

    private function pay(int $gb, int $days, Server $server): string
    {
        return CallbackData::build($this->at($gb, $days, $server), $this->wallet->id);
    }

    /** @return list<list<string>> The callback data of the first call's buttons, row by row. */
    private function rows(): array
    {
        return array_map(static fn(array $row): array => array_column($row, 'callback_data'), $this->inlineKeyboard(0));
    }
}
