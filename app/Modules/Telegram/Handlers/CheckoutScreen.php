<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Bots\Exceptions\SellingSuspendedException;
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
use App\Modules\Payments\DTO\PayLink;
use App\Modules\Payments\DTO\Shortfall;
use App\Modules\Payments\Enums\CheckoutOutcome;
use App\Modules\Payments\Exceptions\MethodUnavailableException;
use App\Modules\Payments\Exceptions\PaymentHeldException;
use App\Modules\Payments\Exceptions\ReceiptInReviewException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\Checkout;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Payments\Services\PaymentSettings;
use App\Modules\Subscriptions\Enums\ClientNameMode;
use App\Modules\Subscriptions\Exceptions\TermTooLongException;
use App\Modules\Subscriptions\Services\ClientNameSettings;
use App\Modules\Telegram\BotSettings;
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
use App\Support\Persian;

/**
 * The bot's checkout screen, shared by every purchase in the bot (a plan, a renewal, extra traffic, time or devices, a
 * wallet top-up, an agent's traffic). show() draws what is being bought with a button per way to pay; buy() runs the
 * shop's one checkout (Payments\Services\Checkout, the website's too) and renders the outcome — through pay() at a
 * discountable checkout, the one pay step of them all. Nothing is ordered until a way to pay is picked, and an unpaid
 * order expires on its own.
 *
 * A checkout that may be discounted lists its discounts under its text and offers «🏷 کد تخفیف دارم»
 * (DiscountCodeHandler). The code typed is kept in the chat's session for that checkout alone (keepCode()) and is
 * judged again when it is paid: one used up meanwhile is dropped and nothing is ordered.
 *
 * A purchase's checkout says what its service will be called and, while the shop lets buyers choose
 * (ClientNameSettings::modes()), offers «🔢 ترتیبی», «🎲 رندوم» and «✏️ دلخواه» (ClientNameHandler). The name drawn or
 * typed is kept for that checkout alone (keepName()), and its order is made with it (requestedName()); «پرداخت دوباره»
 * keeps its order's (keepOrderName()).
 */
final class CheckoutScreen
{
    /**
     * The chat's state while a checkout is on screen, `checkout.{amount}.{checkout}` naming the amount it showed and the
     * checkout it is — its callback data, what it sells (stateFor()): a wallet payment claims it, so a double tap pays
     * once, and only that checkout at that amount (repriced()). A chat at the old `checkout.{amount}` is a checkout
     * redrawn once.
     */
    private const STATE = 'checkout';

    /** The session fact holding the discount code kept for one checkout: `{at: its callback data, code}`. */
    private const CODE = '_discount_code';

    /** The session fact holding the client name kept for one purchase's checkout: `{at: its callback data, mode, name}`. */
    private const NAME = '_client_name';

    /** Each way to name a service, and its button. */
    private const NAME_BUTTONS = [
        ClientNameMode::Sequential->value => BotText::CheckoutNameSequential,
        ClientNameMode::Random->value => BotText::CheckoutNameRandom,
        ClientNameMode::Custom->value => BotText::CheckoutNameCustom,
    ];

    public function __construct(
        private readonly PaymentMethods $methods,
        private readonly Checkout $checkout,
        private readonly ServiceCard $card,
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
        private readonly PaymentSettings $cardSettings,
        private readonly BotSettings $bot,
        private readonly DiscountCodes $codes,
        private readonly ClientNameSettings $names,
    ) {}

    /**
     * The client name kept for the purchase's checkout `$checkout` (its callback data), while the shop still offers the
     * way it was drawn or typed by; null for the shop's pattern.
     */
    public function requestedName(Context $ctx, string $checkout): ?string
    {
        $kept = $this->kept($ctx, self::NAME, $checkout);
        $mode = ClientNameMode::tryFrom((string) ($kept['mode'] ?? ''));

        return $mode !== null && is_string($kept['name'] ?? null) && in_array($mode, $this->names->modes(), true) ? $kept['name'] : null;
    }

    /** Keep the name `$mode` gave (drawn or typed) for the checkout `$checkout`, or (null) drop the kept name. */
    public function keepName(Context $ctx, string $checkout, ?ClientNameMode $mode, ?string $name = null): void
    {
        $this->keep($ctx, self::NAME, $checkout, $mode !== null && $name !== null ? ['mode' => $mode->value, 'name' => $name] : null);
    }

    /**
     * Keep the name an unpaid purchase asked for (`$name`, null for the shop's pattern) for its checkout `$checkout` —
     * «پرداخت دوباره» draws it so, and paying finds that very order. The name is kept as one typed while the shop takes
     * typed names, else as one drawn while it draws them; once it names by its pattern alone, by the pattern, as any
     * checkout drawn since.
     */
    public function keepOrderName(Context $ctx, string $checkout, ?string $name): void
    {
        $modes = $this->names->modes();
        $mode = match (true) {
            in_array(ClientNameMode::Custom, $modes, true) => ClientNameMode::Custom,
            in_array(ClientNameMode::Random, $modes, true) => ClientNameMode::Random,
            default => null,
        };
        $this->keepName($ctx, $checkout, $mode, $name);
    }

    /** Keep `$code` for the checkout `$checkout`, or (null) drop the kept code — any other checkout's goes too. */
    public function keepCode(Context $ctx, string $checkout, ?string $code): void
    {
        $this->keep($ctx, self::CODE, $checkout, $code === null ? null : ['code' => $code]);
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
     * Draw the checkout `$checkout` (its callback data: what it sells, the one buy() is told) in place of the tapped
     * message: `$text`, a button per way to pay `$type` at `$amount` (`$pay` makes each callback;
     * PaymentMethods::forOrder() — a method whose bounds or cards cannot take the amount is left out, but not one the
     * customer's own open payment of it waits on, and when none is left the customer is told so), the row `$instead`
     * (other things to buy) and «بازگشت» to `$back`. Something `$free` gets one «🎁 دریافت رایگان» button instead,
     * through the first way to pay: the checkout settles a free order whichever is picked. With a `$quote`,
     * «🏷 کد تخفیف دارم» — or «❌ حذف کد تخفیف» while a code is applied — is offered while the shop has codes and
     * something is left to pay. A purchase's checkout says its service's name under the text, with a button per way the
     * shop lets buyers name it (nameRow()). The row `$choices` (a renewal's methods, each redrawing the checkout) comes
     * above `$instead`. Both rows read in order, the first on the right. Showing it leaves whatever flow the chat was in.
     *
     * @param \Closure(PaymentMethod): string $pay
     * @param list<array<string, mixed>> $instead
     * @param list<array<string, mixed>> $choices
     */
    public function show(Context $ctx, string $text, OrderType $type, string $amount, \Closure $pay, string $back, string $checkout, array $instead = [], bool $free = false, ?Quote $quote = null, array $choices = []): void
    {
        $ctx->session->enter(self::stateFor($checkout, $amount));

        $methods = $this->methods->forOrder($type, $amount, $ctx->user);
        $keyboard = InlineKeyboard::make();
        if ($free && $methods !== []) {
            $keyboard->row(InlineKeyboard::callback($this->texts->get(BotText::CheckoutFree), $pay($methods[0])));
        } else {
            foreach ($methods as $method) {
                $keyboard->row(InlineKeyboard::callback($method->label, $pay($method)));
            }
        }
        if ($quote !== null && $methods !== [] && ($quote->code !== null || (Money::isPositive($quote->afterAutomatic()) && $this->codes->offered()))) {
            $keyboard->row($quote->code !== null
                ? InlineKeyboard::callback($this->texts->get(BotText::DiscountCodeRemove), DiscountCodeHandler::removeCallback($checkout))
                : InlineKeyboard::callback($this->texts->get(BotText::DiscountCodeButton), DiscountCodeHandler::askCallback($checkout)));
        }
        if ($type === OrderType::Purchase && $methods !== []) {
            $named = $this->nameRow($ctx, $checkout);
            if ($named !== null) {
                [$line, $buttons] = $named;
                $text = BotTexts::paragraphs($text, $line);
                $keyboard->row(...array_reverse($buttons));
            }
        }
        $keyboard->row(...$methods === [] ? [] : array_reverse($choices))->row(...$instead)->row($this->buttons->back($back));

        if ($methods === []) {
            // None for this amount, while some take another: that is what the customer is told.
            $text = BotTexts::paragraphs($text, $this->texts->get($this->methods->forOrder($type, null) !== [] ? BotText::CheckoutNoMethodForAmount : BotText::CheckoutNoGateway));
        }
        $ctx->edit($text, ['reply_markup' => $keyboard->build()]);
    }

    /**
     * A way to pay picked at a discountable checkout (`$checkout`, its callback data): the way `$methodId` while it still
     * pays `$type` — else the checkout drawn again by `$redraw`, with the ways that are left —, the price as it is paid
     * with the code kept for it (priceToPay(); for `$codeFor` when kept for another, a renewal's whatever its method),
     * and what `$open` orders at it paid (buy()) — a way that no longer takes that price (its bounds, a card with room)
     * drawn again too.
     *
     * @param \Closure(?string): Quote $price
     * @param \Closure(?OrderKey, Quote): Order $open
     * @param \Closure(): void $redraw
     */
    public function pay(Context $ctx, int $methodId, OrderType $type, string $checkout, \Closure $price, \Closure $open, \Closure $redraw, ?string $codeFor = null): void
    {
        $method = $this->methods->payable($methodId, $type, null, $ctx->user);
        if ($method === null) {
            // Switched off since the checkout was drawn.
            $redraw();

            return;
        }
        $quote = $this->priceToPay($ctx, $codeFor ?? $checkout, $checkout, $price);
        if ($quote === null) {
            return;
        }
        $this->buy($ctx, $method, $type, $quote->amount(), static fn(?OrderKey $key): Order => $open($key, $quote), $checkout, $redraw);
    }

    /**
     * Pay `$price` with `$method` for the `$type` order `$open` makes (or finds open), and show what came of it in place
     * of the checkout. `$checkout` is the checkout's callback data (as show() drew it), where «بازگشت» leads from here.
     * A way that does not take the price now (its bounds, or its cards' room for today: Checkout::pay()) orders nothing:
     * the checkout is drawn again by `$redraw`, with the ways that are left. A purchase whose term would end its service
     * past the last day the shop keeps orders nothing either (TermTooLongException): the refusal in its place.
     *
     * The wallet (and a free order) claims the checkout state first, so a second tap — or a tap on a checkout the chat
     * left — charges nothing; and it pays only the checkout on screen, at the amount it showed: one priced otherwise since
     * (a sale over, a first purchase paid elsewhere) — or an older checkout's message tapped, whatever it costs now — is
     * drawn again by `$redraw` first, as nothing the customer did not see is taken from their wallet — and so is
     * «🎁 دریافت رایگان» no longer free, whichever way to pay it went through. A
     * card needs no such guard: the same card finds the same payment, and its amount is shown before anything is paid.
     * Nor does an online gateway: a tap finds the same page while it takes money a few minutes yet
     * (PaymentService::LINK_SPARE_MINUTES), else a new one, and two taps at once both show the one page the shop keeps
     * (OnlineInvoices::open()). An order whose online payment is held for support is paid no other way meanwhile: the
     * customer is sent to support (held()).
     *
     * @param \Closure(?OrderKey): Order $open
     * @param \Closure(): void $redraw
     */
    public function buy(Context $ctx, PaymentMethod $method, OrderType $type, string $price, \Closure $open, string $checkout, \Closure $redraw): void
    {
        $instant = $method->isWallet() || !Money::isPositive($price);
        if (($instant || $this->shownFree($ctx, $checkout)) && $this->repriced($ctx, $checkout, $price)) {
            $redraw();

            return;
        }
        $claim = $instant ? static fn(): bool => $ctx->session->claim(self::stateFor($checkout, $price)) : null;
        try {
            $result = $this->checkout->pay($ctx->user, $method, $type, $price, $open, guard: $claim);
        } catch (DiscountCodeRefusedException $e) {
            $this->codeLost($ctx, $checkout, $e->getMessage());

            return;
        } catch (ReceiptInReviewException $e) {
            // One receipt at a time: nothing ordered while their last is with support.
            $ctx->edit($this->texts->render(BotText::ReceiptInReview, ['order' => $e->inReview->order_id]), ['reply_markup' => $this->buttons->backOnly($checkout)]);

            return;
        } catch (PaymentHeldException) {
            $this->held($ctx, $checkout);

            return;
        } catch (MethodUnavailableException) {
            // Its bounds, or its cards' room for today, changed since the checkout was drawn: nothing paid.
            $redraw();

            return;
        } catch (SellingSuspendedException) {
            // The main shop suspended this agent's selling since the checkout was drawn: nothing ordered.
            $this->paused($ctx, $checkout);

            return;
        } catch (TermTooLongException $e) {
            // Its term would end the service past the last day the shop keeps: nothing ordered.
            $ctx->edit($e->getMessage(), ['reply_markup' => $this->buttons->backOnly($checkout)]);

            return;
        }
        // Paid: the code and the name served their checkout.
        if (in_array($result->outcome, [CheckoutOutcome::Settled, CheckoutOutcome::Processing], true)) {
            $this->keepCode($ctx, $checkout, null);
            $this->keepName($ctx, $checkout, null);
        }

        match ($result->outcome) {
            CheckoutOutcome::Short => $this->short($ctx, $result->shortfall(), $checkout),
            // Closed by another tap or another payment first: nothing was charged. (Cancelled only comes from a website's
            // request made again with its key; the bot sends none.)
            CheckoutOutcome::Closed, CheckoutOutcome::Cancelled => $ctx->edit($this->texts->get(BotText::OrderNotPending), ['reply_markup' => $this->buttons->backOnly($checkout)]),
            CheckoutOutcome::Transfer => $this->transfer($ctx, $result->payment(), $result->card(), $checkout),
            CheckoutOutcome::Link => $this->link($ctx, $result->payment(), $result->payLink(), $checkout),
            // Settled by the wallet: the outcome arrives as a fresh message, like the notification it is.
            CheckoutOutcome::Settled => $result->order()->status === OrderStatus::Fulfilled
                ? $this->delivered($ctx, $result->order())
                : $ctx->replace($this->card->failedText($result->order())),
            CheckoutOutcome::Refused => $this->failed($ctx, $result->payment()->note, $checkout),
            CheckoutOutcome::Processing => $this->paid($ctx, $result->order()),
        };
    }

    /**
     * An agent's shop whose selling is suspended (Bot::sells()) sells nothing at this step: SellingPaused — a message's
     * text, the agent's HTML in it, never a popup's — in place of the screen (a message of its own for something typed),
     * with «بازگشت» to `$back`; the step is over.
     */
    public function paused(Context $ctx, string $back): void
    {
        $ctx->session->clear();
        $ctx->edit($this->texts->get(BotText::SellingPaused), ['reply_markup' => $this->buttons->backOnly($back)]);
    }

    /**
     * A purchase's name under its checkout — the one kept for it (requestedName()), else «ترتیبی» — and a button per way
     * the shop lets buyers name a service; null while the shop's pattern is the only one.
     *
     * @return array{Html, list<array<string, mixed>>}|null
     */
    private function nameRow(Context $ctx, string $checkout): ?array
    {
        $modes = $this->names->modes();
        if (count($modes) < 2) {
            return null;
        }
        $name = $this->requestedName($ctx, $checkout);
        $line = $name === null ? $this->texts->part(BotText::CheckoutNamePattern) : $this->texts->part(BotText::CheckoutName, ['client' => $name]);

        return [$line, array_map(fn(ClientNameMode $mode): array => InlineKeyboard::callback(
            $this->texts->get(self::NAME_BUTTONS[$mode->value]),
            ClientNameHandler::modeCallback($mode, $checkout),
        ), $modes)];
    }

    /**
     * Whether the checkout on screen (the chat's state) is another than `$checkout` at `$price`: another amount, or
     * another checkout — an older one's message tapped. A chat that left it is no repricing: its claim fails, and nothing
     * is charged.
     */
    private function repriced(Context $ctx, string $checkout, string $price): bool
    {
        return $ctx->session->inState(self::STATE) && $ctx->session->state() !== self::stateFor($checkout, $price);
    }

    /** Whether the checkout on screen is `$checkout` showing «🎁 دریافت رایگان»: nothing to pay (show()'s `$free`). */
    private function shownFree(Context $ctx, string $checkout): bool
    {
        return $ctx->session->state() === self::stateFor($checkout, '0');
    }

    /**
     * The state of the checkout `$checkout` on screen showing `$amount`: within the session's 128 characters, the amount
     * at most fifteen and the callback data 64 bytes (CallbackData::MAX_BYTES).
     */
    private static function stateFor(string $checkout, string $amount): string
    {
        return self::STATE . '.' . Money::normalize($amount) . '.' . $checkout;
    }

    /** The discount code kept for the checkout `$checkout` (its callback data); null for none. */
    private function code(Context $ctx, string $checkout): ?string
    {
        $code = $this->kept($ctx, self::CODE, $checkout)['code'] ?? null;

        return is_string($code) ? $code : null;
    }

    /**
     * What the checkout `$checkout` costs as it is paid, with the code kept for `$codeFor` (the checkout itself, or a
     * renewal's whatever its method); null after telling the customer the code no longer holds (codeLost(), «بازگشت» to
     * `$checkout` as it was drawn) — nothing is ordered then.
     *
     * @param \Closure(?string): Quote $price
     */
    private function priceToPay(Context $ctx, string $codeFor, string $checkout, \Closure $price): ?Quote
    {
        try {
            return $price($this->code($ctx, $codeFor));
        } catch (DiscountCodeRefusedException $e) {
            $this->codeLost($ctx, $checkout, $e->getMessage());

            return null;
        }
    }

    /** The kept discount code no longer holds as the checkout is paid: dropped, why said, «بازگشت» to the checkout without it. */
    private function codeLost(Context $ctx, string $checkout, string $reason): void
    {
        $this->keepCode($ctx, $checkout, null);
        $ctx->edit($this->texts->render(BotText::DiscountCodeLost, ['reason' => $reason]), ['reply_markup' => $this->buttons->backOnly($checkout)]);
    }

    /**
     * The session fact `$fact` (`{at: a checkout's callback data, …}`) while it is kept for the checkout `$checkout`; null
     * for none, or another checkout's.
     *
     * @return array<string, mixed>|null
     */
    private function kept(Context $ctx, string $fact, string $checkout): ?array
    {
        $kept = $ctx->session->get($fact);

        return is_array($kept) && ($kept['at'] ?? null) === $checkout ? $kept : null;
    }

    /**
     * Keep `$values` as the session fact `$fact` for the checkout `$checkout` alone, or (null) drop it — any other
     * checkout's goes too.
     *
     * @param array<string, string>|null $values
     */
    private function keep(Context $ctx, string $fact, string $checkout, ?array $values): void
    {
        if ($values !== null) {
            $ctx->session->put($fact, ['at' => $checkout] + $values);
        } elseif ($ctx->session->get($fact) !== null) {
            $ctx->session->put($fact, null);
        }
    }

    /**
     * The payment was refused — the wallet's, or an online gateway's that would not open it —, its note the reason: the
     * customer's words (an online gateway's own reason is the owner's, in the log). The order stays open.
     */
    private function failed(Context $ctx, ?string $reason, string $checkout): void
    {
        $ctx->replace($this->texts->render(BotText::PayFailed, [
            'reason' => $reason ?? $this->texts->part(BotText::ServiceUnknown),
        ]), ['reply_markup' => $this->buttons->backOnly($checkout)]);
    }

    /**
     * The order's online payment is held for support (Payments\Exceptions\PaymentHeldException): nothing is paid, and the
     * customer is told not to pay again but to write to support (PayOnlineHeld, as «بررسی پرداخت» tells them), with the
     * button to support's chat and «بازگشت».
     */
    private function held(Context $ctx, string $checkout): void
    {
        $ctx->edit($this->texts->message(BotText::PayOnlineHeld), [
            'reply_markup' => InlineKeyboard::make()->row(...$this->buttons->supportChat())->row($this->buttons->back($checkout))->build(),
        ]);
    }

    /** Paid, but another process is delivering the order right now: say so, with what it brings once delivered. */
    private function paid(Context $ctx, Order $order): void
    {
        $ctx->replace($this->texts->render(BotText::PayProcessing, [
            'order' => $order->id,
            'outcome' => $this->texts->part(ReceiptHandler::outcome($order->type)),
        ]));
    }

    /**
     * The card to transfer to — its number, holder and bank (when the owner named it) —, with buttons that copy its number
     * and the amount (digits a banking app takes); the chat now waits for the receipt (ReceiptHandler). It is protected
     * while the shop protects cards (showCard()). A payment shown no card, taken in support's private chat
     * (PaymentCards::toSupport()), gets the way there instead (toSupport()).
     */
    private function transfer(Context $ctx, Payment $payment, CardTransfer $transfer, string $checkout): void
    {
        $number = $transfer->card;
        if ($number === null) {
            $this->toSupport($ctx, $payment, $transfer->instructions, $checkout);

            return;
        }

        $ctx->session->enter(ReceiptHandler::stateFor($payment));
        $digits = Money::digits($payment->amount);
        $instructions = trim($this->texts->render(BotText::CardInstructions, [
            'amount' => Money::format($payment->amount),
            'amount_digits' => $digits,
            'card' => $number,
            'holder' => (string) $transfer->holder,
            'bank' => $this->bank($transfer),
            'instructions' => $transfer->instructions,
        ]));
        $keyboard = InlineKeyboard::make()->row(...$this->copyRow($digits, $number))->row($this->buttons->back($checkout));

        $this->showCard($ctx, BotTexts::paragraphs($instructions, $this->texts->part(BotText::PayInstructionsSuffix)), $keyboard);
    }

    /**
     * An online gateway's page to pay on (PayOnline): the order's number, the exact sum the page asks (a gateway may add a
     * few Toman to the order's, and its fee when the customer pays it), until when it takes money and what comes once
     * paid, with «💳 پرداخت» to the page, «🔄 بررسی پرداخت» (OnlinePaymentHandler) and «بازگشت» to the checkout. Nothing
     * is awaited in the chat: the gateway's word settles it, and the notice of it comes as any settled payment's. A
     * gateway that gave a card to show in the bot gets it under the page (PayOnlineCard: its holder and bank when the
     * gateway named them) with the copy buttons, protected as a card transfer is (showCard()).
     */
    private function link(Context $ctx, Payment $payment, PayLink $link, string $checkout): void
    {
        $ctx->session->clear();
        $text = $this->texts->render(BotText::PayOnline, [
            'order' => $payment->order_id,
            'amount' => Money::format($link->amount),
            'gateway_fee' => $link->fee === null ? '' : $this->texts->part(BotText::PayOnlineFee, ['fee' => Money::format($link->fee)]),
            'deadline' => $link->expiresAt === null ? '' : $this->texts->part(BotText::PayOnlineDeadline, ['until' => Persian::date($link->expiresAt, withTime: true)]),
            'outcome' => $this->texts->part(ReceiptHandler::outcome($payment->order->type)),
        ]);
        $keyboard = InlineKeyboard::make()->row(InlineKeyboard::url($this->texts->get(BotText::PayOnlineButton), $link->url));
        // A card only with its number.
        $transfer = (string) $link->card?->card === '' ? null : $link->card;
        if ($transfer !== null) {
            $card = (string) $transfer->card;
            $digits = Money::digits($link->amount);
            $holder = trim((string) $transfer->holder);
            $text = BotTexts::paragraphs($text, $this->texts->part(BotText::PayOnlineCard, [
                'card' => $card,
                'card_holder' => $holder === '' ? '' : $this->texts->part(BotText::PayOnlineCardHolder, ['holder' => $holder]),
                'bank' => $this->bank($transfer),
                'amount_digits' => $digits,
            ]));
            $keyboard->row(...$this->copyRow($digits, $card));
        }
        $keyboard->row(InlineKeyboard::callback($this->texts->get(BotText::PayOnlineCheck), OnlinePaymentHandler::checkCallback($payment)))
            ->row($this->buttons->back($checkout));

        if ($transfer === null) {
            $ctx->edit($text, ['reply_markup' => $keyboard->build()]);
        } else {
            $this->showCard($ctx, $text, $keyboard);
        }
    }

    /** The card's bank line (CardBank) when its owner or its gateway named one; empty otherwise. */
    private function bank(CardTransfer $transfer): Html|string
    {
        $bank = trim((string) $transfer->bank);

        return $bank === '' ? '' : $this->texts->part(BotText::CardBank, ['bank_name' => $bank]);
    }

    /**
     * The buttons copying a card's number and the sum to transfer to it (digits a banking app takes) — in reading order,
     * the number first, on the right.
     *
     * @return list<array<string, mixed>>
     */
    private function copyRow(string $digits, string $card): array
    {
        return [InlineKeyboard::copy($this->texts->get(BotText::CardCopyAmount), $digits), InlineKeyboard::copy($this->texts->get(BotText::CardCopyNumber), $card)];
    }

    /**
     * A message showing a card, in place of the checkout. While the shop protects cards (PaymentSettings::protectsCard())
     * it comes as a message of its own, which cannot be forwarded or saved — Telegram protects a message only as it is
     * sent (Context::resend()) —; else it is edited in.
     */
    private function showCard(Context $ctx, string $text, InlineKeyboard $keyboard): void
    {
        if ($this->cardSettings->protectsCard()) {
            $ctx->resend($text, ['reply_markup' => $keyboard->build(), 'protect_content' => true]);
        } else {
            $ctx->edit($text, ['reply_markup' => $keyboard->build()]);
        }
    }

    /**
     * A card payment taken in support's private chat (PaymentCards::toSupport()): no card, but the order's number and the
     * amount, the way to support, what comes once they approve it and the method's note (as the website's answer gives
     * it), with a button to their chat (Buttons::supportChat()). No receipt is awaited here: the payment stays pending
     * for support to approve in the panels.
     */
    private function toSupport(Context $ctx, Payment $payment, string $instructions, string $checkout): void
    {
        $ctx->session->clear();
        $order = $payment->order;

        $ctx->edit(trim($this->texts->render(BotText::CardToSupport, [
            'order' => $order->id,
            'amount' => Money::format($payment->amount),
            'contact' => $this->bot->supportContact(),
            'outcome' => $this->texts->part(ReceiptHandler::outcome($order->type)),
            'instructions' => $instructions,
        ])), ['reply_markup' => InlineKeyboard::make()->row(...$this->buttons->supportChat())->row($this->buttons->back($checkout))->build()]);
    }

    /** What the paid order delivered, as a fresh message, with the way on that fits what it was. */
    private function delivered(Context $ctx, Order $order): void
    {
        $ctx->delete();
        $this->card->sendSettled($ctx->chatId(), $order, match ($order->type) {
            OrderType::Traffic => ['reply_markup' => $this->buttons->backOnly(MainMenu::AGENCY)],
            OrderType::WalletTopUp => ['reply_markup' => $this->buttons->backOnly(MainMenu::WALLET)],
            OrderType::Renewal, OrderType::ExtraTraffic, OrderType::ExtraTime, OrderType::ExtraDevices => ['reply_markup' => $this->buttons->serviceOnly((int) $order->subscription_id)],
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
