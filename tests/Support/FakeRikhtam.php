<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Payments\Drivers\Rikhtam\RikhtamApi;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A stand-in for Rikhtam's one address (rikhtam.com/api.php), as its documentation describes it, for one gateway whose
 * key is KEY: `hook` (a form) takes an https address — on `$domain` or under it, when set —; crypto_create makes an
 * invoice (the same order_id again gives back its open one, a paid one `already_paid`), crypto_status says what came of
 * it; bg_pending lists `$pending`, bg_attach asks `$onAttach` what the shop's address answered (as Rikhtam calls it in the
 * middle of the request) and says attached or attach_failed. Another key is 403. The test moves an invoice on (pick(),
 * pay(), expire()) and writes what Rikhtam sends the shop: deposit(), cryptoPaid(), healthTest(). down() takes it out of
 * reach. An invoice's id is a token, or a number (`$numbered`).
 * Records each call. A Guzzle handler: TestCase::rikhtam() makes it the outgoing client's transport.
 */
final class FakeRikhtam
{
    /** The gateway's «کلید امنیتی». */
    public const KEY = 'rk_5e1f0c9a7b3d4e2f8a6c';

    /** The gateway's id at Rikhtam. */
    public const PROJECT = 12;

    /** The domain Rikhtam's panel has for the gateway: an address on it, or under it, is taken; null takes any https one. */
    public ?string $domain = null;

    /** The address `hook` last took. */
    public ?string $hooked = null;

    /** Whether an invoice's id is a number in the answers (its documentation shows none): else a token, `inv_…`. */
    public bool $numbered = false;

    /** @var list<array{a: string, body: array<string, mixed>, timeout: mixed}> */
    public array $calls = [];

    /** @var array<string, array{order_id: string, amount: string, currency: string, coin: string|null, status: string, body: array<string, mixed>, expires?: int}> By invoice_id; `expires` once a coin is chosen */
    public array $invoices = [];

    /** @var list<array{uid: string, amount: int, time: int, account: string}> What bg_pending lists: amounts in Toman */
    public array $pending = [];

    /** @var (\Closure(string $uid, string $invoice): int)|null What the shop's address answered a manual attach, by its HTTP status; null: 200 */
    public ?\Closure $onAttach = null;

    private bool $down = false;

    /** @param array<string, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $raw = (string) $request->getBody();
        $form = str_contains($request->getHeaderLine('Content-Type'), 'application/x-www-form-urlencoded');
        if ($form) {
            parse_str($raw, $body);
        } else {
            $body = json_decode($raw, true);
        }
        $body = is_array($body) ? $body : [];
        $action = is_string($body['a'] ?? null) ? $body['a'] : '';
        $this->calls[] = ['a' => $action, 'body' => $body, 'timeout' => $options['timeout'] ?? null];
        if ($this->down) {
            return new RejectedPromise(new ConnectException('rikhtam.com is out of reach in this test.', $request));
        }
        if ($request->getMethod() !== 'POST' || (string) $request->getUri() !== RikhtamApi::URL) {
            throw new \LogicException("FakeRikhtam has no {$request->getMethod()} {$request->getUri()}.");
        }
        if ($action === 'hook') {
            return $this->hook($body);
        }
        if (($body['secret'] ?? null) !== self::KEY) {
            return self::answer(403, ['ok' => false, 'code' => 'bad_project', 'message' => 'کلید امنیتی درگاه اشتباه است.']);
        }

        return match ($action) {
            'crypto_create' => $this->create($body),
            'crypto_status' => $this->status($body),
            'bg_pending' => self::answer(200, ['ok' => true, 'deposits' => $this->pending]),
            'bg_attach' => $this->attach($body),
            default => throw new \LogicException("FakeRikhtam has no action «{$action}»."),
        };
    }

    public function down(): void
    {
        $this->down = true;
    }

    /** The only crypto invoice made so far — its id. */
    public function onlyInvoice(): string
    {
        if (count($this->invoices) !== 1) {
            throw new \LogicException(count($this->invoices) . ' invoices were made, not one.');
        }

        return (string) array_key_first($this->invoices);
    }

    /** The customer picks `$coin` on the invoice's page: its address given, taking money until `$until` (Unix seconds). */
    public function pick(string $invoiceId, string $coin, int $until): void
    {
        $this->invoices[$invoiceId] = ['coin' => $coin, 'status' => 'wait', 'expires' => $until] + $this->invoices[$invoiceId];
    }

    /** The invoice is paid on the chain. */
    public function pay(string $invoiceId): void
    {
        $this->invoices[$invoiceId]['status'] = 'paid';
    }

    /** The invoice lapsed unpaid. */
    public function expire(string $invoiceId): void
    {
        $this->invoices[$invoiceId]['status'] = 'expired';
    }

    /**
     * A deposit Rikhtam's app sends the shop's address (a form): `$amountRial` in Rial under the uid `$uid`, with `$fields`
     * over it (`invoice` and `manual` for an attach by hand) — signed with `$key`.
     *
     * @param array<string, string> $fields
     * @return array<string, string>
     */
    public function deposit(int $amountRial, string $uid = 'a1b2c3d4', array $fields = [], string $key = self::KEY): array
    {
        $now = time();

        return $fields + [
            'project_id' => (string) self::PROJECT,
            'uid' => $uid,
            'amount' => (string) $amountRial,
            'amount_rial' => (string) $amountRial,
            'account' => '0123456789',
            'sms_time' => (string) $now,
            'time' => gmdate('Y-m-d H:i:s', $now + 12600),
            'ts' => (string) $now,
            'event_id' => '0',
            'key' => $key,
        ];
    }

    /**
     * Rikhtam's word that the crypto invoice was paid (a form, to the gateway's address), signed with `$key`.
     *
     * @return array<string, string>
     */
    public function cryptoPaid(string $invoiceId, string $key = self::KEY): array
    {
        $invoice = $this->invoices[$invoiceId] ?? ['order_id' => '', 'coin' => null];

        return [
            'event' => 'crypto.paid',
            'key' => $key,
            'order_id' => $invoice['order_id'],
            'invoice_id' => $invoiceId,
            'paid_amount' => '3.25',
            'symbol' => 'USDT',
            'network' => 'TRON',
            'coin' => $invoice['coin'] ?? 'usdt_trc20',
            'tx_hash' => bin2hex(random_bytes(16)),
        ];
    }

    /**
     * The panel's «تست سلامت درگاه»: a deposit of 1 Rial with test=1 and a uid starting `test`, signed with `$key`.
     *
     * @return array<string, string>
     */
    public function healthTest(string $key = self::KEY): array
    {
        return $this->deposit(1, 'test' . bin2hex(random_bytes(4)), ['test' => '1'], $key);
    }

    /** @param array<string, mixed> $body */
    private function hook(array $body): PromiseInterface
    {
        if (($body['key'] ?? null) !== self::KEY) {
            return self::answer(403, ['ok' => false, 'error' => 'bad_key', 'message' => 'کلید امنیتی اشتباه است.']);
        }
        $url = is_string($body['url'] ?? null) ? $body['url'] : '';
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (!str_starts_with($url, 'https://') || $host === '' || $host === 'localhost') {
            return self::answer(422, ['ok' => false, 'error' => 'bad_url', 'message' => 'آدرس باید https و روی دامنه عمومی باشد.']);
        }
        if ($this->domain !== null && $host !== $this->domain && !str_ends_with($host, '.' . $this->domain)) {
            return self::answer(422, ['ok' => false, 'error' => 'wrong_domain', 'message' => 'آدرس روی دامنه ثبت‌شده درگاه نیست.']);
        }
        $this->hooked = $url;

        return self::answer(200, ['ok' => true, 'message' => 'آدرس درگاه ثبت شد.', 'project_id' => self::PROJECT, 'url' => $url, 'domain' => $this->domain ?? $host, 'active' => true]);
    }

    /** @param array<string, mixed> $body */
    private function create(array $body): PromiseInterface
    {
        $order = $body['order_id'] ?? null;
        $amount = $body['amount'] ?? null;
        $currency = $body['currency'] ?? 'IRR';
        if (!is_string($order) || $order === '' || (!is_int($amount) && !is_string($amount)) || (int) $amount <= 0 || !in_array($currency, ['IRR', 'IRT', 'USD', 'CRY'], true)) {
            return self::answer(422, ['ok' => false, 'code' => 'bad_request', 'message' => 'شماره سفارش، مبلغ یا ارز درست نیست.']);
        }
        foreach ($this->invoices as $id => $invoice) {
            if ($invoice['order_id'] === $order) {
                if ($invoice['status'] === 'paid') {
                    return self::answer(409, ['ok' => false, 'code' => 'already_paid', 'message' => 'این سفارش پرداخت شده است.']);
                }
                if (in_array($invoice['status'], ['new', 'wait'], true)) {
                    return self::answer(200, $this->present((string) $id));
                }
            }
        }
        $id = $this->numbered ? (string) (count($this->invoices) + 57) : 'inv_' . bin2hex(random_bytes(6));
        $coin = is_string($body['coin'] ?? null) ? $body['coin'] : null;
        $this->invoices[$id] = ['order_id' => $order, 'amount' => (string) $amount, 'currency' => $currency, 'coin' => $coin, 'status' => $coin === null ? 'new' : 'wait', 'body' => $body];

        return self::answer(200, $this->present($id));
    }

    /** @param array<string, mixed> $body */
    private function status(array $body): PromiseInterface
    {
        $id = $body['invoice_id'] ?? null;
        if (!is_string($id) || !isset($this->invoices[$id])) {
            return self::answer(404, ['ok' => false, 'code' => 'not_found', 'message' => 'صورتحساب پیدا نشد.']);
        }

        return self::answer(200, $this->present($id));
    }

    /** @param array<string, mixed> $body */
    private function attach(array $body): PromiseInterface
    {
        $uid = is_string($body['uid'] ?? null) ? $body['uid'] : '';
        $invoice = is_string($body['invoice'] ?? null) ? $body['invoice'] : '';
        $deposit = null;
        foreach ($this->pending as $i => $row) {
            if ($row['uid'] === $uid) {
                $deposit = $i;
            }
        }
        $answered = $deposit === null ? 404 : ($this->onAttach === null ? 200 : ($this->onAttach)($uid, $invoice));
        if ($answered !== 200) {
            return self::answer(409, ['ok' => false, 'code' => 'attach_failed', 'message' => 'واریز پیدا نشد، قبلا تایید شده یا درگاه شما صورتحساب را نپذیرفت.']);
        }
        $amount = $this->pending[$deposit]['amount'];
        array_splice($this->pending, (int) $deposit, 1);

        return self::answer(200, ['ok' => true, 'message' => 'واریز به صورتحساب پیوست شد.', 'invoice' => $invoice, 'fee' => intdiv($amount, 100), 'debt' => intdiv($amount, 100)]);
    }

    /** @return array<string, mixed> An invoice as crypto_create and crypto_status answer it. */
    private function present(string $id): array
    {
        $invoice = $this->invoices[$id];
        $answer = ['ok' => true, 'invoice_id' => $this->numbered ? (int) $id : $id, 'order_id' => $invoice['order_id'], 'pay_url' => "https://rikhtam.com/pay/{$id}", 'status' => $invoice['status']];
        if ($invoice['coin'] !== null) {
            $answer += ['address' => 'TXYZ1234567890abcdefghijklmnopqrs', 'amount' => '3.25', 'comment' => substr($id, 4), 'expires' => $invoice['expires'] ?? time() + 1800];
        }

        return $answer;
    }

    /** @param array<string, mixed> $body */
    private static function answer(int $status, array $body): PromiseInterface
    {
        return new FulfilledPromise(new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
    }
}
