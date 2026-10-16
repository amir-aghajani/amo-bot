<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Payments\Drivers\Aban\AbanGateway;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A stand-in for AbanGateway's API (v1), as its documentation and OpenAPI file describe it: POST /invoices opens an
 * invoice in Rial — the same order id again is the first invoice, as it stands —, its sum to pay a few Rial more (10 for
 * the first, 20 for the next…), the merchant's card, its page and deadline; GET /invoices/{id} says its state — pending
 * until pay() (paid), payPart() (partially_paid), expire() or cancel(); POST …/verify claims a paid one once (200, then
 * 409 already_verified), 402 not_yet_paid before, 410 once expired. Errors are shaped as its API's: `{error: {code,
 * message}}`, and `{detail}` for a key it does not know (401). KEY is live, TEST_KEY its test mode, each seeing only its
 * own invoices. `feeWallet` false is the fee wallet empty (402). down() takes it out of reach. Records each call
 * (`calls`). A Guzzle handler: TestCase::aban() makes it the outgoing client's transport.
 */
final class FakeAban
{
    /** The live key it knows. */
    public const KEY = 'ag_live_q2xw7t4p8zya6b5c2d7e9f3g4h5j6k7m8n9p2q3w5x6y7r4s';

    /** The test key it knows. */
    public const TEST_KEY = 'ag_test_z9y8x7w6v5t4s3r2q9p8n7m6k5j4h3g2f9e8d7c6b5a4z3y2';

    /** The merchant's card it shows. */
    public const CARD = '6037991234567890';

    /** Whether the fee wallet has room for another invoice's fee. */
    public bool $feeWallet = true;

    /** @var list<array{method: string, url: string, key: string, body: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array{amount: int, payable: int, order: ?string, status: string, verified: bool, test: bool, request: array<string, mixed>}> By id */
    public array $invoices = [];

    private bool $down = false;

    /** @param array<string, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $url = (string) $request->getUri();
        $body = json_decode((string) $request->getBody(), true);
        $body = is_array($body) ? $body : [];
        $authorization = $request->getHeaderLine('Authorization');
        $key = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';
        $this->calls[] = ['method' => $request->getMethod(), 'url' => $url, 'key' => $key, 'body' => $body];
        if ($this->down) {
            return new RejectedPromise(new ConnectException('api.abangateway.ir is out of reach in this test.', $request));
        }
        if ($key === '') {
            return self::answer(401, ['detail' => 'Missing bearer token']);
        }
        if ($key !== self::KEY && $key !== self::TEST_KEY) {
            return self::answer(401, ['detail' => 'Invalid bearer token']);
        }
        $test = $key === self::TEST_KEY;

        if ($request->getMethod() === 'POST' && $url === AbanGateway::INVOICES) {
            return $this->open($body, $test);
        }
        if (preg_match('~^' . preg_quote(AbanGateway::INVOICES, '~') . '/([A-Za-z0-9_]+)(/verify)?$~', $url, $path) === 1) {
            $invoice = $this->invoices[$path[1]] ?? null;
            if ($invoice === null || $invoice['test'] !== $test) {
                return self::error(404, 'invoice_not_found', 'Invoice not found');
            }

            return match ([$request->getMethod(), $path[2] ?? '']) {
                ['GET', ''] => self::answer(200, $this->describe($path[1])),
                ['POST', '/verify'] => $this->verify($path[1]),
                default => throw new \LogicException("FakeAban has no {$request->getMethod()} {$url}."),
            };
        }

        throw new \LogicException("FakeAban has no {$request->getMethod()} {$url}.");
    }

    /** The bank's text came, for the sum to pay: paid. */
    public function pay(string $id): void
    {
        $this->invoice($id)['status'] = 'paid';
    }

    /** One part of a split invoice came. */
    public function payPart(string $id): void
    {
        $this->invoice($id)['status'] = 'partially_paid';
    }

    public function expire(string $id): void
    {
        $this->invoice($id)['status'] = 'expired';
    }

    public function cancel(string $id): void
    {
        $this->invoice($id)['status'] = 'cancelled';
    }

    public function down(): void
    {
        $this->down = true;
    }

    /** The only invoice opened so far — its id. */
    public function onlyInvoice(): string
    {
        if (count($this->invoices) !== 1) {
            throw new \LogicException(count($this->invoices) . ' invoices were opened, not one.');
        }

        return (string) array_key_first($this->invoices);
    }

    /** @return list<array{method: string, url: string, key: string, body: array<string, mixed>}> The calls whose address ends so */
    public function callsTo(string $end): array
    {
        return array_values(array_filter($this->calls, static fn(array $call): bool => str_ends_with($call['url'], $end)));
    }

    /** @param array<string, mixed> $body */
    private function open(array $body, bool $test): PromiseInterface
    {
        $amount = $body['amount_rial'] ?? null;
        if (!is_int($amount) || $amount <= 0) {
            return self::error(422, 'invalid_request', 'amount_rial: Input should be greater than 0', ['fields' => ['amount_rial' => 'greater_than']]);
        }
        foreach (['callback_url', 'return_url'] as $address) {
            if (isset($body[$address]) && (!is_string($body[$address]) || !str_starts_with($body[$address], 'https://'))) {
                return self::error(422, 'unsafe_callback_url', 'The callback URL is not reachable from the public internet');
            }
        }
        $order = is_string($body['order_id'] ?? null) ? $body['order_id'] : null;
        foreach ($this->invoices as $id => $invoice) {
            if ($order !== null && $invoice['order'] === $order) {
                return self::answer(201, $this->describe($id));
            }
        }
        if (!$this->feeWallet) {
            return self::error(402, 'insufficient_fee_wallet', 'Fee wallet balance is not enough to open this invoice', ['required_rial' => 40000, 'available_rial' => 0]);
        }
        $id = 'inv_' . bin2hex(random_bytes(8));
        $this->invoices[$id] = ['amount' => $amount, 'payable' => $amount + 10 * (count($this->invoices) + 1), 'order' => $order, 'status' => 'pending', 'verified' => false, 'test' => $test, 'request' => $body];

        return self::answer(201, $this->describe($id));
    }

    private function verify(string $id): PromiseInterface
    {
        $invoice = $this->invoices[$id];
        if ($invoice['status'] === 'expired') {
            return self::error(410, 'invoice_expired', 'Invoice expired before payment');
        }
        if ($invoice['status'] !== 'paid') {
            return self::error(402, 'not_yet_paid', 'No payment has been recorded for this invoice yet');
        }
        if ($invoice['verified']) {
            return self::error(409, 'already_verified', 'This invoice has already been verified');
        }
        $this->invoices[$id]['verified'] = true;

        return self::answer(200, ['verified' => true, 'invoice_id' => $id, 'order_id' => $invoice['order'], 'amount_rial' => $invoice['amount'], 'paid_at' => gmdate('Y-m-d\TH:i:sP')]);
    }

    /** @return array<string, mixed> The invoice as its API answers it */
    private function describe(string $id): array
    {
        $invoice = $this->invoices[$id];
        $minutes = is_int($invoice['request']['expiry_minutes'] ?? null) ? $invoice['request']['expiry_minutes'] : 60;

        return [
            'invoice_id' => $id,
            'status' => $invoice['status'],
            'amount_rial' => $invoice['amount'],
            'payable_rial' => $invoice['payable'],
            'payable_toman' => intdiv($invoice['payable'], 10),
            'fee_rial' => 40000,
            'order_id' => $invoice['order'],
            'card_number' => self::CARD,
            'card_holder' => 'سارا رضایی',
            'card_last4' => substr(self::CARD, -4),
            'bank' => 'ملی',
            'iban' => null,
            'account_number' => null,
            'payment_url' => "https://abangateway.ir/pay/{$id}",
            'expires_at' => gmdate('Y-m-d\TH:i:sP', time() + $minutes * 60),
            'paid_at' => $invoice['status'] === 'paid' ? gmdate('Y-m-d\TH:i:sP') : null,
            'is_test' => $invoice['test'],
        ];
    }

    /** @return array{amount: int, payable: int, order: ?string, status: string, verified: bool, test: bool, request: array<string, mixed>} */
    private function &invoice(string $id): array
    {
        if (!isset($this->invoices[$id])) {
            throw new \LogicException("FakeAban has opened no invoice {$id}.");
        }

        return $this->invoices[$id];
    }

    /** @param array<string, mixed> $details */
    private static function error(int $status, string $code, string $message, array $details = []): PromiseInterface
    {
        return self::answer($status, ['error' => ['code' => $code, 'message' => $message] + ($details === [] ? [] : ['details' => $details])]);
    }

    /** @param array<string, mixed> $body */
    private static function answer(int $status, array $body): PromiseInterface
    {
        return new FulfilledPromise(new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
    }
}
