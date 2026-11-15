<?php

declare(strict_types=1);

namespace App\Modules\Payments\Controllers;

use App\Core\Http\ApiController;
use App\Core\Http\Json;
use App\Core\Http\LongRunning;
use App\Core\Security\RateLimiter;
use App\Modules\Bots\CurrentBot;
use App\Modules\Payments\Contracts\NamingWebhook;
use App\Modules\Payments\Contracts\SignedWebhook;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\OnlinePayments;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * POST /payments/webhooks/{driver} — a gateway's webhook, one address for its every payment in every shop, which its owner
 * pastes at the gateway. Two kinds of gateway post there:
 * - one that answers no question and signs its word instead (Contracts\SignedWebhook: Variza). The signature says whose
 *   it is: each method of that driver, in every shop, is asked whether it is its own; one that is names a payment of that
 *   method by the gateway's reference for it, and the word is recorded on it and acted on (OnlinePayments::hear()), in
 *   its shop. A webhook no method signs is a 400 and changes nothing; one signed is a 200, whatever it came to — a
 *   payment it names that is not the shop's, or held for support, included —, so the gateway stops sending it.
 * - one whose webhook is not signed and only names a payment (Contracts\NamingWebhook: Rialit): never believed, it only
 *   makes the shop ask the gateway (OnlinePayments::check()), in the payment's shop, with its own method's key — and
 *   only about a payment it names that is one of that driver's methods', still unpaid and not held for support,
 *   NAMED_ASKS times a minute at most, so a burst of webhooks naming forged, settled or held payments asks the gateway
 *   nothing. Whatever the webhook says came of the payment, only the gateway's answer settles it. A body that is not
 *   the gateway's shape is a 400; any of its shape is a 200 — a test, an event about no payment, one the shop does not
 *   know, one it could not ask about now (the shop asks on by itself, Tasks\OnlinePaymentsTask) —, so the gateway stops
 *   sending it.
 * A driver not installed, or none of whose methods takes a webhook (no such method in any shop, or a gateway that is
 * asked and calls back instead), is a 404.
 */
final class GatewayWebhookController extends ApiController
{
    /** What an address no method takes webhooks at gets. */
    public const UNKNOWN = 'هیچ روش پرداختی وب‌هوک این آدرس را نمی‌گیرد.';

    /** What a webhook no method signs gets. */
    public const UNSIGNED = 'امضای این درخواست با هیچ روش پرداختی جور نیست.';

    /** What a body that is not the gateway's webhook gets. */
    public const FOREIGN = 'این درخواست وب‌هوک این درگاه نیست.';

    /** Asks of a payment's gateway per NAMED_WINDOW seconds from webhooks that name it: one past that is heard, not asked on. */
    private const NAMED_ASKS = 6;
    private const NAMED_WINDOW = 60;

    /** Seconds the work may take once the gateway hung up: a delivery talks to a panel. */
    private const TIME_LIMIT = 120;

    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly OnlinePayments $payments,
        private readonly RateLimiter $limiter,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param array{driver: string} $args */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $listening = $this->listening($args['driver']);
        if ($listening === []) {
            return Json::error($response, self::UNKNOWN, 404);
        }
        $body = (string) $request->getBody();
        $headers = array_map(static fn(array $values): string => implode(', ', $values), array_change_key_case($request->getHeaders()));

        // A driver's gateways are alike: what one makes of a body that only names a payment, every one does.
        $naming = $listening[0][1];
        if ($naming instanceof NamingWebhook) {
            return $this->named($args['driver'], $naming, array_map(static fn(array $listener): int => $listener[0]->id, $listening), $body, $headers, $response);
        }

        $signed = false;
        $named = null;
        $heard = [];
        foreach ($listening as [$method, $gateway]) {
            $word = $gateway instanceof SignedWebhook ? $gateway->hear($body, $headers) : null;
            if ($word === null) {
                continue;
            }
            $signed = true;
            // Two methods on one account at the gateway sign alike: the payment is the one of them that has it.
            if ($named === null && $word->reference !== null) {
                $named = self::payment($method, $word->reference);
                $heard = $word->word;
            }
        }
        if (!$signed) {
            $this->logger->warning('A {driver} webhook came whose signature no method checks: a secret mistyped, or not the gateway\'s', ['driver' => $args['driver']]);

            return Json::error($response, self::UNSIGNED, 400);
        }
        if ($named === null) {
            return $this->json($response, ['received' => true]);
        }

        // Held for support (what it says does not fit the payment), or not to be acted on now: kept, said in the log.
        return $this->inShop($named, fn() => $this->payments->hear($named, $heard), 'Payment {id}\'s signed webhook was kept, but not acted on: {detail}', $response);
    }

    /**
     * A webhook that only names a payment (NamingWebhook), by `$gateway`'s reading of it: the unpaid payment of one of
     * `$methods` it names is asked about at its gateway, in its shop, within NAMED_ASKS a minute; nothing else is.
     *
     * @param list<int> $methods
     * @param array<string, string> $headers By lower-case name
     */
    private function named(string $driver, NamingWebhook $gateway, array $methods, string $body, array $headers, Response $response): Response
    {
        try {
            $reference = $gateway->reference($body, $headers);
        } catch (\InvalidArgumentException) {
            $this->logger->warning('A {driver} webhook came that is not the gateway\'s: not its JSON, or headers that do not match its body', ['driver' => $driver]);

            return Json::error($response, self::FOREIGN, 400);
        }
        $payment = $reference === null ? null : self::unpaid($methods, $reference);
        if ($payment === null || $this->limiter->attempt([['online-webhook|' . $payment->id, self::NAMED_ASKS, self::NAMED_WINDOW]]) > 0) {
            return $this->json($response, ['received' => true]);
        }

        // Held for support, or the gateway out of reach: the shop asks on by itself (Tasks\OnlinePaymentsTask).
        return $this->inShop($payment, fn() => $this->payments->check($payment), 'Payment {id}\'s webhook came, but its gateway could not be asked: {detail}', $response);
    }

    /**
     * What the webhook makes the shop do with `$payment` (`$act`), in its shop, the work going on once the gateway hung
     * up: a GatewayUnavailableException it throws said in the log (`$notActed`, of the payment's `{id}` and the
     * exception's `{detail}`). Received, whatever it came to.
     *
     * @param \Closure(): mixed $act
     */
    private function inShop(Payment $payment, \Closure $act, string $notActed, Response $response): Response
    {
        LongRunning::keepGoing(self::TIME_LIMIT);

        return CurrentBot::run($payment->shop(), function () use ($payment, $act, $notActed, $response): Response {
            try {
                $act();
            } catch (GatewayUnavailableException $e) {
                $this->logger->warning($notActed, ['id' => $payment->id, 'detail' => $e->detail]);
            }

            return $this->json($response, ['received' => true]);
        });
    }

    /**
     * The methods of `$driver`, in every shop, whose gateways take its webhooks — signed ones, or ones that name a
     * payment —, each with its gateway; none for a driver not installed.
     *
     * @return list<array{PaymentMethod, SignedWebhook|NamingWebhook}>
     */
    private function listening(string $driver): array
    {
        if ($this->gateways->find($driver) === null) {
            return [];
        }
        $listening = [];
        foreach (CurrentBot::everywhere(static fn() => PaymentMethod::query()->where('driver', $driver)->get()) as $method) {
            $gateway = $this->gateways->forMethod($method);
            if ($gateway instanceof SignedWebhook || $gateway instanceof NamingWebhook) {
                $listening[] = [$method, $gateway];
            }
        }

        return $listening;
    }

    /** The payment of `$method` the gateway named by its reference for it — a page opened on it —; null for none. */
    private static function payment(PaymentMethod $method, string $reference): ?Payment
    {
        return CurrentBot::everywhere(static fn(): ?Payment => Payment::query()
            ->with('method')
            ->where('payment_method_id', $method->id)
            ->where('reference', $reference)
            ->whereNotNull('link')
            ->first());
    }

    /**
     * The payment of one of `$methods` the gateway named by its reference for it — a page opened on it, still unpaid
     * (pending, failed or cancelled: money taken late still settles it) and not held for support —; null for none.
     *
     * @param list<int> $methods
     */
    private static function unpaid(array $methods, string $reference): ?Payment
    {
        return CurrentBot::everywhere(static fn(): ?Payment => Payment::query()
            ->with('method')
            ->whereIn('payment_method_id', $methods)
            ->where('reference', $reference)
            ->whereNotNull('link')
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Failed->value, PaymentStatus::Cancelled->value])
            ->whereNull('invoice->held')
            ->first());
    }
}
