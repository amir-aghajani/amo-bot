<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderPricing;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Providers\Models\Server;
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

/**
 * What the admin sets on a plan, and on a server, for the bot: each plan's button in «خرید اشتراک» and each location's
 * on a plan's screen in its colour, sharing a row with its neighbours of the same width; the plan's note under its
 * checkout («یادداشت پیش‌فاکتور»), a purchase's and a renewal's; and its agent price — on its button, in its details with
 * the plan's own price beside it, at its checkout — for an agent or a colleague.
 */
final class BotPlanButtonsTest extends BotTestCase
{
    private const NOTE = 'روی Happ و v2rayNG تست شده است.';

    private Server $berlin;
    private Plan $plan;
    private User $sara;
    private PaymentMethod $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutQr();
        $this->berlin = $this->sellingServer('Berlin');
        $this->plan = $this->plan(['price' => '150000.00'], $this->berlin);
        $this->sara = $this->wallet($this->customer(), '500000.00');
        $this->wallet = $this->walletMethod();
    }

    public function testThePlansButtonsTakeTheirColourAndShareRowsAsTheirPlansSay(): void
    {
        $this->plan->forceFill(['button_style' => 'success', 'button_row' => 2])->save();
        $second = $this->plan(['name' => 'دوماهه', 'button_style' => 'danger', 'button_row' => 2], $this->berlin);
        $third = $this->plan(['name' => 'سه‌ماهه'], $this->berlin);

        $this->send($this->tap(MainMenu::PLANS));

        $rows = $this->inlineKeyboard(0);
        self::assertSame(
            [[PurchaseHandler::planCallback($second->id), PurchaseHandler::planCallback($this->plan->id)], [PurchaseHandler::planCallback($third->id)]],
            array_map(static fn(array $row): array => array_column($row, 'callback_data'), $rows),
            'the first two side by side, the first on the right',
        );
        self::assertSame([['danger', 'success'], [null]], array_map(static fn(array $row): array => array_map(static fn(array $button): ?string => $button['style'] ?? null, $row), $rows), 'each in its colour; none, the client\'s own');
    }

    public function testALocationsButtonTakesItsServersColourAndRow(): void
    {
        $paris = $this->sellingServer('Paris', ['meta' => ['button_style' => 'primary', 'button_row' => '2']]);
        $this->berlin->forceFill(['meta' => ['button_style' => 'danger', 'button_row' => '2']])->save();
        $this->planEntry($this->plan, $paris);

        $this->send($this->tap(PurchaseHandler::planCallback($this->plan->id)));

        self::assertSame([[
            ['text' => self::text(BotText::ServerButton, ['server' => 'Paris']), 'callback_data' => PurchaseHandler::serverCallback($this->plan->id, $paris->id), 'style' => 'primary'],
            ['text' => self::text(BotText::ServerButton, ['server' => 'Berlin']), 'callback_data' => PurchaseHandler::serverCallback($this->plan->id, $this->berlin->id), 'style' => 'danger'],
        ]], array_slice($this->inlineKeyboard(0), 0, 1), 'one row, the plan\'s first location on the right');
    }

    public function testThePlansNoteIsUnderItsCheckouts(): void
    {
        $this->plan->forceFill(['checkout_note' => self::NOTE])->save();

        $this->send($this->tap(PurchaseHandler::serverCallback($this->plan->id, $this->berlin->id)));
        self::assertSame([BotTexts::paragraphs($this->checkoutText('150000'), self::text(BotText::CheckoutPlanNote, ['plan_note' => self::NOTE]))], $this->said(), 'a purchase\'s');

        $service = $this->subscription($this->sara, $this->plan, $this->berlin, 'sara_1');
        $this->send($this->tap(RenewalHandler::checkoutCallback($service->id)));
        self::assertStringEndsWith(self::text(BotText::CheckoutPlanNote, ['plan_note' => self::NOTE]), $this->said()[0], 'a renewal\'s');

        // The admin may empty the line; a plan without a note has none.
        $this->service(BotTexts::class)->save(BotText::CheckoutPlanNote, '');
        $this->send($this->tap(PurchaseHandler::serverCallback($this->plan->id, $this->berlin->id)));
        self::assertSame([$this->checkoutText('150000')], $this->said());
        $this->plan->forceFill(['checkout_note' => null])->save();
        $this->service(BotTexts::class)->reset(BotText::CheckoutPlanNote);
        $this->send($this->tap(PurchaseHandler::serverCallback($this->plan->id, $this->berlin->id)));
        self::assertSame([$this->checkoutText('150000')], $this->said());
    }

    public function testAColleagueSeesAndPaysTheAgentPrice(): void
    {
        $this->plan->forceFill(['agent_price' => '100000.00'])->save();
        $this->customerGroup('همکاران', [$this->sara], ['agent_pricing' => true]);

        $this->send($this->tap(MainMenu::PLANS));
        self::assertSame(self::text(BotText::PlanButton, [
            'plan' => $this->plan->name,
            'traffic' => Messages::traffic($this->plan->trafficBytes()),
            'duration' => Messages::duration($this->plan->duration_hours),
            'price' => Money::format('100000'),
        ]), $this->inlineKeyboard(0)[0][0]['text']);

        $this->send($this->tap(PurchaseHandler::planCallback($this->plan->id)));
        self::assertStringContainsString(self::text(BotText::PlanAgentPrice, ['regular' => Money::format('150000')]), $this->said()[0], 'the plan\'s own price beside it');

        $this->send($this->tap(PurchaseHandler::serverCallback($this->plan->id, $this->berlin->id)));
        self::assertSame([$this->checkoutText('100000')], $this->said());
        $this->send($this->tap(CallbackData::build(PurchaseHandler::serverCallback($this->plan->id, $this->berlin->id), $this->wallet->id)));

        $order = Order::query()->sole();
        self::assertSame(['100000.00', OrderPricing::Agent], [$order->amount, $order->pricing]);
        self::assertSame('400000.00', $this->sara->balance());
    }

    /** The plan's checkout on Berlin at `$price`. */
    private function checkoutText(string $price): string
    {
        return self::text(BotText::Checkout, ['plan' => $this->plan->name, 'server' => 'Berlin', 'amount' => Money::format($price)]);
    }
}
