<?php

declare(strict_types=1);

namespace App\Modules\Bots;

use App\Modules\Bots\Models\Bot;

/**
 * What a piece of work done for every bot that runs (Bots::eachServing()) came to for one of them: what it gave back,
 * or what stopped it.
 *
 * @template-covariant T
 */
final readonly class BotOutcome
{
    /** @param T|null $result */
    private function __construct(
        public Bot $bot,
        public mixed $result,
        public ?\Throwable $failure,
    ) {}

    /**
     * @template R
     * @param R $result
     * @return self<R>
     */
    public static function done(Bot $bot, mixed $result): self
    {
        return new self($bot, $result, null);
    }

    /** @return self<never> */
    public static function failed(Bot $bot, \Throwable $failure): self
    {
        return new self($bot, null, $failure);
    }
}
