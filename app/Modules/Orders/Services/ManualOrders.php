<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Bots\Exceptions\SellingSuspendedException;
use App\Modules\Bots\Models\Bot;
use App\Modules\Catalog\DTO\Package;
use App\Modules\Catalog\Exceptions\NoServerAvailableException;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Services\CustomServiceSettings;
use App\Modules\Catalog\Services\ServerSelector;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Orders\DTO\ManualOrder;
use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\Enums\ManualPayment;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\RequestKeyReusedException;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\Checkout;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Services\ClientNaming;
use App\Modules\Users\Models\User;
use App\Support\Input;
use App\Support\Money;
use Psr\Log\LoggerInterface;

/**
 * «افزودن سفارش» on a customer's page: support makes a purchase for its customer by hand — a plan on one of the plan's
 * servers that can deliver it now, or a service of support's own numbers (its GB, days and devices, 0 for no limit) on
 * any server that can take one now —, named as support typed it or by the shop's pattern, and pays it at once as support
 * picks: free (a gift), from the customer's wallet, or with money support took outside the shop, at the amount it sets.
 * It is delivered as any purchase is — in an agent's shop out of the agent's pool, checked before anything is made —,
 * the customer is told once it is (a delivery that fails waits for support's retry, which tells them), and the report
 * group's «خریدها» says support made it. A shop admin never makes one for themselves. An agent's shop gets none while
 * its selling is suspended (Bot::sells()) — whoever asks: the owner opens it again first —, nor one on a server
 * or of a plan the owner keeps from it (Bot::hidesServer(), Plan::$hidden_by_owner).
 *
 * What it counts as, by how it was paid:
 * - free: a gift, as a prize's service is — no purchase of the customer's (a first-purchase price and a code for
 *   newcomers stay theirs), no takings, no referrer's commission or referral gift, no cashback, nothing spent towards a
 *   loyalty tier or the nightly draw;
 * - from the wallet: a purchase at that amount — the customer has bought, cashback and loyalty count it, a referred
 *   customer's first purchase brings their referrer's gift —, though no takings and no commission: the wallet's money
 *   counted when it came in;
 * - outside the shop: the same purchase, and the shop's takings too, earning the referrer's commission as a card's does.
 *
 * Made again with its Idempotency-Key, the request is answered by the order the key came to, as it stands — never a
 * second order or charge —, before anything that changed since (the wallet it charged, the name it took) is asked.
 */
final class ManualOrders
{
    /** The most support may charge for one, Toman: a plan's price's own bound. */
    public const AMOUNT_MAX = Package::PRICE_MAX;

    public const NO_PAYMENT = 'روش پرداخت را انتخاب کنید: رایگان، از کیف پول مشتری یا پرداخت خارج از فروشگاه.';
    public const NOT_AN_AMOUNT = 'مبلغ باید عددی بیشتر از صفر به تومان و بدون اعشار باشد؛ حداکثر %s.';
    public const NO_PLAN = 'این پلن در این فروشگاه نیست.';
    public const NO_SERVER = 'سرور سرویس را انتخاب کنید.';
    public const NOT_GB = 'حجم را به گیگابایت و بدون اعشار بنویسید؛ از 0 (نامحدود) تا %d.';
    public const NOT_DAYS = 'تعداد روز باید عددی از 0 (بدون پایان) تا %d باشد.';
    public const NOT_DEVICES = 'تعداد کاربر باید عددی از 0 (نامحدود) تا %d باشد.';

    /** The shop's wallet is switched off: no balance is spent (Payments\Services\PaymentMethods::enabledWallet()). */
    public const WALLET_OFF = 'کیف پول در این فروشگاه خاموش است و از موجودی مشتری چیزی پرداخت نمی‌شود؛ روش پرداخت دیگری انتخاب کنید.';

    /** The customer's wallet cannot pay it: its balance, then what is missing. */
    public const WALLET_SHORT = 'موجودی کیف پول مشتری %s است و برای این مبلغ %s کم دارد.';

    public function __construct(
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
        private readonly PaymentMethods $methods,
        private readonly Checkout $checkout,
        private readonly ServerSelector $selector,
        private readonly ClientNaming $naming,
        private readonly CustomerNotifier $notifier,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Makes, pays and delivers the order `$input` asks for the customer, by `$actor`, under the request's `$key`; the
     * order as it ended (fulfilled, or failed and waiting for a retry) — or, made again with its key, as it stands.
     *
     * @param array<string, mixed> $input {plan_id? | traffic_gb + days + devices, server_id, client_name?, payment, amount?, note?}
     * @throws ActorRefusedException 403 a shop admin's own account
     * @throws ValidationException 422 under the field refused: every refusal of the form at once, then the agent's shop
     *                             not selling (on `status`), the wallet switched off, what the owner keeps from the shop,
     *                             the server, the agent's pool, the name and the wallet's balance, one at a time
     * @throws RequestKeyReusedException 422 on `idempotency_key`: the key came to an order of something else
     */
    public function make(User $customer, Actor $actor, array $input, string $key): Order
    {
        if ($actor->is($customer->id)) {
            throw ActorRefusedException::ownOrder();
        }
        $manual = $this->read($input, $actor->name());
        $method = $manual->payment === ManualPayment::Outside ? $this->methods->outside() : $this->methods->wallet();
        $request = new OrderKey($key, $method->id);
        if ($this->orders->keyed($customer, $key) !== null) {
            return $this->orders->openManual($customer, $manual, $request);
        }

        if (!$customer->shop()->sells()) {
            throw ValidationException::on('status', $actor->isOwner() ? SellingSuspendedException::OWNER : SellingSuspendedException::STAFF);
        }
        if ($manual->payment === ManualPayment::Wallet && !$method->enabled) {
            throw ValidationException::on('payment', self::WALLET_OFF);
        }
        $this->deliverable($customer, $manual);
        $manual = $this->named($manual);
        $this->affordable($customer, $method, $manual);

        $order = $this->orders->openManual($customer, $manual, $request);
        if (!$order->wasRecentlyCreated) {
            return $order;
        }
        $payment = $this->payments->payBySupport($order, $method, $manual->by);
        if ($payment->status !== PaymentStatus::Paid) {
            // The wallet was spent in the same moment: nothing is left half-made.
            $this->orders->cancel($order, $payment->note ?? OrderService::NOTE_CANCELLED_BY_SUPPORT);

            throw ValidationException::on('amount', $payment->note ?? self::NO_PAYMENT);
        }

        $order->refresh();
        $this->logger->info('Order {order} made by hand for user {user} by {actor}: {payment}, {amount} Toman — {status}', [
            'order' => $order->id,
            'user' => $customer->id,
            'actor' => $actor->label(),
            'payment' => $manual->payment->value,
            'amount' => $manual->amount,
            'status' => $order->status->value,
        ]);
        if ($order->status === OrderStatus::Fulfilled) {
            $payment->setRelation('order', $order);
            $this->notifier->paymentSettled($payment);
        }

        return $order;
    }

    /**
     * What the form asks, every refusal at once: how it is paid and at what amount, a plan of the shop or numbers of
     * support's own, the server, the name as typed (checked against the server later, named()) and the note.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    private function read(array $input, string $by): ManualOrder
    {
        $errors = [];
        $payment = ManualPayment::tryFrom(Input::text($input, 'payment'));
        if ($payment === null) {
            $errors['payment'][] = self::NO_PAYMENT;
        }
        $amount = 0;
        if ($payment !== null && $payment !== ManualPayment::Free) {
            $amount = Input::amount($input, 'amount');
            if ($amount === null || $amount <= 0 || $amount > self::AMOUNT_MAX) {
                $errors['amount'][] = sprintf(self::NOT_AN_AMOUNT, Money::format(self::AMOUNT_MAX));
            }
        }

        $plan = null;
        $built = null;
        if (Input::text($input, 'plan_id') !== '') {
            $id = Input::integer($input, 'plan_id');
            $plan = $id === null ? null : Plan::query()->find($id);
            if ($plan === null) {
                $errors['plan_id'][] = self::NO_PLAN;
            }
        } else {
            $built = self::numbers($input, $errors);
        }

        $serverId = Input::integer($input, 'server_id');
        $server = $serverId === null ? null : Server::query()->find($serverId);
        if ($server === null) {
            $errors['server_id'][] = self::NO_SERVER;
        }
        $typed = Input::text($input, 'client_name');
        try {
            $note = Input::note($input, 'note');
        } catch (ValidationException $e) {
            $errors += $e->errors();
            $note = null;
        }
        if ($payment === null || $amount === null || $server === null || $errors !== []) {
            throw new ValidationException($errors);
        }

        return new ManualOrder($plan, $built, $server, $typed === '' ? null : $typed, $payment, $amount, $note, $by);
    }

    /**
     * Support's own numbers: GB, days and devices, each a whole number within its bound (0 for no limit).
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     * @return array{gb: int, days: int, devices: int}
     */
    private static function numbers(array $input, array &$errors): array
    {
        $numbers = [];
        foreach ([['traffic_gb', 'gb', Package::MAX_GB, self::NOT_GB], ['days', 'days', Package::MAX_DAYS, self::NOT_DAYS], ['devices', 'devices', CustomServiceSettings::MAX_DEVICES, self::NOT_DEVICES]] as [$field, $name, $most, $words]) {
            $number = Input::text($input, $field) === '' ? null : Input::integer($input, $field);
            if ($number === null || $number > $most) {
                $errors[$field][] = sprintf($words, $most);
            }
            $numbers[$name] = $number ?? 0;
        }

        return $numbers;
    }

    /**
     * Whether the order can be delivered now: the plan on the server picked (one of its servers that can sell it), or
     * support's numbers on a server that can take a client — and in an agent's shop, neither a server nor a plan the owner
     * keeps from it, and a quota the agent's traffic pays for as the delivery will ask (Bot::trafficRefusal()).
     *
     * @throws ValidationException 422 on `server_id`, or on `plan_id` / `traffic_gb` for a hidden plan or the agent's pool
     */
    private function deliverable(User $customer, ManualOrder $manual): void
    {
        $shop = $customer->shop();
        if ($shop->hidesServer($manual->server->id)) {
            throw ValidationException::on('server_id', ServerSelector::HIDDEN_SERVER);
        }
        if ($manual->plan?->hidden_by_owner === true) {
            throw ValidationException::on('plan_id', ServerSelector::HIDDEN_PLAN);
        }
        try {
            if ($manual->plan !== null) {
                $this->selector->resolve($manual->plan, $manual->server->id);
            } else {
                $this->selector->builtOn($manual->server);
            }
        } catch (NoServerAvailableException $e) {
            throw ValidationException::on('server_id', $e->getMessage());
        }

        $short = $shop->isMain() ? null : Bot::trafficRefusal($manual->package()->bytes, $shop->trafficBalance());
        if ($short !== null) {
            throw ValidationException::on($manual->plan !== null ? 'plan_id' : 'traffic_gb', $short);
        }
    }

    /**
     * The order with the name support typed held to a buyer's typed name's rules on its server (ClientNaming::chosen()),
     * or by the shop's pattern when none was typed.
     *
     * @throws ValidationException 422 on `client_name`
     */
    private function named(ManualOrder $manual): ManualOrder
    {
        if ($manual->clientName === null) {
            return $manual;
        }

        return new ManualOrder($manual->plan, $manual->built, $manual->server, $this->naming->chosen($manual->clientName, $manual->server), $manual->payment, $manual->amount, $manual->note, $manual->by);
    }

    /**
     * Whether the customer's wallet pays support's amount (an agent's credit counting, as at checkout); anything else
     * takes nothing of theirs.
     *
     * @throws ValidationException 422 on `amount`
     */
    private function affordable(User $customer, PaymentMethod $method, ManualOrder $manual): void
    {
        if ($manual->payment !== ManualPayment::Wallet) {
            return;
        }
        $short = $this->checkout->shortfall($customer, $method, Money::normalize($manual->amount));
        if ($short !== null) {
            throw ValidationException::on('amount', sprintf(self::WALLET_SHORT, Money::format($short->balance), Money::format($short->missing)));
        }
    }
}
