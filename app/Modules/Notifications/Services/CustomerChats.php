<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Enums\Delivery;
use App\Modules\Telegram\Api\Refusal;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Users\Models\User;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Writing to a customer's private chat on the bot's own initiative — a notice, a broadcast. A customer without a
 * Telegram account has no chat: nothing is sent. Telegram's refusal of a customer who blocked the bot or whose account
 * is gone is remembered on their row (`users.bot_blocked`, cleared by their next message to the bot), and nobody so
 * marked is written to again. A message that does not arrive is logged and dropped: what it tells stands either way.
 */
final class CustomerChats
{
    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * Run `$send` (it sends, given the chat id) for the customer's chat, and return whether they were told: not without a
     * Telegram account, not when they turned the bot away (known, or learned now), not when Telegram refused or was out
     * of reach (logged). Only Telegram's answers are caught: a bug in `$send` goes up.
     *
     * @param \Closure(int): mixed $send
     * @param string $about What the message is about, for the log ("payment #12")
     */
    public function send(User $customer, \Closure $send, string $about): Delivery
    {
        if ($customer->telegram_id === null) {
            return Delivery::NoTelegram;
        }
        if ($customer->bot_blocked) {
            return Delivery::TurnedAway;
        }

        try {
            $send($customer->telegram_id);

            return Delivery::Told;
        } catch (TelegramApiException $e) {
            if ($this->turnedAway($customer, $e)) {
                return Delivery::TurnedAway;
            }
            // Telegram out of reach costs this one message; a refusal of what was sent is the shop's mistake, logged as an error.
            $transient = $e->refusal()->isTransient();
            $this->logger->log($transient ? LogLevel::WARNING : LogLevel::ERROR, 'Could not tell user {user} about {about}: {message}', ['user' => $customer->id, 'about' => $about, 'message' => $e->getMessage()]);

            return $transient ? Delivery::Unreachable : Delivery::Refused;
        }
    }

    /**
     * Whether Telegram's refusal says the customer turned the bot away (blocked it, or their account or chat is gone);
     * remembered on their row when it does.
     */
    public function turnedAway(User $customer, TelegramApiException $e): bool
    {
        if (!$e->is(Refusal::Forbidden, Refusal::ChatGone)) {
            return false;
        }

        if (!$customer->bot_blocked) {
            $customer->newModelQuery()->whereKey($customer->id)->update(['bot_blocked' => true]);
            $customer->forceFill(['bot_blocked' => true])->syncOriginalAttribute('bot_blocked');
            $this->logger->info('User {user} turned the bot away ({message}); nothing more is sent until they write', ['user' => $customer->id, 'message' => $e->getMessage()]);
        }

        return true;
    }
}
