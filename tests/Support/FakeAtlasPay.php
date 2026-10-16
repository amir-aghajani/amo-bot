<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Payments\Drivers\AtlasPay\AtlasPayGateway;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A stand-in for AtlasPay's API (v1), as its merchant guide describes it: POST /orders opens an order — its id, a tracking
 * code, the sum asked (the base and MARKUP Toman, a markup and the last digit that tells transfers apart), a 20-minute
 * deadline and the mini app's link; with `directInfo`, the card in full too —; POST /orders/{id}/verify says its state
 * and `paid`, with `requiresManualDelivery` when the customer paid short and accepted it (payShort()). An order is
 * awaiting_payment until pay() (confirmed, then settle()), review(), reject() or expire(). Its errors are shaped as its
 * API's (`message`, a list of faults for a 400, `error`, `statusCode`): a key but KEY is 401; more than `most`, 400; an
 * order it never made, 404. A `merchantOrderRef` it was given before is a 400 here too — an assumption: the guide asks
 * for a unique one, and says nothing of what a repeat gets. down() takes it out of reach. Records each call (`calls`). A
 * Guzzle handler: TestCase::atlasPay() makes it the outgoing client's transport.
 */
final class FakeAtlasPay
{
    /** The API key it knows. */
    public const KEY = 'atl_live_4f9c2b7e81d04a6c';

    /** What it adds to every order's base: its markup and the last digit. */
    public const MARKUP = 7;

    /** The card it shows when the merchant has its direct payment info. */
    public const CARD = '6037991812345678';

    /** Whether the merchant has AtlasPay's "direct payment info": the card in full in POST /orders' answer. */
    public bool $directInfo = false;

    /** The most Toman an order may be, as its 400 says; null for no most. */
    public ?int $most = null;

    /** @var list<array{method: string, url: string, key: string, body: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<int, array{base: int, total: int, ref: string, tracking: string, status: string, received: ?int, short: bool, request: array<string, mixed>}> By id */
    public array $orders = [];

    private bool $down = false;

    private int $next = 58;

    /** @param array<string, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $url = (string) $request->getUri();
        $body = json_decode((string) $request->getBody(), true);
        $body = is_array($body) ? $body : [];
        $this->calls[] = ['method' => $request->getMethod(), 'url' => $url, 'key' => $request->getHeaderLine('X-API-Key'), 'body' => $body];
        if ($this->down) {
            return new RejectedPromise(new ConnectException('api.atlaspay.space is out of reach in this test.', $request));
        }
        if ($request->getHeaderLine('X-API-Key') !== self::KEY) {
            return self::error(401, 'Unauthorized', 'X-API-Key نامعتبر است');
        }

        if ($request->getMethod() === 'POST' && $url === AtlasPayGateway::ORDERS) {
            return $this->open($body);
        }
        if ($request->getMethod() === 'POST' && preg_match('~^' . preg_quote(AtlasPayGateway::ORDERS, '~') . '/(\d+)/verify$~', $url, $id) === 1) {
            return $this->verify((int) $id[1]);
        }

        throw new \LogicException("FakeAtlasPay has no {$request->getMethod()} {$url}.");
    }

    /** The bank's text came, for the sum asked: confirmed. */
    public function pay(int $id): void
    {
        $this->order($id)['status'] = 'confirmed';
        $this->order($id)['received'] = $this->order($id)['total'];
    }

    /** The customer paid `$received` Toman, less than asked, and accepted it: confirmed all the same, for less. */
    public function payShort(int $id, int $received): void
    {
        $this->pay($id);
        $this->order($id)['received'] = $received;
        $this->order($id)['short'] = true;
    }

    /** A confirmed order's money sent on to the merchant: settled. */
    public function settle(int $id): void
    {
        $this->order($id)['status'] = 'settled';
    }

    /** A receipt AtlasPay would not take. */
    public function reject(int $id): void
    {
        $this->order($id)['status'] = 'rejected';
    }

    /** Its 20 minutes passed with nothing paid. */
    public function expire(int $id): void
    {
        $this->order($id)['status'] = 'expired';
    }

    /** Under way at AtlasPay: a receipt in review, or what remains of an underpayment awaited. */
    public function review(int $id, string $state = 'admin_review'): void
    {
        $this->order($id)['status'] = $state;
    }

    public function down(): void
    {
        $this->down = true;
    }

    /** The only order opened so far — its id. */
    public function onlyOrder(): int
    {
        if (count($this->orders) !== 1) {
            throw new \LogicException(count($this->orders) . ' orders were opened, not one.');
        }

        return (int) array_key_first($this->orders);
    }

    /** @return list<array{method: string, url: string, key: string, body: array<string, mixed>}> The calls whose address ends so */
    public function callsTo(string $end): array
    {
        return array_values(array_filter($this->calls, static fn(array $call): bool => str_ends_with($call['url'], $end)));
    }

    /** @param array<string, mixed> $body */
    private function open(array $body): PromiseInterface
    {
        $base = $body['baseAmountToman'] ?? null;
        $ref = $body['merchantOrderRef'] ?? null;
        if (!is_int($base) || $base < 1000 || !is_string($ref) || $ref === '') {
            return self::error(400, 'Bad Request', ['baseAmountToman must not be less than 1000', 'merchantOrderRef should not be empty']);
        }
        if ($this->most !== null && $base > $this->most) {
            return self::error(400, 'Bad Request', ["baseAmountToman must not be greater than {$this->most}"]);
        }
        if (in_array($ref, array_column($this->orders, 'ref'), true)) {
            return self::error(400, 'Bad Request', 'merchantOrderRef تکراری است');
        }
        if (isset($body['webhookUrl']) && (!is_string($body['webhookUrl']) || !str_starts_with($body['webhookUrl'], 'https://'))) {
            return self::error(400, 'Bad Request', ['webhookUrl must be a URL address starting with https']);
        }
        $id = $this->next++;
        $tracking = bin2hex(random_bytes(8));
        $total = $base + self::MARKUP;
        $this->orders[$id] = ['base' => $base, 'total' => $total, 'ref' => $ref, 'tracking' => $tracking, 'status' => 'awaiting_payment', 'received' => null, 'short' => false, 'request' => $body];

        $answer = [
            'orderId' => $id,
            'trackingCode' => $tracking,
            'totalAmountToman' => $total,
            'cardNumberMasked' => '6037****5678',
            'paymentDeadlineAt' => gmdate('Y-m-d\TH:i:s.000\Z', time() + 20 * 60),
            'customerStartLink' => "https://t.me/atlaspaybot/pay?startapp=order_{$id}_{$tracking}",
        ];
        if ($this->directInfo) {
            $answer += ['cardNumber' => self::CARD, 'cardHolderName' => 'Ali Rezaei', 'bankName' => 'Bank Melli'];
        }

        return self::answer(201, $answer);
    }

    private function verify(int $id): PromiseInterface
    {
        $order = $this->orders[$id] ?? null;
        if ($order === null) {
            return self::error(404, 'Not Found', 'سفارش پیدا نشد');
        }

        return self::answer(200, [
            'success' => true,
            'id' => $id,
            'trackingCode' => $order['tracking'],
            'merchantOrderRef' => $order['ref'],
            'status' => $order['status'],
            'paid' => in_array($order['status'], ['confirmed', 'settled'], true),
            'totalAmountToman' => $order['total'],
            'actualReceivedAmountToman' => $order['received'],
            'requiresManualDelivery' => $order['short'],
            'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z'),
        ]);
    }

    /** @return array{base: int, total: int, ref: string, tracking: string, status: string, received: ?int, short: bool, request: array<string, mixed>} */
    private function &order(int $id): array
    {
        if (!isset($this->orders[$id])) {
            throw new \LogicException("FakeAtlasPay has opened no order {$id}.");
        }

        return $this->orders[$id];
    }

    /** @param string|list<string> $message */
    private static function error(int $status, string $error, string|array $message): PromiseInterface
    {
        return self::answer($status, ['message' => $message, 'error' => $error, 'statusCode' => $status]);
    }

    /** @param array<string, mixed> $body */
    private static function answer(int $status, array $body): PromiseInterface
    {
        return new FulfilledPromise(new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
    }
}
