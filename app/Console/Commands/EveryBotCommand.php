<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Logging\Redact;
use App\Modules\Bots\BotOutcome;
use App\Modules\Bots\Models\Bot;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Base for a command that works on every serving bot (Bots::eachServing()), each in its own shop; one bot failing
 * stops none of the others, and report() prints how each went.
 */
abstract class EveryBotCommand extends Command
{
    /**
     * Print each bot's outcome: `$done`'s line for a success, the redacted reason for a failure.
     *
     * @template T
     * @param list<BotOutcome<T>> $outcomes
     * @param (\Closure(BotOutcome<T>): string)|null $done
     * @return bool Whether it went through for every bot
     */
    protected static function report(SymfonyStyle $io, array $outcomes, ?\Closure $done = null): bool
    {
        $all = true;
        foreach ($outcomes as $outcome) {
            if ($outcome->failure !== null) {
                $io->warning(sprintf('%s: %s', self::name($outcome->bot), Redact::text($outcome->failure->getMessage())));
                $all = false;
            } elseif ($done !== null) {
                $io->success($done($outcome));
            }
        }

        return $all;
    }

    protected static function name(Bot $bot): string
    {
        return $bot->isMain() ? 'The main bot' : "Agent bot #{$bot->id}";
    }
}
