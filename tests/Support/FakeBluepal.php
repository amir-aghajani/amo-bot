<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Payments\Drivers\Bluepal\BluepalGateway;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A stand-in for Bluepal's API v1, as its developer page describes it: payments/request requests a payment in Toman for
 * the merchant's key as a Bearer token — result 100, a track id ("TRK-…") and its page on bluepal.ir; 102 for a missing
 * merchant id or amount, 105 for a merchant id it knows none active of (MERCHANT is) —; verify/{track_id} says 202
 * (failed, nothing taken) until the customer pays (pay()), 100 with status `success` and the amount paid the first time
 * a paid one is verified, 201 after; 404 for a track id it never gave. Another key is a 401 (Bluepal documents no answer
 * for it). `$loose` answers as a young API may: results as text, track ids as bare digits in a number. answerNext()
 * gives the next call an answer of the test's; down() takes it out of reach. Records each call. A Guzzle handler:
 * TestCase::bluepal() makes it the outgoing client's transport.
 */
final class FakeBluepal
{
    /** The merchant's API key it knows. */
    public const KEY = 'sk_live_7c41e9a2b8d3f605';

    /** The active merchant id it knows. */
    public const MERCHANT = 'MRC-58219';

    /** Results as text and track ids as bare digits in a number. */
    public bool $loose = false;

    /** @var list<array{method: string, path: string, authorization: string, body: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array{amount: int, paid: ?int, state: string, body: array<string, mixed>}> By track id */
    public array $payments = [];

    /** @var list<array{int, array<string, mixed>}> */
    private array $next = [];

    private bool $down = false;

    /** @param array<string, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $body = json_decode((string) $request->getBody(), true);
        $body = is_array($body) ? $body : [];
        $authorization = $request->getHeaderLine('Authorization');
        $this->calls[] = ['method' => $method, 'path' => $path, 'authorization' => $authorization, 'body' => $body];
        if ($this->down) {
            return new RejectedPromise(new ConnectException('bluepal.ir is out of reach in this test.', $request));
        }
        if ($this->next !== []) {
            [$status, $answer] = array_shift($this->next);

            return self::answer($status, $answer);
        }
        if ($authorization !== 'Bearer ' . self::KEY) {
            return self::answer(401, ['message' => 'Unauthorized']);
        }
        $base = (string) parse_url(BluepalGateway::API, PHP_URL_PATH);

        return match (true) {
            $method === 'POST' && $path === $base . BluepalGateway::REQUEST => $this->request($body),
            $method === 'GET' && str_starts_with($path, $base . BluepalGateway::VERIFY) => $this->verify(rawurldecode(substr($path, strlen($base . BluepalGateway::VERIFY)))),
            default => throw new \LogicException("FakeBluepal has no {$method} {$path}."),
        };
    }

    /** The customer paid on the bank's page: the payment's amount, or `$amount` Toman. */
    public function pay(string $track, ?int $amount = null): void
    {
        if (!isset($this->payments[$track])) {
            throw new \LogicException("FakeBluepal has given no track id {$track}.");
        }
        $this->payments[$track]['state'] = 'paid';
        $this->payments[$track]['paid'] = $amount ?? $this->payments[$track]['amount'];
    }

    /**
     * The next call's answer, whatever it asks.
     *
     * @param array<string, mixed> $body
     */
    public function answerNext(int $status, array $body): void
    {
        $this->next[] = [$status, $body];
    }

    public function down(): void
    {
        $this->down = true;
    }

    /** The only payment requested so far — its track id. */
    public function onlyPayment(): string
    {
        if (count($this->payments) !== 1) {
            throw new \LogicException(count($this->payments) . ' payments were requested, not one.');
        }

        return (string) array_key_first($this->payments);
    }

    /** @return list<array{method: string, path: string, authorization: string, body: array<string, mixed>}> The calls whose path has `$part` */
    public function callsTo(string $part): array
    {
        return array_values(array_filter($this->calls, static fn(array $call): bool => str_contains($call['path'], $part)));
    }

    /** @param array<string, mixed> $body */
    private function request(array $body): PromiseInterface
    {
        $merchant = $body['merchant_id'] ?? null;
        $amount = $body['amount'] ?? null;
        if (!is_string($merchant) || $merchant === '' || !is_int($amount)) {
            return $this->result(400, 102, ['message' => 'merchant_id and amount are required']);
        }
        if ($merchant !== self::MERCHANT) {
            return $this->result(403, 105, ['message' => 'merchant is not active']);
        }
        $digits = (string) random_int(1_000_000_000, 9_999_999_999);
        $track = $this->loose ? $digits : "TRK-{$digits}";
        $this->payments[$track] = ['amount' => $amount, 'paid' => null, 'state' => 'new', 'body' => $body];

        return $this->result(200, 100, [
            'track_id' => $this->loose ? (int) $digits : $track,
            'payment_url' => "https://bluepal.ir/pay/{$track}",
        ]);
    }

    private function verify(string $track): PromiseInterface
    {
        $payment = $this->payments[$track] ?? null;
        if ($payment === null) {
            return $this->result(404, 404, ['message' => 'transaction not found']);
        }

        return match ($payment['state']) {
            'paid' => $this->verified($track, 100),
            'verified' => $this->verified($track, 201),
            default => $this->result(200, 202, ['status' => 'failed', 'message' => 'payment failed', 'track_id' => $track]),
        };
    }

    private function verified(string $track, int $code): PromiseInterface
    {
        $this->payments[$track]['state'] = 'verified';

        return $this->result(200, $code, [
            'status' => 'success',
            'message' => $code === 100 ? 'payment verified' : 'already verified',
            'track_id' => $track,
            'amount' => $this->payments[$track]['paid'],
        ]);
    }

    /** @param array<string, mixed> $fields */
    private function result(int $status, int $code, array $fields): PromiseInterface
    {
        return self::answer($status, ['result' => $this->loose ? (string) $code : $code] + $fields);
    }

    /** @param array<string, mixed> $body */
    private static function answer(int $status, array $body): PromiseInterface
    {
        return new FulfilledPromise(new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
    }
}
