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
 * Telegram account (one who signed up on the website) has no chat: nothing is sent, nothing is asked of Telegram.
 * Telegram refuses the chat of a customer who blocked the bot or whose account is gone: such a refusal is remembered on
 * their row (`users.bot_blocked` — their next message to the bot clears it, UserResolver), and nobody remembered so is
 * written to again. A notice that does not reach its customer is logged and dropped: what it tells stands either way.
 */
final class CustomerChats
{
    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * `$send` to the customer's chat — it composes and sends, given the chat id —, and whether they were told: not when
     * they have no Telegram account, nor when they turned the bot away (known, or learned now), nor when Telegram refused
     * or was out of reach (logged). What `$send` gets wrong itself — a text the code mis-filled — is no delivery problem
     * and goes up.
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
            // Telegram out of reach costs this one message; a refusal of what was sent is the shop's mistake to see.
            $transient = $e->refusal()->isTransient();
            $this->logger->log($transient ? LogLevel::WARNING : LogLevel::ERROR, 'Could not tell user {user} about {about}: {message}', ['user' => $customer->id, 'about' => $about, 'message' => $e->getMessage()]);

            return $transient ? Delivery::Unreachable : Delivery::Refused;
        }
    }

    /**
     * Whether Telegram's refusal says the customer turned the bot away — they blocked it, their account is gone, their
     * chat is no more — remembered on their row when it does.
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
