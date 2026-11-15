<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Payments\Drivers\Rialit\RialitGateway;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A stand-in for Rialit's API (v1), as its OpenAPI file describes it: POST /send makes a payment of exactly {amount (Toman,
 * 50,000 to 10,000,000), order_id, payer_id} — 201 with its token, the amount, and what the payer pays with the fee the
 * merchant passes on (`feePercent`: grossed up and rounded up to 50 Toman, as Rialit's dashboard does); the same order_id
 * again gives back its payment, another sum on it is a 409 —; GET /status/{token} says its state, pending until pay(),
 * expire() or cancel() — pay() after expire() is Rialit's late approval. Its answers are at the top level, as Rialit's
 * are; with `wrapped`, under `data`, which the shop reads as well. Its refusals carry `error`: a key but KEY is 401, a
 * server off its allowed IPs (`ipAllowed`) 403 "IP not allowed", a merchant not active 403, `limited` 429, and
 * `failing` the 5xx it names. Its webhook, which webhook() writes with its headers for the test to send, is not signed.
 * The merchant account's one webhook address (`webhookUrl`, null for webhooks off): GET /webhook says it, POST /webhook
 * {webhook_url} sets it — a public https address of 2,000 characters at most, or null; anything else a 400 — and tells the
 * account's owner on Telegram and by email (`notices`), 20 security changes an hour at most (`changes`, its dashboard's
 * among them: a 429 past that). down() takes it out of reach. Records each call (`calls`). A Guzzle handler:
 * TestCase::rialit() makes it the outgoing client's transport.
 */
final class FakeRialit
{
    /** The merchant's API key it knows. */
    public const KEY = 'rlt_live_7c1e9a4b2d8f6e3a';

    /** Its online limits, in Toman. */
    public const LEAST = 50_000;
    public const MOST = 10_000_000;

    /** The security changes it takes in an hour. */
    public const CHANGES_AN_HOUR = 20;

    /** Where it sends the merchant account's webhooks; null for nowhere. */
    public ?string $webhookUrl = null;

    /** The security changes made in the last hour, the dashboard's too: a test sets it to stand for those. */
    public int $changes = 0;

    /** The security notices it sent the account's owner (one a change). */
    public int $notices = 0;

    /** The percent fee the merchant passes on to the payer in Rialit's dashboard; 0 for none. */
    public int $feePercent = 0;

    /** Whether the shop's server is on the merchant's allowed IPs (or the merchant set none). */
    public bool $ipAllowed = true;

    /** Whether the merchant's account is active. */
    public bool $active = true;

    /** Whether it is rate limiting the merchant just now. */
    public bool $limited = false;

    /** A 5xx it answers everything with; null for none. */
    public ?int $failing = null;

    /** Whether its answers come under `data`. */
    public bool $wrapped = false;

    /** @var list<array{method: string, path: string, authorization: string, body: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array{amount: int, payer_amount: int, payer_fee: int, order_id: string, payer_id: int, status: string, request: array<string, mixed>}> By token */
    public array $payments = [];

    private bool $down = false;

    /** @param array<string, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $path = $request->getUri()->getPath();
        $body = json_decode((string) $request->getBody(), true);
        $body = is_array($body) ? $body : [];
        $this->calls[] = ['method' => $request->getMethod(), 'path' => $path, 'authorization' => $request->getHeaderLine('Authorization'), 'body' => $body];
        if ($this->down) {
            return new RejectedPromise(new ConnectException('api.rialit.io is out of reach in this test.', $request));
        }
        $refused = match (true) {
            $request->getHeaderLine('Authorization') !== 'Bearer ' . self::KEY => [401, 'Invalid or revoked API key'],
            !$this->ipAllowed => [403, 'IP not allowed'],
            !$this->active => [403, 'Merchant is not active'],
            $this->limited => [429, 'Too many requests'],
            $this->failing !== null => [$this->failing, 'Internal error'],
            default => null,
        };
        if ($refused !== null) {
            return self::answer($refused[0], ['error' => $refused[1]]);
        }

        $base = (string) parse_url(RialitGateway::API, PHP_URL_PATH);
        if ($request->getMethod() === 'POST' && $path === $base . '/send') {
            return $this->send($body);
        }
        if ($request->getMethod() === 'GET' && preg_match('~^' . preg_quote($base, '~') . '/status/([^/]+)$~', $path, $token) === 1) {
            return $this->status($token[1]);
        }
        if ($request->getMethod() === 'GET' && $path === $base . '/webhook') {
            return $this->said(200, ['webhook_url' => $this->webhookUrl]);
        }
        if ($request->getMethod() === 'POST' && $path === $base . '/webhook') {
            return $this->hook($body);
        }

        throw new \LogicException("FakeRialit has no {$request->getMethod()} {$path}.");
    }

    /** @return list<array{method: string, path: string, authorization: string, body: array<string, mixed>}> The webhook calls made, GET and POST */
    public function webhookCalls(?string $method = null): array
    {
        return array_values(array_filter($this->calls, static fn(array $call): bool => str_ends_with($call['path'], '/webhook') && ($method === null || $call['method'] === $method)));
    }

    /** The payer paid: paid — late too, after it expired. */
    public function pay(string $token): void
    {
        $this->payment($token)['status'] = 'paid';
    }

    /** Its page's time passed with nothing paid. */
    public function expire(string $token): void
    {
        $this->payment($token)['status'] = 'expired';
    }

    /** The payer cancelled it on Rialit's page. */
    public function cancel(string $token): void
    {
        $this->payment($token)['status'] = 'cancelled';
    }

    public function down(): void
    {
        $this->down = true;
    }

    /** The only payment made so far — its token. */
    public function onlyPayment(): string
    {
        if (count($this->payments) !== 1) {
            throw new \LogicException(count($this->payments) . ' payments were made, not one.');
        }

        return (string) array_key_first($this->payments);
    }

    /** @return list<array{method: string, path: string, authorization: string, body: array<string, mixed>}> The status calls made */
    public function statusCalls(): array
    {
        return array_values(array_filter($this->calls, static fn(array $call): bool => str_contains($call['path'], '/status/')));
    }

    /**
     * Rialit's webhook of `$type` about `$token` (its `data.id`), as it posts it: its body, unsigned, and its headers —
     * X-Rialit-Event and X-Rialit-Delivery saying its type and event id again —, unless `$fields` says otherwise.
     *
     * @param array<string, mixed> $fields
     * @return array{body: string, headers: array<string, string>}
     */
    public function webhook(string $type, ?string $token = null, array $fields = []): array
    {
        $event = $fields + [
            'event_id' => sprintf('%08x-%04x-4%03x-a%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff)),
            'type' => $type,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'data' => $token === null ? [] : ['id' => $token, 'status' => substr($type, strlen('payment.')), 'amount' => $this->payments[$token]['amount'] ?? 0],
        ];

        return ['body' => json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'headers' => [
            'Content-Type' => 'application/json',
            'X-Rialit-Event' => (string) $event['type'],
            'X-Rialit-Delivery' => (string) $event['event_id'],
        ]];
    }

    /** @param array<string, mixed> $body */
    private function send(array $body): PromiseInterface
    {
        if (array_keys($body) !== ['amount', 'order_id', 'payer_id'] || !is_int($body['amount']) || !is_string($body['order_id']) || $body['order_id'] === '' || !is_int($body['payer_id'])) {
            return self::answer(400, ['error' => 'Invalid request body']);
        }
        if ($body['amount'] < self::LEAST || $body['amount'] > self::MOST) {
            return self::answer(400, ['error' => 'Amount out of range', 'message' => 'amount must be between 50000 and 10000000']);
        }
        foreach ($this->payments as $token => $payment) {
            if ($payment['order_id'] === $body['order_id']) {
                return $payment['request'] === $body ? $this->made($token, 200) : self::answer(409, ['error' => 'order_id already used with different data']);
            }
        }
        $token = bin2hex(random_bytes(12));
        $payerAmount = $this->payerAmount($body['amount']);
        $this->payments[$token] = [
            'amount' => $body['amount'],
            'payer_amount' => $payerAmount,
            'payer_fee' => $payerAmount - $body['amount'],
            'order_id' => $body['order_id'],
            'payer_id' => $body['payer_id'],
            'status' => 'pending',
            'request' => $body,
        ];

        return $this->made($token, 201);
    }

    /** What the payer pays on `$amount` Toman: the amount grossed up by the fee, rounded up to 50 Toman — the amount itself for no fee. */
    private function payerAmount(int $amount): int
    {
        if ($this->feePercent === 0) {
            return $amount;
        }
        $gross = $amount * 100 / (100 - $this->feePercent);

        return (int) (ceil($gross / 50) * 50);
    }

    /**
     * POST /webhook: exactly {webhook_url}, a public https address or null; past its changes an hour, a 429.
     *
     * @param array<string, mixed> $body
     */
    private function hook(array $body): PromiseInterface
    {
        $url = $body['webhook_url'] ?? null;
        if (array_keys($body) !== ['webhook_url'] || ($url !== null && !self::public($url))) {
            return self::answer(400, ['error' => 'webhook_url must be a public https URL (max 2000 chars) or null']);
        }
        if ($this->changes >= self::CHANGES_AN_HOUR) {
            return self::answer(429, ['error' => 'Too many security changes in the last hour']);
        }
        $this->webhookUrl = $url;
        $this->changes++;
        $this->notices++;

        return $this->said(200, ['webhook_url' => $this->webhookUrl]);
    }

    /** Whether `$url` is an https address on a public host, as Rialit takes one. */
    private static function public(mixed $url): bool
    {
        if (!is_string($url) || strlen($url) > 2000 || !str_starts_with($url, 'https://')) {
            return false;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host !== '' && $host !== 'localhost' && !str_ends_with($host, '.local') && !str_ends_with($host, '.test')
            && (filter_var($host, FILTER_VALIDATE_IP) === false || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false);
    }

    private function made(string $token, int $status): PromiseInterface
    {
        $payment = $this->payments[$token];

        return $this->said($status, ['token' => $token, 'status' => $payment['status'], 'amount' => $payment['amount'], 'payer_amount' => $payment['payer_amount'], 'payer_fee' => $payment['payer_fee']]);
    }

    private function status(string $token): PromiseInterface
    {
        $payment = $this->payments[$token] ?? null;
        if ($payment === null) {
            return self::answer(404, ['error' => 'Payment not found']);
        }

        return $this->said(200, [
            'token' => $token,
            'status' => $payment['status'],
            'amount' => $payment['amount'],
            'order_id' => $payment['order_id'],
            'payer_id' => $payment['payer_id'],
            'payer_amount' => $payment['payer_amount'],
            'payer_fee' => $payment['payer_fee'],
        ]);
    }

    /** @return array{amount: int, payer_amount: int, payer_fee: int, order_id: string, payer_id: int, status: string, request: array<string, mixed>} */
    private function &payment(string $token): array
    {
        if (!isset($this->payments[$token])) {
            throw new \LogicException("FakeRialit has made no payment {$token}.");
        }

        return $this->payments[$token];
    }

    /** @param array<string, mixed> $fields */
    private function said(int $status, array $fields): PromiseInterface
    {
        return self::answer($status, $this->wrapped ? ['success' => true, 'data' => $fields] : $fields);
    }

    /** @param array<string, mixed> $body */
    private static function answer(int $status, array $body): PromiseInterface
    {
        return new FulfilledPromise(new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
    }
}
