<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Polling;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils as Promises;
use Psr\Http\Message\RequestInterface;

/**
 * The long polls' own HTTP: a curl multi handle the poller drives itself, so every bot's poll is open at once and
 * whichever answers is served — apart from the Bot API's client, since a call made while serving an update must not
 * wait for the other bots' polls to end. The transport is the container's `telegram.polling` (where a test puts its
 * fake Telegram), the multi handle `telegram.polling.curl`.
 */
final class LongPolls
{
    /** @param HandlerStack<callable(RequestInterface, array<array-key, mixed>): PromiseInterface> $transport */
    public function __construct(
        private readonly HandlerStack $transport,
        private readonly CurlMultiHandler $curl,
    ) {}

    public function client(): ClientInterface
    {
        return new Client(['handler' => $this->transport, 'http_errors' => false]);
    }

    /**
     * Move the polls on a little: what was asked goes out, curl waits up to its select timeout for an answer, and what
     * answered settles — in the same tick: the promise of an answer curl has just read waits on the task queue, and left
     * there it would settle only after the next wait of up to a second.
     */
    public function tick(): void
    {
        Promises::queue()->run();
        $this->curl->tick();
        Promises::queue()->run();
    }
}
