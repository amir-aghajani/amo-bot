<?php

declare(strict_types=1);

namespace App\Modules\Payments\Controllers;

use App\Core\Http\ApiController;
use App\Core\Http\LongRunning;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Payments\Deposits\Enums\AttachOutcome;
use App\Modules\Payments\Deposits\Services\Deposits;
use App\Modules\Payments\Deposits\Services\DepositSettings;
use App\Modules\Payments\Deposits\Sources\Rikhtam;
use App\Modules\Payments\Drivers\Rikhtam\RikhtamGateway;
use App\Modules\Payments\Enums\DepositStatus;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\OnlinePayments;
use App\Support\Input;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * POST /webhooks/rikhtam/{bot} — Rikhtam's one address for the shop `{bot}` (rikhtam.com), which the shop registers with
 * it itself (Deposits\Services\RikhtamDeposits): a form, its gateway's «کلید امنیتی» in `key`. A key that is neither the
 * shop's Rikhtam key (Deposits\Services\DepositSettings) nor the secret of one of its Rikhtam crypto methods — or a shop
 * that is none — is a 403: nothing is told before the key is right. Then its panel's health test (`test=1`) is answered
 * and changes nothing; `event=crypto.paid` makes the shop ask Rikhtam about the crypto payment it names, of a method whose
 * key it carries (OnlinePayments::check()), {"ok":true} whatever that came to; anything else is a deposit for «تایید
 * خودکار واریز» (Deposits\Services\Deposits), answered as Rikhtam reads it — 200 `{"ok":true,"invoice":"<payment id>"}`:
 * it paid that payment, now or the first time it came; 404: it paid none (Rikhtam offers it to the account's next
 * gateway, then leaves it to its owner to attach by hand), as for a key only crypto methods have or a shop not listening,
 * neither of which records it. One attached by hand (`invoice` set; Rikhtam adds `manual=1`, which the shop needs not)
 * names its payment: 404 for none of the shop's card payments, 409 for one paid already (or a deposit short of its
 * order's price), 410 for one cancelled or expired. A deposit another request is deciding this moment is a 503, which
 * Rikhtam sends again.
 */
final class RikhtamWebhookController extends ApiController
{
    public const WRONG_KEY = 'کلید امنیتی درست نیست.';
    public const NOT_TAKEN = 'این واریز پرداختی را در این فروشگاه تایید نکرد.';

    /** What a deposit attached by hand came to, as Rikhtam reads it: its status and its words. */
    private const ATTACHED = [
        'no_payment' => [404, 'صورتحسابی با این شماره در این فروشگاه نیست.'],
        'paid' => [409, 'این صورتحساب پیش‌تر پرداخت شده است.'],
        'too_little' => [409, 'مبلغ این واریز کمتر از مبلغ سفارش این صورتحساب است.'],
        'closed' => [410, 'این صورتحساب لغو شده یا مهلتش گذشته است.'],
        'busy' => [503, 'این واریز همین حالا در حال بررسی است؛ کمی بعد دوباره بفرستید.'],
    ];

    public function __construct(
        private readonly DepositSettings $settings,
        private readonly Deposits $deposits,
        private readonly Rikhtam $source,
        private readonly GatewayRegistry $gateways,
        private readonly OnlinePayments $payments,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param array{bot: string} $args */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $bot = CurrentBot::everywhere(static fn(): ?Bot => Bot::query()->find((int) $args['bot']));
        if ($bot === null) {
            return $this->answer($response, 403, self::WRONG_KEY);
        }
        $body = self::form($request);
        $key = is_string($body['key'] ?? null) ? trim($body['key']) : '';

        return CurrentBot::run($bot, function () use ($response, $body, $key): Response {
            $deposits = $this->settings->acceptsRikhtam($key);
            $methods = $this->cryptoMethods($key);
            if (!$deposits && $methods === []) {
                return $this->answer($response, 403, self::WRONG_KEY);
            }
            if (($body['test'] ?? null) === '1') {
                return $this->json($response, ['ok' => true, 'test' => true]);
            }
            if (($body['event'] ?? null) === 'crypto.paid') {
                return $this->cryptoPaid($response, $methods, $body);
            }
            // A deposit is the deposits' key's; and while the shop does not listen, no deposit is taken — but one its owner
            // attaches by hand.
            $attachedTo = Rikhtam::attachedTo($body);
            if (!$deposits || ($attachedTo === null && !$this->settings->enabled())) {
                return $this->answer($response, 404, self::NOT_TAKEN);
            }
            $incoming = $this->source->read($body);

            LongRunning::keepGoing(Deposits::TIME_LIMIT);
            if ($attachedTo !== null) {
                [$outcome, $deposit] = $this->deposits->attach($this->source, $incoming, Input::integerOf(ltrim($attachedTo, '#')) ?? 0);
                if ($outcome->paid()) {
                    return $this->json($response, ['ok' => true, 'invoice' => (string) $deposit->payment_id]);
                }
                [$status, $words] = self::ATTACHED[$outcome->value];

                return $this->answer($response, $status, $words);
            }

            $deposit = $this->deposits->take($this->source, $incoming);

            return match ($deposit->status) {
                DepositStatus::Matched => $this->json($response, ['ok' => true, 'invoice' => (string) $deposit->payment_id]),
                DepositStatus::Received => $this->answer($response, ...self::ATTACHED[AttachOutcome::Busy->value]),
                default => $this->answer($response, 404, self::NOT_TAKEN),
            };
        });
    }

    /**
     * The crypto payment Rikhtam says was paid, named by its invoice (else by its order number) among the payments of the
     * methods the key opens: its gateway asked, and acted on (OnlinePayments::check()). {"ok":true} whatever it came to,
     * so Rikhtam stops sending it.
     *
     * @param list<PaymentMethod> $methods
     * @param array<string, mixed> $body
     */
    private function cryptoPaid(Response $response, array $methods, array $body): Response
    {
        $invoice = is_string($body['invoice_id'] ?? null) ? trim($body['invoice_id']) : '';
        $order = is_string($body['order_id'] ?? null) ? trim($body['order_id']) : '';
        $payment = $methods === [] || ($invoice === '' && $order === '') ? null : Payment::query()
            ->with('method')
            ->whereIn('payment_method_id', array_map(static fn(PaymentMethod $method): int => $method->id, $methods))
            // By its invoice; else by the order number the shop gave it, the field Rikhtam's guide says to go by.
            ->when($invoice !== '', static fn(Builder $named) => $named->where('reference', $invoice), static fn(Builder $named) => $named->where('invoice->order_id', $order))
            ->first();
        if ($payment === null) {
            $this->logger->warning('Rikhtam said crypto invoice {invoice} (order {order}) was paid, but no payment of the shop has it', ['invoice' => mb_substr($invoice, 0, 100), 'order' => mb_substr($order, 0, 100)]);

            return $this->json($response, ['ok' => true]);
        }

        LongRunning::keepGoing(Deposits::TIME_LIMIT);
        try {
            $this->payments->check($payment);
        } catch (GatewayUnavailableException $e) {
            // Held for support, or Rikhtam out of reach: the shop asks on by itself (Tasks\OnlinePaymentsTask).
            $this->logger->warning('Payment {id}\'s Rikhtam word was heard, but not acted on: {detail}', ['id' => $payment->id, 'detail' => $e->detail]);
        }

        return $this->json($response, ['ok' => true]);
    }

    /**
     * The current shop's Rikhtam crypto methods whose secret `$key` is.
     *
     * @return list<PaymentMethod>
     */
    private function cryptoMethods(string $key): array
    {
        if ($key === '' || $this->gateways->find(RikhtamGateway::key()) === null) {
            return [];
        }
        $opened = [];
        foreach (PaymentMethod::query()->where('driver', RikhtamGateway::key())->get() as $method) {
            $gateway = $this->gateways->forMethod($method);
            if ($gateway instanceof RikhtamGateway && $gateway->accepts($key)) {
                $opened[] = $method;
            }
        }

        return $opened;
    }

    /**
     * The form Rikhtam posts: as PHP read it, else read off the body — a number in it (a body sent as JSON) the text a
     * form carries, so `test: 1` is the health test still.
     *
     * @return array<string, mixed>
     */
    private static function form(Request $request): array
    {
        $parsed = $request->getParsedBody();
        if (!is_array($parsed) || $parsed === []) {
            parse_str((string) $request->getBody(), $parsed);
        }

        return array_map(static fn(mixed $value): mixed => is_int($value) ? (string) $value : $value, $parsed);
    }

    private function answer(Response $response, int $status, string $message): Response
    {
        return $this->json($response, ['ok' => false, 'message' => $message], $status);
    }
}
