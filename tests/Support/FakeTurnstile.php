<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Captcha\Drivers\Turnstile;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A stand-in for Cloudflare Turnstile's siteverify: a widget's token passes when it is one passed() made — solved on its
 * host, for its action, as Cloudflare says them —, and only once (Cloudflare takes a token once); any other is refused (as
 * one a bot made up), and every one under a secret it does not know (UNKNOWN_SECRET). down() takes it out of reach.
 * Records what each check sent (`checks`). A Guzzle handler: TestCase::turnstile() makes it the transport of the shop's
 * outgoing client.
 */
final class FakeTurnstile
{
    /** A secret Cloudflare knows no widget by: every token is refused with `invalid-input-secret`. */
    public const UNKNOWN_SECRET = '0x4AAAAAAA-unknown-secret';

    /** @var list<array<string, string>> What each siteverify call sent (its form) */
    public array $checks = [];

    /** @var list<string> The tokens it has taken */
    private array $taken = [];

    private bool $down = false;

    /** The token a widget hands the page once the visitor passed on `$hostname`, for `$action` (null: the widget named none). */
    public static function passed(?string $action, string $hostname = 'shop.example'): string
    {
        return 'pass|' . ($action ?? '') . '|' . $hostname . '|' . bin2hex(random_bytes(4));
    }

    /** @param array<string, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $url = (string) $request->getUri();
        if ("{$request->getMethod()} {$url}" !== 'POST ' . Turnstile::SITEVERIFY) {
            throw new \LogicException("FakeTurnstile has no {$request->getMethod()} {$url}.");
        }
        parse_str((string) $request->getBody(), $form);
        $this->checks[] = array_map(static fn(mixed $value): string => is_scalar($value) ? (string) $value : '', $form);
        if ($this->down) {
            return new RejectedPromise(new ConnectException('challenges.cloudflare.com is out of reach in this test.', $request));
        }

        if (($form['secret'] ?? null) === self::UNKNOWN_SECRET) {
            return self::answer(['success' => false, 'error-codes' => ['invalid-input-secret']]);
        }
        $token = is_string($form['response'] ?? null) ? $form['response'] : '';
        [$pass, $action, $hostname] = explode('|', $token) + ['', '', ''];
        if ($pass !== 'pass') {
            return self::answer(['success' => false, 'error-codes' => ['invalid-input-response']]);
        }
        if (in_array($token, $this->taken, true)) {
            return self::answer(['success' => false, 'error-codes' => ['timeout-or-duplicate']]);
        }
        $this->taken[] = $token;

        return self::answer(['success' => true, 'challenge_ts' => '2026-10-07T12:00:00.000Z', 'hostname' => $hostname, 'error-codes' => [], 'action' => $action, 'cdata' => '']);
    }

    /** Out of reach from now on (or back). */
    public function down(bool $down = true): void
    {
        $this->down = $down;
    }

    /** @param array<string, mixed> $body */
    private static function answer(array $body): PromiseInterface
    {
        return new FulfilledPromise(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($body)));
    }
}
