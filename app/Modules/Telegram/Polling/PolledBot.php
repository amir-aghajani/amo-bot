<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Polling;

use App\Modules\Bots\Models\Bot;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * One bot the poller polls, and where its polling stands: the update to ask from, the poll it has open, how long it
 * waits before asking again after a failure.
 */
final class PolledBot
{
    /** The update to ask for next: one after the last served. */
    public int $offset = 0;

    /** The poll open now: fulfilled with the updates, or with what went wrong. */
    public ?PromiseInterface $poll = null;

    /** Not asked again before this moment (unix seconds): after a failure. */
    public int $waitUntil = 0;

    /** Seconds the next failure waits, doubling up to a limit; back to one after an answer. */
    public int $backoff = 1;

    /** When it last said it is alive (unix seconds). */
    public int $heartbeat = 0;

    /** Answers it has had (--once: one each). */
    public int $answers = 0;

    /** @param string $key What it is polled as: a token that changes makes it another bot */
    public function __construct(
        public Bot $bot,
        public readonly string $key,
    ) {}
}
