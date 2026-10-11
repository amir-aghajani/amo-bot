<?php

declare(strict_types=1);

namespace App\Modules\Bots\Services;

use App\Core\Config\Repository as Config;
use App\Modules\Bots\BotOutcome;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Telegram\Api\BotToken;
use Illuminate\Support\Collection;

/**
 * The bots this installation runs and what is known of each: the main bot's token and @username live in config.php, an
 * agent's in its row.
 */
final class Bots
{
    public function __construct(private readonly Config $config) {}

    /** The bot's @username, without the "@" ('' while unknown) — the current bot's when none is named. */
    public function username(?Bot $bot = null): string
    {
        $bot ??= CurrentBot::get();
        $username = $bot->isMain() ? (string) $this->config->get('telegram.username', '') : (string) $bot->username;

        return ltrim(trim($username), '@');
    }

    /**
     * The shop's name for its customers (its website, its emails' signature): APP_NAME for the main shop; an agent's bot's
     * title, else its @username.
     */
    public function name(Bot $bot): string
    {
        $appName = (string) $this->config->get('app.name', '');
        if ($bot->isMain()) {
            return $appName;
        }
        $username = $this->username($bot);

        return (string) ($bot->title ?? ($username !== '' ? $username : $appName));
    }

    /**
     * The bot's own Telegram id — the main bot's read off its token in config.php, an agent's as recorded when its token
     * was handed over —; null while it has none. The current bot's when none is named.
     */
    public function telegramId(?Bot $bot = null): ?int
    {
        $bot ??= CurrentBot::get();

        return $bot->isMain() ? BotToken::botId((string) $this->config->get('telegram.token', '')) : $bot->telegram_id;
    }

    /** Whether the bot has a token to run on. */
    public function hasToken(Bot $bot): bool
    {
        return $bot->isMain() ? (string) $this->config->get('telegram.token', '') !== '' : ($bot->token ?? '') !== '';
    }

    /**
     * The bots that run now, in order: the main one when it has a token, then every agent's switched on with a token.
     * Read afresh each time: an agent may hand a token over, or lose their agency, while the poller runs.
     *
     * @return Collection<int, Bot>
     */
    public function serving(): Collection
    {
        $agents = Bot::activeAgents()->whereNotNull('token')->get()
            ->filter(static fn(Bot $bot): bool => ($bot->token ?? '') !== '')
            ->values();

        return $this->hasToken(CurrentBot::main()) ? collect([CurrentBot::main()])->concat($agents)->values() : $agents;
    }

    /**
     * Run `$work` for every bot that runs (serving()), each in its own shop; one that fails stops none of the others.
     *
     * @template T
     * @param \Closure(Bot): T $work
     * @return list<BotOutcome<T>> Each bot's, in that order: what `$work` gave back, or what it threw
     */
    public function eachServing(\Closure $work): array
    {
        $outcomes = [];
        foreach ($this->serving() as $bot) {
            try {
                $outcomes[] = BotOutcome::done($bot, CurrentBot::run($bot, static fn() => $work($bot)));
            } catch (\Throwable $e) {
                $outcomes[] = BotOutcome::failed($bot, $e);
            }
        }

        return $outcomes;
    }

    /**
     * The shops the scheduler works through one by one (receipts, renewals, reminders, broadcasts, report groups): the main
     * bot's always (its rows are there, token or not), then every agent's bot that runs.
     *
     * @return Collection<int, Bot>
     */
    public function shops(): Collection
    {
        $agents = $this->serving()->reject(static fn(Bot $bot): bool => $bot->isMain());

        return collect([CurrentBot::main()])->concat($agents)->values();
    }

    /** A bot by id — the main one without asking the database. */
    public function find(int $id): ?Bot
    {
        return $id === Bot::MAIN ? CurrentBot::main() : Bot::query()->find($id);
    }
}
