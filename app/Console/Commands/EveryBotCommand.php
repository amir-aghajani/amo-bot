<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Logging\Redact;
use App\Modules\Bots\BotOutcome;
use App\Modules\Bots\Models\Bot;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A command that does its work for every bot the shop runs (Bots::eachServing(): the main one when it has a token, then
 * each agent's that is switched on with one), each in its own shop — and one bot that fails keeps it from none of the
 * others: it says how the work went for each.
 */
abstract class EveryBotCommand extends Command
{
    /**
     * Each bot's outcome said — `$done`'s line for one the work went through for, why it stopped for the others.
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
