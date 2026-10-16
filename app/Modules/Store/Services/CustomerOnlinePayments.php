<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Security\RateLimiter;
use App\Modules\Payments\Enums\GatewayKind;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Exceptions\PaymentHeldException;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\OnlinePayments;
use App\Modules\Store\Exceptions\CheckoutRefusedException;
use App\Modules\Store\Presenters\OrderPresenter;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * An online payment asked about from the website, as the bot's «بررسی پرداخت» asks it: the customer's own payment made
 * on an online gateway, asked of the gateway itself (Payments\Services\OnlinePayments::check() — settled or closed as the
 * gateway says, the customer told by whichever call settled it). Each ask is a call to the gateway: CHECKS per
 * CHECK_WINDOW a customer. A payment held for support is never asked about: the customer is sent to support, as the
 * checkout of its order is (PaymentHeldException).
 */
final class CustomerOnlinePayments
{
    /** Asks a customer sends per CHECK_WINDOW seconds. */
    public const CHECKS = 10;
    public const CHECK_WINDOW = 60;

    /** A payment made any other way: a card transfer, the wallet. */
    public const NOT_ONLINE = 'بررسی پرداخت فقط برای پرداخت آنلاین است.';

    public function __construct(
        private readonly OnlinePayments $payments,
        private readonly GatewayRegistry $gateways,
        private readonly CustomerOrders $orders,
        private readonly CustomerCheckout $checkout,
        private readonly OrderPresenter $presenter,
        private readonly RateLimiter $limiter,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Asks the gateway about the customer's payment `$paymentId` now: what it came to (OnlineState), its order as it
     * stands and the service once delivered — as a checkout answers them.
     *
     * @return array{state: string, order: array<string, mixed>, subscription: array<string, mixed>|null}
     * @throws ModelNotFoundException 404: no payment of theirs
     * @throws ValidationException 422 on `status`: not an online payment
     * @throws PaymentHeldException 422 on `status`: held for support — before, or by this very ask
     * @throws TooManyAttemptsException 429
     * @throws CheckoutRefusedException 503: its gateway could not be asked; nothing changed
     */
    public function check(User $customer, int $paymentId): array
    {
        $payment = Payment::query()->with('method')->whereRelation('order', 'user_id', $customer->id)->findOrFail($paymentId);
        if ($this->gateways->kind($payment->method->driver) !== GatewayKind::Online) {
            throw ValidationException::on('status', self::NOT_ONLINE);
        }
        if (OnlinePayments::awaitsSupport($payment)) {
            throw new PaymentHeldException($payment);
        }
        $this->limiter->enforce([['payments|check|' . $customer->id, self::CHECKS, self::CHECK_WINDOW]], 'پرداخت را زیاد بررسی کرده‌اید');

        try {
            $state = $this->payments->check($payment);
        } catch (GatewayUnavailableException $e) {
            if ($e->held) {
                // What the gateway answered did not fit the payment: held for support from now on (the log says so).
                throw new PaymentHeldException($payment);
            }
            $this->logger->warning('Payment {id} was asked about from the website, but its gateway could not be asked: {detail}', ['id' => $payment->id, 'detail' => $e->detail]);

            throw CheckoutRefusedException::unreachable();
        }
        $order = $this->orders->find($customer, $payment->order_id);

        return [
            'state' => $state->value,
            'order' => $this->presenter->present($order),
            'subscription' => $this->checkout->delivered($customer, $order),
        ];
    }
}
