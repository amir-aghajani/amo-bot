<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Payments\Drivers\CubePay\CubePayGateway;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Psr\Http\Message\RequestInterface;

/**
 * A stand-in for CubePay's unified router, as its documentation describes it: create-order.php opens an order (in Toman)
 * as `kind` says — a card payment (an authority, the card to show, a few Toman added to the sum) by default, a crypto
 * one or a choice of the two —, and gives an order opened again while unpaid the same page; verify-payment.php answers a
 * card payment's authority (402 unpaid, 200 the first time it is paid then 409, 410 once closed);
 * check-order-status.php answers any other by its order id. pay() and close() are the customer paying and the page's
 * time running out; refuse() makes the next opening a refusal; down() takes it out of reach. Any call without TOKEN is a
 * 401. Records each call (`calls`). A Guzzle handler: TestCase::cubePay() makes it the outgoing client's transport.
 */
final class FakeCubePay
{
    /** The merchant's API token it knows. */
    public const TOKEN = 'cp-test-token-1234';

    /** The card it shows for a card payment. */
    public const CARD = '6219861900412221';

    /** The Toman it adds to a card payment's sum. */
    public const OFFSET = 37;

    /** What CubePay makes of the next orders: card, crypto or choice. */
    public string $kind = 'card';

    /** Whether the merchant asked CubePay to show the card in the bot. */
    public bool $showCard = false;

    /** @var list<array{method: string, url: string, body: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array{order_id: string, kind: string, authority: string|null, price: int, state: string, verified: bool, request: array<string, mixed>}> By order id */
    public array $orders = [];

    /** @var array{int, string}|null The next opening's refusal: its status and message */
    private ?array $refusal = null;

    private bool $down = false;

    /** @param array<string, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $url = (string) $request->getUri();
        parse_str($request->getUri()->getQuery(), $query);
        $body = json_decode((string) $request->getBody(), true);
        $body = is_array($body) ? $body : [];
        $this->calls[] = ['method' => $request->getMethod(), 'url' => $url, 'body' => $body + $query];
        if ($this->down) {
            return new RejectedPromise(new ConnectException('cubevps.ir is out of reach in this test.', $request));
        }
        if ($request->getHeaderLine('Authorization') !== 'Bearer ' . self::TOKEN) {
            return self::answer(401, ['success' => false, 'message' => 'توکن ارسال نشده یا نامعتبر است.']);
        }

        $endpoint = $request->getMethod() . ' ' . strtok($url, '?');

        return match ($endpoint) {
            'POST ' . CubePayGateway::CREATE => $this->create($body),
            'POST ' . CubePayGateway::VERIFY => $this->verify((string) ($body['authority'] ?? '')),
            'GET ' . CubePayGateway::STATUS => $this->status((string) ($query['order_id'] ?? '')),
            default => throw new \LogicException("FakeCubePay has no {$endpoint}."),
        };
    }

    /** The customer paid the order (by its id at CubePay, or a card payment's authority). */
    public function pay(string $order): void
    {
        $this->order($order)['state'] = 'paid';
    }

    /** The order's page closed unpaid. */
    public function close(string $order): void
    {
        $this->order($order)['state'] = 'expired';
    }

    /** The next opening is refused with that HTTP status and message. */
    public function refuse(int $status, string $message): void
    {
        $this->refusal = [$status, $message];
    }

    public function down(): void
    {
        $this->down = true;
    }

    /** The only order opened so far — its id at CubePay. */
    public function onlyOrder(): string
    {
        if (count($this->orders) !== 1) {
            throw new \LogicException(count($this->orders) . ' orders were opened, not one.');
        }

        return (string) array_key_first($this->orders);
    }

    /** @return list<array{method: string, url: string, body: array<string, mixed>}> The calls to one endpoint */
    public function callsTo(string $endpoint): array
    {
        return array_values(array_filter($this->calls, static fn(array $call): bool => strtok($call['url'], '?') === $endpoint));
    }

    /** @param array<string, mixed> $body */
    private function create(array $body): PromiseInterface
    {
        if ($this->refusal !== null) {
            [$status, $message] = $this->refusal;
            $this->refusal = null;

            return self::answer($status, ['success' => false, 'message' => $message]);
        }
        $id = (string) ($body['order_id'] ?? '');
        $price = $body['price_amount'] ?? null;
        if ($id === '' || !is_int($price) || $price < 100) {
            return self::answer(400, ['success' => false, 'message' => 'order_id یا price_amount ارسال نشده است.']);
        }
        $open = $this->orders[$id] ?? null;
        if ($open === null || $open['state'] !== 'pending') {
            $open = $this->orders[$id] = [
                'order_id' => $id,
                'kind' => $this->kind,
                'authority' => $this->kind === 'card' ? bin2hex(random_bytes(25)) : null,
                'price' => $price,
                'state' => 'pending',
                'verified' => false,
                'request' => $body,
            ];
        }

        $answer = ['success' => true, 'method' => $open['kind']];
        if ($open['kind'] === 'card') {
            return self::answer(200, $answer + [
                'pay_page_url' => 'https://cubevps.ir/smspay/pay.php?authority=' . $open['authority'],
                'authority' => $open['authority'],
                'card' => ['number' => self::CARD, 'holder' => 'CubePay Holder', 'sheba' => null],
                'pay_amount_toman' => $open['price'] + self::OFFSET,
                'expires_at' => Carbon::now()->addMinutes(30)->utc()->toIso8601String(),
                'expires_in_minutes' => 30,
                'show_card_in_bot' => $this->showCard,
            ]);
        }

        return self::answer(200, $answer + [
            'pay_page_url' => ($open['kind'] === 'crypto' ? 'https://cubevps.ir/crypto/pay.php?token=' : 'https://cubevps.ir/pay/choose.php?token=') . sha1($id),
        ]);
    }

    private function verify(string $authority): PromiseInterface
    {
        foreach ($this->orders as $id => $order) {
            if ($order['authority'] === $authority) {
                return match ($order['state']) {
                    'pending' => self::answer(402, ['success' => false, 'status' => 'pending', 'message' => 'هنوز پرداختی ثبت نشده است.']),
                    'expired' => self::answer(410, ['success' => false, 'status' => 'expired', 'message' => 'مهلت تراکنش تمام شده است.']),
                    default => $this->verified($id),
                };
            }
        }

        return self::answer(404, ['success' => false, 'message' => 'چنین تراکنشی یافت نشد.']);
    }

    /** A paid card payment: yes the first time, then "verified once already". */
    private function verified(string $id): PromiseInterface
    {
        if ($this->orders[$id]['verified']) {
            return self::answer(409, ['success' => false, 'status' => 'verified', 'message' => 'این تراکنش قبلا تایید شده است.']);
        }
        $this->orders[$id]['verified'] = true;

        return self::answer(200, ['success' => true, 'message' => 'پرداخت تایید شد.', 'order_id' => $id, 'amount' => $this->orders[$id]['price'] * 10, 'status' => 'verified']);
    }

    private function status(string $id): PromiseInterface
    {
        $order = $this->orders[$id] ?? null;
        if ($order === null || $order['kind'] === 'card') {
            return self::answer(404, ['success' => false, 'message' => 'سفارشی با این order_id پیدا نشد.']);
        }

        return self::answer(200, ['success' => true] + match ($order['state']) {
            'pending' => $order['kind'] === 'choice' ? ['status' => 'choosing_method'] : ['method' => 'crypto', 'status' => 'waiting'],
            'expired' => ['method' => 'crypto', 'status' => 'expired'],
            default => $order['kind'] === 'choice' ? ['method' => 'card', 'status' => 'verified'] : ['method' => 'crypto', 'status' => 'finished'],
        });
    }

    /** @return array{order_id: string, kind: string, authority: string|null, price: int, state: string, verified: bool, request: array<string, mixed>} */
    private function &order(string $key): array
    {
        foreach ($this->orders as $id => &$order) {
            if ($id === $key || $order['authority'] === $key) {
                return $order;
            }
        }

        throw new \LogicException("FakeCubePay has opened no order {$key}.");
    }

    /** @param array<string, mixed> $body */
    private static function answer(int $status, array $body): PromiseInterface
    {
        return new FulfilledPromise(new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
    }
}
