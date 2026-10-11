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
 * The long polls' own HTTP client over a curl multi handle the poller drives, so every bot's poll is open at once —
 * apart from the Bot API's client, so a call made while serving an update never waits on the polls.
 */
final class LongPolls
{
    /** @param HandlerStack<callable(RequestInterface, array<array-key, mixed>): PromiseInterface> $transport */
    public function __construct(
        private readonly HandlerStack $transport,
        private readonly CurlMultiHandler $curl,
    ) {}

    /** An HTTP client over the polls' own transport. */
    public function client(): ClientInterface
    {
        return new Client(['handler' => $this->transport, 'http_errors' => false]);
    }

    /**
     * Move the polls on: send what was asked, let curl wait for an answer, and settle what answered in the same tick
     * (otherwise an answer would wait on the task queue until the next wait).
     */
    public function tick(): void
    {
        Promises::queue()->run();
        $this->curl->tick();
        Promises::queue()->run();
    }
}
