<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Core\Exceptions\DomainRuleException;
use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Security\RateLimiter;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Exceptions\SellingSuspendedException;
use App\Modules\Catalog\DTO\CustomPrice;
use App\Modules\Catalog\Enums\CustomQuantity;
use App\Modules\Catalog\Exceptions\NoServerAvailableException;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Services\CustomServices;
use App\Modules\Catalog\Services\ServerSelector;
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
use App\Modules\Payments\Exceptions\MethodUnavailableException;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\Checkout;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Exceptions\CheckoutRefusedException;
use App\Modules\Store\Presenters\OrderPresenter;
use App\Modules\Store\Presenters\PlanPresenter;
use App\Modules\Subscriptions\DTO\ExtraOffer;
use App\Modules\Subscriptions\DTO\RenewalPreview;
use App\Modules\Subscriptions\Enums\ClientNameMode;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Subscriptions\Enums\RenewalRefusal;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ClientNaming;
use App\Modules\Subscriptions\Services\CustomerExtras;
use App\Modules\Subscriptions\Services\CustomerRenewal;
use App\Modules\Telegram\BotSettings;
use App\Modules\Users\Models\User;
use App\Support\Input;
use App\Support\Money;
use App\Support\Validation;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The website's checkout. It goes through the shop's one checkout (Payments\Services\Checkout), which the bot uses too,
 * under the bot's rules: a plan on sale on a server that sells it, a renewal the customer may make (CustomerRenewal: on
 * the service's own plan, or — one without a plan — on a plan they pick among those on sale on its server, or with a
 * package they build), extras the service may take (CustomerExtras), a top-up within the wallet's bounds, a way to pay
 * that may pay that kind of order.
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

    /** A service without a plan renews on one its customer picks (CustomerRenewal::choices()): none picked, or another. */
    public const PICK_A_PLAN = 'یک پلن برای تمدید این سرویس انتخاب کنید.';
    public const PLAN_NOT_OFFERED = 'این پلن برای تمدید این سرویس در دسترس نیست.';

    /**
     * … or with a package they build (`gb` and `days`, CustomerRenewal::buildable()): nothing picked while it may be, both
     * sent, or a package for a service renewed on its own plan.
     */
    public const PICK_A_PLAN_OR_PACKAGE = 'یک پلن برای تمدید این سرویس انتخاب کنید، یا حجم و مدت دلخواه را.';
    public const PLAN_OR_PACKAGE = 'برای تمدید یا یک پلن انتخاب کنید یا حجم و مدت دلخواه را، نه هر دو.';
    public const RENEWS_ON_ITS_PLAN = 'این سرویس با پلن خودش تمدید می‌شود.';

    /** A renewal before its window opens (%s: from when, RenewalWindow::when()), and a method the shop does not offer. */
    public const NOT_YET = 'تمدید این سرویس %s ممکن است.';
    public const METHOD_NOT_OFFERED = 'این روش تمدید در این فروشگاه نیست.';

    /** An extra's amount that is no whole number (%s: its unit, «گیگابایت», «روز» or «کاربر»). */
    public const NOT_A_QUANTITY = 'تعداد %s را با عدد و بدون اعشار بنویسید.';

    /** Ordering requests a customer sends per CHECKOUT_WINDOW seconds, repeats included. */
    public const CHECKOUTS = 30;
    public const CHECKOUT_WINDOW = 600;

    /** What GET /payment-methods takes as `for` (an agent's traffic is bought in the bot only). */
    private const KINDS = [OrderType::Purchase, OrderType::Renewal, OrderType::WalletTopUp, OrderType::ExtraTraffic, OrderType::ExtraTime, OrderType::ExtraDevices];

    /** A `for` that is none of the kinds taken (%s: them, OrderType::valuesInWords()). */
    private const NO_KIND = 'نوع سفارش (for) باید %s باشد.';
    private const NO_CODE = 'کد تخفیف را بنویسید.';
    private const NO_SERVER = 'سرور را انتخاب کنید.';
    private const NO_NAME_MODE = 'روش نام‌گذاری سرویس (name_mode) باید sequential، random یا custom باشد.';
    private const NO_METHOD = 'روش پرداخت را انتخاب کنید.';
    private const NOT_PAYABLE = 'این روش پرداخت برای این سفارش در دسترس نیست.';
    private const WALLET_TOP_UP = 'کیف پول با خودش شارژ نمی‌شود؛ روش دیگری انتخاب کنید.';

    public function __construct(
        private readonly Checkout $checkout,
        private readonly OrderService $orders,
        private readonly Pricing $pricing,
        private readonly PaymentMethods $methods,
        private readonly ServerSelector $selector,
        private readonly CustomServices $custom,
        private readonly CustomerRenewal $renewals,
        private readonly CustomerExtras $extras,
        private readonly ClientNaming $naming,
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
     * method, but never the wallet for a top-up —, and with `amount` (read as the bot reads one) only those that take it:
     * within their bounds, a card method with a card that has room for it today (or the customer's own open payment of such
     * an order, shown again).
     *
     * @param array<string, mixed> $query
     * @return list<array{id: int, label: string, kind: string|null, min_amount: string|null, max_amount: string|null}>
     * @throws ValidationException 422 on `for` or `amount`
     */
    public function methods(User $customer, array $query): array
    {
        $kind = OrderType::tryFrom(is_string($query['for'] ?? null) ? $query['for'] : '');
        if ($kind === null || !in_array($kind, self::KINDS, true)) {
            throw ValidationException::on('for', sprintf(self::NO_KIND, OrderType::valuesInWords(self::KINDS)));
        }
        $amount = null;
        if (($query['amount'] ?? '') !== '') {
            $amount = Input::amountOf($query['amount']) ?? throw ValidationException::on('amount', Validation::notWholeToman('مبلغ (amount)'));
        }

        return array_map($this->presenter->method(...), $this->methods->forOrder($kind, $amount === null ? null : Money::normalize($amount), $customer));
    }

    /**
     * Buy `plan_id` on `server_id`, paid with `method_id`: the plan on sale now (onSale()), a server it sells on today
     * (ServerSelector::resolve()), at the customer's price (Pricing: a first-purchase or agent price when theirs); its
     * service named as `name_mode` asks (clientName()).
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `plan_id`, `server_id`, `name_mode`, `client_name` or `method_id`
     * @throws NoServerAvailableException 422 on `server_id`: another server of the plan sells, not this one
     * @throws RequestKeyReusedException 422 on `idempotency_key`
     * @throws MethodUnavailableException 422 on `method_id`: the price outside its bounds, or its cards full today
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
        $replayed = $plan === null || $server === null ? null : $this->replayed($customer, $key, $input, fn(?OrderKey $keyed): Order => $this->orders->openPurchase($customer, $plan, $server, $keyed, clientName: $this->clientName($customer, $key, $input, $server)));
        if ($replayed !== null) {
            return $replayed;
        }

        $plan = $this->onSale($plan);
        if ($serverId === null) {
            throw ValidationException::on('server_id', self::NO_SERVER);
        }
        $server = $this->selector->resolve($plan, $serverId)['server'];
        $name = $this->clientName($customer, $key, $input, $server);
        $method = $this->method($customer, $input, OrderType::Purchase);
        $quote = $this->quote($customer, $key, $input, fn(?string $code): Quote => $this->pricing->purchase($customer, $plan, $server, $code));

        return $this->checkout->pay($customer, $method, OrderType::Purchase, $quote->amount(), fn(?OrderKey $key): Order => $this->orders->openPurchase($customer, $plan, $server, $key, $quote, $name), $key);
    }

    /**
     * The name a purchase asks for its service on `$server` — `name_mode`, with `client_name` the name typed for `custom`
     * — as ClientNaming::requested() judges it; null for the shop's pattern. A random name is worked out from the key, so
     * two requests sent with one key at once ask the same. Made again with its key, the request asks what its order was
     * made with, judged no more: a random name is not drawn anew, nor a typed one refused for the service carrying it.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `name_mode` or `client_name`
     */
    private function clientName(User $customer, string $key, array $input, Server $server): ?string
    {
        $mode = Input::text($input, 'name_mode');
        $mode = $mode === '' ? ClientNameMode::Sequential : ClientNameMode::tryFrom($mode) ?? throw ValidationException::on('name_mode', self::NO_NAME_MODE);
        $typed = Input::text($input, 'client_name');
        $keyed = $this->orders->keyed($customer, $key);
        if ($keyed === null) {
            return $this->naming->requested($mode, $typed, $server, $customer->id . '|' . $key);
        }

        return match ($mode) {
            ClientNameMode::Sequential => null,
            ClientNameMode::Random => $keyed->client_name,
            ClientNameMode::Custom => $typed,
        };
    }

    /**
     * What a service the customer builds («سرویس دلخواه») would cost them, nothing ordered: `gb` GB for `days` days (on
     * `server_id` once picked) — the GB's and the days' prices by their tiers, the customer's discounts as lines, with
     * `discount_code` as their order would take it (held to their budget of codes that do not hold), and what is left.
     *
     * @param array<string, mixed> $input
     * @return array{gb: int, days: int, devices: int, server_id: int|null, traffic_price: string, days_price: string, base: string, discounts: list<array{source: string, label: string, amount: string, percent: int|null}>, amount: string}
     * @throws ValidationException 422 on `status` while the builder is not offered; on `gb`, `days` or `server_id`
     * @throws DiscountCodeRefusedException 422 on `discount_code`
     * @throws TooManyAttemptsException 429: too many codes that did not hold
     */
    public function customQuote(User $customer, array $input): array
    {
        $serverId = Input::integerOf($input['server_id'] ?? null);
        // A server named is looked up among those offered, which refuses a builder not offered at all.
        $server = $serverId === null ? null : $this->custom->server($serverId);
        $price = $this->builtPrice($input, offered: $server !== null);
        $priced = fn(?string $code): Quote => $this->pricing->custom($customer, $price, $server, $code);
        $code = Input::text($input, 'discount_code');
        $quote = $code === '' ? $priced(null) : $this->codes->tried($customer, static fn(): Quote => $priced($code));

        return [
            'gb' => $price->gb,
            'days' => $price->days,
            'devices' => $this->custom->devices(),
            'server_id' => $server?->id,
            'traffic_price' => $price->traffic,
            'days_price' => $price->time,
        ] + $quote->present();
    }

    /**
     * Buy a service the customer builds: `gb` GB for `days` days on `server_id` (it may be left out while the builder
     * sells on one server), paid with `method_id` — while the builder is offered, within its bounds, at the customer's
     * price (Pricing::custom()), a purchase without a plan (OrderService::openCustomPurchase()); its service named as
     * `name_mode` asks, as a plan's purchase (clientName()).
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `status` while the builder is not offered; on `gb`, `days`, `server_id`,
     *                             `name_mode`, `client_name` or `method_id`
     * @throws RequestKeyReusedException 422 on `idempotency_key`
     * @throws MethodUnavailableException 422 on `method_id`: the price outside its bounds, or its cards full today
     * @throws TooManyUnpaidOrdersException 422
     * @throws TooManyAttemptsException 429
     * @throws CheckoutRefusedException 503: the shop takes no orders while its bot is switched off
     */
    public function buildService(User $customer, array $input, string $key): CheckoutResult
    {
        $this->ordering($customer);
        [$gb, $days] = self::package($input);
        $serverId = Input::integerOf($input['server_id'] ?? null);
        // Made again without a server named: the one its order was made on.
        $server = $serverId !== null ? Server::query()->find($serverId) : $this->orders->keyed($customer, $key)?->server;
        $replayed = $gb === null || $days === null || $server === null ? null : $this->replayed($customer, $key, $input, fn(?OrderKey $keyed): Order => $this->orders->openCustomPurchase($customer, $gb, $days, $server, $keyed, clientName: $this->clientName($customer, $key, $input, $server)));
        if ($replayed !== null) {
            return $replayed;
        }

        // The server among those offered, which refuses a builder not offered at all.
        $server = $this->custom->server($serverId);
        $price = $this->builtPrice($input, offered: true);
        $name = $this->clientName($customer, $key, $input, $server);
        $method = $this->method($customer, $input, OrderType::Purchase);
        $quote = $this->quote($customer, $key, $input, fn(?string $code): Quote => $this->pricing->custom($customer, $price, $server, $code));

        return $this->checkout->pay($customer, $method, OrderType::Purchase, $quote->amount(), fn(?OrderKey $key): Order => $this->orders->openCustomPurchase($customer, $price->gb, $price->days, $server, $key, $quote, $name), $key);
    }

    /**
     * The renewal of the customer's service before it is paid (CustomerRenewal::preview()): what it renews with, its
     * price and the service as the renewal would leave it by `method` (the first the shop offers when none is named) —
     * while it may be renewed, its window is open, and no renewal of it is under way. A service without a plan renews on
     * the one `plan_id` picks among its choices, or — while the builder is offered — with the package `gb` and `days`
     * build; one with a plan on that plan, and a `plan_id` naming another (or a package) is refused (a page drawn before
     * its first renewal), never quietly renewed on what was not shown.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `method`: one the shop does not offer; on `status`: it may not be renewed now, or
     *                             not yet (from when), or the builder is not offered; on `plan_id`: none picked, not a
     *                             choice, or sent with a package; on `gb` or `days` as the builder refuses them, or for
     *                             a service renewed on its own plan
     * @throws RenewalUnderWayException 422 on `status`: a receipt with support, or paid and being delivered
     */
    public function renewal(Subscription $subscription, array $input = []): RenewalPreview
    {
        $method = $this->renewalMethod($input);
        $with = $this->renewsWith($input);

        return $this->renewals->preview($subscription, $with, $method) ?? throw $this->refused($subscription, $this->renewals->refusal($subscription, $with, $method));
    }

    /**
     * What the customer's service may be renewed on now (CustomerRenewal::picks()): the plans, each at their renewal
     * price today (Pricing::renewalOffers(), the customer read once) — a service without a plan, its choices; any other,
     * its own plan —, and whether a package they build may renew it instead. Nothing while it may not be renewed now:
     * not at all, not yet (its window), or a renewal of it under way.
     *
     * @return array{plans: list<array<string, mixed>>, custom_services: bool}
     */
    public function renewalChoices(User $customer, Subscription $subscription): array
    {
        ['plans' => $plans, 'builds' => $builds] = $this->renewals->picks($subscription);
        $quotes = $this->pricing->renewalOffers($customer, ...$plans);

        return [
            'plans' => array_map(static fn(Plan $plan): array => PlanPresenter::renewal($plan, $quotes[$plan->id]), $plans),
            'custom_services' => $builds,
        ];
    }

    /**
     * Renew the customer's service as renewal() previews it — by `method`, on a plan or with a package built
     * (OrderService::openPreviewed()) —, paid with `method_id`.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `method`, `status`, `plan_id`, `gb`, `days` or `method_id`
     * @throws RenewalUnderWayException 422 on `status`
     * @throws RequestKeyReusedException 422 on `idempotency_key`
     * @throws MethodUnavailableException 422 on `method_id`: the price outside its bounds, or its cards full today
     * @throws TooManyUnpaidOrdersException 422
     * @throws TooManyAttemptsException 429
     * @throws CheckoutRefusedException 503: the shop takes no orders while its bot is switched off
     */
    public function renew(User $customer, Subscription $subscription, array $input, string $key): CheckoutResult
    {
        $this->ordering($customer);
        // The order a key made is with what the request names — a package built; the plan picked for a service without
        // one, which its delivery then makes its own —, else on the service's own plan; by the method it names, offered
        // now or not.
        [$gb, $days] = self::package($input);
        $named = Input::integerOf($input['plan_id'] ?? null);
        $plan = $named !== null ? Plan::query()->find($named) : $subscription->plan;
        $keyed = RenewalMethod::tryFrom(Input::text($input, 'method')) ?? $this->renewals->methods()[0];
        $open = match (true) {
            $gb !== null && $days !== null => fn(?OrderKey $key): Order => $this->orders->openCustomRenewal($customer, $subscription, $gb, $days, $keyed, $key),
            $plan !== null => fn(?OrderKey $key): Order => $this->orders->openRenewal($customer, $subscription, $plan, $keyed, $key),
            default => null,
        };
        $replayed = $open === null ? null : $this->replayed($customer, $key, $input, $open);
        if ($replayed !== null) {
            return $replayed;
        }

        $preview = $this->renewal($subscription, $input);
        $method = $this->method($customer, $input, OrderType::Renewal);
        $quote = $this->quote($customer, $key, $input, fn(?string $code): Quote => $this->renewals->priced($subscription, $preview, $code));

        return $this->checkout->pay($customer, $method, OrderType::Renewal, $quote->amount(), fn(?OrderKey $key): Order => $this->orders->openPreviewed($customer, $subscription, $preview, $key, $quote), $key);
    }

    /**
     * The extra traffic, time and devices the service may buy now, at its plan's prices or the shop's (devices prorated
     * to what it has left); a kind is null when none may be bought (CustomerExtras::offersFor()).
     *
     * @return array{traffic: ExtraOffer|null, time: ExtraOffer|null, devices: ExtraOffer|null}
     */
    public function extras(Subscription $subscription): array
    {
        return $this->extras->offersFor([$subscription])[$subscription->id];
    }

    /**
     * Buy extra traffic, time or devices for the customer's service — the amount under the kind's field (`gb`, `days`,
     * `devices`, a whole number as typed) — at what it costs now (CustomerExtras::quote()), paid with `method_id`.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `status` while the service cannot take it; on `gb`/`days`/`devices` for an
     *                             amount that is no whole number or not on sale; on `method_id`
     * @throws RequestKeyReusedException 422 on `idempotency_key`
     * @throws MethodUnavailableException 422 on `method_id`: the price outside its bounds, or its cards full today
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

        $quantity = $this->quantity($subscription, $kind, $input);
        $price = $this->extras->quote($subscription, $kind, $quantity);
        $method = $this->method($customer, $input, $kind->orderType());
        $quote = $this->quote($customer, $key, $input, fn(?string $code): Quote => $this->pricing->extra($customer, $subscription, $kind, $price, $code));

        return $this->checkout->pay($customer, $method, $kind->orderType(), $quote->amount(), fn(?OrderKey $key): Order => $this->orders->openExtra($customer, $subscription, $kind, $quantity, $price, $key, $quote), $key);
    }

    /**
     * Charge the customer's wallet by `amount` (read as the bot reads one: Persian digits, separators, «تومان», and the
     * API's own "50000.00"), within the wallet's bounds, paid with any way but the wallet.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `amount` or `method_id`
     * @throws RequestKeyReusedException 422 on `idempotency_key`
     * @throws MethodUnavailableException 422 on `method_id`: the price outside its bounds, or its cards full today
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
        $method = $this->method($customer, $input, OrderType::WalletTopUp);

        return $this->checkout->pay($customer, $method, OrderType::WalletTopUp, Money::normalize($amount), fn(?OrderKey $key): Order => $this->orders->openTopUp($customer, $amount, $key), $key);
    }

    /**
     * What a checkout came to, as the website is answered: the outcome, the order as it stands (support's notes never),
     * a card transfer's details, and the service once delivered (a purchase's, or the one renewed or given extras) while
     * it is still theirs — a repeated request after they handed it on gets none. A checkout that paid nothing is
     * refused instead.
     *
     * @return array{outcome: string, order: array<string, mixed>, transfer: array{payment_id: int, amount: string, card: string|null, holder: string|null, bank: string|null, instructions: string|null, contact: array{text: string, url: string|null}|null}|null, subscription: array<string, mixed>|null}
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
        $service = null;
        if ($delivered) {
            try {
                $service = $this->subscriptions->present($this->subscriptions->find($customer, (int) $order->subscription_id));
            } catch (ModelNotFoundException) {
                // Handed to another customer (or removed) since it was delivered: the order stays theirs, the service not.
            }
        }

        return [
            'outcome' => $result->outcome->value,
            'order' => $this->presenter->present($order),
            'transfer' => $result->outcome !== CheckoutOutcome::Transfer ? null : $this->transfer($result),
            'subscription' => $service,
        ];
    }

    /**
     * A card transfer's details, as the bot shows them (CheckoutScreen): the payment the receipt is for, the amount, the
     * card and the method's note — or, for a payment shown no card, taken in support's private chat (PaymentCards::
     * toSupport()), the way to support instead (`contact`: as written in the bot's settings, and as a link when it is one).
     *
     * @return array{payment_id: int, amount: string, card: string|null, holder: string|null, bank: string|null, instructions: string|null, contact: array{text: string, url: string|null}|null}
     */
    private function transfer(CheckoutResult $result): array
    {
        $card = $result->card();

        return [
            'payment_id' => $result->payment()->id,
            'amount' => $result->payment()->amount,
            'card' => $card->card,
            'holder' => $card->holder,
            // The owner's word for the card (its bank), when they gave one.
            'bank' => $card->bank,
            'instructions' => $card->instructions !== '' ? $card->instructions : null,
            'contact' => $card->card === null ? ['text' => $this->bot->supportContact(), 'url' => $this->bot->supportUrl()] : null,
        ];
    }

    /**
     * What a discount code takes off what the customer means to buy, nothing ordered: the same quote their order would
     * be made at (base, each discount line, what is left). `for` names what: purchase (`plan_id`, `server_id` once
     * picked), renewal (`subscription_id`), extra_traffic, extra_time or extra_devices (`subscription_id` and `gb`,
     * `days` or `devices`). Held to the customer's budget of codes that do not hold, so codes cannot be guessed.
     *
     * @param array<string, mixed> $input
     * @return array{base: string, discounts: list<array{source: string, label: string, amount: string, percent: int|null}>, amount: string}
     * @throws ValidationException 422 on `code`, `for`, `plan_id`, `server_id`, `status`, `gb`, `days` or `devices`
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
            throw ValidationException::on('for', sprintf(self::NO_KIND, OrderType::valuesInWords(array_map(OrderType::from(...), OrderType::FOR_THEMSELVES))));
        }

        $price = match ($kind) {
            OrderType::Purchase => $this->purchasePrice($customer, $input),
            OrderType::Renewal => $this->renewalPrice($customer, $input),
            default => $this->extraPrice($customer, $input, ExtraKind::of($kind) ?? throw new \LogicException("{$kind->value} is no extra.")),
        };
        try {
            return $this->codes->tried($customer, static fn(): Quote => $price($code))->present();
        } catch (DiscountCodeRefusedException $e) {
            throw ValidationException::on('code', $e->getMessage());
        }
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
     * How checkCode() prices a renewal of the customer's service, while they may renew it — on the plan `plan_id` picks
     * for one without a plan, or with the package `gb` and `days` build —, by the `method` named (one the shop offers).
     *
     * @param array<string, mixed> $input
     * @return \Closure(?string): Quote
     */
    private function renewalPrice(User $customer, array $input): \Closure
    {
        $subscription = $this->subscriptions->find($customer, Input::integerOf($input['subscription_id'] ?? null) ?? 0);
        $preview = $this->renewal($subscription, $input);

        return fn(?string $code): Quote => $this->renewals->priced($subscription, $preview, $code);
    }

    /**
     * How checkCode() prices extra traffic, time or devices for the customer's service: that amount, as it is sold now.
     *
     * @param array<string, mixed> $input
     * @return \Closure(?string): Quote
     */
    private function extraPrice(User $customer, array $input, ExtraKind $kind): \Closure
    {
        $subscription = $this->subscriptions->find($customer, Input::integerOf($input['subscription_id'] ?? null) ?? 0);
        $base = $this->extras->quote($subscription, $kind, $this->quantity($subscription, $kind, $input));

        return fn(?string $code): Quote => $this->pricing->extra($customer, $subscription, $kind, $base, $code);
    }

    /**
     * The plan while it is on sale: switched on and not kept from sale by the owner, covered by an agent's traffic, and
     * on a server that can deliver it now.
     *
     * @throws ValidationException 422 on `plan_id`
     */
    private function onSale(?Plan $plan): Plan
    {
        if ($plan === null || !$plan->onSale() || !$this->selector->covers($plan) || $this->selector->choices($plan) === []) {
            throw ValidationException::on('plan_id', self::NOT_ON_SALE);
        }

        return $plan;
    }

    /**
     * What the `gb` and `days` asked for cost, while the builder is offered (CustomServices::quote()) — `$offered` when
     * the caller knows it is already (a server of it found).
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `status` while it is not offered; on `gb` or `days`: no whole number, or out of bounds
     */
    private function builtPrice(array $input, bool $offered = false): CustomPrice
    {
        if (!$offered && !$this->custom->offered()) {
            throw ValidationException::on('status', CustomServices::OFF);
        }
        [$gb, $days] = self::package($input);

        return $this->custom->quote(
            $gb ?? throw ValidationException::on(CustomQuantity::Traffic->field(), sprintf(self::NOT_A_QUANTITY, CustomQuantity::Traffic->unit())),
            $days ?? throw ValidationException::on(CustomQuantity::Days->field(), sprintf(self::NOT_A_QUANTITY, CustomQuantity::Days->unit())),
        );
    }

    /**
     * The package a request asks for — `gb` and `days` —, each null when it is no whole number.
     *
     * @param array<string, mixed> $input
     * @return array{int|null, int|null}
     */
    private static function package(array $input): array
    {
        return [Input::wholeOf($input[CustomQuantity::Traffic->field()] ?? null), Input::wholeOf($input[CustomQuantity::Days->field()] ?? null)];
    }

    /**
     * What a renewal request renews with: the package `gb` and `days` build (CustomServices::quote(), while the builder is
     * offered), the plan `plan_id` names, or — neither sent — the service's own plan (null).
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `status` while the builder is not offered; on `gb` or `days`; on `plan_id` sent
     *                             with them
     */
    private function renewsWith(array $input): int|CustomPrice|null
    {
        $planId = Input::integerOf($input['plan_id'] ?? null);
        if (($input['gb'] ?? null) === null && ($input['days'] ?? null) === null) {
            return $planId;
        }
        if ($planId !== null) {
            throw ValidationException::on('plan_id', self::PLAN_OR_PACKAGE);
        }

        return $this->builtPrice($input);
    }

    /**
     * Why the service has no renewal (CustomerRenewal::refusal()), in the website's words under its field: it may not be
     * renewed now, or not yet — from when said —, or the builder is offered no more (on `status`); a renewal of it is
     * under way (RenewalUnderWayException, on `status`); one renewed on its own plan was named another (on `plan_id`) or
     * a package (on `gb`); one renewed on what its customer picks had nothing picked, or a plan not among its choices
     * (on `plan_id`); a method the shop does not offer (on `method`).
     */
    private function refused(Subscription $subscription, ?RenewalRefusal $refusal): DomainRuleException
    {
        return match ($refusal) {
            RenewalRefusal::MethodNotOffered => ValidationException::on('method', self::METHOD_NOT_OFFERED),
            RenewalRefusal::SellingSuspended => SellingSuspendedException::onStatus(),
            RenewalRefusal::RenewsOnItsPlan => ValidationException::on(CustomQuantity::Traffic->field(), self::RENEWS_ON_ITS_PLAN),
            RenewalRefusal::PlanNotOffered => ValidationException::on('plan_id', self::PLAN_NOT_OFFERED),
            RenewalRefusal::NotYet => ValidationException::on('status', sprintf(self::NOT_YET, $this->renewals->window($subscription)->when())),
            RenewalRefusal::UnderWay => new RenewalUnderWayException($subscription),
            RenewalRefusal::BuilderOff => ValidationException::on('status', CustomServices::OFF),
            RenewalRefusal::PickAPlan => ValidationException::on('plan_id', self::PICK_A_PLAN),
            RenewalRefusal::PickAPlanOrPackage => ValidationException::on('plan_id', self::PICK_A_PLAN_OR_PACKAGE),
            // Null: it was renewable a moment ago, and is not now.
            RenewalRefusal::NotRenewable, null => ValidationException::on('status', self::NOT_RENEWABLE),
        };
    }

    /**
     * The renewal method `method` names while the shop offers it; the first it offers when none is named
     * (CustomerRenewal::resolveMethod()).
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `method`
     */
    private function renewalMethod(array $input): RenewalMethod
    {
        $named = Input::text($input, 'method');
        $method = $named === '' ? null : RenewalMethod::tryFrom($named) ?? throw ValidationException::on('method', self::METHOD_NOT_OFFERED);

        return $this->renewals->resolveMethod($method) ?? throw ValidationException::on('method', self::METHOD_NOT_OFFERED);
    }

    /**
     * The extra's amount typed under the kind's field. When it is no whole number, the service's own state is said first
     * (what quote() would say), then that the amount is none.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `status` or the kind's field
     */
    private function quantity(Subscription $subscription, ExtraKind $kind, array $input): int
    {
        $quantity = Input::wholeOf($input[$kind->field()] ?? null);
        if ($quantity !== null) {
            return $quantity;
        }
        $problem = $this->extras->refusal($subscription, $kind);

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
     * The way to pay `method_id` names, while it may pay that kind of order — at some amount: whether it takes the price
     * is asked by the checkout itself (Checkout::pay(), its bounds in the refusal). A card method `$customer`
     * holds an open payment of is theirs to ask again, its card switched off since or not.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `method_id`
     */
    private function method(User $customer, array $input, OrderType $type): PaymentMethod
    {
        $id = Input::integerOf($input['method_id'] ?? null) ?? throw ValidationException::on('method_id', self::NO_METHOD);
        $method = $this->methods->payable($id, $type, null, $customer);
        if ($method !== null) {
            return $method;
        }

        $wallet = $type === OrderType::WalletTopUp && PaymentMethod::query()->find($id)?->isWallet() === true;
        throw ValidationException::on('method_id', $wallet ? self::WALLET_TOP_UP : self::NOT_PAYABLE);
    }

    /**
     * Start an ordering request: refused while the shop takes no orders — nor, an agent's shop whose selling is
     * suspended (Bot::sells()), any order: a receipt for one made before is still taken —, then counted against
     * the customer's window.
     *
     * @throws CheckoutRefusedException 503
     * @throws TooManyAttemptsException 429
     */
    private function ordering(User $customer): void
    {
        $this->takingOrders();
        if (!CurrentBot::get()->sells()) {
            throw CheckoutRefusedException::suspended();
        }
        $this->limiter->enforce([['checkout|' . $customer->id, self::CHECKOUTS, self::CHECKOUT_WINDOW]], 'درخواست پرداخت زیادی فرستاده‌اید');
    }
}
