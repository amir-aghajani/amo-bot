<?php

declare(strict_types=1);

namespace App\Modules\Payments\Controllers;

use App\Core\Http\ApiController;
use App\Core\Http\Json;
use App\Core\Http\LongRunning;
use App\Core\Security\RateLimiter;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Services\Bots;
use App\Modules\Payments\Enums\OnlineState;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\OnlineInvoices;
use App\Modules\Payments\Services\OnlinePayments;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * GET or POST /payments/callback/{payment}/{secret} — an online gateway's word that something happened to a payment:
 * CubePay, AtlasPay and Aban call it from their servers (each only given an https address); Zarinpal — and Aban, from
 * its page — send the customer's browser back to it, paid or not. What it says is never believed: with the payment's
 * own secret in the address (OnlineInvoices), it only makes the shop ask the gateway itself (OnlinePayments::check()), in
 * the payment's shop — CALLBACK_ASKS times a minute a payment at most, any call past that answered from the payment as
 * it stands. A gateway is answered whether the payment is paid now — a 503 while the gateway cannot be asked, so it calls
 * again, but never for one held for support (it is not asked again); a 404 for an address that names no payment —, a
 * browser the same as a page (ReturnPage), with the way back to the bot for a customer with a Telegram account.
 */
final class CallbackController extends ApiController
{
    /** Asks of a payment's gateway per ASK_WINDOW from its callback: a gateway or a browser calling more is answered as it stands. */
    public const CALLBACK_ASKS = 6;
    private const ASK_WINDOW = 60;

    /** Seconds the work may take once the gateway hung up: a delivery talks to a panel. */
    private const TIME_LIMIT = 120;

    /** What an address that names no payment gets. */
    public const UNKNOWN = 'این آدرس پرداختی را نشان نمی‌دهد.';

    public function __construct(
        private readonly OnlineInvoices $invoices,
        private readonly OnlinePayments $payments,
        private readonly Bots $bots,
        private readonly RateLimiter $limiter,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param array{payment: string, secret: string} $args */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $browser = self::isBrowser($request);
        $id = (int) $args['payment'];
        $payment = $this->invoices->isCallbackSecret($id, $args['secret'])
            ? CurrentBot::everywhere(static fn(): ?Payment => Payment::query()->with('method')->find($id))
            : null;
        if ($payment === null) {
            return $browser ? ReturnPage::respond($response, ReturnPage::UNKNOWN, null, '', null, 404) : Json::error($response, self::UNKNOWN, 404);
        }

        LongRunning::keepGoing(self::TIME_LIMIT);

        return CurrentBot::run($payment->shop(), function () use ($payment, $response, $browser): Response {
            if ($this->limiter->attempt([['online-callback|' . $payment->id, self::CALLBACK_ASKS, self::ASK_WINDOW]]) > 0) {
                return $browser ? $this->page($response, $payment, self::stored($payment)) : $this->json($response, ['paid' => self::isPaid($payment)]);
            }

            try {
                $state = $this->payments->check($payment);
            } catch (GatewayUnavailableException $e) {
                if ($e->held) {
                    // Support's now: its gateway is told nothing will come of calling again, the customer to wait for support.
                    return $browser ? $this->page($response, $payment, ReturnPage::HELD) : $this->json($response, ['paid' => false]);
                }
                $this->logger->warning('Payment {id}\'s callback came, but its gateway could not be asked: {detail}', ['id' => $payment->id, 'detail' => $e->detail]);

                return $browser ? $this->page($response, $payment, ReturnPage::UNANSWERED, 503) : Json::error($response, $e->getMessage(), 503);
            }

            return $browser ? $this->page($response, $payment, $state === OnlineState::Paid && $payment->wentToWallet() ? ReturnPage::WALLET : $state->value) : $this->json($response, ['paid' => $state === OnlineState::Paid]);
        });
    }

    /**
     * The return page about the payment's order, under its shop's name, with «بازگشت به ربات» for a customer with a
     * Telegram account when the bot has a username.
     */
    private function page(Response $response, Payment $payment, string $found, int $status = 200): Response
    {
        $username = $this->bots->username($payment->shop());

        return ReturnPage::respond($response, $found, $payment, $this->bots->name($payment->shop()), $username === '' ? null : 'https://t.me/' . $username, $status);
    }

    /** What the payment came to as the shop last heard, for a call past CALLBACK_ASKS: ReturnPage's word for it. */
    private static function stored(Payment $payment): string
    {
        return match (true) {
            OnlinePayments::isHeld($payment) => ReturnPage::HELD,
            $payment->wentToWallet() => ReturnPage::WALLET,
            self::isPaid($payment) => OnlineState::Paid->value,
            in_array($payment->status, [PaymentStatus::Failed, PaymentStatus::Cancelled], true) => OnlineState::Closed->value,
            default => OnlineState::Waiting->value,
        };
    }

    private static function isPaid(Payment $payment): bool
    {
        return in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::Refunded], true);
    }

    /** A customer's browser sent back by the gateway — a page it asks for —, not the gateway's own server calling. */
    private static function isBrowser(Request $request): bool
    {
        return $request->getMethod() === 'GET' && str_contains($request->getHeaderLine('Accept'), 'text/html');
    }
}
