<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Support\Sleeper;
use App\Modules\Telegram\Api\BotApi;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\Fakes\NullSleeper;

/**
 * A stand-in for api.telegram.org: records every Bot API call the code makes and answers it — with
 * what a test queued (`reply()`, `fail()`, `raw()`), or a plain success otherwise, so tests only spell
 * out the answers that matter (a member status, a refused file) and never count sendMessage calls
 * up front. It is a Guzzle handler: TestCase::telegram() makes it the transport every BotApi of the
 * container sends through; api() gives a client of its own (a unit test of BotApi).
 */
final class FakeTelegram
{
    /** The main bot's token while a test talks to the fake (TestCase::telegram()). */
    public const TOKEN = '4242:bot-token';

    /** The main bot's own user id: the number in front of its token. */
    public const BOT_ID = 4242;

    /** An agent's bot's token (Fixtures::agentBot()): its calls carry it in their URL — see tokenOf(). */
    public const AGENT_TOKEN = '777000:AAagent-bot-token-for-the-tests-0123';

    /** @var list<array{method: string, params: array<string, string>, files: array<string, string>, request: RequestInterface}> */
    public array $history = [];

    /** @var list<Response> */
    private array $queue = [];

    /** @var array<string, \Closure(array<string, string>, string): mixed> Answers by method, ahead of the queue */
    private array $answers = [];

    private int $messageId = 100;

    /** A Bot API client that talks to this fake — and, unless a test watches the waiting, never sleeps through a flood wait. */
    public function api(?Sleeper $sleeper = null): BotApi
    {
        return new BotApi(new Client(['handler' => HandlerStack::create($this)]), static fn(): string => self::TOKEN, 'https://api.telegram.org', $sleeper ?? new NullSleeper());
    }

    /** @param array<string, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $files = [];
        if (str_starts_with($request->getHeaderLine('Content-Type'), 'multipart/')) {
            ['params' => $params, 'files' => $files] = self::multipart((string) $request->getBody(), $request->getHeaderLine('Content-Type'));
        } else {
            parse_str((string) $request->getBody(), $params);
        }
        $method = basename($request->getUri()->getPath());
        $this->history[] = ['method' => $method, 'params' => $params, 'files' => $files, 'request' => $request];

        if (isset($this->answers[$method])) {
            $answer = ($this->answers[$method])($params, self::tokenIn($request));

            return new FulfilledPromise($answer instanceof Response ? $answer : self::ok($answer));
        }

        return new FulfilledPromise(array_shift($this->queue) ?? self::ok(['message_id' => ++$this->messageId]));
    }

    /**
     * Answer every call of one method with what `$answer` makes of its parameters and the token it was made with (which
     * bot asks) — a successful result, or a Response (`error()`) — ahead of the queue, which the other methods keep using
     * in order.
     *
     * @param \Closure(array<string, string>, string): mixed $answer
     */
    public function on(string $method, \Closure $answer): void
    {
        $this->answers[$method] = $answer;
    }

    /**
     * A refusal as Telegram answers it — the error envelope under its HTTP status — for `raw()` or an `on()` answer.
     *
     * @param array<string, mixed> $parameters The ResponseParameters (retry_after…)
     */
    public static function error(int $code, string $description, array $parameters = []): Response
    {
        $status = $code >= 400 && $code < 600 ? $code : 400;

        return new Response($status, [], (string) json_encode(['ok' => false, 'error_code' => $code, 'description' => $description] + ($parameters === [] ? [] : ['parameters' => $parameters])));
    }

    /** Telegram's answer to too many requests: a 429 asking for `$seconds` of patience. */
    public static function flood(int $seconds): Response
    {
        return self::error(429, "Too Many Requests: retry after {$seconds}", ['retry_after' => $seconds]);
    }

    /** Queue a successful answer for the next call(s), in order. */
    public function reply(mixed ...$results): void
    {
        foreach ($results as $result) {
            $this->queue[] = self::ok($result);
        }
    }

    /** Queue a refusal for the next call. */
    public function fail(int $code, string $description): void
    {
        $this->queue[] = self::error($code, $description);
    }

    /** Queue a response as-is (a file download, a non-JSON body). */
    public function raw(Response $response): void
    {
        $this->queue[] = $response;
    }

    /** @return array<string, string> The uploaded files of the n-th call, by field name — the bytes themselves. */
    public function files(int $index): array
    {
        return $this->history[$index]['files'] ?? [];
    }

    /**
     * A multipart body as fields and files: a file part (one with a filename) goes to `files`, its
     * filename to `params` — so `params()` reads the same for uploads as for forms.
     *
     * @return array{params: array<string, string>, files: array<string, string>}
     */
    private static function multipart(string $body, string $contentType): array
    {
        preg_match('/boundary=([^;]+)/', $contentType, $m);
        $boundary = trim($m[1] ?? '', '"');
        $params = [];
        $files = [];

        foreach (explode('--' . $boundary, $body) as $part) {
            if (!str_contains($part, "\r\n\r\n")) {
                continue;
            }
            [$headers, $content] = explode("\r\n\r\n", $part, 2);
            if (preg_match('/name="([^"]+)"/', $headers, $name) !== 1) {
                continue;
            }
            $content = substr($content, 0, -2); // the CRLF before the next boundary
            if (preg_match('/filename="([^"]*)"/', $headers, $filename) === 1) {
                $files[$name[1]] = $content;
                $params[$name[1]] = $filename[1];
            } else {
                $params[$name[1]] = $content;
            }
        }

        return ['params' => $params, 'files' => $files];
    }

    /** Forget the calls so far (the queue stays). */
    public function reset(): void
    {
        $this->history = [];
    }

    /** @return list<string> Bot API methods called, in order. */
    public function calls(): array
    {
        return array_column($this->history, 'method');
    }

    /** @return array<string, string> The form parameters of the n-th call. */
    public function params(int $index): array
    {
        return $this->history[$index]['params'] ?? throw new \OutOfRangeException("No Telegram call #{$index} was made (" . count($this->history) . ' so far).');
    }

    /** The token the n-th call was made with — which bot spoke (TOKEN, the main one; AGENT_TOKEN, an agent's). */
    public function tokenOf(int $index): string
    {
        return self::tokenIn($this->history[$index]['request'] ?? throw new \OutOfRangeException("No Telegram call #{$index} was made (" . count($this->history) . ' so far).'));
    }

    private static function tokenIn(RequestInterface $request): string
    {
        preg_match('~/bot([^/]+)/~', $request->getUri()->getPath(), $match);

        return $match[1] ?? '';
    }

    /**
     * What was said to a chat, in order: the text of each message sent there, or the caption of each picture.
     *
     * @return list<string>
     */
    public function sentTo(int $chat): array
    {
        $said = [];
        foreach ($this->history as $call) {
            if (in_array($call['method'], ['sendMessage', 'sendPhoto'], true) && ($call['params']['chat_id'] ?? null) === (string) $chat) {
                $said[] = $call['params']['text'] ?? $call['params']['caption'] ?? '';
            }
        }

        return $said;
    }

    /** The message the n-th call replies to; null when it is no reply. */
    public function replyTarget(int $index): ?int
    {
        $reply = $this->params($index)['reply_parameters'] ?? null;

        return $reply === null ? null : (int) (json_decode($reply, true)['message_id'] ?? 0);
    }

    /** @return array<mixed> The reply markup the n-th call carried, decoded (see markupOf()). */
    public function markup(int $index): array
    {
        return self::markupOf($this->params($index));
    }

    /**
     * The reply markup a call carried, decoded — its inline buttons, a reply keyboard, `remove_keyboard`, `force_reply` —
     * or [] when it carried none.
     *
     * @param array<string, string> $params The call's parameters (params(i), or one picked out of the history)
     * @return array<mixed>
     */
    public static function markupOf(array $params): array
    {
        $markup = json_decode($params['reply_markup'] ?? '{}', true);

        return is_array($markup) ? $markup : [];
    }

    public static function ok(mixed $result): Response
    {
        return new Response(200, [], (string) json_encode(['ok' => true, 'result' => $result]));
    }
}
