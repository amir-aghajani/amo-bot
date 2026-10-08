<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Security\RateLimiter;
use App\Modules\Catalog\Exceptions\NoServerAvailableException;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Services\ServerSelector;
use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\RenewalUnderWayException;
use App\Modules\Orders\Exceptions\RequestKeyReusedException;
use App\Modules\Orders\Exceptions\TooManyUnpaidOrdersException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\DTO\CheckoutResult;
use App\Modules\Payments\Enums\CheckoutOutcome;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\Checkout;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Exceptions\CheckoutRefusedException;
use App\Modules\Store\Presenters\OrderPresenter;
use App\Modules\Subscriptions\DTO\RenewalPreview;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\CustomerRenewal;
use App\Modules\Telegram\BotSettings;
use App\Modules\Users\Models\User;
use App\Support\Input;
use App\Support\Money;

/**
 * The checkout on the shop's website — the shop's one (Payments\Services\Checkout), which the bot goes through too, under
 * the bot's own rules: a plan as the bot sells it now on a server it is sold on, a service its customer may renew on
 * its plan (CustomerRenewal), a top-up within the bot's bounds (OrderService::topUpAllowed()), a way to pay that may pay
 * that kind of order (PaymentMethods::payable()) — and what came of it, as the website is answered (answer()).
 *
 * Every request that orders comes with its key (the Idempotency-Key): made again — the same way to pay named
 * (`method_id`) —, it is answered by the order that key came to, as it stands (Checkout::replayed()) — before the rules
 * are asked again, which may say otherwise of a request made a moment ago; a key that came to an order of something
 * else, or was first sent with another way to pay, is refused. A customer sends CHECKOUTS of them in CHECKOUT_WINDOW at
 * most (a 429 with the wait), and is left OrderService::UNPAID_MAX unpaid orders open at most. While the bot is switched
 * off (its master switch, BotSettings::enabled()) the shop takes no orders on its website either — none of these, nor a
 * receipt (takingOrders()) —; reading what it sells, and signing in, stay open.
 */
final class CustomerCheckout
{
    public const NOT_ON_SALE = 'این پلن الان فروخته نمی‌شود.';
    public const NOT_RENEWABLE = 'تمدید این سرویس در حال حاضر ممکن نیست؛ با پشتیبانی در تماس باشید.';

    /** Ordering requests — purchases, renewals, top-ups, a request made again among them — a customer sends in CHECKOUT_WINDOW seconds. */
    public const CHECKOUTS = 30;
    public const CHECKOUT_WINDOW = 600;

    /** The kinds of order a website's checkout makes — what GET /payment-methods takes as `for` (an agent's traffic is the bot's). */
    private const KINDS = [OrderType::Purchase, OrderType::Renewal, OrderType::WalletTopUp];

    private const NO_KIND = 'نوع سفارش (for) باید purchase، renewal یا wallet_topup باشد.';
    private const NO_SERVER = 'سرور را انتخاب کنید.';
    private const NO_METHOD = 'روش پرداخت را انتخاب کنید.';
    private const NOT_PAYABLE = 'این روش پرداخت برای این سفارش در دسترس نیست.';
    private const WALLET_TOP_UP = 'کیف پول با خودش شارژ نمی‌شود؛ روش دیگری انتخاب کنید.';

    public function __construct(
        private readonly Checkout $checkout,
        private readonly OrderService $orders,
        private readonly PaymentMethods $methods,
        private readonly ServerSelector $selector,
        private readonly CustomerRenewal $renewals,
        private readonly CustomerOrders $customerOrders,
        private readonly CustomerSubscriptions $subscriptions,
        private readonly OrderPresenter $presenter,
        private readonly RateLimiter $limiter,
        private readonly BotSettings $bot,
    ) {}

    /**
     * The shop takes orders on its website while its bot does — the bot's master switch on —: off, a purchase, a renewal,
     * a top-up and a receipt are refused as the bot refuses every customer.
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
     * The ways to pay a kind of order (`for`: purchase, renewal or wallet_topup), in checkout order — what the bot's
     * checkout offers it (PaymentMethods::forOrder()): every one switched on, but the wallet for a top-up.
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
     * A purchase — `plan_id` on `server_id`, paid with `method_id` — as the bot sells it: the plan on sale now (switched
     * on, on a server that can deliver it — else no location is left to pick —, and in an agent's shop covered by their
     * traffic), on a server it is sold on today (ServerSelector::resolve()).
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
        $this->takingOrders();
        $this->throttle($customer);
        $plan = Plan::query()->find(Input::integerOf($input['plan_id'] ?? null) ?? 0);
        $serverId = Input::integerOf($input['server_id'] ?? null);
        $server = $serverId === null ? null : Server::query()->find($serverId);
        $methodId = Input::integerOf($input['method_id'] ?? null);
        if ($plan !== null && $server !== null && $methodId !== null) {
            $replayed = $this->checkout->replayed($customer, new OrderKey($key, $methodId), fn(?OrderKey $key): Order => $this->orders->openPurchase($customer, $plan, $server, $key));
            if ($replayed !== null) {
                return $replayed;
            }
        }

        // Not on sale: switched off, short of an agent's traffic, or on no server that can deliver it now.
        if ($plan === null || !$plan->is_active || !$this->selector->covers($plan) || $this->selector->choices($plan) === []) {
            throw ValidationException::on('plan_id', self::NOT_ON_SALE);
        }
        if ($serverId === null) {
            throw ValidationException::on('server_id', self::NO_SERVER);
        }
        $server = $this->selector->resolve($plan, $serverId)['server'];
        $method = $this->method($input, OrderType::Purchase);

        return $this->checkout->pay($customer, $method, Money::normalize($plan->price), fn(?OrderKey $key): Order => $this->orders->openPurchase($customer, $plan, $server, $key), $key);
    }

    /**
     * The renewal of the customer's service before it is paid: on its plan, at its price, and the service as it would
     * leave it (CustomerRenewal::preview()) — while its customer may renew it and no renewal of it is under way.
     *
     * @throws ValidationException 422 on `status`: it may not be renewed now
     * @throws RenewalUnderWayException 422 on `status`: its receipt with support, or paid and being delivered
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
     * The customer's service renewed — paid with `method_id` — as renewal() previews it.
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
        $this->takingOrders();
        $this->throttle($customer);
        $plan = $subscription->plan;
        $methodId = Input::integerOf($input['method_id'] ?? null);
        if ($plan !== null && $methodId !== null) {
            $replayed = $this->checkout->replayed($customer, new OrderKey($key, $methodId), fn(?OrderKey $key): Order => $this->orders->openRenewal($customer, $subscription, $plan, $key));
            if ($replayed !== null) {
                return $replayed;
            }
        }

        $preview = $this->renewal($subscription);
        $method = $this->method($input, OrderType::Renewal);

        return $this->checkout->pay($customer, $method, $preview->price, fn(?OrderKey $key): Order => $this->orders->openRenewal($customer, $subscription, $preview->plan, $key), $key);
    }

    /**
     * The customer's wallet charged by `amount` — read as the bot reads a typed one (Input::amountOf(): Persian digits,
     * separators, «تومان», and the API's own "50000.00"), within the bot's bounds — paid with `method_id`, any way but
     * the wallet itself.
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
        $this->takingOrders();
        $this->throttle($customer);
        $amount = Input::amountOf($input['amount'] ?? null);
        $methodId = Input::integerOf($input['method_id'] ?? null);
        if ($amount !== null && $methodId !== null) {
            $replayed = $this->checkout->replayed($customer, new OrderKey($key, $methodId), fn(?OrderKey $key): Order => $this->orders->openTopUp($customer, $amount, $key));
            if ($replayed !== null) {
                return $replayed;
            }
        }

        if ($amount === null || !$this->orders->topUpAllowed($amount)) {
            throw ValidationException::on('amount', $this->orders->topUpBounds());
        }
        $method = $this->method($input, OrderType::WalletTopUp);

        return $this->checkout->pay($customer, $method, Money::normalize($amount), fn(?OrderKey $key): Order => $this->orders->openTopUp($customer, $amount, $key), $key);
    }

    /**
     * What a checkout came to, as the website is answered: the outcome — settled, transfer, processing —, the order as it
     * stands with its payments (support's notes never), a transfer's card and the payment its receipt is for, and the
     * service a purchase or a renewal delivered, once delivered. One that paid nothing is refused in the website's words.
     *
     * @return array{outcome: string, order: array<string, mixed>, transfer: array{payment_id: int, amount: string, card: string, holder: string, instructions: string|null}|null, subscription: array<string, mixed>|null}
     * @throws CheckoutRefusedException 422 the wallet short, or its refusal; 409 paid or closed elsewhere in the same moment, or — made again — cancelled since
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
        $delivered = in_array($order->type, [OrderType::Purchase, OrderType::Renewal], true) && $order->fulfilled_at !== null && $order->subscription_id !== null;

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
     * One more ordering request of the customer's, held to its window (the throttle's folder, Core\Security\RateLimiter).
     *
     * @throws TooManyAttemptsException 429
     */
    private function throttle(User $customer): void
    {
        $wait = $this->limiter->attempt([['checkout|' . $customer->id, self::CHECKOUTS, self::CHECKOUT_WINDOW]]);
        if ($wait > 0) {
            throw TooManyAttemptsException::wait('درخواست پرداخت زیادی فرستاده‌اید', $wait);
        }
    }
}
