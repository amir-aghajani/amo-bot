<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Payments\Drivers\Variza\VarizaGateway;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A stand-in for Variza's API v1, as its documentation describes it: pay makes a payment link in Toman (201, a slug and
 * its page on variza.ir) for the merchant's key as a Bearer token — another key is 401, an amount under 1,000 Toman or
 * no return_url 422 —; it answers no other question. Its word on a payment is its webhook, which webhook() writes and
 * signs as Variza does (HMAC-SHA256 of the raw body with the webhook secret, `sha256=<hex>`), for the test to send. down()
 * takes it out of reach. Records each call. A Guzzle handler: TestCase::variza() makes it the outgoing client's transport.
 */
final class FakeVariza
{
    /** The merchant's API key it knows. */
    public const KEY = 'vz_live_9f8e7d6c5b4a3f2e1d0c';

    /** The secret it signs the merchant's webhooks with. */
    public const SECRET = 'whsec_1a2b3c4d5e6f';

    /** The few Toman its page asks above a link's sum, to tell its payments apart. */
    public const ABOVE = 128;

    /** @var list<array{path: string, authorization: string, body: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array{amount: int, body: array<string, mixed>}> By slug */
    public array $links = [];

    private bool $down = false;

    /** @param array<string, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $path = $request->getUri()->getPath();
        $body = json_decode((string) $request->getBody(), true);
        $body = is_array($body) ? $body : [];
        $authorization = $request->getHeaderLine('Authorization');
        $this->calls[] = ['path' => $path, 'authorization' => $authorization, 'body' => $body];
        if ($this->down) {
            return new RejectedPromise(new ConnectException('variza.ir is out of reach in this test.', $request));
        }
        if ($request->getMethod() !== 'POST' || $path !== (string) parse_url(VarizaGateway::API, PHP_URL_PATH) . '/pay') {
            throw new \LogicException("FakeVariza has no {$request->getMethod()} {$path}.");
        }
        if ($authorization !== 'Bearer ' . self::KEY) {
            return self::answer(401, ['message' => 'Unauthenticated.']);
        }
        $amount = $body['amount'] ?? null;
        if (!is_int($amount) || $amount < 1000) {
            return self::answer(422, ['message' => 'The amount field must be at least 1000.', 'errors' => ['amount' => ['The amount field must be at least 1000.']]]);
        }
        if (!is_string($body['return_url'] ?? null) || $body['return_url'] === '') {
            return self::answer(422, ['message' => 'The return url field is required.', 'errors' => ['return_url' => ['The return url field is required.']]]);
        }
        $slug = substr(strtr(base64_encode(random_bytes(9)), '+/', 'xy'), 0, 10);
        $this->links[$slug] = ['amount' => $amount, 'body' => $body];

        return self::answer(201, [
            'slug' => $slug,
            'pay_url' => "https://variza.ir/pay/{$slug}",
            'amount' => $amount,
            'title' => $body['title'] ?? null,
            'return_url' => $body['return_url'],
            'expires_at' => gmdate('Y-m-d\TH:i:sP', time() + 30 * 60),
        ]);
    }

    public function down(): void
    {
        $this->down = true;
    }

    /** The only link made so far — its slug. */
    public function onlyLink(): string
    {
        if (count($this->links) !== 1) {
            throw new \LogicException(count($this->links) . ' links were made, not one.');
        }

        return (string) array_key_first($this->links);
    }

    /**
     * Variza's webhook on `$slug`, signed with `$secret`: payment.paid for the link's sum and the few Toman above it,
     * unless `$fields` says otherwise.
     *
     * @param array<string, mixed> $fields
     *
     * @return array{body: string, headers: array<string, string>}
     */
    public function webhook(string $slug, array $fields = [], string $secret = self::SECRET): array
    {
        $base = $this->links[$slug]['amount'] ?? 0;
        $body = json_encode($fields + [
            'event' => 'payment.paid',
            'slug' => $slug,
            'attempt_code' => '152688947983',
            'amount' => $base + self::ABOVE,
            'base_amount' => $base,
            'status' => 'paid',
            'sent_at' => gmdate('Y-m-d\TH:i:sP'),
            'member_phone' => null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return ['body' => $body, 'headers' => [
            'Content-Type' => 'application/json',
            'X-Webhook-Signature' => 'sha256=' . hash_hmac('sha256', $body, $secret),
            'X-Delivery-Id' => bin2hex(random_bytes(8)),
        ]];
    }

    /** @param array<string, mixed> $body */
    private static function answer(int $status, array $body): PromiseInterface
    {
        return new FulfilledPromise(new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
    }
}
