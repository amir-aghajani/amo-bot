<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\Rialit;

use App\Modules\Payments\Contracts\NamingWebhook;
use App\Modules\Payments\Contracts\RegistersWebhook;
use App\Modules\Payments\Contracts\TelegramPayer;
use App\Modules\Payments\Drivers\GatewayHttp;
use App\Modules\Payments\Drivers\OnlinePage;
use App\Modules\Payments\DTO\OnlineInvoice;
use App\Modules\Payments\DTO\OnlineRequest;
use App\Modules\Payments\DTO\OnlineVerdict;
use App\Modules\Payments\DTO\PaymentInitiation;
use App\Modules\Payments\Enums\GatewayRefusal;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Models\Payment;
use App\Support\Money;
use GuzzleHttp\ClientInterface;

/**
 * A Rialit method's gateway, on its API (v1, the merchant's key as a Bearer token — as Rialit's own OpenAPI description,
 * docs.rialit.io/openapi.yaml, has it): a payment is sent in Toman (send) for the shop's order id — the same id again is
 * the same payment, another sum on it a 409 — and the Telegram account that pays it (Rialit takes nobody else: a
 * customer with none is not offered it, TelegramPayer, and refused before Rialit is called), and paid on Rialit's page
 * (pay.rialit.io/v1/<token>, open 30 minutes). Rialit takes no callback address: the merchant's account has one webhook
 * address for every payment (Controllers\GatewayWebhookController), whose word is never believed — it carries no
 * signature, and only names a payment by its token (reference()) for the shop to ask about. The address is set in
 * Rialit's dashboard, or by the shop through its API (webhook(), hook(): each change a security notice to the account's
 * owner, 20 an hour at most). What came of a payment is status's word: paid — for the shop's own order and sum, which
 * the answer must say, held otherwise —, pending while the page takes money and closed after (Rialit may keep one
 * pending past its page), expired or cancelled closed; Rialit's support may still approve one late, which settles it
 * all the same. The fee the merchant passes on to the payer in Rialit's dashboard is Rialit's to add: the payer pays
 * `payer_amount`, the order its own sum and `payer_fee` above it, never credited. Its details kept on the payment
 * (`payments.invoice`): the token, the order id and the Toman it was sent for, and what the payer pays and of it the
 * fee. The token is the payment's reference.
 */
final class RialitGateway implements NamingWebhook, RegistersWebhook, TelegramPayer
{
    use OnlinePage;

    public const API = 'https://api.rialit.io/api/v1';
    public const PAGE = 'https://pay.rialit.io/v1/';

    /** A payment's token at Rialit. */
    private const TOKEN = '/^[A-Za-z0-9]{1,64}$/D';

    /** Minutes a payment's page takes money: Rialit's link is open for 30. */
    private const PAGE_MINUTES = 30;

    /** A Telegram account's id, as Rialit takes a payer's: a positive JSON number. */
    private const TELEGRAM_ID = '/^[1-9]\d{0,18}$/D';

    /** status's words: paid, not yet, and over unpaid. */
    private const PAID = 'paid';
    private const PENDING = 'pending';
    private const OVER = ['expired', 'cancelled'];

    /** Its webhook's events about a payment, each `payment.<what>`: data.id names it. */
    private const PAYMENT_EVENT = 'payment.';

    public function __construct(
        private readonly string $apiKey,
        private readonly ClientInterface $http,
    ) {}

    /** The key a Rialit method's row is stored under (payment_methods.driver): its driver's. */
    public static function key(): string
    {
        return 'rialit';
    }

    /** Its page and the sum the payer pays there — the order's and the fee the merchant passes on —, as open() kept them. */
    public function initiate(Payment $payment): PaymentInitiation
    {
        $invoice = $payment->invoice ?? [];
        $asked = is_int($invoice['payer_amount'] ?? null) && $invoice['payer_amount'] > 0 ? Money::normalize($invoice['payer_amount']) : null;
        $fee = is_int($invoice['payer_fee'] ?? null) && $invoice['payer_fee'] > 0 ? Money::normalize($invoice['payer_fee']) : null;

        return $this->page($payment, $asked, null, $fee);
    }

    public function open(Payment $payment, OnlineRequest $request): OnlineInvoice
    {
        if ($request->telegramId === null || preg_match(self::TELEGRAM_ID, $request->telegramId) !== 1) {
            // Rialit's payer is a Telegram account: one signed in by SMS has none to give it, and no other id may stand in.
            throw new GatewayUnavailableException("Rialit takes only a payer with a Telegram account, and payment #{$payment->id}'s customer has none: they can pay another way");
        }
        [$status, $answer] = $this->call('POST', '/send', ['json' => [
            'amount' => $request->amount,
            'order_id' => $request->orderId,
            'payer_id' => (int) $request->telegramId,
        ]]);
        if ($status !== 200 && $status !== 201) {
            throw new GatewayUnavailableException(self::refusal('would not make a payment', $status, $answer));
        }
        $said = self::fields($answer);
        $token = $said['token'] ?? null;
        $amount = array_key_exists('amount', $said) ? GatewayHttp::whole($said['amount']) : $request->amount;
        $payerAmount = GatewayHttp::whole($said['payer_amount'] ?? null);
        $payerFee = GatewayHttp::whole($said['payer_fee'] ?? null);
        // A payer's sum below the order's would have it paid for less than its price, and settled all the same.
        if (!is_string($token) || preg_match(self::TOKEN, $token) !== 1 || $amount !== $request->amount
            || $payerAmount === null || $payerAmount < $request->amount || $payerFee !== $payerAmount - $request->amount) {
            throw new GatewayUnavailableException(self::refusal('made a payment it did not describe', $status, $answer));
        }

        return new OnlineInvoice(self::PAGE . $token, OnlineInvoice::until($said['expires_at'] ?? null, self::PAGE_MINUTES), $token, [
            'token' => $token,
            'order_id' => $request->orderId,
            'amount' => $request->amount,
            // What the payer pays, and of it the fee the merchant passes on: never credited (the order's sum is).
            'payer_amount' => $payerAmount,
            'payer_fee' => $payerFee,
        ]);
    }

    public function check(Payment $payment): OnlineVerdict
    {
        $invoice = $payment->invoice ?? [];
        $token = $invoice['token'] ?? null;
        $orderId = $invoice['order_id'] ?? null;
        $amount = $invoice['amount'] ?? null;
        if (!is_string($token) || preg_match(self::TOKEN, $token) !== 1 || !is_string($orderId) || !is_int($amount)) {
            throw new GatewayUnavailableException("Payment #{$payment->id} keeps no Rialit token, order id and amount.");
        }

        [$status, $answer] = $this->call('GET', '/status/' . $token);
        $said = self::fields($answer);
        $state = $status === 200 && is_string($said['status'] ?? null) ? strtolower(trim($said['status'])) : null;

        return match (true) {
            $state === self::PAID => self::paid($payment, $token, $orderId, $amount, $said, $answer),
            $state === self::PENDING => self::whilePageOpen($payment),
            in_array($state, self::OVER, true) => OnlineVerdict::closed(),
            default => throw new GatewayUnavailableException(self::refusal($status === 200 ? "said payment {$token} is in a state the shop does not know" : "did not say what came of payment {$token}", $status, $answer)),
        };
    }

    /**
     * The token a Rialit webhook names: its body a JSON object of an event (`type`) and its delivery's id (`event_id`),
     * which its X-Rialit-Event and X-Rialit-Delivery headers say again; a payment's event (`payment.paid`, `.expired`,
     * `.cancelled`) names its payment by `data.id`. Anything else of Rialit's — its dashboard's `webhook.test`, an event
     * about no payment — names none.
     */
    public function reference(string $body, array $headers): ?string
    {
        $event = json_decode($body, true);
        $type = is_array($event) ? ($event['type'] ?? null) : null;
        $delivery = is_array($event) ? ($event['event_id'] ?? null) : null;
        if (!is_string($type) || $type === '' || !is_string($delivery) || $delivery === ''
            || ($headers['x-rialit-event'] ?? null) !== $type || ($headers['x-rialit-delivery'] ?? null) !== $delivery) {
            throw new \InvalidArgumentException('Not a Rialit webhook: no JSON event, or headers that do not say its event and delivery.');
        }
        $token = is_array($event['data'] ?? null) ? ($event['data']['id'] ?? null) : null;

        return str_starts_with($type, self::PAYMENT_EVENT) && is_string($token) && preg_match(self::TOKEN, $token) === 1 ? $token : null;
    }

    /** The merchant account's webhook address, as GET /webhook says it (`webhook_url`): null — or blank — for webhooks off. */
    public function webhook(): ?string
    {
        [$status, $answer] = $this->call('GET', '/webhook');
        if ($status !== 200) {
            throw self::refused('would not say its webhook address', $status, $answer);
        }

        return self::address('did not say its webhook address', $answer);
    }

    /**
     * The merchant account's webhook address set to `$url` (POST /webhook) — Rialit tells the account's owner of the
     * change on Telegram and by email, and takes 20 security changes an hour, its dashboard's among them —: the address
     * its answer says after.
     */
    public function hook(string $url): ?string
    {
        [$status, $answer] = $this->call('POST', '/webhook', ['json' => ['webhook_url' => $url]]);
        if ($status !== 200) {
            throw self::refused('would not change its webhook address', $status, $answer, change: true);
        }

        return self::address('changed its webhook address but did not say to what', $answer);
    }

    /**
     * Paid, when Rialit's answer says the payment's own order id and sum; held for support otherwise — an answer that does
     * not say them is not this payment's either.
     *
     * @param array<string, mixed> $said
     * @param array<string, mixed> $answer
     */
    private static function paid(Payment $payment, string $token, string $orderId, int $amount, array $said, array $answer): OnlineVerdict
    {
        if (($said['order_id'] ?? null) !== $orderId || GatewayHttp::whole($said['amount'] ?? null) !== $amount) {
            throw new GatewayUnavailableException(self::refusal(sprintf('said payment %s was paid for order %s and %s Toman, not payment #%d\'s %s and %d', $token, json_encode($said['order_id'] ?? null), json_encode($said['amount'] ?? null), $payment->id, $orderId, $amount), 200, $answer), held: true);
        }

        return OnlineVerdict::paid($token);
    }

    /**
     * One call to Rialit with the merchant's key (Drivers\GatewayHttp): its HTTP status and its JSON object.
     *
     * @param array<string, mixed> $options
     * @return array{int, array<string, mixed>}
     * @throws GatewayUnavailableException out of reach, or no key to call with
     */
    private function call(string $method, string $path, array $options = []): array
    {
        if ($this->apiKey === '') {
            throw new GatewayUnavailableException('The Rialit method has no API key (or it no longer decrypts): enter it again in the payment method', refusal: GatewayRefusal::Key);
        }

        return GatewayHttp::call($this->http, 'Rialit', $method, self::API . $path, $options, ['Authorization' => 'Bearer ' . $this->apiKey]);
    }

    /**
     * Rialit's answer's fields, at its top level or under `data` — `data`'s first.
     *
     * @param array<string, mixed> $answer
     * @return array<string, mixed>
     */
    private static function fields(array $answer): array
    {
        $data = $answer['data'] ?? null;

        return is_array($data) && $data !== [] && !array_is_list($data) ? $data + $answer : $answer;
    }

    /**
     * The address a webhook call's answer says (`webhook_url`, at its top level or under `data`): null — or blank — for
     * none.
     *
     * @param array<string, mixed> $answer
     * @throws GatewayUnavailableException an answer that does not say one
     */
    private static function address(string $what, array $answer): ?string
    {
        $said = self::fields($answer);
        $url = array_key_exists('webhook_url', $said) ? $said['webhook_url'] : false;
        if ($url !== null && !is_string($url)) {
            throw new GatewayUnavailableException(self::refusal($what, 200, $answer), refusal: GatewayRefusal::Trouble);
        }

        return $url === null || trim($url) === '' ? null : trim($url);
    }

    /**
     * Rialit's refusal of a webhook call, its log line (refusal()) and why (GatewayRefusal): the key, the IP, the account,
     * the limit — a change's 20 an hour —, the address (`$change`'s 400), or Rialit's own trouble.
     *
     * @param array<string, mixed> $answer
     */
    private static function refused(string $what, int $status, array $answer, bool $change = false): GatewayUnavailableException
    {
        $why = match (true) {
            $status === 401 => GatewayRefusal::Key,
            $status === 403 && self::ipNotAllowed($answer) => GatewayRefusal::Ip,
            $status === 403 => GatewayRefusal::Blocked,
            $status === 429 => GatewayRefusal::Limited,
            $change && $status === 400 => GatewayRefusal::Invalid,
            default => GatewayRefusal::Trouble,
        };

        return new GatewayUnavailableException(self::refusal($what, $status, $answer, $change), refusal: $why);
    }

    /**
     * Whether a 403's words say the shop server's IP is not among the allowed IPs (another 403 is the account's).
     *
     * @param array<string, mixed> $answer
     */
    private static function ipNotAllowed(array $answer): bool
    {
        return str_contains(strtolower(GatewayHttp::said($answer['error'] ?? null) . ' ' . GatewayHttp::said($answer['message'] ?? null)), 'ip not allowed');
    }

    /**
     * A log line of what Rialit said: the HTTP status, its words (`error`, and `message` when it says more) cut short, and
     * what the status means for the merchant — a webhook address's change (`$change`) its own 400 and 429.
     *
     * @param array<string, mixed> $answer
     */
    private static function refusal(string $what, int $status, array $answer, bool $change = false): string
    {
        $said = implode(': ', array_unique(array_filter([GatewayHttp::said($answer['error'] ?? null), GatewayHttp::said($answer['message'] ?? null)])));
        $meaning = match (true) {
            $status === 401 => ' — the API key is wrong or was revoked: make a new one in Rialit\'s dashboard and set it in the payment method',
            $status === 403 && self::ipNotAllowed($answer) => ' — the shop server\'s outgoing IP is not on the allowed IPs in Rialit\'s dashboard: add it there, or empty the list',
            $status === 403 => ' — the merchant account at Rialit is not active',
            $status === 404 => ' — Rialit knows no such payment: was the method\'s key changed to another merchant\'s?',
            $status === 409 => ' — this order id was sent to Rialit before with another sum',
            $change && $status === 400 => ' — Rialit would not take the shop\'s address: it takes an https address on a public host only',
            $status === 400, $status === 422 => ' — Rialit would not take the request: an amount outside its 50,000 to 10,000,000 Toman, maybe',
            $change && $status === 429 => ' — Rialit takes 20 security changes an hour at most, its dashboard\'s among them: try again later',
            $status === 429 => ' — too many requests just now',
            $status >= 500 => ' — Rialit is in trouble just now: the shop asks again later',
            default => '',
        };

        return "Rialit {$what} (HTTP {$status})" . ($said === '' ? '' : ": {$said}") . $meaning;
    }
}
