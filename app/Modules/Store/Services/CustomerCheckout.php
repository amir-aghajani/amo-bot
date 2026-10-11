<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Security\RateLimiter;
use App\Modules\Catalog\Exceptions\NoServerAvailableException;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Services\ServerSelector;
use App\Modules\Discounts\DTO\DiscountLine;
use App\Modules\Discounts\Exceptions\DiscountCodeRefusedException;
use App\Modules\Discounts\Services\DiscountCodes;
use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\DTO\Quote;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\RenewalUnderWayException;
use App\Modules\Orders\Exceptions\RequestKeyReusedException;
use App\Modules\Orders\Exceptions\TooManyUnpaidOrdersException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\Pricing;
use App\Modules\Payments\DTO\CheckoutResult;
use App\Modules\Payments\Enums\CheckoutOutcome;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\Checkout;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Exceptions\CheckoutRefusedException;
use App\Modules\Store\Presenters\OrderPresenter;
use App\Modules\Subscriptions\DTO\ExtraOffer;
use App\Modules\Subscriptions\DTO\RenewalPreview;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\CustomerExtras;
use App\Modules\Subscriptions\Services\CustomerRenewal;
use App\Modules\Telegram\BotSettings;
use App\Modules\Users\Models\User;
use App\Support\Input;
use App\Support\Money;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The website's checkout. It goes through the shop's one checkout (Payments\Services\Checkout), which the bot uses too,
 * under the bot's rules: a plan on sale on a server that sells it, a renewal the customer may make (CustomerRenewal),
 * extras the service may take (CustomerExtras), a top-up within the wallet's bounds, a way to pay that may pay that kind
 * of order.
 *
 * Idempotency: every ordering request carries a key (the Idempotency-Key header). A request made again — the same key
 * and way to pay — is answered by the order that key came to, as it stands now, before any rule is asked again
 * (Checkout::replayed()), since the rules may say otherwise a moment later; a key reused for something else is refused.
 *
 * Limits: a customer sends CHECKOUTS ordering requests per CHECKOUT_WINDOW and keeps OrderService::UNPAID_MAX unpaid
 * orders at most. While the bot's master switch is off the website takes no orders either (takingOrders()).
 */
final class CustomerCheckout
{
    public const NOT_ON_SALE = 'این پلن الان فروخته نمی‌شود.';
    public const NOT_RENEWABLE = 'تمدید این سرویس در حال حاضر ممکن نیست؛ با پشتیبانی در تماس باشید.';

    /** An extra's amount that is no whole number (%s: its unit, «گیگابایت» or «روز»). */
    public const NOT_A_QUANTITY = 'تعداد %s را با عدد و بدون اعشار بنویسید.';

    /** Ordering requests a customer sends per CHECKOUT_WINDOW seconds, repeats included. */
    public const CHECKOUTS = 30;
    public const CHECKOUT_WINDOW = 600;

    /** What GET /payment-methods takes as `for` (an agent's traffic is bought in the bot only). */
    private const KINDS = [OrderType::Purchase, OrderType::Renewal, OrderType::WalletTopUp, OrderType::ExtraTraffic, OrderType::ExtraTime];

    private const NO_KIND = 'نوع سفارش (for) باید purchase، renewal، wallet_topup، extra_traffic یا extra_time باشد.';
    private const NO_DISCOUNT_KIND = 'نوع سفارش (for) باید purchase، renewal، extra_traffic یا extra_time باشد.';
    private const NO_CODE = 'کد تخفیف را بنویسید.';
    private const NO_SERVER = 'سرور را انتخاب کنید.';
    private const NO_METHOD = 'روش پرداخت را انتخاب کنید.';
    private const NOT_PAYABLE = 'این روش پرداخت برای این سفارش در دسترس نیست.';
    private const WALLET_TOP_UP = 'کیف پول با خودش شارژ نمی‌شود؛ روش دیگری انتخاب کنید.';

    public function __construct(
        private readonly Checkout $checkout,
        private readonly OrderService $orders,
        private readonly Pricing $pricing,
        private readonly PaymentMethods $methods,
        private readonly ServerSelector $selector,
        private readonly CustomerRenewal $renewals,
        private readonly CustomerExtras $extras,
        private readonly CustomerOrders $customerOrders,
        private readonly CustomerSubscriptions $subscriptions,
        private readonly OrderPresenter $presenter,
        private readonly RateLimiter $limiter,
        private readonly BotSettings $bot,
        private readonly DiscountCodes $codes,
    ) {}

    /**
     * Refuse while the bot's master switch is off: the website then takes no orders, renewals, extras, top-ups or
     * receipts, as the bot refuses every customer.
     *
     * @throws CheckoutRefusedException 503
     */
    public function takingOrders(): void
    {
        if (!$this->bot->enabled()) {
            throw CheckoutRefusedException::paused();
        }
    }

    /**
     * The ways to pay a kind of order (`for`), in checkout order: the bot's (PaymentMethods::forOrder()) — every enabled
     * method, but never the wallet for a top-up.
     *
     * @param array<string, mixed> $query
     * @return list<array{id: int, label: string, kind: string|null}>
     * @throws ValidationException 422 on `for`
     */
    public function methods(array $query): array
    {
        $kind = OrderType::tryFrom(is_string($query['for'] ?? null) ? $query['for'] : '');
        if ($kind === null || !in_array($kind, self::KINDS, true)) {
            throw ValidationException::on('for', self::NO_KIND);
        }

        return array_map($this->presenter->method(...), $this->methods->forOrder($kind));
    }

    /**
     * Buy `plan_id` on `server_id`, paid with `method_id`: the plan on sale now (onSale()), a server it sells on today
     * (ServerSelector::resolve()), at the customer's price (Pricing: a first-purchase or agent price when theirs).
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `plan_id`, `server_id` or `method_id`
     * @throws NoServerAvailableException 422 on `server_id`: another server of the plan sells, not this one
     * @throws RequestKeyReusedException 422 on `idempotency_key`
     * @throws TooManyUnpaidOrdersException 422
     * @throws TooManyAttemptsException 429
     * @throws CheckoutRefusedException 503: the shop takes no orders while its bot is switched off
     */
    public function purchase(User $customer, array $input, string $key): CheckoutResult
    {
        $this->ordering($customer);
        $plan = Plan::query()->find(Input::integerOf($input['plan_id'] ?? null) ?? 0);
        $serverId = Input::integerOf($input['server_id'] ?? null);
        $server = $serverId === null ? null : Server::query()->find($serverId);
        $replayed = $plan === null || $server === null ? null : $this->replayed($customer, $key, $input, fn(?OrderKey $key): Order => $this->orders->openPurchase($customer, $plan, $server, $key));
        if ($replayed !== null) {
            return $replayed;
        }

        $plan = $this->onSale($plan);
        if ($serverId === null) {
            throw ValidationException::on('server_id', self::NO_SERVER);
        }
        $server = $this->selector->resolve($plan, $serverId)['server'];
        $method = $this->method($input, OrderType::Purchase);
        $quote = $this->quote($customer, $key, $input, fn(?string $code): Quote => $this->pricing->purchase($customer, $plan, $server, $code));

        return $this->checkout->pay($customer, $method, $quote->amount(), fn(?OrderKey $key): Order => $this->orders->openPurchase($customer, $plan, $server, $key, $quote), $key);
    }

    /**
     * The renewal of the customer's service before it is paid (CustomerRenewal::preview()): its plan, its price and the
     * service as the renewal would leave it — while it may be renewed and no renewal of it is under way.
     *
     * @throws ValidationException 422 on `status`: it may not be renewed now
     * @throws RenewalUnderWayException 422 on `status`: a receipt with support, or paid and being delivered
     */
    public function renewal(Subscription $subscription): RenewalPreview
    {
        $preview = $this->renewals->preview($subscription) ?? throw ValidationException::on('status', self::NOT_RENEWABLE);
        if ($this->orders->renewalUnderWay($subscription)) {
            throw new RenewalUnderWayException($subscription);
        }

        return $preview;
    }

    /**
     * Renew the customer's service as renewal() previews it, paid with `method_id`.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `status` or `method_id`
     * @throws RenewalUnderWayException 422 on `status`
     * @throws RequestKeyReusedException 422 on `idempotency_key`
     * @throws TooManyUnpaidOrdersException 422
     * @throws TooManyAttemptsException 429
     * @throws CheckoutRefusedException 503: the shop takes no orders while its bot is switched off
     */
    public function renew(User $customer, Subscription $subscription, array $input, string $key): CheckoutResult
    {
        $this->ordering($customer);
        $plan = $subscription->plan;
        $replayed = $plan === null ? null : $this->replayed($customer, $key, $input, fn(?OrderKey $key): Order => $this->orders->openRenewal($customer, $subscription, $plan, $key));
        if ($replayed !== null) {
            return $replayed;
        }

        $preview = $this->renewal($subscription);
        $method = $this->method($input, OrderType::Renewal);
        $quote = $this->quote($customer, $key, $input, fn(?string $code): Quote => $code === null ? $preview->quote : $this->pricing->renewal($customer, $subscription, $preview->plan, $code));

        return $this->checkout->pay($customer, $method, $quote->amount(), fn(?OrderKey $key): Order => $this->orders->openRenewal($customer, $subscription, $preview->plan, $key, $quote), $key);
    }

    /**
     * The extra traffic and time the service may buy now, at its plan's prices or the shop's; a kind is null when none
     * may be bought (CustomerExtras::offersFor()).
     *
     * @return array{traffic: ExtraOffer|null, time: ExtraOffer|null}
     */
    public function extras(Subscription $subscription): array
    {
        return $this->extras->offersFor([$subscription])[$subscription->id];
    }

    /**
     * Buy extra traffic or time for the customer's service — the amount under the kind's field (`gb`, `days`, a whole
     * number as typed) — at what it costs now (CustomerExtras::quote()), paid with `method_id`.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `status` while the service cannot take it; on `gb`/`days` for an amount that is no
     *                             whole number or not on sale; on `method_id`
     * @throws RequestKeyReusedException 422 on `idempotency_key`
     * @throws TooManyUnpaidOrdersException 422
     * @throws TooManyAttemptsException 429
     * @throws CheckoutRefusedException 503: the shop takes no orders while its bot is switched off
     */
    public function extra(User $customer, Subscription $subscription, ExtraKind $kind, array $input, string $key): CheckoutResult
    {
        $this->ordering($customer);
        $typed = Input::wholeOf($input[$kind->field()] ?? null);
        $replayed = $typed === null ? null : $this->replayed($customer, $key, $input, fn(?OrderKey $key): Order => $this->keyedExtra($customer, $subscription, $kind, $typed, $key));
        if ($replayed !== null) {
            return $replayed;
        }

        $quantity = self::quantity($subscription, $kind, $input);
        $price = $this->extras->quote($subscription, $kind, $quantity);
        $method = $this->method($input, $kind->orderType());
        $quote = $this->quote($customer, $key, $input, fn(?string $code): Quote => $this->pricing->extra($customer, $subscription, $kind, $price, $code));

        return $this->checkout->pay($customer, $method, $quote->amount(), fn(?OrderKey $key): Order => $this->orders->openExtra($customer, $subscription, $kind, $quantity, $price, $key, $quote), $key);
    }

    /**
     * Charge the customer's wallet by `amount` (read as the bot reads one: Persian digits, separators, «تومان», and the
     * API's own "50000.00"), within the wallet's bounds, paid with any way but the wallet.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `amount` or `method_id`
     * @throws RequestKeyReusedException 422 on `idempotency_key`
     * @throws TooManyUnpaidOrdersException 422
     * @throws TooManyAttemptsException 429
     * @throws CheckoutRefusedException 503: the shop takes no orders while its bot is switched off
     */
    public function topUp(User $customer, array $input, string $key): CheckoutResult
    {
        $this->ordering($customer);
        $amount = Input::amountOf($input['amount'] ?? null);
        $replayed = $amount === null ? null : $this->replayed($customer, $key, $input, fn(?OrderKey $key): Order => $this->orders->openTopUp($customer, $amount, $key));
        if ($replayed !== null) {
            return $replayed;
        }

        if ($amount === null || !$this->orders->topUpAllowed($amount)) {
            throw ValidationException::on('amount', $this->orders->topUpBounds());
        }
        $method = $this->method($input, OrderType::WalletTopUp);

        return $this->checkout->pay($customer, $method, Money::normalize($amount), fn(?OrderKey $key): Order => $this->orders->openTopUp($customer, $amount, $key), $key);
    }

    /**
     * What a checkout came to, as the website is answered: the outcome, the order as it stands (support's notes never),
     * a card transfer's details, and the service once delivered (a purchase's, or the one renewed or given extras). A
     * checkout that paid nothing is refused instead.
     *
     * @return array{outcome: string, order: array<string, mixed>, transfer: array{payment_id: int, amount: string, card: string, holder: string, instructions: string|null}|null, subscription: array<string, mixed>|null}
     * @throws CheckoutRefusedException 422 the wallet short, or its refusal; 409 paid or closed elsewhere a moment ago, or — made again — cancelled since
     */
    public function answer(User $customer, CheckoutResult $result): array
    {
        $order = match ($result->outcome) {
            CheckoutOutcome::Short => throw CheckoutRefusedException::short($result->shortfall()),
            CheckoutOutcome::Closed => throw CheckoutRefusedException::closed(),
            CheckoutOutcome::Cancelled => throw CheckoutRefusedException::cancelled(),
            CheckoutOutcome::Refused => throw CheckoutRefusedException::refused($result->payment()->note),
            default => $this->customerOrders->find($customer, $result->order()->id),
        };
        $delivered = ($order->type === OrderType::Purchase || $order->type->forService()) && $order->fulfilled_at !== null && $order->subscription_id !== null;

        return [
            'outcome' => $result->outcome->value,
            'order' => $this->presenter->present($order),
            'transfer' => $result->outcome !== CheckoutOutcome::Transfer ? null : [
                'payment_id' => $result->payment()->id,
                'amount' => $result->payment()->amount,
                'card' => $result->card()->card,
                'holder' => $result->card()->holder,
                'instructions' => $result->card()->instructions !== '' ? $result->card()->instructions : null,
            ],
            'subscription' => $delivered ? $this->subscriptions->present($this->subscriptions->find($customer, (int) $order->subscription_id)) : null,
        ];
    }

    /**
     * What a discount code takes off what the customer means to buy, nothing ordered: the same quote their order would
     * be made at (base, each discount line, what is left). `for` names what: purchase (`plan_id`, `server_id` once
     * picked), renewal (`subscription_id`), extra_traffic or extra_time (`subscription_id` and `gb` or `days`). Held to the
     * customer's budget of codes that do not hold, so codes cannot be guessed.
     *
     * @param array<string, mixed> $input
     * @return array{base: string, discounts: list<array{source: string, label: string, amount: string, percent: int|null}>, amount: string}
     * @throws ValidationException 422 on `code`, `for`, `plan_id`, `server_id`, `status`, `gb` or `days`
     * @throws NoServerAvailableException 422 on `server_id`
     * @throws RenewalUnderWayException 422 on `status`
     * @throws ModelNotFoundException 404: no service of theirs
     * @throws TooManyAttemptsException 429
     */
    public function checkCode(User $customer, array $input): array
    {
        $code = Input::text($input, 'code');
        if ($code === '') {
            throw ValidationException::on('code', self::NO_CODE);
        }
        $kind = OrderType::tryFrom(Input::text($input, 'for'));
        if ($kind === null || !$kind->forThemselves()) {
            throw ValidationException::on('for', self::NO_DISCOUNT_KIND);
        }

        $price = match ($kind) {
            OrderType::Purchase => $this->purchasePrice($customer, $input),
            OrderType::Renewal => $this->renewalPrice($customer, $input),
            default => $this->extraPrice($customer, $input, ExtraKind::of($kind) ?? throw new \LogicException("{$kind->value} is no extra.")),
        };
        try {
            $quote = $this->codes->tried($customer, static fn(): Quote => $price($code));
        } catch (DiscountCodeRefusedException $e) {
            throw ValidationException::on('code', $e->getMessage());
        }

        return [
            'base' => $quote->base,
            'discounts' => array_map(static fn(DiscountLine $line): array => $line->present(), $quote->lines()),
            'amount' => $quote->amount(),
        ];
    }

    /**
     * The order an extra's key came to, made again: OrderService answers it by the key at the price it was made at. If
     * the key was forgotten that very moment (a week old), a new order at what the amount costs now.
     *
     * @throws ValidationException 422 on `status` or the kind's field: no order for the key, and the amount not sold now
     * @throws RequestKeyReusedException the key came to an order of something else, or with another way to pay
     * @throws TooManyUnpaidOrdersException
     */
    private function keyedExtra(User $customer, Subscription $subscription, ExtraKind $kind, int $quantity, ?OrderKey $key): Order
    {
        $price = ($key === null ? null : $this->orders->keyed($customer, $key->key)?->amount) ?? $this->extras->quote($subscription, $kind, $quantity);

        return $this->orders->openExtra($customer, $subscription, $kind, $quantity, $price, $key);
    }

    /**
     * How checkCode() prices a purchase: the plan on sale, on the server named once picked.
     *
     * @param array<string, mixed> $input
     * @return \Closure(?string): Quote
     */
    private function purchasePrice(User $customer, array $input): \Closure
    {
        $plan = $this->onSale(Plan::query()->find(Input::integerOf($input['plan_id'] ?? null) ?? 0));
        $serverId = Input::integerOf($input['server_id'] ?? null);
        $server = $serverId === null ? null : $this->selector->resolve($plan, $serverId)['server'];

        return fn(?string $code): Quote => $this->pricing->purchase($customer, $plan, $server, $code);
    }

    /**
     * How checkCode() prices a renewal of the customer's service, while they may renew it.
     *
     * @param array<string, mixed> $input
     * @return \Closure(?string): Quote
     */
    private function renewalPrice(User $customer, array $input): \Closure
    {
        $subscription = $this->subscriptions->find($customer, Input::integerOf($input['subscription_id'] ?? null) ?? 0);
        $preview = $this->renewal($subscription);

        return fn(?string $code): Quote => $this->pricing->renewal($customer, $subscription, $preview->plan, $code);
    }

    /**
     * How checkCode() prices extra traffic or time for the customer's service: that amount, as it is sold now.
     *
     * @param array<string, mixed> $input
     * @return \Closure(?string): Quote
     */
    private function extraPrice(User $customer, array $input, ExtraKind $kind): \Closure
    {
        $subscription = $this->subscriptions->find($customer, Input::integerOf($input['subscription_id'] ?? null) ?? 0);
        $base = $this->extras->quote($subscription, $kind, self::quantity($subscription, $kind, $input));

        return fn(?string $code): Quote => $this->pricing->extra($customer, $subscription, $kind, $base, $code);
    }

    /**
     * The plan while it is on sale: switched on, covered by an agent's traffic, and on a server that can deliver it now.
     *
     * @throws ValidationException 422 on `plan_id`
     */
    private function onSale(?Plan $plan): Plan
    {
        if ($plan === null || !$plan->is_active || !$this->selector->covers($plan) || $this->selector->choices($plan) === []) {
            throw ValidationException::on('plan_id', self::NOT_ON_SALE);
        }

        return $plan;
    }

    /**
     * The extra's amount typed under the kind's field. When it is no whole number, the service's own state is said first
     * (what quote() would say), then that the amount is none.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `status` or the kind's field
     */
    private static function quantity(Subscription $subscription, ExtraKind $kind, array $input): int
    {
        $quantity = Input::wholeOf($input[$kind->field()] ?? null);
        if ($quantity !== null) {
            return $quantity;
        }
        $problem = CustomerExtras::problem($subscription, $kind);

        throw $problem !== null ? ValidationException::on('status', $problem) : ValidationException::on($kind->field(), sprintf(self::NOT_A_QUANTITY, $kind->unit()));
    }

    /**
     * What the order costs: `$price` with the request's `discount_code`, held to the customer's budget of codes that do
     * not hold (DiscountCodes::tried()). The code is ignored when the key already came to an order: a repeat is answered
     * by that order, whatever its code says now.
     *
     * @param array<string, mixed> $input
     * @param \Closure(?string): Quote $price
     * @throws DiscountCodeRefusedException 422 on `discount_code`
     * @throws TooManyAttemptsException 429: too many codes that did not hold
     */
    private function quote(User $customer, string $key, array $input, \Closure $price): Quote
    {
        $code = Input::text($input, 'discount_code');
        if ($code === '' || $this->orders->keyed($customer, $key) !== null) {
            return $price(null);
        }

        return $this->codes->tried($customer, static fn(): Quote => $price($code));
    }

    /**
     * The order a repeated request's key came to, answered as it stands (Checkout::replayed()); null for a new request,
     * or one without a `method_id` to key it by.
     *
     * @param array<string, mixed> $input
     * @param \Closure(?OrderKey): Order $open How the request would open its order
     * @throws RequestKeyReusedException 422 on `idempotency_key`
     */
    private function replayed(User $customer, string $key, array $input, \Closure $open): ?CheckoutResult
    {
        $methodId = Input::integerOf($input['method_id'] ?? null);

        return $methodId === null ? null : $this->checkout->replayed($customer, new OrderKey($key, $methodId), $open);
    }

    /**
     * The way to pay `method_id` names, while it may pay that kind of order.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `method_id`
     */
    private function method(array $input, OrderType $type): PaymentMethod
    {
        $id = Input::integerOf($input['method_id'] ?? null) ?? throw ValidationException::on('method_id', self::NO_METHOD);
        $method = $this->methods->payable($id, $type);
        if ($method !== null) {
            return $method;
        }

        $wallet = $type === OrderType::WalletTopUp && PaymentMethod::query()->find($id)?->isWallet() === true;
        throw ValidationException::on('method_id', $wallet ? self::WALLET_TOP_UP : self::NOT_PAYABLE);
    }

    /**
     * Start an ordering request: refused while the shop takes no orders, then counted against the customer's window.
     *
     * @throws CheckoutRefusedException 503
     * @throws TooManyAttemptsException 429
     */
    private function ordering(User $customer): void
    {
        $this->takingOrders();
        $this->limiter->enforce([['checkout|' . $customer->id, self::CHECKOUTS, self::CHECKOUT_WINDOW]], 'درخواست پرداخت زیادی فرستاده‌اید');
    }
}
