<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Discounts\DTO\DiscountLine;
use App\Modules\Discounts\Exceptions\DiscountCodeRefusedException;
use App\Modules\Discounts\Services\DiscountCodes;
use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\DTO\Quote;
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
use App\Modules\Telegram\Texts\Html;
use App\Support\Money;

/**
 * The bot's checkout screen, shared by every purchase in the bot (a plan, a renewal, extra traffic or time, a wallet
 * top-up, an agent's traffic). show() draws what is being bought with a button per way to pay; buy() runs the shop's
 * one checkout (Payments\Services\Checkout, the website's too) and renders the outcome. Nothing is ordered until a way
 * to pay is picked, and an unpaid order expires on its own.
 *
 * A checkout that may be discounted lists its discounts under its text and offers «🏷 کد تخفیف دارم»
 * (DiscountCodeHandler). The code typed is kept in the chat's session for that checkout alone (keepCode()) and is
 * judged again when it is paid: one used up meanwhile is dropped and nothing is ordered.
 */
final class CheckoutScreen
{
    /** The chat's state while a checkout is on screen: a wallet payment claims it, so a double tap pays once. */
    private const STATE = 'checkout';

    /** The session fact holding the discount code kept for one checkout: `{at: its callback data, code}`. */
    private const CODE = '_discount_code';

    public function __construct(
        private readonly PaymentMethods $methods,
        private readonly Checkout $checkout,
        private readonly ServiceCard $card,
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
        private readonly DiscountCodes $codes,
    ) {}

    /** The discount code kept for the checkout `$checkout` (its callback data); null for none. */
    public function code(Context $ctx, string $checkout): ?string
    {
        $kept = $ctx->session->get(self::CODE);

        return is_array($kept) && ($kept['at'] ?? null) === $checkout && is_string($kept['code'] ?? null) ? $kept['code'] : null;
    }

    /** Keep `$code` for the checkout `$checkout`, or (null) drop the kept code — any other checkout's goes too. */
    public function keepCode(Context $ctx, string $checkout, ?string $code): void
    {
        if ($code !== null) {
            $ctx->session->put(self::CODE, ['at' => $checkout, 'code' => $code]);
        } elseif ($ctx->session->get(self::CODE) !== null) {
            $ctx->session->put(self::CODE, null);
        }
    }

    /**
     * What the checkout costs to show it: with the code just typed (`$typed`, kept once it holds), else with the kept
     * code. A kept code that no longer holds is dropped, and the reason comes back for priced() to show.
     *
     * @param \Closure(?string): Quote $price The price with a given code (null: none)
     * @return array{Quote, string|null} The quote, and why a kept code was dropped
     * @throws DiscountCodeRefusedException when `$typed` does not hold
     */
    public function quote(Context $ctx, string $checkout, \Closure $price, ?string $typed = null): array
    {
        if ($typed !== null) {
            $quote = $price($typed);
            $this->keepCode($ctx, $checkout, $quote->code?->code);

            return [$quote, null];
        }
        $code = $this->code($ctx, $checkout);
        if ($code === null) {
            return [$price(null), null];
        }

        try {
            return [$price($code), null];
        } catch (DiscountCodeRefusedException $e) {
            $this->keepCode($ctx, $checkout, null);

            return [$price(null), $e->getMessage()];
        }
    }

    /**
     * What the checkout costs as it is paid, with the kept code; null after telling the customer the code no longer
     * holds (codeLost()) — nothing is ordered then.
     *
     * @param \Closure(?string): Quote $price
     */
    public function priceToPay(Context $ctx, string $checkout, \Closure $price): ?Quote
    {
        try {
            return $price($this->code($ctx, $checkout));
        } catch (DiscountCodeRefusedException $e) {
            $this->codeLost($ctx, $checkout, $e->getMessage());

            return null;
        }
    }

    /** The checkout's text with a line per discount and the amount before them under it — and why a kept code was dropped. */
    public function priced(string $text, Quote $quote, ?string $dropped = null): string
    {
        $lines = array_map(fn(DiscountLine $line): Html => $this->texts->part(BotText::CheckoutDiscount, [
            'discount' => $line->label,
            'amount' => Money::format($line->amount),
        ]), $quote->lines());
        if ($lines !== []) {
            $lines[] = $this->texts->part(BotText::CheckoutBeforeDiscount, ['regular' => Money::format($quote->base)]);
        }
        $lines = array_filter($lines, static fn(Html $line): bool => $line->html !== '');

        return BotTexts::paragraphs(
            $text,
            Html::lines(...$lines),
            $dropped === null ? '' : $this->texts->part(BotText::DiscountCodeDropped, ['reason' => $dropped]),
        );
    }

    /** A plan's checkout (purchase or renewal) with the plan's «یادداشت پیش‌فاکتور» under it, when it has one. */
    public function withNote(string $text, Plan $plan): string
    {
        $note = trim((string) $plan->checkout_note);

        return $note === '' ? $text : BotTexts::paragraphs($text, $this->texts->part(BotText::CheckoutPlanNote, ['plan_note' => $note]));
    }

    /**
     * Draw the checkout in place of the tapped message: `$text`, a button per way to pay `$type` (`$pay` makes each
     * callback), the row `$instead` (other things to buy) and «بازگشت» to `$back`. Something `$free` gets one «🎁 دریافت
     * رایگان» button instead, through the first way to pay: the checkout settles a free order whichever is picked. With a
     * `$quote` and its `$checkout` callback, «🏷 کد تخفیف دارم» — or «❌ حذف کد تخفیف» while a code is applied — is
     * offered while the shop has codes and something is left to pay. Showing it leaves whatever flow the chat was in.
     *
     * @param \Closure(PaymentMethod): string $pay
     * @param list<array<string, mixed>> $instead
     */
    public function show(Context $ctx, string $text, OrderType $type, \Closure $pay, string $back, array $instead = [], bool $free = false, ?Quote $quote = null, ?string $checkout = null): void
    {
        $ctx->session->enter(self::STATE);

        $methods = $this->methods->forOrder($type);
        $keyboard = InlineKeyboard::make();
        if ($free && $methods !== []) {
            $keyboard->row(InlineKeyboard::callback($this->texts->get(BotText::CheckoutFree), $pay($methods[0])));
        } else {
            foreach ($methods as $method) {
                $keyboard->row(InlineKeyboard::callback($method->label, $pay($method)));
            }
        }
        if ($quote !== null && $checkout !== null && $methods !== [] && ($quote->code !== null || (Money::isPositive($quote->afterAutomatic()) && $this->codes->offered()))) {
            $keyboard->row($quote->code !== null
                ? InlineKeyboard::callback($this->texts->get(BotText::DiscountCodeRemove), DiscountCodeHandler::removeCallback($checkout))
                : InlineKeyboard::callback($this->texts->get(BotText::DiscountCodeButton), DiscountCodeHandler::askCallback($checkout)));
        }
        $keyboard->row(...$instead)->row($this->buttons->back($back));

        $ctx->edit($methods === [] ? BotTexts::paragraphs($text, $this->texts->get(BotText::CheckoutNoGateway)) : $text, ['reply_markup' => $keyboard->build()]);
    }

    /**
     * Pay `$price` with `$method` for what `$open` orders (or finds open), and show what came of it in place of the
     * checkout. `$checkout` is the checkout's callback data, where «بازگشت» leads from here.
     *
     * The wallet (and a free order) claims the checkout state first, so a second tap — or a tap on a checkout the chat
     * left — charges nothing. A card needs no such guard: the same card finds the same payment.
     *
     * @param \Closure(?OrderKey): Order $open
     */
    public function buy(Context $ctx, PaymentMethod $method, string $price, \Closure $open, string $checkout): void
    {
        $claim = $method->isWallet() || !Money::isPositive($price) ? static fn(): bool => $ctx->session->claim(self::STATE) : null;
        try {
            $result = $this->checkout->pay($ctx->user, $method, $price, $open, guard: $claim);
        } catch (DiscountCodeRefusedException $e) {
            $this->codeLost($ctx, $checkout, $e->getMessage());

            return;
        }
        // Paid: the code served its checkout.
        if (in_array($result->outcome, [CheckoutOutcome::Settled, CheckoutOutcome::Processing], true)) {
            $this->keepCode($ctx, $checkout, null);
        }

        match ($result->outcome) {
            CheckoutOutcome::Short => $this->short($ctx, $result->shortfall(), $checkout),
            // Closed by another tap or another payment first: nothing was charged. (Cancelled only comes from a website's
            // request made again with its key; the bot sends none.)
            CheckoutOutcome::Closed, CheckoutOutcome::Cancelled => $ctx->edit($this->texts->get(BotText::OrderNotPending), ['reply_markup' => $this->buttons->backOnly($checkout)]),
            CheckoutOutcome::Transfer => $this->transfer($ctx, $result->payment(), $result->card(), $checkout),
            // Settled by the wallet: the outcome arrives as a fresh message, like the notification it is.
            CheckoutOutcome::Settled => $result->order()->status === OrderStatus::Fulfilled
                ? $this->delivered($ctx, $result->order())
                : $ctx->replace($this->card->failedText($result->order())),
            CheckoutOutcome::Refused => $this->failed($ctx, $result->payment()->note, $checkout),
            CheckoutOutcome::Processing => $this->paid($ctx, $result->order()),
        };
    }

    /** The kept discount code no longer holds as the checkout is paid: dropped, why said, «بازگشت» to the checkout without it. */
    public function codeLost(Context $ctx, string $checkout, string $reason): void
    {
        $this->keepCode($ctx, $checkout, null);
        $ctx->edit($this->texts->render(BotText::DiscountCodeLost, ['reason' => $reason]), ['reply_markup' => $this->buttons->backOnly($checkout)]);
    }

    /** The wallet's payment was refused, with the gateway's reason when it gave one. */
    private function failed(Context $ctx, ?string $reason, string $checkout): void
    {
        $ctx->replace($this->texts->render(BotText::PayFailed, [
            'reason' => $reason ?? $this->texts->part(BotText::ServiceUnknown),
        ]), ['reply_markup' => $this->buttons->backOnly($checkout)]);
    }

    /** Paid, but another process is delivering the order right now: say so, with what it brings once delivered. */
    private function paid(Context $ctx, Order $order): void
    {
        $ctx->replace($this->texts->render(BotText::PayProcessing, [
            'order' => $order->id,
            'outcome' => $this->texts->part(ReceiptHandler::outcome($order->type)),
        ]));
    }

    /** The card to transfer to; the chat now waits for the receipt (ReceiptHandler). */
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

    /** What the paid order delivered, as a fresh message, with the way on that fits what it was. */
    private function delivered(Context $ctx, Order $order): void
    {
        $ctx->delete();
        $this->card->sendSettled($ctx->chatId(), $order, match ($order->type) {
            OrderType::Traffic => ['reply_markup' => $this->buttons->backOnly(MainMenu::AGENCY)],
            OrderType::WalletTopUp => ['reply_markup' => $this->buttons->backOnly(MainMenu::WALLET)],
            OrderType::Renewal, OrderType::ExtraTraffic, OrderType::ExtraTime => ['reply_markup' => $this->buttons->serviceOnly((int) $order->subscription_id)],
            OrderType::Purchase => [],
        });
    }

    /** The wallet cannot cover it: balance, price and what is missing, with the top-up and «بازگشت». */
    private function short(Context $ctx, Shortfall $shortfall, string $checkout): void
    {
        $ctx->edit($this->texts->render(BotText::PayInsufficient, [
            'balance' => Messages::balance($shortfall->balance),
            'amount' => Money::format($shortfall->price),
            'missing' => Money::format($shortfall->missing),
        ]), ['reply_markup' => InlineKeyboard::make()->row($this->buttons->topUp())->row($this->buttons->back($checkout))->build()]);
    }
}
