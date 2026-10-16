<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Payments\Drivers\Zarinpal\ZarinpalGateway;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A stand-in for Zarinpal's v4 API, live and sandbox alike, as its documentation describes it and its sandbox answers:
 * request.json opens a payment (an authority, "A…" live, "S…" in the sandbox; its fee, the customer's when `payerFee`);
 * inquiry.json says IN_BANK until the customer pays (pay(): PAID) or not (fail(): FAILED), REVERSED once the merchant
 * gave it back (reverse()); verify.json confirms a paid one for the amount asked — 100 the first time, 101 after
 * (VERIFIED), -50 for another amount, -51 for one not paid; an authority it never made is -54 the way its validation
 * errors say it. Live and sandbox are two services: neither knows the other's authorities. Any code but MERCHANT is a
 * -10. down() takes it out of reach. Records each call (`calls`). A Guzzle handler: TestCase::zarinpal() makes it the
 * outgoing client's transport.
 */
final class FakeZarinpal
{
    /** The merchant code it knows. */
    public const MERCHANT = '1344b5d4-0048-11e8-94db-005056a205be';

    /** Zarinpal's fee on every payment, in Rial. */
    public const FEE = 1000;

    /** The bank reference of a verified payment. */
    public const REF_ID = 201;

    /** Whether its merchant has the customer pay Zarinpal's fee. */
    public bool $payerFee = false;

    /** @var list<array{url: string, body: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array{amount: int, state: string, verified: bool, sandbox: bool, request: array<string, mixed>}> By authority */
    public array $payments = [];

    private bool $down = false;

    /** @param array<string, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $url = (string) $request->getUri();
        $body = json_decode((string) $request->getBody(), true);
        $body = is_array($body) ? $body : [];
        $this->calls[] = ['url' => $url, 'body' => $body];
        if ($this->down) {
            return new RejectedPromise(new ConnectException('zarinpal.com is out of reach in this test.', $request));
        }
        if (($body['merchant_id'] ?? null) !== self::MERCHANT) {
            return self::error(401, -10, 'Terminal is not valid, please check merchant_id or ip address.');
        }

        $sandbox = str_starts_with($url, ZarinpalGateway::SANDBOX);

        return match (substr($url, strlen($sandbox ? ZarinpalGateway::SANDBOX : ZarinpalGateway::LIVE))) {
            ZarinpalGateway::REQUEST => $this->request($body, $sandbox),
            ZarinpalGateway::INQUIRY => $this->inquiry((string) ($body['authority'] ?? ''), $sandbox),
            ZarinpalGateway::VERIFY => $this->verify((string) ($body['authority'] ?? ''), $sandbox, $body['amount'] ?? null),
            default => throw new \LogicException("FakeZarinpal has no {$request->getMethod()} {$url}."),
        };
    }

    /** The customer paid on the page. */
    public function pay(string $authority): void
    {
        $this->payment($authority)['state'] = 'PAID';
    }

    /** The customer cancelled, or the bank refused. */
    public function fail(string $authority): void
    {
        $this->payment($authority)['state'] = 'FAILED';
    }

    /** The merchant gave a paid one back to the customer (Zarinpal's reverse). */
    public function reverse(string $authority): void
    {
        $this->payment($authority)['state'] = 'REVERSED';
    }

    public function down(): void
    {
        $this->down = true;
    }

    /** The only payment opened so far — its authority. */
    public function onlyPayment(): string
    {
        if (count($this->payments) !== 1) {
            throw new \LogicException(count($this->payments) . ' payments were opened, not one.');
        }

        return (string) array_key_first($this->payments);
    }

    /** @return list<array{url: string, body: array<string, mixed>}> The calls to one path, live or sandbox */
    public function callsTo(string $path): array
    {
        return array_values(array_filter($this->calls, static fn(array $call): bool => str_ends_with($call['url'], $path)));
    }

    /** @param array<string, mixed> $body */
    private function request(array $body, bool $sandbox): PromiseInterface
    {
        $amount = $body['amount'] ?? null;
        if (!is_int($amount) || $amount < 1000) {
            return self::error(400, -9, 'The amount must be at least 1000.');
        }
        $authority = ($sandbox ? 'S' : 'A') . str_pad(bin2hex(random_bytes(8)), 35, '0', STR_PAD_LEFT);
        $this->payments[$authority] = ['amount' => $amount, 'state' => 'IN_BANK', 'verified' => false, 'sandbox' => $sandbox, 'request' => $body];

        return self::answer(200, ['data' => ['authority' => $authority, 'fee' => self::FEE, 'fee_type' => $this->payerFee ? 'Payer' : 'Merchant', 'code' => 100, 'message' => 'Success'], 'errors' => []]);
    }

    private function inquiry(string $authority, bool $sandbox): PromiseInterface
    {
        $payment = $this->known($authority, $sandbox);

        return $payment === null
            ? self::answer(404, ['message' => 'Invalid authority', 'errors' => ['authority' => ['Invalid authority.', '-54']]])
            : self::answer(200, ['data' => ['status' => $payment['state'], 'code' => 100, 'message' => 'Success'], 'errors' => []]);
    }

    private function verify(string $authority, bool $sandbox, mixed $amount): PromiseInterface
    {
        $payment = $this->known($authority, $sandbox);
        if ($payment === null) {
            return self::error(404, -54, 'Invalid authority.');
        }
        if (!in_array($payment['state'], ['PAID', 'VERIFIED'], true)) {
            return self::error(400, -51, 'Session is not valid, session is not active paid try.');
        }
        if ($amount !== $payment['amount']) {
            return self::error(400, -50, 'Session is not valid, amounts values is not the same.');
        }
        $code = $payment['verified'] ? 101 : 100;
        $this->payments[$authority]['verified'] = true;
        $this->payments[$authority]['state'] = 'VERIFIED';

        return self::answer(200, ['data' => ['code' => $code, 'message' => $code === 100 ? 'Verified' : 'Verified', 'card_hash' => str_repeat('A', 64), 'card_pan' => '502229******5995', 'ref_id' => self::REF_ID, 'fee_type' => 'Merchant', 'fee' => 0], 'errors' => []]);
    }

    /** @return array{amount: int, state: string, verified: bool, sandbox: bool, request: array<string, mixed>}|null The payment one service made */
    private function known(string $authority, bool $sandbox): ?array
    {
        $payment = $this->payments[$authority] ?? null;

        return $payment !== null && $payment['sandbox'] === $sandbox ? $payment : null;
    }

    /** @return array{amount: int, state: string, verified: bool, sandbox: bool, request: array<string, mixed>} */
    private function &payment(string $authority): array
    {
        if (!isset($this->payments[$authority])) {
            throw new \LogicException("FakeZarinpal has opened no payment {$authority}.");
        }

        return $this->payments[$authority];
    }

    private static function error(int $status, int $code, string $message): PromiseInterface
    {
        return self::answer($status, ['data' => [], 'errors' => ['message' => $message, 'code' => $code, 'validations' => []]]);
    }

    /** @param array<string, mixed> $body */
    private static function answer(int $status, array $body): PromiseInterface
    {
        return new FulfilledPromise(new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
    }
}
