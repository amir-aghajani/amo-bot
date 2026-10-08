<?php

declare(strict_types=1);

namespace App\Modules\Bots\Models\Concerns;

use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of one bot's shop (`bot_id`): a query sees the current bot's rows only (CurrentBot — none are hidden while it
 * works everywhere()), and a new row is the current bot's unless it says otherwise.
 *
 * @property int $bot_id
 * @property-read Bot $bot
 */
trait BelongsToBot
{
    public static function bootBelongsToBot(): void
    {
        static::addGlobalScope(CurrentBot::SCOPE, static function (Builder $query): void {
            $bot = CurrentBot::scope();
            if ($bot !== null) {
                $query->where($query->qualifyColumn('bot_id'), $bot);
            }
        });

        static::creating(static function (Model $row): void {
            if ($row->getAttribute('bot_id') === null) {
                $row->setAttribute('bot_id', CurrentBot::forNewRow());
            }
        });
    }

    /** @return BelongsTo<Bot, $this> */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /**
     * The shop this row belongs to, for work that must be done in it (CurrentBot::run()): the main bot, or the shop being
     * worked in — the common case, at hand without a query —, or the bot its row names.
     */
    public function shop(): Bot
    {
        $bot = (int) ($this->bot_id ?? Bot::MAIN);

        return match (true) {
            $bot === Bot::MAIN => CurrentBot::main(),
            $bot === CurrentBot::id() => CurrentBot::get(),
            default => $this->bot ?? throw new \LogicException(static::class . " #{$this->getKey()} belongs to a bot that is gone."),
        };
    }
}
