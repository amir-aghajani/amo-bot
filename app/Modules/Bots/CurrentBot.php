<?php

declare(strict_types=1);

namespace App\Modules\Bots;

use App\Modules\Bots\Models\Bot;

/**
 * Whose shop the code is working in: the bot an update came to, the shop the panel shows, the bot a scheduled task is
 * going through. Every row of a shop carries its `bot_id` (Models\Concerns\BelongsToBot), and a query sees the rows of
 * the current bot only, so an agent's shop never shows another's — a customer, a plan or a payment of another bot is
 * simply not there. Nothing set, it is the main bot's shop: the CLI, the tasks and the tests start there. The work
 * that is the shop's as a whole — a server's services, its grants, the periodic sync — runs everywhere(): no rows are
 * hidden then, and a row made there must say whose it is.
 *
 * The state is the process's (a request, a poll round, a task), so it is always set for a piece of work and put back
 * after it — run() and everywhere() do both.
 */
final class CurrentBot
{
    /** The name of the global scope BelongsToBot adds. */
    public const SCOPE = 'bot';

    private static ?Bot $bot = null;

    private static bool $everywhere = false;

    private static ?Bot $main = null;

    /** The bot whose shop this is — the main one when none was set. */
    public static function get(): Bot
    {
        return self::$bot ?? self::main();
    }

    public static function id(): int
    {
        return self::$bot->id ?? Bot::MAIN;
    }

    public static function isMain(): bool
    {
        return self::id() === Bot::MAIN;
    }

    /** The `bot_id` a query is held to; null while working everywhere(). */
    public static function scope(): ?int
    {
        return self::$everywhere ? null : self::id();
    }

    /** The `bot_id` a new row gets when it does not say. */
    public static function forNewRow(): int
    {
        if (self::$everywhere) {
            throw new \LogicException('A row made while working across every bot must say whose it is (bot_id).');
        }

        return self::id();
    }

    /**
     * Do the work in this bot's shop, and come back to where we were after it (even when it throws).
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public static function run(Bot|int $bot, \Closure $work): mixed
    {
        $bot = $bot instanceof Bot ? $bot : self::find($bot);
        [$previous, $wasEverywhere] = [self::$bot, self::$everywhere];
        self::$bot = $bot->isMain() ? null : $bot;
        self::$everywhere = false;

        try {
            return $work();
        } finally {
            [self::$bot, self::$everywhere] = [$previous, $wasEverywhere];
        }
    }

    /**
     * Do the work across every bot's shop — the shop's own business: its servers, their services, their grants.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public static function everywhere(\Closure $work): mixed
    {
        $wasEverywhere = self::$everywhere;
        self::$everywhere = true;

        try {
            return $work();
        } finally {
            self::$everywhere = $wasEverywhere;
        }
    }

    /** Back to the main bot's shop, nothing remembered — between two tests. */
    public static function reset(): void
    {
        self::$bot = null;
        self::$everywhere = false;
        self::$main = null;
    }

    /**
     * The main bot. It has a row of its own (#1, every shop starts with it); what the code needs of it — its id, that it
     * runs — does not need the database, which may not exist yet (the web installer).
     */
    public static function main(): Bot
    {
        if (self::$main === null) {
            self::$main = (new Bot())->forceFill(['id' => Bot::MAIN])->syncOriginal();
            self::$main->exists = true;
        }

        return self::$main;
    }

    private static function find(int $id): Bot
    {
        if ($id === Bot::MAIN) {
            return self::main();
        }

        return Bot::query()->find($id) ?? throw new \InvalidArgumentException("There is no bot #{$id}.");
    }
}
