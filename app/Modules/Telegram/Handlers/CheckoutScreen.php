<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\DTO\CardTransfer;
use App\Modules\Payments\DTO\Shortfall;
use App\Modules\Payments\Enums\CheckoutOutcome;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\Checkout;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Notifications\ServiceCard;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Support\Money;

/**
 * The bot's checkout, every purchase in the bot goes through it — a plan (PurchaseHandler), a service's renewal
 * (RenewalHandler), a wallet top-up (TopUpHandler), an agent's traffic (AgencyHandler): what is being bought and a button
 * for each way that may pay it (show()); then, once the customer picked one, the checkout itself — the shop's one, which
 * the website goes through too (Payments\Services\Checkout) — and what came of it, rendered (buy()). The wallet settles at
 * once and the checkout makes way for what it delivered, as a fresh message; a card shows where to transfer and waits for
 * the receipt (ReceiptHandler). A checkout left alone orders nothing, and an order whose transfer never came expires
 * (ExpireOrdersTask).
 */
final class CheckoutScreen
{
    /** The step a chat is at while a checkout is on its screen: what a wallet payment claims, once (buy()). */
    private const STATE = 'checkout';

    public function __construct(
        private readonly PaymentMethods $methods,
        private readonly Checkout $checkout,
        private readonly ServiceCard $card,
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
    ) {}

    /**
     * What is being bought (`$text`), a button for each way that may pay that kind of order (`$pay` makes its callback
     * data), and «بازگشت» to the step before. Showing it leaves whatever flow the chat was in (a receipt awaited).
     *
     * @param \Closure(PaymentMethod): string $pay
     */
    public function show(Context $ctx, string $text, OrderType $type, \Closure $pay, string $back): void
    {
        $ctx->session->enter(self::STATE);

        $methods = $this->methods->forOrder($type);
        $keyboard = InlineKeyboard::make();
        foreach ($methods as $method) {
            $keyboard->row(InlineKeyboard::callback($method->label, $pay($method)));
        }
        $keyboard->row($this->buttons->back($back));

        $ctx->edit($methods === [] ? BotTexts::paragraphs($text, $this->texts->get(BotText::CheckoutNoGateway)) : $text, ['reply_markup' => $keyboard->build()]);
    }

    /**
     * The customer picked `$method` to pay `$price` for something not ordered yet, which `$open` orders — or finds open —
     * once nothing stands in the way (Checkout::pay()); and what came of it, in place of the checkout. `$checkout` is the
     * checkout's own callback data: where «بازگشت» goes from whatever comes of it.
     *
     * @param \Closure(?OrderKey): Order $open
     */
    public function buy(Context $ctx, PaymentMethod $method, string $price, \Closure $open, string $checkout): void
    {
        // The wallet pays a checkout once: a second tap on it — or one on a checkout the chat has moved on from — finds it
        // claimed and charges nothing. (A card needs no such guard: the same card finds the same payment.)
        $claim = $method->isWallet() ? static fn(): bool => $ctx->session->claim(self::STATE) : null;
        $result = $this->checkout->pay($ctx->user, $method, $price, $open, guard: $claim);

        match ($result->outcome) {
            CheckoutOutcome::Short => $this->short($ctx, $result->shortfall(), $checkout),
            // A second tap on the same button, or a payment from elsewhere, closed the order first: nothing was charged.
            // (Cancelled is a website's request made again with its key; the bot's checkout has none.)
            CheckoutOutcome::Closed, CheckoutOutcome::Cancelled => $ctx->edit($this->texts->get(BotText::OrderNotPending), ['reply_markup' => $this->buttons->backOnly($checkout)]),
            CheckoutOutcome::Transfer => $this->transfer($ctx, $result->payment(), $result->card(), $checkout),
            // Settled on the spot (the wallet): the checkout is over, so its message goes and the outcome arrives as a
            // fresh message — the delivered service (a QR card or the text) reads like the notification it is.
            CheckoutOutcome::Settled => $result->order()->status === OrderStatus::Fulfilled
                ? $this->delivered($ctx, $result->order())
                : $ctx->replace($this->card->failedText($result->order())),
            CheckoutOutcome::Refused => $this->failed($ctx, $result->payment()->note, $checkout),
            CheckoutOutcome::Processing => $this->paid($ctx, $result->order()),
        };
    }

    /** The wallet's payment did not go through — the gateway's reason, or none to give. */
    private function failed(Context $ctx, ?string $reason, string $checkout): void
    {
        $ctx->replace($this->texts->render(BotText::PayFailed, [
            'reason' => $reason ?? $this->texts->part(BotText::ServiceUnknown),
        ]), ['reply_markup' => $this->buttons->backOnly($checkout)]);
    }

    /**
     * The wallet paid, and the order's delivery is under way elsewhere — another process took it in the same moment: said
     * as it stands, paid, with what it brings, which comes here once delivered (OrderActions tells it, as after a retry).
     * Nothing to go back to: the checkout is paid.
     */
    private function paid(Context $ctx, Order $order): void
    {
        $ctx->replace($this->texts->render(BotText::PayProcessing, [
            'order' => $order->id,
            'outcome' => $this->texts->part(ReceiptHandler::outcome($order->type)),
        ]));
    }

    /**
     * A card to transfer to, and the receipt awaited (ReceiptHandler): the customer's next picture is it. «بازگشت» goes
     * back to the checkout — the same card again finds the same payment.
     */
    private function transfer(Context $ctx, Payment $payment, CardTransfer $card, string $checkout): void
    {
        $ctx->session->enter(ReceiptHandler::stateFor($payment));
        $instructions = trim($this->texts->render(BotText::CardInstructions, [
            'amount' => Money::format($payment->amount),
            'card' => $card->card,
            'holder' => $card->holder,
            'instructions' => $card->instructions,
        ]));

        $ctx->edit(BotTexts::paragraphs($instructions, $this->texts->part(BotText::PayInstructionsSuffix)), ['reply_markup' => $this->buttons->backOnly($checkout)]);
    }

    /**
     * What the paid order delivered, in place of the checkout: the service; the renewed one with the way to its screen;
     * the charged wallet, or the agent's traffic, with the way back to where it was bought.
     */
    private function delivered(Context $ctx, Order $order): void
    {
        $ctx->delete();
        $this->card->sendSettled($ctx->chatId(), $order, match ($order->type) {
            OrderType::Traffic => ['reply_markup' => $this->buttons->backOnly(MainMenu::AGENCY)],
            OrderType::WalletTopUp => ['reply_markup' => $this->buttons->backOnly(MainMenu::WALLET)],
            OrderType::Renewal => ['reply_markup' => InlineKeyboard::make()->row(InlineKeyboard::callback(
                $this->texts->get(BotText::ReminderOpenService),
                SubscriptionHandler::serviceCallback((int) $order->subscription_id),
            ))->build()],
            OrderType::Purchase => [],
        });
    }

    /** The wallet cannot cover it: the balance, the price and what is missing — the way to top it up, and back to the checkout. */
    private function short(Context $ctx, Shortfall $shortfall, string $checkout): void
    {
        $ctx->edit($this->texts->render(BotText::PayInsufficient, [
            'balance' => Messages::balance($shortfall->balance),
            'amount' => Money::format($shortfall->price),
            'missing' => Money::format($shortfall->missing),
        ]), ['reply_markup' => InlineKeyboard::make()->row($this->buttons->topUp())->row($this->buttons->back($checkout))->build()]);
    }
}
