<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Payments\Drivers\AqayePardakht\AqayePardakhtGateway;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A stand-in for AqayePardakht's API v2, as its documentation describes it and its sandbox answers: create makes a
 * transaction in Toman (a callback off the gateway's verified domain, DOMAIN, is -15; an amount out of 1,000 to
 * 400,000,000 Toman -5) — an id of a few letters, as its sandbox gives —; verify says "0" (with status "error") until the
 * customer pays (pay()) or after they gave up (fail()), "1" the first time a paid one is verified, "2" after (status
 * "error" too, as its table has it), "-10" for another amount, "-8" for an id it never made, "-9" for another gateway's
 * pin. Every answer but a success is HTTP 422. A pin but PIN or `sandbox` is -6. down() takes it out of reach. Records
 * each call. A Guzzle handler: TestCase::aqayePardakht() makes it the outgoing client's transport.
 */
final class FakeAqayePardakht
{
    /** The live gateway's pin it knows. */
    public const PIN = 'a1b2c3d4e5f6a7b8c9d0';

    /** The domain the gateway is verified for. */
    public const DOMAIN = 'shop.example';

    /** @var list<array{path: string, body: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array{pin: string, amount: int, state: string, body: array<string, mixed>}> By id */
    public array $transactions = [];

    private bool $down = false;

    /** @param array<string, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $path = $request->getUri()->getPath();
        $body = json_decode((string) $request->getBody(), true);
        $body = is_array($body) ? $body : [];
        $this->calls[] = ['path' => $path, 'body' => $body];
        if ($this->down) {
            return new RejectedPromise(new ConnectException('panel.aqayepardakht.ir is out of reach in this test.', $request));
        }
        $pin = $body['pin'] ?? null;
        if (!is_string($pin) || $pin === '') {
            return self::error('-2');
        }
        if ($pin !== self::PIN && $pin !== AqayePardakhtGateway::SANDBOX_PIN) {
            return self::error('-6');
        }
        $base = (string) parse_url(AqayePardakhtGateway::API, PHP_URL_PATH);

        return match ($path) {
            $base . '/create' => $this->create($pin, $body),
            $base . '/verify' => $this->verify($pin, $body),
            default => throw new \LogicException("FakeAqayePardakht has no {$request->getMethod()} {$path}."),
        };
    }

    /** The customer paid on the bank's page. */
    public function pay(string $id): void
    {
        $this->transaction($id)['state'] = 'paid';
    }

    /** The customer gave up, or the bank refused. */
    public function fail(string $id): void
    {
        $this->transaction($id)['state'] = 'failed';
    }

    public function down(): void
    {
        $this->down = true;
    }

    /** The only transaction made so far — its id. */
    public function onlyTransaction(): string
    {
        if (count($this->transactions) !== 1) {
            throw new \LogicException(count($this->transactions) . ' transactions were made, not one.');
        }

        return (string) array_key_first($this->transactions);
    }

    /** @return list<array{path: string, body: array<string, mixed>}> The calls whose path ends so */
    public function callsTo(string $end): array
    {
        return array_values(array_filter($this->calls, static fn(array $call): bool => str_ends_with($call['path'], $end)));
    }

    /** @param array<string, mixed> $body */
    private function create(string $pin, array $body): PromiseInterface
    {
        $amount = $body['amount'] ?? null;
        $callback = $body['callback'] ?? null;
        if (!is_int($amount)) {
            return self::error($amount === null ? '-1' : '-4');
        }
        if ($amount < 1000 || $amount > 400_000_000) {
            return self::error('-5');
        }
        if (!is_string($callback) || $callback === '') {
            return self::error('-3');
        }
        if ($pin !== AqayePardakhtGateway::SANDBOX_PIN && parse_url($callback, PHP_URL_HOST) !== self::DOMAIN) {
            return self::error('-15');
        }
        $id = strtoupper(substr(bin2hex(random_bytes(4)), 0, 5));
        $this->transactions[$id] = ['pin' => $pin, 'amount' => $amount, 'state' => 'new', 'body' => $body];

        return self::answer(200, ['status' => 'success', 'transid' => $id]);
    }

    /** @param array<string, mixed> $body */
    private function verify(string $pin, array $body): PromiseInterface
    {
        $transaction = $this->transactions[(string) ($body['transid'] ?? '')] ?? null;
        if ($transaction === null) {
            return self::error(($body['transid'] ?? '') === '' ? '-7' : '-8');
        }
        if ($transaction['pin'] !== $pin) {
            return self::error('-9');
        }
        if (($body['amount'] ?? null) !== $transaction['amount']) {
            return self::error('-10');
        }

        return match ($transaction['state']) {
            'paid' => $this->verified((string) $body['transid']),
            'verified' => self::error('2'),
            default => self::error('0'),
        };
    }

    private function verified(string $id): PromiseInterface
    {
        $this->transactions[$id]['state'] = 'verified';

        return self::answer(200, ['status' => 'success', 'code' => '1']);
    }

    /** @return array{pin: string, amount: int, state: string, body: array<string, mixed>} */
    private function &transaction(string $id): array
    {
        if (!isset($this->transactions[$id])) {
            throw new \LogicException("FakeAqayePardakht has made no transaction {$id}.");
        }

        return $this->transactions[$id];
    }

    private static function error(string $code): PromiseInterface
    {
        return self::answer(422, ['status' => 'error', 'code' => $code]);
    }

    /** @param array<string, mixed> $body */
    private static function answer(int $status, array $body): PromiseInterface
    {
        return new FulfilledPromise(new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
    }
}
