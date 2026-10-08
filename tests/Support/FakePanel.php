<?php

declare(strict_types=1);

namespace Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A stand-in for whatever the app's outgoing HTTP client talks to — a 3x-ui panel first of all:
 * records every request and answers it with what the test queued (`ok()`, `fail()`, `raw()`,
 * `refuse()`), or a plain success envelope otherwise. `healthyPanel()` queues the answers a server
 * probe asks for, in order. It is a Guzzle handler: TestCase::panelHttp() makes it the transport of
 * the container's outgoing client; client() gives a client of its own (a unit test of a driver).
 */
final class FakePanel
{
    /** @var list<array{method: string, path: string, request: RequestInterface, options: array<string, mixed>}> */
    public array $history = [];

    /** @var list<Response|\Throwable> */
    private array $queue = [];

    /** A Guzzle client whose every request lands here. */
    public function client(): ClientInterface
    {
        return new Client(['handler' => HandlerStack::create($this)]);
    }

    /** @param array<string, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $this->history[] = ['method' => $request->getMethod(), 'path' => $request->getUri()->getPath(), 'request' => $request, 'options' => $options];

        $answer = array_shift($this->queue) ?? self::success(null);

        return $answer instanceof \Throwable ? new RejectedPromise($answer) : new FulfilledPromise($answer);
    }

    /** Queue a 3x-ui success envelope carrying `$obj` for the next call(s), in order. */
    public function ok(mixed ...$objs): void
    {
        foreach ($objs as $obj) {
            $this->queue[] = self::success($obj);
        }
    }

    /** Queue a 3x-ui refusal (`success: false` with the panel's message) for the next call. */
    public function fail(string $msg, int $status = 200): void
    {
        $this->queue[] = new Response($status, ['Content-Type' => 'application/json'], (string) json_encode(['success' => false, 'msg' => $msg, 'obj' => null]));
    }

    /** Queue a response as-is (a bare 401, a redirect, an HTML page, a Telegram answer). */
    public function raw(Response ...$responses): void
    {
        foreach ($responses as $response) {
            $this->queue[] = $response;
        }
    }

    /** The next call never reaches the panel: the transport throws (DNS, refused, timeout, TLS). */
    public function refuse(\Throwable $failure): void
    {
        $this->queue[] = $failure;
    }

    /** Forget the calls so far (the queue stays). */
    public function reset(): void
    {
        $this->history = [];
    }

    /** @return list<string> Requests made, in order, as "METHOD /path". */
    public function calls(): array
    {
        return array_map(static fn(array $entry): string => "{$entry['method']} {$entry['path']}", $this->history);
    }

    public function request(int $index): RequestInterface
    {
        return $this->history[$index]['request'] ?? throw new \OutOfRangeException("No panel call #{$index} was made (" . count($this->history) . ' so far).');
    }

    /**
     * How the n-th request was sent — its transfer options: the TLS rule, the timeouts, whether a redirect is followed.
     *
     * @return array<string, mixed>
     */
    public function options(int $index): array
    {
        return $this->history[$index]['options'] ?? throw new \OutOfRangeException("No panel call #{$index} was made (" . count($this->history) . ' so far).');
    }

    /**
     * The body of the n-th request as the panel reads it: a JSON body decoded, a form body parsed.
     *
     * @return array<string, mixed>
     */
    public function params(int $index): array
    {
        $request = $this->request($index);
        $body = (string) $request->getBody();

        if (str_starts_with($request->getHeaderLine('Content-Type'), 'application/json')) {
            $decoded = json_decode($body, true);

            return is_array($decoded) ? $decoded : [];
        }

        parse_str($body, $fields);

        return $fields;
    }

    /**
     * The answers a 3x-ui probe asks for, in order: inbound options (the auth check), status, the
     * inbound list, the settings (whether the subscription server is on).
     */
    public function healthyPanel(bool $subscriptions = true): void
    {
        $this->ok(
            [['id' => 1, 'remark' => 'VLESS', 'protocol' => 'vless', 'port' => 443, 'enable' => true]],
            ['cpu' => 3.5, 'mem' => ['current' => 1, 'total' => 4], 'disk' => ['current' => 1, 'total' => 8], 'xray' => ['state' => 'running', 'version' => 'v25.10.31']],
            self::inboundList(),
            $subscriptions
                ? ['subEnable' => true, 'subDomain' => 'sub.example.com', 'subPort' => 2096, 'subPath' => '/sub/', 'subCertFile' => 'c', 'subKeyFile' => 'k']
                : ['subEnable' => false, 'subPort' => 2096, 'subPath' => '/sub/'],
        );
    }

    /**
     * Two inbounds as GET /inbounds/list answers them, client secrets included — what a sync must not pass on.
     *
     * @return list<array<string, mixed>>
     */
    public static function inboundList(): array
    {
        return [
            ['id' => 1, 'tag' => 'in-443', 'remark' => 'VLESS', 'protocol' => 'vless', 'port' => 443, 'enable' => true, 'settings' => ['clients' => [['id' => 'secret-uuid', 'email' => 'a']]], 'streamSettings' => ['network' => 'tcp', 'security' => 'reality'], 'clientStats' => []],
            ['id' => 2, 'tag' => 'in-8443', 'remark' => 'Trojan', 'protocol' => 'trojan', 'port' => 8443, 'enable' => true, 'settings' => ['clients' => []], 'streamSettings' => ['network' => 'ws', 'security' => 'tls'], 'clientStats' => []],
        ];
    }

    /** 3x-ui's answer to a call that went through: the success envelope carrying `$obj`. */
    public static function success(mixed $obj): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['success' => true, 'msg' => '', 'obj' => $obj]));
    }
}
