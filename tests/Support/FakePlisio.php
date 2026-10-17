<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Payments\Drivers\Plisio\PlisioGateway;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A stand-in for Plisio's API (v1), as its documentation describes it: every call a GET with the secret key in its query;
 * invoices/new opens an invoice in a fiat currency (the same order number again, with return_existing, is that invoice
 * again); operations/<id> says its state — new until seen() (pending), pay() (completed), overpay() (mismatch),
 * expire() or cancel() —, with its order's number, sum and incoming money only for a white-label shop (`whiteLabel`), as
 * Plisio answers; operations?search=… lists the invoices of an order. switchCoin() is the customer picking another coin:
 * another invoice of the same order, and the first "cancelled duplicate" once that one is paid. Errors are shaped as its
 * API's: `{status: "error", data: {name, message, code}}`. A key but KEY is 101; less than `least` dollars, 105. down()
 * takes it out of reach — its message carrying the request's address, key included, as cURL's does. Records each call.
 */
final class FakePlisio
{
    /** The secret key it knows. */
    public const KEY = 'pl_sk_7Hq2Xw9Lm4Rt8Vb3Nc6Zd1Fg5Jk0Ps';

    /** Whether the merchant's shop has Plisio's white-label: its operations answered in full. */
    public bool $whiteLabel = false;

    /** The fewest dollars an invoice may be for (a coin's network minimum), as its 105 says. */
    public string $least = '0.10';

    /** @var list<array{path: string, query: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array{order: string, usd: string, currency: string, status: string, money: bool, parent: ?string, query: array<string, mixed>}> By id */
    public array $invoices = [];

    private bool $down = false;

    /** @param array<string, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        parse_str($request->getUri()->getQuery(), $query);
        $path = $request->getUri()->getPath();
        $this->calls[] = ['path' => $path, 'query' => $query];
        if ($this->down) {
            return new RejectedPromise(new ConnectException('cURL error 28: Operation timed out for ' . $request->getUri(), $request));
        }
        if ($request->getMethod() !== 'GET') {
            throw new \LogicException("FakePlisio takes GET only, not {$request->getMethod()}.");
        }
        if (($query['api_key'] ?? null) !== self::KEY) {
            return self::error(401, 'Unauthorized', 'Please check your secret key is correct and domain is verified', 101);
        }
        $base = (string) parse_url(PlisioGateway::API, PHP_URL_PATH);

        return match (true) {
            $path === $base . '/invoices/new' => $this->open($query),
            $path === $base . '/operations' => $this->search($query),
            preg_match('~^' . preg_quote($base, '~') . '/operations/([A-Za-z0-9]+)$~', $path, $id) === 1 => $this->operation($id[1]),
            default => throw new \LogicException("FakePlisio has no GET {$path}."),
        };
    }

    /** The customer's coin reached the chain, awaiting its confirmations. */
    public function seen(string $id): void
    {
        $this->invoice($id)['status'] = 'pending';
        $this->invoice($id)['money'] = true;
    }

    /** Paid in full, confirmed — and the order's other invoices cancelled as duplicates. */
    public function pay(string $id): void
    {
        $this->settle($id, 'completed');
    }

    /** Paid more than asked. */
    public function overpay(string $id): void
    {
        $this->settle($id, 'mismatch');
    }

    /** Its time over — with money on it (paid short, or late) or none. */
    public function expire(string $id, bool $withMoney = false): void
    {
        $this->invoice($id)['status'] = 'expired';
        $this->invoice($id)['money'] = $withMoney;
    }

    /** No payment within its hours. */
    public function cancel(string $id): void
    {
        $this->invoice($id)['status'] = 'cancelled';
    }

    /** The customer picked another coin on its page: another invoice of the same order. Its id. */
    public function switchCoin(string $id): string
    {
        $first = $this->invoice($id);
        $other = bin2hex(random_bytes(12));
        $this->invoices[$other] = ['parent' => $id, 'currency' => 'TRX', 'status' => 'new', 'money' => false] + $first;

        return $other;
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

    /** @return list<array{path: string, query: array<string, mixed>}> The calls whose path ends so */
    public function callsTo(string $end): array
    {
        return array_values(array_filter($this->calls, static fn(array $call): bool => str_ends_with($call['path'], $end)));
    }

    /** @param array<string, mixed> $query */
    private function open(array $query): PromiseInterface
    {
        foreach (['order_number', 'order_name'] as $required) {
            if (!is_string($query[$required] ?? null) || $query[$required] === '') {
                return self::error(400, 'Bad Request', 'Missing required attribute: {"name":"' . $required . '"}', 103);
            }
        }
        $usd = $query['source_amount'] ?? null;
        if (($query['source_currency'] ?? null) === null || !is_numeric($usd)) {
            return self::error(422, 'Unprocessable Entity', 'Amount or source_amount is required', 103);
        }
        if (bccomp((string) $usd, $this->least, 8) < 0) {
            return self::error(422, 'Unprocessable Entity', 'Amount is less than minimum', 105);
        }
        foreach ($this->invoices as $id => $invoice) {
            if ($invoice['order'] === $query['order_number']) {
                return ($query['return_existing'] ?? null) === 'true'
                    ? self::answer(200, ['status' => 'success', 'data' => $this->created($id)])
                    : self::error(422, 'Unprocessable Entity', 'Invoice with this order_number already exists', 106);
            }
        }
        $id = bin2hex(random_bytes(12));
        $this->invoices[$id] = ['order' => (string) $query['order_number'], 'usd' => (string) $usd, 'currency' => (string) $query['source_currency'], 'status' => 'new', 'money' => false, 'parent' => null, 'query' => $query];

        return self::answer(201, ['status' => 'success', 'data' => $this->created($id)]);
    }

    /** @return array<string, mixed> invoices/new's data: the id and page, and a white-label shop's coin details */
    private function created(string $id): array
    {
        $invoice = $this->invoices[$id];
        $data = ['txn_id' => $id, 'invoice_url' => "https://plisio.net/invoice/{$id}", 'invoice_total_sum' => '0.00000000'];
        if ($this->whiteLabel) {
            $minutes = (int) ($invoice['query']['expire_min'] ?? 60);
            $data += ['status' => $invoice['status'], 'source_currency' => $invoice['currency'], 'psys_cid' => 'USDT_TRX', 'currency' => 'USDT', 'amount' => $invoice['usd'], 'expire_utc' => time() + $minutes * 60, 'wallet_hash' => 'TQn9Y2khEsLJW1ChVWFMSMeRDow5KcbLSE'];
        }

        return $data;
    }

    private function operation(string $id): PromiseInterface
    {
        return isset($this->invoices[$id])
            ? self::answer(200, ['status' => 'success', 'data' => $this->describe($id)])
            : self::error(404, 'Not Found', 'The specified resource does not exist', 111);
    }

    /** @param array<string, mixed> $query */
    private function search(array $query): PromiseInterface
    {
        $text = (string) ($query['search'] ?? '');
        $found = array_keys(array_filter($this->invoices, static fn(array $invoice): bool => $text !== '' && str_contains($invoice['order'], $text)));

        return self::answer(200, ['status' => 'success', 'data' => ['operations' => array_map($this->describe(...), $found), '_meta' => ['totalCount' => count($found)]]]);
    }

    /** @return array<string, mixed> An invoice as operations answer it: in full for a white-label shop, its basics else */
    private function describe(string $id): array
    {
        $invoice = $this->invoices[$id];
        $basics = ['user_id' => 1, 'shop_id' => 'shop1', 'type' => 'invoice', 'status' => $invoice['status'], 'tx_url' => $invoice['money'] ? ['https://tronscan.org/#/transaction/' . md5($id)] : [], 'id' => $id];
        if (!$this->whiteLabel) {
            return $basics;
        }

        return $basics + [
            'source_currency' => $invoice['currency'],
            'actual_sum' => $invoice['money'] ? '5.00000000' : '0.00000000',
            'parent_id' => $invoice['parent'],
            'params' => ['order_number' => $invoice['order'], 'order_name' => 'سفارش', 'source_amount' => $invoice['usd'], 'source_currency' => $invoice['currency']],
        ];
    }

    private function settle(string $id, string $status): void
    {
        $this->invoice($id)['status'] = $status;
        $this->invoice($id)['money'] = true;
        $order = $this->invoices[$id]['order'];
        foreach ($this->invoices as $other => $invoice) {
            if ($other !== $id && $invoice['order'] === $order) {
                $this->invoices[$other]['status'] = 'cancelled duplicate';
            }
        }
    }

    /** @return array{order: string, usd: string, currency: string, status: string, money: bool, parent: ?string, query: array<string, mixed>} */
    private function &invoice(string $id): array
    {
        if (!isset($this->invoices[$id])) {
            throw new \LogicException("FakePlisio has opened no invoice {$id}.");
        }

        return $this->invoices[$id];
    }

    private static function error(int $status, string $name, string $message, int $code): PromiseInterface
    {
        return self::answer($status, ['status' => 'error', 'data' => ['name' => $name, 'message' => $message, 'code' => $code]]);
    }

    /** @param array<string, mixed> $body */
    private static function answer(int $status, array $body): PromiseInterface
    {
        return new FulfilledPromise(new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
    }
}
