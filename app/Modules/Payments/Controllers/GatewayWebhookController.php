<?php

declare(strict_types=1);

namespace App\Modules\Payments\Controllers;

use App\Core\Http\ApiController;
use App\Core\Http\Json;
use App\Core\Http\LongRunning;
use App\Modules\Bots\CurrentBot;
use App\Modules\Payments\Contracts\SignedWebhook;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\OnlinePayments;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * POST /payments/webhooks/{driver} — the webhook of a gateway that answers no question and signs its word instead
 * (Contracts\SignedWebhook: Variza), one address for its every payment in every shop, which its owner pastes at the
 * gateway. The signature says whose it is: each method of that driver, in every shop, is asked whether it is its own;
 * one that is names a payment of that method by the gateway's reference for it, and the word is recorded on it and acted
 * on (OnlinePayments::hear()), in its shop. A driver not installed, or none of whose methods takes a signed webhook (no
 * such method in any shop, or a gateway that is asked instead), is a 404; a webhook no method signs is a 400 and changes
 * nothing; one signed is a 200, whatever it came to — a payment it names that is not the shop's, or held for support,
 * included —, so the gateway stops sending it.
 */
final class GatewayWebhookController extends ApiController
{
    /** What an address no method takes webhooks at gets. */
    public const UNKNOWN = 'هیچ روش پرداختی وب‌هوک این آدرس را نمی‌گیرد.';

    /** What a webhook no method signs gets. */
    public const UNSIGNED = 'امضای این درخواست با هیچ روش پرداختی جور نیست.';

    /** Seconds the work may take once the gateway hung up: a delivery talks to a panel. */
    private const TIME_LIMIT = 120;

    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly OnlinePayments $payments,
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

        $signed = false;
        $named = null;
        $heard = [];
        foreach ($listening as [$method, $gateway]) {
            $word = $gateway->hear($body, $headers);
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

        LongRunning::keepGoing(self::TIME_LIMIT);

        return CurrentBot::run($named->shop(), function () use ($named, $heard, $response): Response {
            try {
                $this->payments->hear($named, $heard);
            } catch (GatewayUnavailableException $e) {
                // Held for support (what it says does not fit the payment), or not to be acted on now: kept, said in the log.
                $this->logger->warning('Payment {id}\'s signed webhook was kept, but not acted on: {detail}', ['id' => $named->id, 'detail' => $e->detail]);
            }

            return $this->json($response, ['received' => true]);
        });
    }

    /**
     * The methods of `$driver`, in every shop, whose gateways take signed webhooks, each with its gateway; none for a
     * driver not installed.
     *
     * @return list<array{PaymentMethod, SignedWebhook}>
     */
    private function listening(string $driver): array
    {
        if ($this->gateways->find($driver) === null) {
            return [];
        }
        $listening = [];
        foreach (CurrentBot::everywhere(static fn() => PaymentMethod::query()->where('driver', $driver)->get()) as $method) {
            $gateway = $this->gateways->forMethod($method);
            if ($gateway instanceof SignedWebhook) {
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
}
